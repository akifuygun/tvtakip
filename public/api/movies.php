<?php
// Movies API — one endpoint for the whole (deliberately small) movie surface.
//
// GET  ?q=inception[&page=N]
//      -> {results: [{imdb_id, name, year, image}], more: bool}   (one page of
//         TMDB's search, `more` = it has another; a movie IMDB doesn't list
//         comes with its provisional id — valid_movie_id())
// POST {action: "add"|"remove", imdb_id}
//      -> add imports the movie server-side when not cached, then upserts the
//         user_movies row; remove deletes it (watched state goes with it).
// POST {action: "watch", imdb_id, watched: bool}
//      -> sets the watched flag. Marking watched auto-adds the movie to the
//         list (upsert), and is gated on the release date like episode airing.
require_once __DIR__ . '/../../includes/importer.php';

// GET (search) is public — the guest movies page searches too, and results
// only link to public pages. Mutations below stay login-gated.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) > 100) {
        json_response(['error' => 'Missing or invalid q'], 400);
    }

    $page = max(1, min(TMDB_MAX_PAGE, (int) ($_GET['page'] ?? 1)));

    $search = http_get_json(tmdb_api_url('/search/movie', ['query' => $q, 'page' => $page]));
    if ($search === null) {
        json_response(['error' => 'Movie search failed'], 502);
    }

    // Search results carry no external ids — resolve each one's IMDB id.
    $results = $search['results'] ?? [];
    $extUrls = array_map(fn($r) => tmdb_api_url('/movie/' . $r['id'] . '/external_ids'), $results);
    $exts = http_multi_json($extUrls, TMDB_PAGE_SIZE);

    $out = [];
    foreach ($results as $i => $r) {
        $imdbId = $exts[$i]['imdb_id'] ?? null;
        $out[] = [
            'imdb_id' => valid_imdb_id($imdbId) ? $imdbId : 'tm' . $r['id'],
            'name' => (string) ($r['title'] ?? ''),
            'year' => !empty($r['release_date']) ? substr($r['release_date'], 0, 4) : null,
            'image' => !empty($r['poster_path']) ? TMDB_IMG_BASE . $r['poster_path'] : '',
        ];
    }
    json_response([
        'results' => $out,
        'more' => $page < min(TMDB_MAX_PAGE, (int) ($search['total_pages'] ?? 0)),
    ]);
}

require_login_json();
$data = read_json_post();
$action = $data['action'] ?? '';
$imdbId = $data['imdb_id'] ?? '';
if (!valid_movie_id($imdbId)) {
    json_response(['error' => 'Missing or invalid IMDB id'], 400);
}

if ($action === 'add') {
    $stmt = db()->prepare('SELECT synced_at FROM movies WHERE imdb_id = ?');
    $stmt->execute([$imdbId]);
    $movie = $stmt->fetch();
    if (!$movie || $movie['synced_at'] === null) {
        try {
            $imdbId = import_movie(db(), $imdbId);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 404);
        }
    }
    $stmt = db()->prepare('INSERT IGNORE INTO user_movies (user_id, movie_imdb_id) VALUES (?, ?)');
    $stmt->execute([current_user_id(), $imdbId]);
    json_response(['ok' => true, 'in_list' => true]);
}

if ($action === 'remove') {
    $stmt = db()->prepare('DELETE FROM user_movies WHERE user_id = ? AND movie_imdb_id = ?');
    $stmt->execute([current_user_id(), $imdbId]);
    json_response(['ok' => true, 'in_list' => false]);
}

if ($action === 'watch') {
    $watched = (bool) ($data['watched'] ?? false);
    $stmt = db()->prepare('SELECT released FROM movies WHERE imdb_id = ?');
    $stmt->execute([$imdbId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['error' => 'Unknown movie'], 404);
    }
    // Release-gate marking watched (a null release date stays markable — an
    // announced film with no date must not be stuck forever).
    if ($watched && $row['released'] !== null && $row['released'] > today()) {
        json_response(['error' => 'This movie has not been released yet.'], 400);
    }
    $stmt = db()->prepare(
        'INSERT INTO user_movies (user_id, movie_imdb_id, watched, watched_at)
         VALUES (?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE watched = VALUES(watched), watched_at = VALUES(watched_at)'
    );
    $stmt->execute([current_user_id(), $imdbId, (int) $watched, (int) $watched]);
    json_response(['ok' => true, 'watched' => $watched]);
}

json_response(['error' => 'Unknown action'], 400);
