// tvtakip frontend: TVmaze API calls (browser-side) + calls to our own backend.
const TVMAZE = 'https://api.tvmaze.com';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

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

function showPayload(show) {
  return {
    id: show.id,
    name: show.name,
    image_url: show.image?.medium ?? '',
    status: show.status ?? '',
  };
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
    results.textContent = 'Searching…';
    try {
      const res = await fetch(`${TVMAZE}/search/shows?q=${encodeURIComponent(q)}`);
      const items = await res.json();
      results.replaceChildren();
      if (!items.length) {
        results.textContent = 'No shows found.';
        return;
      }
      for (const { show } of items) {
        results.append(renderSearchCard(show, trackedIds));
      }
    } catch {
      results.textContent = 'Search failed. Please try again.';
    }
  });
}

function renderSearchCard(show, trackedIds) {
  const isTracked = trackedIds.has(show.id);
  const trackBtn = el('button', {
    class: 'button button-small track-btn',
    text: isTracked ? 'Tracking ✓' : 'Track',
    ...(isTracked ? { disabled: '' } : {}),
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('api/track.php', { action: 'track', show: showPayload(show) });
        trackBtn.textContent = 'Tracking ✓';
        trackedIds.add(show.id);
      } catch (err) {
        trackBtn.disabled = false;
        alert(err.message);
      }
    },
  });

  const year = show.premiered ? ` (${show.premiered.slice(0, 4)})` : '';
  return el('div', { class: 'show-card' }, [
    show.image?.medium
      ? el('img', { src: show.image.medium, alt: '' })
      : el('div', { class: 'no-poster', text: 'No image' }),
    el('h3', {}, [el('a', { href: `show.php?id=${show.id}`, text: show.name + year })]),
    trackBtn,
  ]);
}

// ---------- Dashboard: untrack buttons ----------
function initDashboard() {
  document.querySelectorAll('.untrack-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Untrack this show? Your watched history for it will be removed.')) return;
      btn.disabled = true;
      try {
        await apiPost('api/track.php', {
          action: 'untrack',
          show: { id: Number(btn.dataset.showId) },
        });
        btn.closest('.show-card')?.remove();
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
  const showId = Number(root.dataset.showId);
  let isTracked = root.dataset.tracked === '1';

  let show, watched;
  try {
    const [showRes, watchedRes] = await Promise.all([
      fetch(`${TVMAZE}/shows/${showId}?embed=episodes`),
      fetch(`api/watch.php?show_id=${showId}`),
    ]);
    if (!showRes.ok) throw new Error();
    show = await showRes.json();
    watched = new Set((await watchedRes.json()).watched || []);
  } catch {
    root.textContent = 'Could not load this show. Please try again later.';
    return;
  }

  document.title = `${show.name} — tvtakip`;
  const episodes = show._embedded?.episodes ?? [];
  root.replaceChildren();

  const trackBtn = el('button', {
    class: 'button',
    text: isTracked ? 'Untrack' : 'Track this show',
    onclick: async () => {
      trackBtn.disabled = true;
      try {
        await apiPost('api/track.php', {
          action: isTracked ? 'untrack' : 'track',
          show: showPayload(show),
        });
        isTracked = !isTracked;
        trackBtn.textContent = isTracked ? 'Untrack' : 'Track this show';
      } catch (err) {
        alert(err.message);
      }
      trackBtn.disabled = false;
    },
  });

  const summary = el('div', { class: 'show-summary' });
  summary.innerHTML = show.summary ?? ''; // TVmaze returns sanitized-enough HTML (<p>, <b>, <i>)

  root.append(
    el('div', { class: 'show-header' }, [
      show.image?.medium ? el('img', { src: show.image.medium, alt: '' }) : '',
      el('div', {}, [
        el('h1', { text: show.name }),
        el('p', { class: 'muted', text: [show.premiered?.slice(0, 4), show.status, show.network?.name].filter(Boolean).join(' · ') }),
        summary,
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

  const checkboxes = new Map(); // episode id -> checkbox
  const epContainer = el('div', { class: 'seasons' });
  for (const [season, eps] of seasons) {
    const list = el('ul', { class: 'episode-list' });
    for (const ep of eps) {
      const checkbox = el('input', {
        type: 'checkbox',
        ...(watched.has(ep.id) ? { checked: '' } : {}),
        onchange: async () => {
          checkbox.disabled = true;
          try {
            await apiPost('api/watch.php', {
              show_id: showId,
              episode: { id: ep.id, season: ep.season, number: ep.number },
              watched: checkbox.checked,
            });
          } catch (err) {
            checkbox.checked = !checkbox.checked;
            alert(err.message);
          }
          checkbox.disabled = false;
        },
      });
      checkboxes.set(ep.id, checkbox);
      const aired = ep.airdate ? ` — ${ep.airdate}` : '';
      list.append(el('li', {}, [
        el('label', {}, [checkbox, ` S${String(ep.season).padStart(2, '0')}E${String(ep.number).padStart(2, '0')} ${ep.name ?? ''}${aired}`]),
      ]));
    }
    epContainer.append(el('details', { class: 'season', ...(seasons.size === 1 ? { open: '' } : {}) }, [
      el('summary', { text: `Season ${season} (${eps.length} episodes)` }),
      list,
    ]));
  }
  const markAllBtn = el('button', {
    class: 'button button-small',
    text: 'Mark all watched',
    onclick: async () => {
      markAllBtn.disabled = true;
      try {
        await apiPost('api/watch.php', {
          show_id: showId,
          episodes: episodes.map((ep) => ({ id: ep.id, season: ep.season, number: ep.number })),
        });
        for (const cb of checkboxes.values()) cb.checked = true;
      } catch (err) {
        alert(err.message);
      }
      markAllBtn.disabled = false;
    },
  });

  root.append(
    el('div', { class: 'episodes-header' }, [el('h2', { text: 'Episodes' }), markAllBtn]),
    epContainer,
  );
}

initSearch();
initDashboard();
initShowDetail();
