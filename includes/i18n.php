<?php
// Internationalization: English (TVTrack) + Turkish (TVTakip).
// Language is kept in a cookie and switched via ?lang=xx (flag links).

function current_lang(): string
{
    $l = $_COOKIE['lang'] ?? 'en';
    return in_array($l, ['en', 'tr'], true) ? $l : 'en';
}

// Handle a language switch before any output, then redirect to the same page
// without the ?lang param (so it doesn't stick in the URL).
if (isset($_GET['lang'])) {
    $to = in_array($_GET['lang'], ['en', 'tr'], true) ? $_GET['lang'] : 'en';
    setcookie('lang', $to, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    $q = $_GET;
    unset($q['lang']);
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $path . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

/** URL to switch to a language, preserving the current page's other params. */
function lang_url(string $lang): string
{
    $q = $_GET;
    $q['lang'] = $lang;
    return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($q);
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
        'meta_description' => "Track your favorite TV series, follow upcoming episodes, and mark what you've watched — a free, no-clutter personal TV episode tracker.",
        'tagline' => 'Track your TV series and never miss an episode.',
        // calendar
        'calendar_title' => 'Calendar',
        'calendar_sub' => "The next unwatched episode of each show you track.",
        'all_caught_up' => '🎉 All caught up!',
        'no_unwatched' => 'No unwatched aired episodes.',
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
        'mark_all_watched' => 'Mark All Episodes Watched', 'mark_all_unwatched' => 'Mark All Unwatched',
        'mark_season_watched' => 'Mark Season Watched', 'mark_season_unwatched' => 'Mark Season Unwatched',
        'mark_ep_watched' => '✅ Mark %s Watched', 'mark_ep_unwatched' => '❌ Mark %s Not Watched',
        'airs_on' => '📅 Airs %s', 'not_aired' => '📅 Not aired yet',
        'specials' => 'Specials', 'season_n' => 'Season %d', 'episodes_count' => '(%d episodes)',
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
        'meta_description' => 'Favori dizilerini takip et, yeni bölümleri kaçırma ve izlediklerini işaretle — ücretsiz, sade bir kişisel dizi takip uygulaması.',
        'tagline' => 'Dizilerini takip et, hiçbir bölümü kaçırma.',
        'calendar_title' => 'Takvim',
        'calendar_sub' => 'Takip ettiğin her dizinin izlemediğin ilk bölümü.',
        'all_caught_up' => '🎉 Her şeyi izledin!',
        'no_unwatched' => 'İzlenmemiş yayınlanmış bölüm yok.',
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
        'search_title' => 'Dizi ara', 'search_placeholder' => 'örn. Breaking Bad', 'search_button' => 'Ara',
        'searching' => 'Aranıyor…', 'no_shows_found' => 'Dizi bulunamadı.', 'search_failed' => 'Arama başarısız: %s',
        'no_imdb' => 'IMDB kimliği yok — takip edilemez', 'track' => 'Takip et', 'tracking' => 'Takipte ✓', 'importing' => 'İçe aktarılıyor…',
        'loading_show' => 'Dizi yükleniyor…',
        'importing_first' => 'Bölümler içe aktarılıyor (bu dizi için ilk ziyaret)…',
        'could_not_load' => 'Dizi yüklenemedi: %s',
        'track_show' => 'Bu diziyi takip et',
        'episodes' => 'Bölümler', 'refresh_episodes' => 'Bölümleri yenile', 'refreshing' => 'Yenileniyor…',
        'mark_all_watched' => 'Tüm Bölümleri İzlendi İşaretle', 'mark_all_unwatched' => 'Tümünü İzlenmedi İşaretle',
        'mark_season_watched' => 'Sezonu İzlendi İşaretle', 'mark_season_unwatched' => 'Sezonu İzlenmedi İşaretle',
        'mark_ep_watched' => '✅ %s İzlendi İşaretle', 'mark_ep_unwatched' => '❌ %s İzlenmedi İşaretle',
        'airs_on' => '📅 Yayın: %s', 'not_aired' => '📅 Henüz yayınlanmadı',
        'specials' => 'Özel Bölümler', 'season_n' => '%d. Sezon', 'episodes_count' => '(%d bölüm)',
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
