// TV view (tv.php). The page works without this file (marking watched is a
// plain form POST); it adds remote arrow-key navigation and the calendar's
// background refresh.

// Same timezone report as app.js, so "today" follows the TV's clock.
try {
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz && !document.cookie.includes(`tz=${encodeURIComponent(tz)}`)) {
    document.cookie = `tz=${encodeURIComponent(tz)}; path=/; max-age=31536000; SameSite=Lax`;
  }
} catch { /* keep server default */ }

const TV_TARGETS = '.tv-head a, .tv-main a, .tv-main button';
const TV_KEYS = { ArrowLeft: [-1, 0], ArrowUp: [0, -1], ArrowRight: [1, 0], ArrowDown: [0, 1] };

// Focus a button and bring its whole card into view, poster included (the
// header's button: back to the top).
function tvFocus(el) {
  el.focus({ preventScroll: true });
  const card = el.closest('.tv-card');
  if (card) card.scrollIntoView({ block: 'nearest' });
  else window.scrollTo(0, 0);
}

// The nearest target from `from` in direction [dx, dy], counting only targets
// wholly past its edge on that side: the gap between the facing edges plus a
// penalty for sideways drift (none while the two still overlap side-on), so
// moves keep to the row or column and Down enters a card at its top button.
function tvNeighbor(from, [dx, dy]) {
  const a = from.getBoundingClientRect();
  let best = null;
  let bestScore = Infinity;
  document.querySelectorAll(TV_TARGETS).forEach((el) => {
    const b = el.getBoundingClientRect();
    if (el === from || !b.width) return;
    const gap = dx > 0 ? b.left - a.right : dx < 0 ? a.left - b.right
      : dy > 0 ? b.top - a.bottom : a.top - b.bottom;
    const drift = dx ? Math.max(0, b.top - a.bottom, a.top - b.bottom)
      : Math.max(0, b.left - a.right, a.left - b.right);
    const score = gap + 2 * drift;
    if (gap > -1 && score < bestScore) {
      best = el;
      bestScore = score;
    }
  });
  return best;
}

// Arrow keys jump between buttons. TV browsers that steer an on-screen pointer
// with the arrows may keep the keys to themselves; hover is styled the same.
function initTvNav() {
  document.addEventListener('keydown', (e) => {
    const dir = TV_KEYS[e.key];
    if (!dir) return;
    const cur = document.activeElement;
    const next = cur?.matches(TV_TARGETS) ? tvNeighbor(cur, dir)
      : document.querySelector('.tv-main a, .tv-main button') || document.querySelector(TV_TARGETS);
    if (!next) return; // edge of the page: let the browser scroll
    e.preventDefault();
    tvFocus(next);
  });
  // The server autofocuses a card's first button; show the rest of the card too.
  window.addEventListener('load', () => {
    if (document.activeElement?.matches(TV_TARGETS)) tvFocus(document.activeElement);
  });
}

// Freshness, as on the calendar (app.js refreshCalendar): the server refreshes
// this viewer's stale shows a few per call, so keep calling while some are
// left — capped per visit — and swap in a fresh render whenever one changed.
const TV_REFRESH_ROUNDS = 10;
async function refreshTv() {
  try {
    for (let round = 0; round < TV_REFRESH_ROUNDS; round++) {
      const res = await (await fetch('/api/tick.php?scope=mine')).json();
      if (res.changed) await reloadTv();
      if (!res.more) break;
    }
  } catch {
    // best-effort: the page stays as first rendered
  }
}

// Swap in a fresh server render of the cards, keeping focus on the same show's
// button (or the first card's when that show is done).
async function reloadTv() {
  const res = await fetch('/tv', { cache: 'no-store' });
  if (!res.ok) return;
  const next = new DOMParser().parseFromString(await res.text(), 'text/html').querySelector('.tv-main');
  const main = document.querySelector('.tv-main');
  // Only ever replace the cards with cards (never, say, a login page).
  if (!main || !next) return;
  const active = document.activeElement;
  const card = main.contains(active) ? active.closest('.tv-card') : null;
  const index = card ? [...card.querySelectorAll('a, button')].indexOf(active) : -1;
  main.replaceWith(next);
  if (!card) return;
  const again = next.querySelector(`.tv-card[data-show="${card.dataset.show}"]`);
  const buttons = again ? [...again.querySelectorAll('a, button')] : [];
  const el = buttons[Math.min(index, buttons.length - 1)] || next.querySelector('a, button');
  if (el) tvFocus(el);
}

initTvNav();
refreshTv();
