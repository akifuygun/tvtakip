// tvtakip frontend.
// All provider access (TVmaze/TMDB) happens server-side: search goes through
// api/search.php, and imports happen inside api/episodes.php / api/track.php —
// the browser only ever sends IMDB ids and reads our own cache.
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
// Display labels for canonical status values (normalized server-side).
const STATUS_LABELS = { running: 'Running', ended: 'Ended', canceled: 'Canceled', upcoming: 'Upcoming' };

async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

async function apiGet(url) {
  const res = await fetch(url);
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

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = input.value.trim();
    if (!q) return;
    results.textContent = 'Searching TVmaze and TMDB…';
    try {
      const { results: items } = await apiGet(`api/search.php?q=${encodeURIComponent(q)}`);
      results.replaceChildren();
      if (!items.length) {
        results.textContent = 'No shows found.';
        return;
      }
      for (const item of items) {
        results.append(renderSearchCard(item, trackedIds));
      }
    } catch (err) {
      results.textContent = `Search failed: ${err.message}`;
    }
  });
}

function renderSearchCard(item, trackedIds) {
  const year = item.year ? ` (${item.year})` : '';
  const poster = item.image
    ? el('img', { src: item.image, alt: '' })
    : el('div', { class: 'no-poster', text: 'No image' });
  const source = el('span', { class: 'muted source-tag', text: item.source });

  if (!item.imdb_id) {
    return el('div', { class: 'show-card' }, [
      poster,
      el('h3', { text: item.name + year }),
      source,
      el('span', { class: 'muted', text: 'No IMDB id — cannot track' }),
    ]);
  }

  const isTracked = trackedIds.has(item.imdb_id);
  const trackBtn = el('button', {
    class: 'button button-small track-btn',
    text: isTracked ? 'Tracking ✓' : 'Track',
    ...(isTracked ? { disabled: '' } : {}),
    onclick: async () => {
      trackBtn.disabled = true;
      trackBtn.textContent = 'Importing…';
      try {
        await apiPost('api/track.php', { action: 'track', imdb_id: item.imdb_id });
        trackedIds.add(item.imdb_id);
        trackBtn.textContent = 'Tracking ✓';
      } catch (err) {
        trackBtn.disabled = false;
        trackBtn.textContent = 'Track';
        alert(err.message);
      }
    },
  });

  return el('div', { class: 'show-card' }, [
    el('a', { href: `show.php?id=${item.imdb_id}` }, [poster]),
    el('h3', {}, [el('a', { href: `show.php?id=${item.imdb_id}`, text: item.name + year })]),
    source,
    trackBtn,
  ]);
}

// ---------- Dashboard: untrack buttons ----------
function initDashboard() {
  document.querySelectorAll('.untrack-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Untrack this show? Your watched history will be kept if you track it again.')) return;
      btn.disabled = true;
      try {
        await apiPost('api/track.php', { action: 'untrack', imdb_id: btn.dataset.showId });
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
        await apiPost('api/watch.php', { episode_id: Number(btn.dataset.episodeId), watched: true });
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
  const status = el('p', { class: 'loading', text: 'Loading show…' });
  root.replaceChildren(status);

  let data;
  try {
    data = await apiGet(`api/episodes.php?show_id=${showId}`);
    // synced_at is only set once a server-side import completed.
    if (!data.show?.synced_at || !data.episodes.length) {
      status.textContent = 'Importing episodes (first visit for this show)…';
      data = await apiPost('api/episodes.php', { show_id: showId });
    }
  } catch (err) {
    status.textContent = `Could not load this show: ${err.message}`;
    return;
  }

  const show = data.show;
  const episodes = data.episodes;
  const watched = new Set(data.watched || []);
  // Server clock, not browser clock — keeps aired-ness in sync with the API.
  const today = data.today ?? new Date().toISOString().slice(0, 10);
  document.title = `${show.name} — TVTrack`;
  root.replaceChildren();

  const trackBtn = el('button', {
    class: 'button',
    text: isTracked ? 'Untrack' : 'Track this show',
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('api/track.php', {
          action: isTracked ? 'untrack' : 'track',
          imdb_id: showId,
        });
        isTracked = !isTracked;
        trackBtn.textContent = isTracked ? 'Untrack' : 'Track this show';
      } catch (err) {
        alert(err.message);
      }
      trackBtn.disabled = false;
    },
  });

  root.append(
    el('div', { class: 'show-header' }, [
      show.image_url ? el('img', { src: show.image_url, alt: '' }) : '',
      el('div', {}, [
        el('h1', {}, [show.name + ' ', imdbLink(showId)]),
        el('p', {
          class: 'muted',
          text: [show.premiered?.slice(0, 4), STATUS_LABELS[show.status]].filter(Boolean).join(' · '),
        }),
        el('p', { class: 'show-summary', text: show.overview ?? '' }),
        trackBtn,
      ]),
    ]),
  );

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
      const code = `S${String(ep.season).padStart(2, '0')}E${String(ep.number).padStart(2, '0')}`;
      const aired = ep.airdate ? ` — ${ep.airdate}` : '';
      const hasAired = !!ep.airdate && ep.airdate <= today;
      let isWatched = watched.has(ep.id);

      const li = el('li', {});
      const toggleBtn = el('button', { class: 'button button-small ep-toggle-btn' });
      const setWatched = (value) => {
        isWatched = value;
        toggleBtn.textContent = value ? `❌ Mark ${code} Not Watched` : `✅ Mark ${code} Watched`;
        toggleBtn.classList.toggle('button-secondary', value);
        li.classList.toggle('watched', value);
        updateSeasonBtn?.();
      };
      if (hasAired) {
        setWatched(isWatched);
        toggleBtn.addEventListener('click', async () => {
          toggleBtn.disabled = true;
          try {
            await apiPost('api/watch.php', { episode_id: ep.id, watched: !isWatched });
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
        toggleBtn.textContent = ep.airdate ? `📅 Airs ${ep.airdate}` : '📅 Not aired yet';
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
    const title = season === 0 ? 'Specials' : `Season ${season}`;
    const seasonFullyWatched = () =>
      seasonToggles.length > 0 && seasonToggles.every((t) => t.watched());
    const seasonBtn = el('button', {
      class: 'button button-small button-secondary season-watched-btn',
      onclick: async (e) => {
        // Inside <summary>: don't let the click also toggle the season open/closed.
        e.preventDefault();
        e.stopPropagation();
        const unwatch = seasonFullyWatched();
        seasonBtn.disabled = true;
        try {
          await apiPost('api/watch.php', { show_id: showId, all: true, season, watched: !unwatch });
          for (const t of seasonToggles) t.setWatched(!unwatch);
        } catch (err) {
          alert(err.message);
        }
        seasonBtn.disabled = false;
      },
    });
    updateSeasonBtn = () => {
      seasonBtn.textContent = seasonFullyWatched() ? 'Mark Season Unwatched' : 'Mark Season Watched';
    };
    updateSeasonBtn();
    epContainer.append(el('details', { class: 'season', ...(season === newestSeason ? { open: '' } : {}) }, [
      el('summary', {}, [`${title} (${eps.length} episodes)`, seasonBtn]),
      list,
    ]));
  }

  const markAllBtn = el('button', {
    class: 'button button-small',
    text: 'Mark All Episodes Watched',
    onclick: async () => {
      markAllBtn.disabled = true;
      try {
        await apiPost('api/watch.php', { show_id: showId, all: true });
        for (const setWatched of allToggles) setWatched(true);
      } catch (err) {
        alert(err.message);
      }
      markAllBtn.disabled = false;
    },
  });

  const unmarkAllBtn = el('button', {
    class: 'button button-small button-danger',
    text: 'Mark All Unwatched',
    onclick: async () => {
      if (!confirm('Remove your watched history for this whole show?')) return;
      unmarkAllBtn.disabled = true;
      try {
        await apiPost('api/watch.php', { show_id: showId, all: true, watched: false });
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
    text: 'Refresh episodes',
    onclick: async () => {
      refreshBtn.disabled = true;
      refreshBtn.textContent = 'Refreshing…';
      try {
        await apiPost('api/episodes.php', { show_id: showId });
        location.reload();
      } catch (err) {
        alert(err.message);
        refreshBtn.textContent = 'Refresh episodes';
        refreshBtn.disabled = false;
      }
    },
  });

  root.append(
    el('div', { class: 'episodes-header' }, [
      el('h2', { text: 'Episodes' }),
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
