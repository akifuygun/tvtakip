<?php
// Global error/exception handling. Required first by auth.php, so it wraps
// every page and API entry point.
//
// - Uncaught throwables and fatal errors are logged via error_log() (visible
//   in the host's error log) and turned into a clean 500: a generic JSON body
//   for /api/ requests, a minimal HTML page otherwise — never a stack trace.
// - Warnings/notices are logged (and shown on local dev) but deliberately NOT
//   promoted to exceptions: this codebase isn't written under strict handling,
//   so promoting them would turn survivable notices into fatal 500s.

declare(strict_types=1);

// Local dev sees full errors; production logs them and shows a generic message.
// Keyed off the SAPI (CLI or the built-in `php -S` dev server), never the
// client-controlled Host header — which drives display_errors.
define('APP_IS_LOCAL', PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server');

ini_set('display_errors', APP_IS_LOCAL ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** True when the current request targets a JSON API endpoint. */
function request_is_api(): bool
{
    return str_contains(strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), '/api/');
}

/** Log a server error and emit a clean 500 (JSON for APIs, HTML for pages). */
function emit_server_error(string $logMessage): void
{
    error_log('[tvtrack] ' . $logMessage);
    if (headers_sent()) {
        return; // output already started mid-render — can only log
    }
    http_response_code(500);
    if (request_is_api()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Server error']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<p style="font-family:system-ui,sans-serif;padding:2rem">'
            . 'Something went wrong. Please try again in a moment.</p>';
    }
}

set_exception_handler(static function (Throwable $e): void {
    emit_server_error(sprintf(
        '%s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if ($err && ($err['type'] & $fatal)) {
        emit_server_error(sprintf(
            'FATAL %d: %s in %s:%d',
            $err['type'],
            $err['message'],
            $err['file'],
            $err['line']
        ));
    }
});
