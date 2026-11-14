<?php
/**
 * Balance due one week (7 days) before the event everywhere (2.5.79).
 * The defaults and the FAQ "add if missing" fallback no longer carry the
 * old 48-hour balance wording. The 48-hour minimum booking notice is a
 * separate rule and stays.
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

global $wpdb;
$kept_show = $wpdb->hide_errors();
try {
    brb_t(get_option('brb_version') === '2.5.79', 'plugin storage version is 2.5.79');
    $d = brb_defaults();
    brb_t(isset($d['balance_due_days']) && (string) $d['balance_due_days'] === '7', 'defaults: balance_due_days is 7');
    brb_t(isset($d['min_notice_hours']) && (string) $d['min_notice_hours'] === '48', 'defaults: the separate 48-hour booking notice is unchanged');

    $src = (string) file_get_contents(WP_PLUGIN_DIR . '/boardroom-booking/boardroom-booking.php');
    brb_t(strpos($src, "'When is the balance due?' => 'The remaining balance is due no later than one week before the event.',") !== false, 'FAQ fallback: the balance answer is one week before the event');
    brb_t(strpos($src, "'When is the balance due?' => 'The remaining balance is due the week of the event, no later than 48 hours before.'") === false, 'FAQ fallback: the 48-hour balance answer is gone');

    // The fallback map sits after an early return in brb_refresh_faq(), so it is
    // checked as source text. The FAQ markup itself must agree with it.
    $faq = brb_faq_markup();
    brb_t(strpos($faq, 'no later than one week before') !== false && strpos($faq, 'no later than 48 hours before') === false, 'FAQ markup: balance one week before, no 48-hour balance wording');
    brb_t(strpos($faq, 'at least 48 hours') !== false, 'FAQ markup: the separate 48-hour booking notice stays');
} finally {
    $wpdb->show_errors($kept_show);
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
