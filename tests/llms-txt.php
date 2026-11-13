<?php
/**
 * /llms.txt is served as plain text and robots.txt and the sitemap still work.
 * Run with wp eval-file. The HTTP checks start PHP's built-in server on a free
 * local port against this WordPress install.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('brb_t')) {
    $GLOBALS['brb_fail'] = 0;
    $GLOBALS['brb_ok'] = 0;
    function brb_t($cond, $msg) {
        if ($cond) {
            $GLOBALS['brb_ok']++;
            echo "ok  $msg\n";
        } else {
            $GLOBALS['brb_fail']++;
            echo "FAIL $msg\n";
        }
    }
}

function brb_llms_test_http($port, $path, $method = 'GET') {
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Host: localhost:8080\r\nConnection: close\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents('http://127.0.0.1:' . (int) $port . $path, false, $ctx);
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
    $status = 0;
    $type = '';
    foreach ((array) $headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        if (stripos($h, 'Content-Type:') === 0) $type = trim(substr($h, 13));
    }
    return ['status' => $status, 'type' => $type, 'body' => is_string($body) ? $body : '', 'headers' => (array) $headers];
}

$server = null;
$router = '';
$saved_permalinks = null;
try {
    $body = brb_llms_txt_body();
    $required = [
        'The Boardroom is a party and event venue in Moline, IL (Quad Cities).',
        'Capacity: up to 80 guests',
        'Address: 2220 37th Street, Moline, IL 61265',
        'Email: boardroomqc@gmail.com',
        'Hours: 10:00-23:30',
        'https://boardroomqc.com/reserve-the-boardroom-quad-cities/',
        'https://boardroomqc.com/faq/',
        'https://boardroomqc.com/contact/',
        'https://boardroomqc.com/gallery/',
    ];
    foreach ($required as $line) brb_t(strpos($body, $line) !== false, 'llms.txt has "' . $line . '"');
    brb_t(stripos($body, 'airbnb') === false && stripos($body, 'overnight') === false && stripos($body, 'lodging') === false, 'llms.txt does not mention Airbnb, overnight stays, or lodging');
    brb_t(!preg_match('/\(?\b\d{3}\)?[\s.\-]*\d{3}[\s.\-]*\d{4}\b|tel:|phone/i', $body), 'llms.txt has no phone number');
    brb_t(!preg_match('/\b(LLC|Inc\.?|Corp\.?|Ltd\.?)\b/', $body) || (preg_match_all('/[A-Z][\w ]+ LLC/', $body, $m) && array_unique($m[0]) === ['Boardroom QC LLC']), 'llms.txt names no legal entity other than Boardroom QC LLC');
    brb_t(strlen($body) < 1500 && substr($body, -1) === "\n" && strpos($body, '<') === false, 'llms.txt is short plain text with no HTML');
    brb_t(brb_is_llms_request('/llms.txt') && brb_is_llms_request('/llms.txt?x=1') && !brb_is_llms_request('/llms.txt/') && !brb_is_llms_request('/robots.txt') && !brb_is_llms_request('/faq/llms.txt') && !brb_is_llms_request('/sitemap_index.xml'), 'only /llms.txt is answered');
    $res = brb_llms_txt_response();
    brb_t($res['status'] === 200 && $res['headers']['Content-Type'] === 'text/plain; charset=utf-8' && $res['body'] === $body, 'the response is 200 text/plain; charset=utf-8');
    brb_t(has_action('parse_request', 'brb_llms_maybe_serve') === 0, 'llms.txt is served at parse_request, before the main query');

    // Real HTTP through WordPress's index.php, like Apache's rewrite does.
    // The live site uses pretty permalinks; robots.txt and the Yoast sitemap
    // only route through rewrite rules, so match that here.
    $saved_permalinks = get_option('permalink_structure');
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    $router = tempnam(sys_get_temp_dir(), 'brb-router') . '.php';
    file_put_contents($router, '<?php $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($p !== "/" && is_file($_SERVER["DOCUMENT_ROOT"] . $p)) return false; $_SERVER["SCRIPT_NAME"] = "/index.php"; $_SERVER["PHP_SELF"] = "/index.php"; $_SERVER["SCRIPT_FILENAME"] = $_SERVER["DOCUMENT_ROOT"] . "/index.php"; require $_SERVER["DOCUMENT_ROOT"] . "/index.php";');
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', rtrim(ABSPATH, '/'), $router];
    $server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        usleep(100000);
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($c) { $up = true; fclose($c); }
    }
    brb_t($up, 'a local web server starts for the HTTP checks');
    if ($up) {
        $get = brb_llms_test_http($port, '/llms.txt');
        brb_t($get['status'] === 200, 'GET /llms.txt returns 200 (got ' . $get['status'] . ')');
        brb_t(strtolower($get['type']) === 'text/plain; charset=utf-8', 'GET /llms.txt is text/plain; charset=utf-8 (got "' . $get['type'] . '")');
        brb_t($get['body'] === $body, 'GET /llms.txt returns the llms.txt text exactly');
        $head = brb_llms_test_http($port, '/llms.txt', 'HEAD');
        brb_t($head['status'] === 200 && strtolower($head['type']) === 'text/plain; charset=utf-8' && $head['body'] === '', 'HEAD /llms.txt returns 200 headers with no body');
        $robots = brb_llms_test_http($port, '/robots.txt');
        brb_t($robots['status'] === 200 && stripos($robots['type'], 'text/plain') === 0 && strpos($robots['body'], 'User-agent:') !== false && strpos($robots['body'], 'llms') === false, 'robots.txt still returns 200 plain text (got ' . $robots['status'] . ' "' . $robots['type'] . '")');
        $site = brb_llms_test_http($port, '/sitemap_index.xml');
        brb_t($site['status'] === 200 && stripos($site['type'], 'xml') !== false && strpos($site['body'], '<sitemapindex') !== false, 'the Yoast sitemap index still returns 200 XML (got ' . $site['status'] . ' "' . $site['type'] . '")');
        $missing = brb_llms_test_http($port, '/llms-full.txt');
        brb_t($missing['status'] === 404, 'other missing .txt paths still 404 (got ' . $missing['status'] . ')');
        $page = brb_llms_test_http($port, '/gallery/');
        brb_t($page['status'] === 200 && stripos($page['type'], 'text/html') === 0 && strpos($page['body'], '<html') !== false, 'a normal page (/gallery/) still renders as HTML (got ' . $page['status'] . ')');
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($router !== '' && is_file($router)) @unlink($router);
    if ($saved_permalinks !== null) {
        update_option('permalink_structure', $saved_permalinks);
        $GLOBALS['wp_rewrite']->init();
        flush_rewrite_rules(false);
    }
    if ($router !== '' && is_file(substr($router, 0, -4))) @unlink(substr($router, 0, -4));
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
