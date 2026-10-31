// TV view (tv.php). The page works without this file (marking watched is a
// plain form POST); it keeps the page in place while marking and runs the
// calendar's background refresh.

// Same timezone report as app.js, so "today" follows the TV's clock.
try {
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz && !document.cookie.includes(`tz=${encodeURIComponent(tz)}`)) {
    document.cookie = `tz=${encodeURIComponent(tz)}; path=/; max-age=31536000; SameSite=Lax`;
  }
} catch { /* keep server default */ }

// Swap in a fresh server render of the cards. False when `html` isn't the TV
// page (say, the login page after a lost session).
function swapTv(html) {
  const next = new DOMParser().parseFromString(html, 'text/html').querySelector('.tv-main');
  const main = document.querySelector('.tv-main');
  if (!main || !next) return false;
  main.replaceWith(next);
  return true;
}

// Mark Watched without a page load: post the form and swap in the list the
// server redirects to, so the page — and the remote's pointer on it — stays
// where it was. Anything unexpected falls back to the plain form post.
function initTvMark() {
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!form.closest('.tv-main')) return;
    e.preventDefault();
    form.querySelector('button').disabled = true;
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form) });
      if (res.ok && swapTv(await res.text())) return;
    } catch {
      // network hiccup: fall through to the plain post
    }
    form.submit();
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
      if (res.changed) {
        const page = await fetch('/tv', { cache: 'no-store' });
        if (page.ok) swapTv(await page.text());
      }
      if (!res.more) break;
    }
  } catch {
    // best-effort: the page stays as first rendered
  }
}

initTvMark();
refreshTv();
