</main>
<?php $footerTheme = app_theme(); ?>
<footer class="site-footer">
    <span class="footer-copy">❤️ by Akif 07-26</span>
    <span class="footer-controls">
    <span class="footer-themes">
        <button type="button" class="theme-btn<?= $footerTheme === 'light' ? ' theme-active' : '' ?>"
                data-theme-value="light" title="<?= t('theme_light') ?>" aria-label="<?= t('theme_light') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
            </svg>
        </button>
        <button type="button" class="theme-btn<?= $footerTheme === 'dark' ? ' theme-active' : '' ?>"
                data-theme-value="dark" title="<?= t('theme_dark') ?>" aria-label="<?= t('theme_dark') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </span>
    <span class="footer-flags">
        <a href="<?= htmlspecialchars(lang_url('tr')) ?>" title="<?= t('lang_tr') ?>"
           class="flag<?= current_lang() === 'tr' ? ' flag-active' : '' ?>" aria-label="Türkçe">
            <svg viewBox="0 0 30 20" width="26" height="18">
                <rect width="30" height="20" fill="#E30A17"/>
                <circle cx="11.5" cy="10" r="5" fill="#fff"/>
                <circle cx="13" cy="10" r="4" fill="#E30A17"/>
                <polygon fill="#fff" points="17,7.2 17.66,9.09 19.66,9.13 18.07,10.35 18.65,12.27 17,11.12 15.35,12.27 15.93,10.35 14.34,9.13 16.34,9.09"/>
            </svg>
        </a>
        <a href="<?= htmlspecialchars(lang_url('en')) ?>" title="<?= t('lang_en') ?>"
           class="flag<?= current_lang() === 'en' ? ' flag-active' : '' ?>" aria-label="English">
            <svg viewBox="0 0 60 30" width="27" height="18">
                <clipPath id="uk"><rect width="60" height="30"/></clipPath>
                <g clip-path="url(#uk)">
                    <rect width="60" height="30" fill="#012169"/>
                    <path d="M0,0 60,30 M60,0 0,30" stroke="#fff" stroke-width="6"/>
                    <path d="M0,0 60,30 M60,0 0,30" stroke="#C8102E" stroke-width="4"/>
                    <path d="M30,0 V30 M0,15 H60" stroke="#fff" stroke-width="10"/>
                    <path d="M30,0 V30 M0,15 H60" stroke="#C8102E" stroke-width="6"/>
                </g>
            </svg>
        </a>
    </span>
    </span>
</footer>
<script src="/assets/js/app.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
