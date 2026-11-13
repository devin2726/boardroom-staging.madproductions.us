<?php
/**
 * /llms.txt: a short plain-text summary of The Boardroom for AI assistants
 * and crawlers. WordPress has no file at that path, so Apache sends the
 * request to index.php and we answer it at parse_request, before the main
 * query runs (so no 404, no page-view tracking, no canonical redirect).
 * robots.txt and the Yoast sitemaps are not touched.
 */
if (!defined('ABSPATH')) exit;

function brb_llms_txt_body() {
    $site = 'https://boardroomqc.com';
    $lines = [
        '# The Boardroom',
        '',
        '> The Boardroom is a party and event venue in Moline, IL (Quad Cities).',
        '',
        '- Capacity: up to 80 guests',
        '- Address: 2220 37th Street, Moline, IL 61265',
        '- Email: boardroomqc@gmail.com',
        '- Hours: 10:00-23:30, one full-day booking (America/Chicago)',
        '',
        '## Links',
        '',
        '- [Book a date](' . $site . '/reserve-the-boardroom-quad-cities/): pick a date and see the price on the calendar',
        '- [FAQ](' . $site . '/faq/): booking, hours, food, BYOB, capacity, and cancellation',
        '- [Contact](' . $site . '/contact/): questions for the team',
        '- [Gallery](' . $site . '/gallery/): photos of the space',
    ];
    return implode("\n", $lines) . "\n";
}

function brb_llms_request_path($uri = null) {
    if ($uri === null) $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) parse_url((string) $uri, PHP_URL_PATH);
    // Allow WordPress in a subdirectory: strip the home path first.
    $base = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base));
    return $path;
}

function brb_is_llms_request($uri = null) {
    return brb_llms_request_path($uri) === '/llms.txt';
}

function brb_llms_txt_response() {
    $headers = [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
        'X-Content-Type-Options' => 'nosniff',
    ];
    // Staging already blocks every crawler in robots.txt; say so here too.
    if (function_exists('brb_is_production') && !brb_is_production()) $headers['X-Robots-Tag'] = 'noindex, nofollow';
    return ['status' => 200, 'headers' => $headers, 'body' => brb_llms_txt_body()];
}

function brb_llms_maybe_serve() {
    if (!brb_is_llms_request()) return;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') return;
    $res = brb_llms_txt_response();
    status_header($res['status']);
    foreach ($res['headers'] as $name => $value) header($name . ': ' . $value);
    header('Content-Length: ' . strlen($res['body']));
    if ($method !== 'HEAD') echo $res['body'];
    exit;
}
add_action('parse_request', 'brb_llms_maybe_serve', 0);
