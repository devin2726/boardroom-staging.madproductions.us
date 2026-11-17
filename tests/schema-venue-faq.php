<?php
/**
 * 2.5.83: venue JSON-LD (image, sameAs, hours) and FAQPage from visible Q&A only.
 * Run inside WordPress: wp eval-file tests/schema-venue-faq.php
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
function brb_t($cond, $msg) {
    if ($cond) { $GLOBALS['brb_ok']++; echo "ok  $msg\n"; }
    else { $GLOBALS['brb_fail']++; echo "FAIL $msg\n"; }
}
function brb_sv_head_for($query) {
    global $wp_query, $wp_the_query;
    $wp_query = $query;
    $wp_the_query = $query;
    ob_start();
    do_action('wp_head');
    return (string) ob_get_clean();
}
function brb_sv_blocks($html) {
    $out = ['raw' => [], 'data' => [], 'valid' => true];
    preg_match_all('#<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $html, $found);
    foreach ($found[1] as $raw) {
        $data = json_decode($raw, true);
        if (!is_array($data)) $out['valid'] = false;
        $out['raw'][] = $raw;
        $out['data'][] = is_array($data) ? $data : null;
    }
    if (!$found[1]) $out['valid'] = false;
    return $out;
}
function brb_sv_type($blocks, $type) {
    $hits = [];
    foreach ($blocks['data'] as $d) if (is_array($d) && ($d['@type'] ?? '') === $type) $hits[] = $d;
    return $hits;
}
function brb_sv_plugin_raw($blocks) {
    $raw = '';
    foreach ($blocks['data'] as $i => $d) {
        if (is_array($d) && in_array($d['@type'] ?? '', ['EventVenue', 'FAQPage'], true)) $raw .= $blocks['raw'][$i];
    }
    return $raw;
}
function brb_sv_schema_pairs($faq) {
    $pairs = [];
    foreach ((array) ($faq['mainEntity'] ?? []) as $e) {
        $pairs[] = ['question' => (string) ($e['name'] ?? ''), 'answer' => (string) ($e['acceptedAnswer']['text'] ?? '')];
    }
    return $pairs;
}
$other = 'air' . 'bnb';
global $wp_query, $wp_the_query;
$saved_query = $wp_query;
$saved_the = $wp_the_query;
$made = [];

try {
    brb_t(BRB_VER === '2.5.83', 'BRB_VER is 2.5.83');

    // ---- venue schema on the home page
    $front_id = (int) get_option('page_on_front');
    $home = brb_sv_blocks(brb_sv_head_for($front_id ? new WP_Query(['page_id' => $front_id]) : new WP_Query(['post_type' => 'page', 'posts_per_page' => 1])));
    $venues = brb_sv_type($home, 'EventVenue');
    $venue = $venues[0] ?? [];
    $raw = brb_sv_plugin_raw($home);
    brb_t($home['valid'] && count($venues) === 1, 'every JSON-LD block is valid JSON and there is one EventVenue');
    brb_t(($venue['name'] ?? '') === 'The Boardroom' && ($venue['legalName'] ?? '') === 'Boardroom QC LLC', 'venue keeps The Boardroom name and legalName Boardroom QC LLC');
    brb_t(($venue['address']['streetAddress'] ?? '') === '2220 37th Street' && ($venue['address']['postalCode'] ?? '') === '61265', 'venue address stays 2220 37th Street, Moline 61265');
    brb_t(($venue['maximumAttendeeCapacity'] ?? null) === 80, 'venue capacity is 80');
    $ohs = $venue['openingHoursSpecification'][0] ?? [];
    brb_t(($ohs['opens'] ?? '') === '10:00' && ($ohs['closes'] ?? '') === '23:30' && ($venue['openingHours'] ?? '') === 'Mo-Su 10:00-23:30', 'venue hours are 10:00 to 23:30 every day');
    brb_t(stripos($raw, 'price') === false && !preg_match('/\$\s*\d/', $raw) && stripos($raw, $other) === false, 'plugin schema has no price, priceRange, dollar amount, or ' . $other);
    brb_t(!array_key_exists('telephone', $venue), 'venue has no telephone (the site shows none)');
    $img = $venue['image'] ?? [];
    $img_file = get_theme_root() . '/boardroom/assets/img/IMG_0223.jpeg';
    brb_t(($img['@type'] ?? '') === 'ImageObject' && substr((string) ($img['url'] ?? ''), -strlen('/wp-content/themes/boardroom/assets/img/IMG_0223.jpeg')) === '/wp-content/themes/boardroom/assets/img/IMG_0223.jpeg' && file_exists($img_file), 'venue image is the home page event-space photo, and the file exists');
    $size = file_exists($img_file) ? getimagesize($img_file) : [0, 0];
    brb_t(($img['width'] ?? 0) === $size[0] && ($img['height'] ?? 0) === $size[1] && ($img['caption'] ?? '') !== '' && ($img['name'] ?? '') === ($img['caption'] ?? ''), 'venue image width and height match the file and it has a caption');
    brb_t(($venue['sameAs'] ?? null) === [BRB_MAPS_URL], 'sameAs lists only the Google Maps link the site already shows');
    $home_faq = brb_sv_type($home, 'FAQPage');
    brb_t($home_faq === [], 'home has no FAQPage');

    // ---- /faq/: FAQPage equals the visible Q&A minus dollar answers
    $faq = brb_sv_blocks(brb_sv_head_for(new WP_Query(['pagename' => 'faq'])));
    $faq_pages = brb_sv_type($faq, 'FAQPage');
    $visible = array_values(array_filter(brb_faq_qa_pairs(brb_faq_rendered_html()), function ($p) { return !brb_faq_answer_has_dollar_amount($p['answer']); }));
    brb_t($faq['valid'] && count($faq_pages) === 1 && $visible !== [] && brb_sv_schema_pairs($faq_pages[0]) === $visible, 'FAQ page FAQPage matches its visible questions and answers exactly');

    // ---- a post without visible Q&A gets no FAQPage
    $plain_id = wp_insert_post(['post_title' => 'Schema test plain', 'post_name' => 'brb-schema-test-plain', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<p>Up to 80 guests. See the FAQ page for answers.</p>']);
    $made[] = $plain_id;
    $plain = brb_sv_blocks(brb_sv_head_for(new WP_Query(['p' => $plain_id])));
    brb_t($plain['valid'] && brb_sv_type($plain, 'FAQPage') === [] && count(brb_sv_type($plain, 'EventVenue')) === 1, 'a post with no visible Q&A gets no FAQPage, and still one EventVenue');

    // ---- a post with visible <details> Q&A gets FAQPage built from that text
    $qa_content = '<p>Intro.</p><details><summary>How many guests fit?</summary><p>Up to 80 guests.</p></details>'
        . '<details><summary>When must everyone be out?</summary><p>Everyone is out by 11:30 PM.</p></details>'
        . '<details><summary>What insurance is needed?</summary><p>$1,000,000 per occurrence.</p></details>';
    $qa_id = wp_insert_post(['post_title' => 'Schema test QA', 'post_name' => 'brb-schema-test-qa', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => $qa_content]);
    $made[] = $qa_id;
    $qa = brb_sv_blocks(brb_sv_head_for(new WP_Query(['p' => $qa_id])));
    $qa_faq = brb_sv_type($qa, 'FAQPage');
    $qa_visible = brb_faq_qa_pairs(apply_filters('the_content', $qa_content));
    $qa_schema = $qa_faq ? brb_sv_schema_pairs($qa_faq[0]) : [];
    $all_visible = true;
    foreach ($qa_schema as $pair) if (!in_array($pair, $qa_visible, true)) $all_visible = false;
    brb_t($qa['valid'] && count($qa_faq) === 1 && count($qa_schema) === 2 && $all_visible, 'a post with visible Q&A gets one FAQPage whose entries match the visible text');
    brb_t(strpos((string) wp_json_encode($qa_faq), '$') === false, 'FAQPage on posts leaves out the dollar answer');

    // ---- hidden Q&A (password-protected) does not get FAQPage
    $locked_id = wp_insert_post(['post_title' => 'Schema test locked', 'post_name' => 'brb-schema-test-locked', 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => 'x', 'post_content' => $qa_content]);
    $made[] = $locked_id;
    $locked = brb_sv_blocks(brb_sv_head_for(new WP_Query(['p' => $locked_id])));
    brb_t(brb_sv_type($locked, 'FAQPage') === [], 'a password-protected post with Q&A gets no FAQPage');
} finally {
    foreach ($made as $id) if ($id) wp_delete_post($id, true);
    $wp_query = $saved_query;
    $wp_the_query = $saved_the;
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
