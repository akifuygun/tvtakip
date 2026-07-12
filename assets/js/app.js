// tvtakip frontend.
// All provider access (TVmaze/TMDB) happens server-side: search goes through
// api/search.php, and imports happen inside api/episodes.php / api/track.php —
// the browser only ever sends IMDB ids and reads our own cache.
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const IS_ADMIN = document.querySelector('meta[name="is-admin"]')?.content === '1';

// Report the browser's timezone so server-rendered dates ("aired today",
// upcoming groupings) match the user's clock. Takes effect from the next
// request; PHP validates the value and falls back to Europe/Istanbul.
try {
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz && !document.cookie.includes(`tz=${encodeURIComponent(tz)}`)) {
    document.cookie = `tz=${encodeURIComponent(tz)}; path=/; max-age=31536000; SameSite=Lax`;
  }
} catch { /* keep server default */ }

// Translations shared with PHP via window.I18N (current language).
const I18N = window.I18N || { lang: 'en', app: 'TVTrack', t: {} };
function t(key, ...args) {
  let i = 0;
  return (I18N.t[key] ?? key).replace(/%[sd]/g, () => args[i++]);
}
const STATUS_LABELS = {
  running: t('status_running'), ended: t('status_ended'),
  canceled: t('status_canceled'), upcoming: t('status_upcoming'),
};

// Backstop: root-anchor so calls work from subpaths like /series/ttNNN too.
function apiUrl(url) {
  return url.startsWith('/') ? url : '/' + url;
}

// Mirrors PHP series_url()/lang_path(): language-prefixed public show URL.
function seriesUrl(imdbId) {
  return (I18N.lang === 'tr' ? '/tr' : '') + `/series/${imdbId}`;
}

// Mirrors PHP episode_code(): S01E05.
function epCode(ep) {
  return `S${String(ep.season).padStart(2, '0')}E${String(ep.number).padStart(2, '0')}`;
}

// The API sends airstamps as MySQL UTC datetimes ("2026-07-12 01:00:00").
function airstampDate(airstamp) {
  return new Date(airstamp.replace(' ', 'T') + 'Z');
}

async function apiPost(url, body) {
  const res = await fetch(apiUrl(url), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

async function apiGet(url) {
  const res = await fetch(apiUrl(url));
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'text') node.textContent = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value);
  }
  for (const child of children) node.append(child);
  return node;
}

// Resolve only if the URL actually loads as an image (in the browser, so the
// server never fetches user-supplied URLs). Rejects on error or timeout.
function loadImage(url, timeout = 10000) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const timer = setTimeout(() => { img.src = ''; reject(new Error('timeout')); }, timeout);
    img.onload = () => { clearTimeout(timer); img.naturalWidth > 0 ? resolve() : reject(new Error('empty')); };
    img.onerror = () => { clearTimeout(timer); reject(new Error('not an image')); };
    img.src = url;
  });
}

// Prompt for an image URL, verify it loads, then set it on the show, replacing
// `node` with the new poster <img>. Used by the show page and My Shows cards.
async function promptSetImage(showId, node) {
  const url = prompt(t('add_image_prompt'));
  if (!url || !url.trim()) return;
  const clean = url.trim();
  try {
    await loadImage(clean);
  } catch {
    alert(t('invalid_image_url'));
    return;
  }
  try {
    const res = await apiPost('/api/image.php', { imdb_id: showId, image_url: clean });
    node.replaceWith(el('img', { src: res.image_url, alt: '' }));
  } catch (err) {
    alert(err.message);
  }
}

// Admin-only ✕ button that removes a show's poster, then runs onRemoved().
function makeRemoveButton(showId, onRemoved) {
  const rm = el('button', { type: 'button', class: 'poster-remove', title: t('remove_poster_title'), text: '✕' });
  rm.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm(t('remove_poster_confirm'))) return;
    try {
      await apiPost('/api/image.php', { imdb_id: showId, remove: true });
      onRemoved();
    } catch (err) {
      alert(err.message);
    }
  });
  return rm;
}

// A clickable "No image / Click to add" placeholder box.
function addImagePlaceholder(showId, extraClass = '') {
  const ph = el('button', { type: 'button', class: `no-poster no-poster-edit ${extraClass}`.trim(), title: t('add_image_title') }, [
    el('span', { text: t('no_image') }),
    el('span', { class: 'muted', text: t('click_to_add') }),
  ]);
  ph.addEventListener('click', () => promptSetImage(showId, ph));
  return ph;
}

// Poster for the show-detail header: an <img> (with an admin remove overlay),
// or a clickable "add image" box.
function posterEl(showId, imageUrl) {
  if (!imageUrl) {
    // Only admins can set posters; others just see a plain placeholder.
    return IS_ADMIN ? addImagePlaceholder(showId) : el('div', { class: 'no-poster', text: t('no_image') });
  }
  const img = el('img', { src: imageUrl, alt: '' });
  if (!IS_ADMIN) return img;
  const wrap = el('div', { class: 'poster-wrap' }, [img]);
  wrap.append(makeRemoveButton(showId, () => wrap.replaceWith(posterEl(showId, null))));
  return wrap;
}

function imdbLink(imdbId, cls = 'imdb-link') {
  return el('a', {
    href: `https://www.imdb.com/title/${imdbId}/`,
    target: '_blank',
    rel: 'noopener',
    class: cls,
    text: 'IMDB',
  });
}

// ---------- Search page ----------
function initSearch() {
  const form = document.getElementById('search-form');
  if (!form) return;
  const input = document.getElementById('search-input');
  const results = document.getElementById('search-results');
  const trackedIds = new Set(window.TRACKED_IDS || []);

  // Arriving from the header search box (?q=...): run the search right away.
  if (input.value.trim()) {
    setTimeout(() => form.requestSubmit(), 0);
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = input.value.trim();
    if (!q) return;
    results.textContent = t('searching');
    try {
      const { results: items } = await apiGet(`/api/search.php?q=${encodeURIComponent(q)}`);
      results.replaceChildren();
      if (!items.length) {
        results.textContent = t('no_shows_found');
        return;
      }
      for (const item of items) {
        results.append(renderSearchCard(item, trackedIds));
      }
    } catch (err) {
      results.textContent = t('search_failed', err.message);
    }
  });
}

function renderSearchCard(item, trackedIds) {
  const year = item.year ? ` (${item.year})` : '';
  const poster = item.image
    ? el('img', { src: item.image, alt: '' })
    : el('div', { class: 'no-poster', text: t('no_image') });
  const source = el('span', { class: 'muted source-tag', text: item.source });

  if (!item.imdb_id) {
    return el('div', { class: 'show-card' }, [
      poster,
      el('h3', { text: item.name + year }),
      source,
      el('span', { class: 'muted', text: t('no_imdb') }),
    ]);
  }

  const isTracked = trackedIds.has(item.imdb_id);
  const trackBtn = el('button', {
    class: 'button button-small track-btn',
    text: isTracked ? t('tracking') : t('track'),
    ...(isTracked ? { disabled: '' } : {}),
    onclick: async () => {
      trackBtn.disabled = true;
      trackBtn.textContent = t('importing');
      try {
        await apiPost('/api/track.php', { action: 'track', imdb_id: item.imdb_id });
        trackedIds.add(item.imdb_id);
        trackBtn.textContent = t('tracking');
      } catch (err) {
        trackBtn.disabled = false;
        trackBtn.textContent = t('track');
        alert(err.message);
      }
    },
  });

  return el('div', { class: 'show-card' }, [
    el('a', { href: seriesUrl(item.imdb_id) }, [poster]),
    el('h3', {}, [el('a', { href: seriesUrl(item.imdb_id), text: item.name + year })]),
    source,
    trackBtn,
  ]);
}

// ---------- Dashboard: untrack buttons ----------
function initDashboard() {
  document.querySelectorAll('.untrack-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm(t('untrack_confirm'))) return;
      btn.disabled = true;
      try {
        await apiPost('/api/track.php', { action: 'untrack', imdb_id: btn.dataset.showId });
        btn.closest('.show-card')?.remove();
      } catch (err) {
        btn.disabled = false;
        alert(err.message);
      }
    });
  });
}

// ---------- Calendar page ----------
function initCalendar() {
  const cal = document.getElementById('calendar');
  if (!cal) return;
  cal.querySelectorAll('.cal-watch-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await apiPost('/api/watch.php', { episode_id: Number(btn.dataset.episodeId), watched: true });
        // Reload so the show's next unwatched episode takes this row's place.
        location.reload();
      } catch (err) {
        btn.disabled = false;
        alert(err.message);
      }
    });
  });
}

// ---------- Show detail page ----------
async function initShowDetail() {
  const root = document.getElementById('show-detail');
  if (!root) return;
  const showId = root.dataset.showId;
  let isTracked = root.dataset.tracked === '1';
  const status = el('p', { class: 'loading', text: t('loading_show') });
  root.replaceChildren(status);

  let data;
  try {
    data = await apiGet(`/api/episodes.php?show_id=${showId}`);
    // synced_at is only set once a server-side import completed.
    if (!data.show?.synced_at || !data.episodes.length) {
      status.textContent = t('importing_first');
      data = await apiPost('/api/episodes.php', { show_id: showId });
    }
  } catch (err) {
    status.textContent = t('could_not_load', err.message);
    return;
  }

  const show = data.show;
  const episodes = data.episodes;
  const watched = new Set(data.watched || []);
  // Server clock, not browser clock — keeps aired-ness in sync with the API.
  const today = data.today ?? new Date().toISOString().slice(0, 10);
  const nowUtc = data.now ?? new Date().toISOString().slice(0, 19).replace('T', ' ');
  // Exact UTC airstamp when known (string compare works: same format), else date.
  const epHasAired = (ep) => (ep.airstamp ? ep.airstamp <= nowUtc : !!ep.airdate && ep.airdate <= today);
  // "Airs …" in the viewer's local time when we know the exact time.
  const airsLabel = (ep) => {
    if (ep.airstamp) {
      return airstampDate(ep.airstamp)
        .toLocaleString(I18N.lang === 'tr' ? 'tr-TR' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
    }
    return ep.airdate;
  };

  // Live countdown box for the next upcoming episode.
  function buildCountdown(n) {
    const box = el('div', { class: 'countdown' });
    const label = el('div', {
      class: 'countdown-label',
      text: `${t('next_episode')}: ${epCode(n.ep)}${n.ep.name ? ' · ' + n.ep.name : ''}`,
    });
    const timer = el('div', { class: 'countdown-timer' });
    const sub = el('div', { class: 'countdown-sub muted', text: airsLabel(n.ep) });
    box.append(label, timer, sub);
    const render = () => {
      const ms = n.target.getTime() - Date.now();
      if (ms <= 0) {
        timer.textContent = t('airing_now');
        return;
      }
      const d = Math.floor(ms / 86400000);
      const h = Math.floor(ms / 3600000) % 24;
      const m = Math.floor(ms / 60000) % 60;
      const s = Math.floor(ms / 1000) % 60;
      if (!n.ep.airstamp) {
        timer.textContent = d <= 1 ? t('in_day') : t('in_days', d);
      } else if (d > 0) {
        timer.textContent = `${d}${t('unit_d')} ${h}${t('unit_h')} ${m}${t('unit_m')}`;
      } else if (h > 0) {
        timer.textContent = `${h}${t('unit_h')} ${m}${t('unit_m')} ${s}${t('unit_s')}`;
      } else {
        timer.textContent = `${m}${t('unit_m')} ${s}${t('unit_s')}`;
      }
      // Tick per second only while seconds are on screen (under a day out).
      setTimeout(render, n.ep.airstamp && d === 0 ? 1000 : 60000);
    };
    render();
    return box;
  }
  document.title = `${I18N.app} — ${show.name}`;
  root.replaceChildren();

  // Soonest unaired episode with a known air time → live countdown.
  let nextUp = null;
  for (const ep of episodes) {
    if (epHasAired(ep)) continue;
    const target = ep.airstamp
      ? airstampDate(ep.airstamp)
      : (ep.airdate ? new Date(ep.airdate + 'T00:00:00') : null);
    if (target && (!nextUp || target < nextUp.target)) {
      nextUp = { ep, target };
    }
  }

  const trackBtn = el('button', {
    class: 'button',
    text: isTracked ? t('untrack') : t('track_show'),
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('/api/track.php', {
          action: isTracked ? 'untrack' : 'track',
          imdb_id: showId,
        });
        isTracked = !isTracked;
        trackBtn.textContent = isTracked ? t('untrack') : t('track_show');
      } catch (err) {
        alert(err.message);
      }
      trackBtn.disabled = false;
    },
  });

  const info = el('div', { class: 'show-info' }, [
    el('h1', {}, [show.name + ' ', imdbLink(showId)]),
    el('p', {
      class: 'muted',
      text: [show.premiered?.slice(0, 4), STATUS_LABELS[show.status]].filter(Boolean).join(' · '),
    }),
  ]);
  if (nextUp) info.append(buildCountdown(nextUp));
  info.append(
    el('p', { class: 'show-summary', text: show.overview ?? '' }),
    trackBtn,
  );

  root.append(el('div', { class: 'show-header' }, [posterEl(showId, show.image_url), info]));

  // Episodes grouped by season
  const seasons = new Map();
  for (const ep of episodes) {
    if (!seasons.has(ep.season)) seasons.set(ep.season, []);
    seasons.get(ep.season).push(ep);
  }

  const allToggles = []; // per-episode setWatched fns, for mark-all
  const epContainer = el('div', { class: 'seasons' });
  // Newest season first; only that one starts unfolded.
  const orderedSeasons = [...seasons.entries()].sort((a, b) => b[0] - a[0]);
  const newestSeason = orderedSeasons[0]?.[0];
  for (const [season, eps] of orderedSeasons) {
    const seasonToggles = []; // {watched(), setWatched()} per aired episode
    let updateSeasonBtn = null;
    const list = el('ul', { class: 'episode-list' });
    for (const ep of eps) {
      const code = epCode(ep);
      const aired = ep.airdate ? ` — ${ep.airdate}` : '';
      const hasAired = epHasAired(ep);
      let isWatched = watched.has(ep.id);

      const li = el('li', {});
      const toggleBtn = el('button', { class: 'button button-small ep-toggle-btn' });
      const setWatched = (value) => {
        isWatched = value;
        toggleBtn.textContent = value ? t('mark_ep_unwatched', code) : t('mark_ep_watched', code);
        toggleBtn.classList.toggle('button-secondary', value);
        li.classList.toggle('watched', value);
        updateSeasonBtn?.();
      };
      if (hasAired) {
        setWatched(isWatched);
        toggleBtn.addEventListener('click', async () => {
          toggleBtn.disabled = true;
          try {
            await apiPost('/api/watch.php', { episode_id: ep.id, watched: !isWatched });
            setWatched(!isWatched);
          } catch (err) {
            alert(err.message);
          }
          toggleBtn.disabled = false;
        });
        allToggles.push(setWatched);
        seasonToggles.push({ watched: () => isWatched, setWatched });
      } else {
        // Unaired (future or unknown airdate): not markable, and bulk actions skip it.
        toggleBtn.textContent = (ep.airstamp || ep.airdate) ? t('airs_on', airsLabel(ep)) : t('not_aired');
        toggleBtn.disabled = true;
        toggleBtn.classList.add('button-secondary');
        li.classList.add('unaired');
      }

      const title = el('span', { class: 'ep-title' }, [
        `${code} ${ep.name ?? ''}${aired} `,
        ...(ep.imdb_id ? [imdbLink(ep.imdb_id)] : []),
      ]);
      li.append(title, toggleBtn);
      list.append(li);
    }
    const title = season === 0 ? t('specials') : t('season_n', season);
    const seasonFullyWatched = () =>
      seasonToggles.length > 0 && seasonToggles.every((tog) => tog.watched());
    const seasonBtn = el('button', {
      class: 'button button-small button-secondary season-watched-btn',
      onclick: async (e) => {
        // Inside <summary>: don't let the click also toggle the season open/closed.
        e.preventDefault();
        e.stopPropagation();
        const unwatch = seasonFullyWatched();
        seasonBtn.disabled = true;
        try {
          await apiPost('/api/watch.php', { show_id: showId, all: true, season, watched: !unwatch });
          for (const tog of seasonToggles) tog.setWatched(!unwatch);
        } catch (err) {
          alert(err.message);
        }
        seasonBtn.disabled = false;
      },
    });
    updateSeasonBtn = () => {
      seasonBtn.textContent = seasonFullyWatched() ? t('mark_season_unwatched') : t('mark_season_watched');
    };
    updateSeasonBtn();
    epContainer.append(el('details', { class: 'season', ...(season === newestSeason ? { open: '' } : {}) }, [
      el('summary', {}, [`${title} ${eps.length === 1 ? t('episodes_count_one') : t('episodes_count', eps.length)}`, seasonBtn]),
      list,
    ]));
  }

  const markAllBtn = el('button', {
    class: 'button button-small',
    text: t('mark_all_watched'),
    onclick: async () => {
      markAllBtn.disabled = true;
      try {
        await apiPost('/api/watch.php', { show_id: showId, all: true });
        for (const setWatched of allToggles) setWatched(true);
      } catch (err) {
        alert(err.message);
      }
      markAllBtn.disabled = false;
    },
  });

  const unmarkAllBtn = el('button', {
    class: 'button button-small button-danger',
    text: t('mark_all_unwatched'),
    onclick: async () => {
      if (!confirm(t('unwatch_all_confirm'))) return;
      unmarkAllBtn.disabled = true;
      try {
        await apiPost('/api/watch.php', { show_id: showId, all: true, watched: false });
        for (const setWatched of allToggles) setWatched(false);
      } catch (err) {
        alert(err.message);
      }
      unmarkAllBtn.disabled = false;
    },
  });

  // Server-side re-import: picks up newly aired episodes and backfills
  // episode IMDB ids as TMDB gets them.
  const refreshBtn = el('button', {
    class: 'button button-small button-secondary',
    text: t('refresh_episodes'),
    onclick: async () => {
      refreshBtn.disabled = true;
      refreshBtn.textContent = t('refreshing');
      try {
        await apiPost('/api/episodes.php', { show_id: showId });
        location.reload();
      } catch (err) {
        alert(err.message);
        refreshBtn.textContent = t('refresh_episodes');
        refreshBtn.disabled = false;
      }
    },
  });

  root.append(
    el('div', { class: 'episodes-header' }, [
      el('h2', { text: t('episodes') }),
      el('div', { class: 'episodes-actions' }, [refreshBtn, markAllBtn, unmarkAllBtn]),
    ]),
    epContainer,
  );
}

// ---------- Mobile nav toggle ----------
function initNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.getElementById('site-nav');
  if (!toggle || !nav) return;
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}

initNav();
initSearch();
initDashboard();
initCalendar();
initShowDetail();

// PWA: register the service worker (HTTPS only — it silently no-ops on http).
if ('serviceWorker' in navigator && location.protocol === 'https:') {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}
