// tvtakip frontend.
// Search queries TVmaze and TMDB and merges results by IMDB id. Episode data
// comes from TMDB (incl. per-episode IMDB ids), falling back to TVmaze for
// shows TMDB doesn't know (their episode IMDB ids stay null and are updated
// on refresh once TMDB has the show). Everything is cached in our own DB
// keyed by IMDB ids: the first browser to open a show pays the API calls.
const TVMAZE = 'https://api.tvmaze.com';
const TMDB = 'https://api.themoviedb.org/3';
const TMDB_IMG = 'https://image.tmdb.org/t/p/w342';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const TMDB_KEY = document.querySelector('meta[name="tmdb-key"]')?.content ?? '';
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

// ---------- TMDB fetch layer ----------
async function tmdb(path, params = {}) {
  const url = new URL(TMDB + path);
  url.searchParams.set('api_key', TMDB_KEY);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url);
  if (!res.ok) throw new Error(`TMDB request failed (${res.status})`);
  return res.json();
}

/** Run fn over items with limited concurrency, preserving order. */
async function pool(items, limit, fn) {
  const results = new Array(items.length);
  let next = 0;
  await Promise.all(
    Array.from({ length: Math.min(limit, items.length) }, async () => {
      while (next < items.length) {
        const i = next++;
        results[i] = await fn(items[i], i);
      }
    }),
  );
  return results;
}

/** Fallback: show + episodes from TVmaze (no episode IMDB ids there). */
async function fetchShowFromTVmaze(imdbId) {
  const res = await fetch(`${TVMAZE}/lookup/shows?imdb=${imdbId}`);
  if (!res.ok) throw new Error('Show not found on TVmaze either.');
  const show = await res.json();
  const epRes = await fetch(`${TVMAZE}/shows/${show.id}/episodes`);
  const eps = epRes.ok ? await epRes.json() : [];
  return {
    show: {
      imdb_id: imdbId,
      name: show.name,
      image_url: show.image?.medium ?? '',
      status: show.status ?? '',
      overview: (show.summary ?? '').replace(/<[^>]+>/g, ''),
      premiered: show.premiered || null,
    },
    episodes: eps.map((ep) => ({
      imdb_id: null,
      season: ep.season,
      number: ep.number,
      name: ep.name ?? '',
      airdate: ep.airdate || null,
    })),
  };
}

/** Full show + episodes (with per-episode IMDB ids) from TMDB, by show IMDB id. */
async function fetchShowFromTMDB(imdbId, onProgress) {
  const found = await tmdb(`/find/${imdbId}`, { external_source: 'imdb_id' });
  const tv = found.tv_results?.[0];
  if (!tv) throw new Error('Show not found on TMDB.');

  const detail = await tmdb(`/tv/${tv.id}`);
  const seasonNumbers = (detail.seasons ?? []).map((s) => s.season_number);
  const seasons = await pool(seasonNumbers, 4, (n) => tmdb(`/tv/${tv.id}/season/${n}`));
  const episodes = seasons.flatMap((s) => s.episodes ?? []);

  let done = 0;
  const withIds = await pool(episodes, 8, async (ep) => {
    let epImdb = null;
    try {
      const ext = await tmdb(`/tv/${tv.id}/season/${ep.season_number}/episode/${ep.episode_number}/external_ids`);
      epImdb = ext.imdb_id || null;
    } catch {
      // Missing external ids for one episode shouldn't sink the whole import.
    }
    onProgress?.(++done, episodes.length);
    return {
      imdb_id: epImdb,
      season: ep.season_number,
      number: ep.episode_number,
      name: ep.name ?? '',
      airdate: ep.air_date || null,
    };
  });

  return {
    show: {
      imdb_id: imdbId,
      name: detail.name,
      image_url: detail.poster_path ? TMDB_IMG + detail.poster_path : '',
      status: detail.status ?? '',
      overview: detail.overview ?? '',
      premiered: detail.first_air_date || null,
    },
    episodes: withIds,
  };
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
/** Search TVmaze and TMDB in parallel; merge results by IMDB id. */
async function searchBoth(q) {
  const [tvmazeRes, tmdbRes] = await Promise.allSettled([
    fetch(`${TVMAZE}/search/shows?q=${encodeURIComponent(q)}`).then((r) => r.json()),
    tmdb('/search/tv', { query: q }),
  ]);

  const byImdb = new Map();
  const noId = [];

  if (tvmazeRes.status === 'fulfilled') {
    for (const { show } of tvmazeRes.value) {
      const item = {
        imdbId: show.externals?.imdb ?? null,
        name: show.name,
        year: show.premiered?.slice(0, 4) ?? null,
        image: show.image?.medium ?? '',
        status: show.status ?? '',
        source: 'TVmaze',
      };
      if (item.imdbId) byImdb.set(item.imdbId, item);
      else noId.push(item);
    }
  }

  if (tmdbRes.status === 'fulfilled') {
    const results = (tmdbRes.value.results ?? []).slice(0, 12);
    // Search results carry no external ids — resolve each one's IMDB id.
    const exts = await pool(results, 8, (r) => tmdb(`/tv/${r.id}/external_ids`).catch(() => null));
    results.forEach((r, i) => {
      const item = {
        imdbId: exts[i]?.imdb_id || null,
        name: r.name,
        year: r.first_air_date?.slice(0, 4) ?? null,
        image: r.poster_path ? TMDB_IMG + r.poster_path : '',
        status: '',
        source: 'TMDB',
      };
      if (item.imdbId) {
        const existing = byImdb.get(item.imdbId);
        if (existing) {
          existing.image = existing.image || item.image;
          existing.source = 'TVmaze + TMDB';
        } else {
          byImdb.set(item.imdbId, item);
        }
      } else {
        // No IMDB id to merge on — drop only if it looks like a duplicate.
        const dup = [...byImdb.values(), ...noId].some(
          (x) => x.name.toLowerCase() === item.name.toLowerCase() && x.year === item.year,
        );
        if (!dup) noId.push(item);
      }
    });
  }

  if (tvmazeRes.status === 'rejected' && tmdbRes.status === 'rejected') {
    throw new Error('Both search providers failed.');
  }
  return [...byImdb.values(), ...noId];
}

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
      const items = await searchBoth(q);
      results.replaceChildren();
      if (!items.length) {
        results.textContent = 'No shows found.';
        return;
      }
      for (const item of items) {
        results.append(renderSearchCard(item, trackedIds));
      }
    } catch {
      results.textContent = 'Search failed. Please try again.';
    }
  });
}

function renderSearchCard(item, trackedIds) {
  const year = item.year ? ` (${item.year})` : '';
  const poster = item.image
    ? el('img', { src: item.image, alt: '' })
    : el('div', { class: 'no-poster', text: 'No image' });
  const source = el('span', { class: 'muted source-tag', text: item.source });

  if (!item.imdbId) {
    return el('div', { class: 'show-card' }, [
      poster,
      el('h3', { text: item.name + year }),
      source,
      el('span', { class: 'muted', text: 'No IMDB id — cannot track' }),
    ]);
  }

  const isTracked = trackedIds.has(item.imdbId);
  const trackBtn = el('button', {
    class: 'button button-small track-btn',
    text: isTracked ? 'Tracking ✓' : 'Track',
    ...(isTracked ? { disabled: '' } : {}),
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('api/track.php', {
          action: 'track',
          show: {
            imdb_id: item.imdbId,
            name: item.name,
            image_url: item.image,
            status: item.status,
          },
        });
        trackBtn.textContent = 'Tracking ✓';
        trackedIds.add(item.imdbId);
      } catch (err) {
        trackBtn.disabled = false;
        alert(err.message);
      }
    },
  });

  return el('div', { class: 'show-card' }, [
    el('a', { href: `show.php?id=${item.imdbId}` }, [poster]),
    el('h3', {}, [el('a', { href: `show.php?id=${item.imdbId}`, text: item.name + year })]),
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
        await apiPost('api/track.php', {
          action: 'untrack',
          show: { imdb_id: btn.dataset.showId },
        });
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

  // TMDB first (has episode IMDB ids); TVmaze as fallback for shows TMDB
  // doesn't know. A later refresh retries TMDB and backfills the ids.
  const importShow = async (statusEl) => {
    let payload;
    try {
      payload = await fetchShowFromTMDB(showId, (done, total) => {
        statusEl.textContent = `Fetching episode IMDB ids… ${done}/${total}`;
      });
    } catch {
      statusEl.textContent = 'Not on TMDB yet — fetching episodes from TVmaze…';
      payload = await fetchShowFromTVmaze(showId);
    }
    if (!payload.show.image_url) {
      // Combine providers for the poster: whoever has one wins.
      try {
        const other = await fetch(`${TVMAZE}/lookup/shows?imdb=${showId}`);
        if (other.ok) payload.show.image_url = (await other.json()).image?.medium ?? '';
      } catch { /* poster stays empty */ }
    }
    await apiPost('api/episodes.php', payload);
  };

  let data;
  try {
    data = await apiGet(`api/episodes.php?show_id=${showId}`);
    // synced_at is only set once a full import completed — a missing or
    // interrupted import (partial cache) triggers a fresh one.
    if (!data.show?.synced_at || !data.episodes.length) {
      status.textContent = 'Fetching episodes (first visit for this show)…';
      await importShow(status);
      data = await apiGet(`api/episodes.php?show_id=${showId}`);
    }
  } catch (err) {
    status.textContent = `Could not load this show: ${err.message}`;
    return;
  }

  const show = data.show;
  const episodes = data.episodes;
  const watched = new Set(data.watched || []);
  document.title = `${show.name} — TVTakip`;
  root.replaceChildren();

  const trackBtn = el('button', {
    class: 'button',
    text: isTracked ? 'Untrack' : 'Track this show',
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('api/track.php', {
          action: isTracked ? 'untrack' : 'track',
          show: { imdb_id: showId, name: show.name, image_url: show.image_url ?? '', status: show.status ?? '' },
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
  for (const [season, eps] of seasons) {
    const seasonToggles = []; // {watched(), setWatched()} per aired episode
    let updateSeasonBtn = null;
    const list = el('ul', { class: 'episode-list' });
    const today = new Date().toISOString().slice(0, 10);
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
    epContainer.append(el('details', { class: 'season', ...(seasons.size === 1 ? { open: '' } : {}) }, [
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

  // Re-import from TMDB to pick up newly aired episodes or backfilled IMDB ids.
  const refreshBtn = el('button', {
    class: 'button button-small button-secondary',
    text: 'Refresh episodes',
    onclick: async () => {
      refreshBtn.disabled = true;
      const original = refreshBtn.textContent;
      try {
        await importShow(refreshBtn);
        location.reload();
      } catch (err) {
        alert(err.message);
        refreshBtn.textContent = original;
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

initSearch();
initDashboard();
initCalendar();
initShowDetail();
