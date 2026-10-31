// TV view (tv.php). The page works without this file (marking watched is a
// plain form POST, ▶ links open their site); it keeps the page in place while
// marking, shows the video behind ▶ links set to video, and runs the
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

// The ▶ badge of a site set to tv 'video' in WATCH_SITES (data-video): ask the
// server for the video behind the episode page (api/watch_video.php) and show
// its URL instead of opening the page. Delegated, so it survives the .tv-main
// swaps; the badge reads "…" while the server fetches.
function initTvVideo() {
  document.addEventListener('click', async (e) => {
    const link = e.target.closest('.tv-main a[data-video]');
    if (!link) return;
    e.preventDefault();
    if (link.dataset.busy) return;
    link.dataset.busy = '1';
    const label = link.textContent;
    link.textContent = '…';
    let text = document.body.dataset.videoFailed;
    try {
      const url = `/api/watch_video.php?episode_id=${encodeURIComponent(link.dataset.episode)}&site=${encodeURIComponent(link.dataset.video)}`;
      const data = await (await fetch(url, { cache: 'no-store' })).json();
      text = data.text || data.error || text;
    } catch {
      // network hiccup or a non-JSON answer: the generic message
    }
    link.textContent = label;
    delete link.dataset.busy;
    alert(text);
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
initTvVideo();
refreshTv();
