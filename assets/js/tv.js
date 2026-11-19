// TV view (tv.php). The page works without this file (marking watched is a
// plain form POST); it keeps the page in place while marking.

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
      const body = new FormData(form);
      let res = await fetch(form.action, { method: 'POST', body });
      let html = await res.text();
      // A page left open outlives its session, and its form token with it:
      // the server then answers with a fresh page saying so — post once more
      // with that page's token.
      const token = res.url.includes('expired=')
        && new DOMParser().parseFromString(html, 'text/html').querySelector('input[name="csrf_token"]')?.value;
      if (token) {
        body.set('csrf_token', token);
        res = await fetch(form.action, { method: 'POST', body });
        html = await res.text();
      }
      if (res.ok && swapTv(html)) return;
    } catch {
      // network hiccup: fall through to the plain post
    }
    form.submit();
  });
}

initTvMark();
