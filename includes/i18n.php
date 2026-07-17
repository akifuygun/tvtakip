<?php
// Internationalization: English (TVTrack) + Turkish (TVTakip).
// Language is kept in a cookie and switched via ?lang=xx (flag links).

function current_lang(): string
{
    // A /tr/ URL prefix wins (crawlable Turkish pages); the cookie otherwise.
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    if ($path === '/tr' || str_starts_with($path, '/tr/')) {
        return 'tr';
    }
    $l = $_COOKIE['lang'] ?? 'en';
    return in_array($l, ['en', 'tr'], true) ? $l : 'en';
}

/** Strip the /tr prefix from a path ('/tr/browse' -> '/browse', '/tr' -> '/'). */
function bare_path(string $path): string
{
    return preg_replace('#^/tr(?=/|$)#', '', $path) ?: '/';
}

/** Language-prefixed variant of a bare public path. */
function lang_path(string $path, ?string $lang = null): string
{
    if (($lang ?? current_lang()) !== 'tr') {
        return $path;
    }
    return $path === '/' ? '/tr/' : '/tr' . $path;
}

/** Public pages exist under both / and /tr/ URLs; app pages don't. */
function is_public_path(string $barePath): bool
{
    return $barePath === '/' || $barePath === '/index.php'
        || preg_match('#^/(browse|upcoming|series/tt\d{6,10}|movie/tt\d{6,10})/?$#', $barePath) === 1;
}

// Handle a language switch before any output, then redirect to the same page
// without the ?lang param (so it doesn't stick in the URL).
/** Query params minus values the URL rewrite injected (they live in the path). */
function lang_switch_params(): array
{
    $q = $_GET;
    if (preg_match('#^(?:/tr)?/(series|movie)/#', strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'))) {
        unset($q['id']); // injected by the /series/ttNNN and /movie/ttNNN rewrites
    }
    return $q;
}

if (isset($_GET['lang'])) {
    $to = in_array($_GET['lang'], ['en', 'tr'], true) ? $_GET['lang'] : 'en';
    setcookie('lang', $to, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    $q = lang_switch_params();
    unset($q['lang']);
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    // Public pages have per-language URLs — switch the /tr prefix too.
    $bare = bare_path($path);
    if (is_public_path($bare)) {
        $path = lang_path($bare === '/index.php' ? '/' : $bare, $to);
    }
    header('Location: ' . $path . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

/** URL to switch to a language, preserving the current page's other params. */
function lang_url(string $lang): string
{
    $q = lang_switch_params();
    $q['lang'] = $lang;
    return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($q);
}

/** Locale-formatted date for public pages (TR: 15.07.2026, EN: Jul 15, 2026). */
function format_date(?string $ymd): string
{
    if (!$ymd) {
        return '';
    }
    $ts = strtotime($ymd);
    return current_lang() === 'tr' ? date('d.m.Y', $ts) : date('M j, Y', $ts);
}

const APP_NAMES = ['en' => 'TVTrack', 'tr' => 'TVTakip'];

function app_name(): string
{
    return APP_NAMES[current_lang()];
}

$GLOBALS['I18N'] = [
    'en' => [
        // chrome / nav
        'welcome' => 'Welcome', 'nav_calendar' => 'Calendar', 'nav_myshows' => 'My Shows',
        'nav_search' => 'Search', 'logout' => 'Logout', 'login' => 'Log in', 'register' => 'Register',
        'change_password' => 'Change password', 'menu' => 'Menu',
        'lang_tr' => 'Türkçe', 'lang_en' => 'English',
        'theme_light' => 'Light mode', 'theme_dark' => 'Dark mode',
        'meta_description' => "Track your favorite TV series, follow upcoming episodes, and mark what you've watched — a free, no-clutter personal TV episode tracker.",
        'tagline' => 'Track your TV series and never miss an episode.',
        // public pages & landing
        'nav_browse' => 'Browse', 'nav_upcoming' => 'Upcoming',
        'features_title' => 'Everything you need to follow your shows',
        'feat_calendar_t' => 'Episode calendar',
        'feat_calendar_d' => "See each show's next unwatched episode the moment you log in.",
        'feat_track_t' => 'Progress tracking',
        'feat_track_d' => 'Mark episodes, seasons or whole shows watched — your history is kept even if you untrack.',
        'feat_search_t' => 'Two-provider search',
        'feat_search_d' => 'TVmaze and TMDB merged by IMDB id, so hard-to-find shows still show up.',
        'feat_imdb_t' => 'IMDB-based',
        'feat_imdb_d' => 'Shows and episodes are keyed by IMDB ids, with direct IMDB links everywhere.',
        'feat_lang_t' => 'English & Turkish',
        'feat_lang_d' => 'A fully bilingual interface — switch languages any time from the footer.',
        'feat_pwa_t' => 'Installable app',
        'feat_pwa_d' => "Add it to your phone's home screen and use it like a native app.",
        'popular_title' => 'Popular shows',
        'browse_all_shows' => 'Browse all shows',
        'see_upcoming' => 'See upcoming episodes',
        'pub_browse_title' => 'Browse TV Shows',
        'pub_browse_sub' => 'Episode guides and air dates for %d TV series.',
        'pub_upcoming_title' => 'Upcoming Episodes',
        'pub_upcoming_sub' => 'Episodes airing in the next %d days.',
        'pub_no_upcoming' => 'No episodes scheduled in the next %d days.',
        'upcoming_sub_mine' => 'Upcoming episodes of the shows you track.',
        'upcoming_none_mine' => 'None of your tracked shows have episodes scheduled soon.',
        'today_label' => 'Today', 'tomorrow_label' => 'Tomorrow',
        'episode_guide' => 'Episode Guide',
        'series_cta' => 'Sign up free to track this show and never miss an episode.',
        'series_not_found' => 'Show not found.',
        'series_meta_suffix' => 'episode guide, seasons and air dates',
        'breadcrumb_home' => 'Home',
        'runtime_min' => '%d min',
        'caught_up_show' => 'Caught up',
        'one_behind' => '1 behind',
        'n_behind' => '%d behind',
        'lb_close' => 'Close',
        'lb_prev' => 'Previous image',
        'lb_next' => 'Next image',
        'filter_by_network' => 'Filter by network',
        'all_networks' => 'All', 'network_others' => 'Others',
        'flt_network' => 'Network', 'flt_genre' => 'Genre', 'flt_status' => 'Status',
        'show_more' => 'Show more',
        // calendar
        'calendar_title' => 'Calendar',
        'calendar_sub' => "The next unwatched episode of each show you track.",
        'all_caught_up' => '🎉 All caught up!',
        'no_unwatched' => 'No unwatched aired episodes.',
        'coming_up' => "Here's what's coming up next on the shows you track.",
        'find_more' => 'Find more shows to track',
        'mark_watched' => '✅ Watched',
        'notice_no_data' => 'No episode data yet for',
        'notice_open_one' => '— open it once to import episodes.',
        'notice_open_many' => '— open each once to import episodes.',
        'welcome_h1' => 'Track your TV series',
        'welcome_p' => "Search for shows, follow them, and keep track of every episode you've watched.",
        'get_started' => 'Get started',
        // my shows
        'myshows_title' => 'My Shows',
        'no_shows_yet' => "You're not tracking any shows yet",
        'search_for_show' => 'Search for a show',
        'group_running' => 'Running', 'group_upcoming' => 'Upcoming', 'group_finished' => 'Cancelled / Ended',
        'untrack' => 'Untrack', 'no_image' => 'No image',
        'airs_today' => 'Airs today', 'day_remaining' => '%d day remaining', 'days_remaining' => '%d days remaining',
        'status_running' => 'Running', 'status_ended' => 'Ended', 'status_canceled' => 'Canceled', 'status_upcoming' => 'Upcoming',
        // movies
        'nav_mymovies' => 'Movies',
        'mymovies_title' => 'My Movies',
        'no_movies_yet' => "You're not tracking any movies yet",
        'movies_empty_hint' => 'Search above to add your first movie.',
        'movie_search_placeholder' => 'e.g. Inception',
        'no_movies_found' => 'No movies found.',
        'add_movie' => 'Add',
        'add_watched' => 'Add as watched',
        'in_list' => 'In list ✓',
        'group_watchlist' => 'To watch',
        'group_watched' => 'Watched',
        'movie_watched_badge' => '✅ Watched',
        'movie_mark_watched' => '✅ Mark watched',
        'movie_mark_unwatched' => '❌ Mark not watched',
        'remove_movie' => 'Remove',
        'remove_movie_confirm' => 'Remove this movie from your list?',
        'movie_not_found' => 'Movie not found.',
        'movie_cta' => 'Sign up free to add this movie to your watchlist.',
        'movie_meta_suffix' => 'overview, release date and details',
        'movie_status_released' => 'Released',
        'movie_release_label' => 'In cinemas: %s',
        'add_to_list' => 'Add to my list',
        'remove_from_list' => 'Remove from my list',
        // search
        'search_title' => 'Search shows', 'search_placeholder' => 'e.g. Breaking Bad', 'search_button' => 'Search',
        'searching' => 'Searching…', 'no_shows_found' => 'No shows found.', 'search_failed' => 'Search failed: %s',
        'no_imdb' => 'No IMDB id — cannot track', 'track' => 'Track', 'tracking' => 'Tracking ✓', 'importing' => 'Importing…',
        // show page
        'loading_show' => 'Loading show…',
        'importing_first' => 'Importing episodes (first visit for this show)…',
        'could_not_load' => 'Could not load this show: %s',
        'track_show' => 'Track this show',
        'episodes' => 'Episodes', 'refresh_episodes' => 'Refresh episodes', 'refreshing' => 'Refreshing…',
        'next_episode' => 'Next episode', 'airing_now' => '🔴 Airing now',
        'in_day' => 'in 1 day', 'in_days' => 'in %d days',
        'unit_d' => 'd', 'unit_h' => 'h', 'unit_m' => 'm', 'unit_s' => 's',
        'mark_all_watched' => 'Mark All Watched', 'mark_all_unwatched' => 'Mark All Unwatched',
        'mark_season_watched' => 'Mark Season Watched', 'mark_season_unwatched' => 'Mark Season Unwatched',
        'mark_ep_watched' => '✅ Mark %s Watched', 'mark_ep_unwatched' => '❌ Mark %s Not Watched',
        'airs_on' => '📅 Airs %s', 'not_aired' => '📅 Not aired yet',
        'specials' => 'Specials', 'season_n' => 'Season %d',
        'episodes_count' => '(%d episodes)', 'episodes_count_one' => '(1 episode)',
        'click_to_add' => 'Click to add',
        'add_image_title' => 'Click to add an image',
        'add_image_prompt' => 'Paste an image URL for this show:',
        'invalid_image_url' => "That URL doesn't load as an image. Check the link (it must point directly to an image file).",
        'remove_poster_confirm' => 'Remove this poster?', 'remove_poster_title' => 'Remove poster (admin)',
        'untrack_confirm' => 'Untrack this show? Your watched history will be kept if you track it again.',
        'unwatch_all_confirm' => 'Remove your watched history for this whole show?',
        // login / register / change password
        'login_title' => 'Log in', 'email' => 'Email', 'password' => 'Password', 'remember_me' => 'Remember me',
        'invalid_login' => 'Invalid email or password.', 'session_expired' => 'Session expired, please try again.',
        'no_account' => 'No account yet?',
        'register_title' => 'Create an account', 'name' => 'Name', 'name_placeholder' => 'Your name',
        'have_account' => 'Already have an account?',
        'err_name' => 'Please enter your name (2–100 characters).',
        'err_email' => 'Please enter a valid email address.',
        'err_password' => 'Password must be at least 8 characters.',
        'err_email_taken' => 'That email is already registered.',
        'cp_title' => 'Change password', 'current_password' => 'Current password',
        'new_password' => 'New password', 'confirm_new_password' => 'Confirm new password',
        'cp_success' => 'Your password has been changed.',
        'err_current_wrong' => 'Current password is incorrect.',
        'err_new_short' => 'New password must be at least 8 characters.',
        'err_no_match' => 'New passwords do not match.',
    ],
    'tr' => [
        'welcome' => 'Hoşgeldin', 'nav_calendar' => 'Takvim', 'nav_myshows' => 'Dizilerim',
        'nav_search' => 'Ara', 'logout' => 'Çıkış', 'login' => 'Giriş yap', 'register' => 'Kayıt ol',
        'change_password' => 'Şifre değiştir', 'menu' => 'Menü',
        'lang_tr' => 'Türkçe', 'lang_en' => 'English',
        'theme_light' => 'Açık tema', 'theme_dark' => 'Koyu tema',
        'meta_description' => 'Favori dizilerini takip et, yeni bölümleri kaçırma ve izlediklerini işaretle — ücretsiz, sade bir kişisel dizi takip uygulaması.',
        'tagline' => 'Dizilerini takip et, hiçbir bölümü kaçırma.',
        'nav_browse' => 'Diziler', 'nav_upcoming' => 'Yaklaşanlar',
        'features_title' => 'Dizilerini takip etmek için gereken her şey',
        'feat_calendar_t' => 'Bölüm takvimi',
        'feat_calendar_d' => 'Giriş yaptığın anda her dizinin izlemediğin ilk bölümünü gör.',
        'feat_track_t' => 'İlerleme takibi',
        'feat_track_d' => 'Bölümleri, sezonları veya tüm diziyi izlendi işaretle — takibi bıraksan bile geçmişin korunur.',
        'feat_search_t' => 'Çift kaynaklı arama',
        'feat_search_d' => 'TVmaze ve TMDB, IMDB kimliğiyle birleştirilir; zor bulunan diziler bile karşına çıkar.',
        'feat_imdb_t' => 'IMDB tabanlı',
        'feat_imdb_d' => 'Diziler ve bölümler IMDB kimlikleriyle tutulur; her yerde doğrudan IMDB bağlantıları vardır.',
        'feat_lang_t' => 'Türkçe & İngilizce',
        'feat_lang_d' => 'Tamamen iki dilli arayüz — alt bilgiden istediğin an dil değiştir.',
        'feat_pwa_t' => 'Kurulabilir uygulama',
        'feat_pwa_d' => 'Telefonunun ana ekranına ekle, yerel uygulama gibi kullan.',
        'popular_title' => 'Popüler diziler',
        'browse_all_shows' => 'Tüm dizilere göz at',
        'see_upcoming' => 'Yaklaşan bölümleri gör',
        'pub_browse_title' => 'Dizilere Göz At',
        'pub_browse_sub' => '%d dizi için bölüm rehberleri ve yayın tarihleri.',
        'pub_upcoming_title' => 'Yaklaşan Bölümler',
        'pub_upcoming_sub' => 'Önümüzdeki %d gün içinde yayınlanacak bölümler.',
        'pub_no_upcoming' => 'Önümüzdeki %d gün içinde planlanmış bölüm yok.',
        'upcoming_sub_mine' => 'Takip ettiğin dizilerin yaklaşan bölümleri.',
        'upcoming_none_mine' => 'Takip ettiğin dizilerin yakında yayınlanacak bölümü yok.',
        'today_label' => 'Bugün', 'tomorrow_label' => 'Yarın',
        'episode_guide' => 'Bölüm Rehberi',
        'series_cta' => 'Bu diziyi takip etmek ve hiçbir bölümü kaçırmamak için ücretsiz kayıt ol.',
        'series_not_found' => 'Dizi bulunamadı.',
        'series_meta_suffix' => 'bölüm rehberi, sezonlar ve yayın tarihleri',
        'breadcrumb_home' => 'Ana sayfa',
        'runtime_min' => '%d dk',
        'caught_up_show' => 'Güncel',
        'one_behind' => '1 geride',
        'n_behind' => '%d geride',
        'lb_close' => 'Kapat',
        'lb_prev' => 'Önceki görsel',
        'lb_next' => 'Sonraki görsel',
        'filter_by_network' => 'Kanala göre filtrele',
        'all_networks' => 'Tümü', 'network_others' => 'Diğer',
        'flt_network' => 'Kanal', 'flt_genre' => 'Tür', 'flt_status' => 'Durum',
        'show_more' => 'Daha fazla göster',
        'calendar_title' => 'Takvim',
        'calendar_sub' => 'Takip ettiğin her dizinin izlemediğin ilk bölümü.',
        'all_caught_up' => '🎉 Her şeyi izledin!',
        'no_unwatched' => 'İzlenmemiş yayınlanmış bölüm yok.',
        'coming_up' => 'Takip ettiğin dizilerde sırada bunlar var.',
        'find_more' => 'Takip edecek dizi bul',
        'mark_watched' => '✅ İzledim',
        'notice_no_data' => 'Şu diziler için henüz bölüm verisi yok:',
        'notice_open_one' => '— bölümleri içe aktarmak için onu bir kez aç.',
        'notice_open_many' => '— bölümleri içe aktarmak için her birini bir kez aç.',
        'welcome_h1' => 'Dizilerini takip et',
        'welcome_p' => 'Dizileri ara, takip et ve izlediğin her bölümü işaretle.',
        'get_started' => 'Başla',
        'myshows_title' => 'Dizilerim',
        'no_shows_yet' => 'Henüz dizi takip etmiyorsun',
        'search_for_show' => 'Dizi ara',
        'group_running' => 'Devam ediyor', 'group_upcoming' => 'Yakında', 'group_finished' => 'İptal / Bitti',
        'untrack' => 'Takibi bırak', 'no_image' => 'Görsel yok',
        'airs_today' => 'Bugün yayında', 'day_remaining' => '%d gün kaldı', 'days_remaining' => '%d gün kaldı',
        'status_running' => 'Devam ediyor', 'status_ended' => 'Bitti', 'status_canceled' => 'İptal edildi', 'status_upcoming' => 'Yakında',
        // movies
        'nav_mymovies' => 'Filmler',
        'mymovies_title' => 'Filmlerim',
        'no_movies_yet' => 'Henüz film eklemedin',
        'movies_empty_hint' => 'İlk filmini eklemek için yukarıdan ara.',
        'movie_search_placeholder' => 'örn. Inception',
        'no_movies_found' => 'Film bulunamadı.',
        'add_movie' => 'Ekle',
        'add_watched' => 'İzlenmiş olarak ekle',
        'in_list' => 'Listede ✓',
        'group_watchlist' => 'İzlenecekler',
        'group_watched' => 'İzlenenler',
        'movie_watched_badge' => '✅ İzlendi',
        'movie_mark_watched' => '✅ İzlendi işaretle',
        'movie_mark_unwatched' => '❌ İzlenmedi işaretle',
        'remove_movie' => 'Kaldır',
        'remove_movie_confirm' => 'Bu film listenden kaldırılsın mı?',
        'movie_not_found' => 'Film bulunamadı.',
        'movie_cta' => 'Bu filmi izleme listene eklemek için ücretsiz kayıt ol.',
        'movie_meta_suffix' => 'özet, vizyon tarihi ve detaylar',
        'movie_status_released' => 'Yayınlandı',
        'movie_release_label' => 'Vizyon tarihi: %s',
        'add_to_list' => 'Listeme ekle',
        'remove_from_list' => 'Listemden kaldır',
        'search_title' => 'Dizi ara', 'search_placeholder' => 'örn. Breaking Bad', 'search_button' => 'Ara',
        'searching' => 'Aranıyor…', 'no_shows_found' => 'Dizi bulunamadı.', 'search_failed' => 'Arama başarısız: %s',
        'no_imdb' => 'IMDB kimliği yok — takip edilemez', 'track' => 'Takip et', 'tracking' => 'Takipte ✓', 'importing' => 'İçe aktarılıyor…',
        'loading_show' => 'Dizi yükleniyor…',
        'importing_first' => 'Bölümler içe aktarılıyor (bu dizi için ilk ziyaret)…',
        'could_not_load' => 'Dizi yüklenemedi: %s',
        'track_show' => 'Bu diziyi takip et',
        'episodes' => 'Bölümler', 'refresh_episodes' => 'Bölümleri yenile', 'refreshing' => 'Yenileniyor…',
        'next_episode' => 'Sonraki bölüm', 'airing_now' => '🔴 Şimdi yayında',
        'in_day' => '1 gün içinde', 'in_days' => '%d gün içinde',
        'unit_d' => 'g', 'unit_h' => 'sa', 'unit_m' => 'dk', 'unit_s' => 'sn',
        'mark_all_watched' => 'Tümünü İzlendi İşaretle', 'mark_all_unwatched' => 'Tümünü İzlenmedi İşaretle',
        'mark_season_watched' => 'Sezonu İzlendi İşaretle', 'mark_season_unwatched' => 'Sezonu İzlenmedi İşaretle',
        'mark_ep_watched' => '✅ %s İzlendi İşaretle', 'mark_ep_unwatched' => '❌ %s İzlenmedi İşaretle',
        'airs_on' => '📅 Yayın: %s', 'not_aired' => '📅 Henüz yayınlanmadı',
        'specials' => 'Özel Bölümler', 'season_n' => '%d. Sezon',
        'episodes_count' => '(%d bölüm)', 'episodes_count_one' => '(1 bölüm)',
        'click_to_add' => 'Eklemek için tıkla',
        'add_image_title' => 'Görsel eklemek için tıkla',
        'add_image_prompt' => "Bu dizi için bir görsel URL'si yapıştır:",
        'invalid_image_url' => "Bu URL bir görsel olarak yüklenmiyor. Bağlantıyı kontrol et (doğrudan bir görsel dosyasına işaret etmeli).",
        'remove_poster_confirm' => 'Bu görsel kaldırılsın mı?', 'remove_poster_title' => 'Görseli kaldır (yönetici)',
        'untrack_confirm' => 'Bu dizinin takibi bırakılsın mı? Tekrar takip edersen izleme geçmişin korunur.',
        'unwatch_all_confirm' => 'Bu dizinin tüm izleme geçmişi silinsin mi?',
        'login_title' => 'Giriş yap', 'email' => 'E-posta', 'password' => 'Şifre', 'remember_me' => 'Beni hatırla',
        'invalid_login' => 'Geçersiz e-posta veya şifre.', 'session_expired' => 'Oturum süresi doldu, lütfen tekrar deneyin.',
        'no_account' => 'Hesabın yok mu?',
        'register_title' => 'Hesap oluştur', 'name' => 'İsim', 'name_placeholder' => 'Adınız',
        'have_account' => 'Zaten hesabın var mı?',
        'err_name' => 'Lütfen adını gir (2–100 karakter).',
        'err_email' => 'Lütfen geçerli bir e-posta adresi gir.',
        'err_password' => 'Şifre en az 8 karakter olmalı.',
        'err_email_taken' => 'Bu e-posta zaten kayıtlı.',
        'cp_title' => 'Şifre değiştir', 'current_password' => 'Mevcut şifre',
        'new_password' => 'Yeni şifre', 'confirm_new_password' => 'Yeni şifre (tekrar)',
        'cp_success' => 'Şifren değiştirildi.',
        'err_current_wrong' => 'Mevcut şifre yanlış.',
        'err_new_short' => 'Yeni şifre en az 8 karakter olmalı.',
        'err_no_match' => 'Yeni şifreler eşleşmiyor.',
    ],
];

/** Translate a key for the current language, with optional sprintf args. */
function t(string $key, ...$args): string
{
    $lang = current_lang();
    $s = $GLOBALS['I18N'][$lang][$key] ?? $GLOBALS['I18N']['en'][$key] ?? $key;
    return $args ? vsprintf($s, $args) : $s;
}

/** JSON dictionary + metadata for the frontend (current language over English). */
function i18n_js(): string
{
    $lang = current_lang();
    $dict = array_merge($GLOBALS['I18N']['en'], $GLOBALS['I18N'][$lang]);
    return json_encode(['lang' => $lang, 'app' => app_name(), 't' => $dict], JSON_UNESCAPED_UNICODE);
}
