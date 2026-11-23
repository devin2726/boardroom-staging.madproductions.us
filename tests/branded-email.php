<?php
/**
 * Run inside WordPress: wp eval-file tests/branded-email.php
 * 2.5.88: the receipt label says "Payment Date", and guest booking emails get a branded HTML copy
 * (plain-text part unchanged). Throwaway bookings only (brandedemail-*@example.com); no real email is sent.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}
$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
if (!function_exists('brb_t')) {
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
function brb_be_env($name, $value) {
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        return;
    }
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
global $wpdb;
$snapshot = get_option('brb_settings');
$created_ids = [];
$GLOBALS['brb_mails'] = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $atts['brand_ctx'] = $GLOBALS['brb_email_brand_ctx'] ?? null;
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 5, 2);

try {
    $set = brb_opt();
    $set['deposit_type'] = 'percent';
    $set['deposit_pct'] = '50';
    $set['card_fee_percent'] = '3';
    $set['damage_deposit_amount'] = '0';
    update_option('brb_settings', $set);
    brb_be_env('BRB_STRIPE_SECRET_KEY', 'sk_test_ZZZexamplekey');
    $book = function ($email, $offset, $price, $mode = '') {
        $day = brb_now()->modify('+' . (int) $offset . ' days');
        while ($day->format('N') !== '6') $day = $day->modify('+1 day');
        $r = brb_build_range(['date' => $day->format('Y-m-d'), 'type' => 'day', 'days' => 1, 'guests' => 20], ['override_notice' => true]);
        if (is_wp_error($r)) return $r;
        return brb_insert_booking($r, brb_agreed_quote($price), [
            'name' => 'Sample Guest',
            'email' => $email,
            'phone' => '555-0199',
            'notes' => 'Throwaway test booking.',
            'event_about' => 'Throwaway test booking.',
            'charge_now_mode' => $mode,
            'charge_now_value' => 0,
        ], 'phone', 'staff');
    };
    $full = $book('brandedemail-full@example.com', 620, 1050, 'full');
    $dep = $book('brandedemail-deposit@example.com', 650, 1050, '');
    if (is_wp_error($full) || !$full || is_wp_error($dep) || !$dep) throw new RuntimeException('throwaway bookings were not created');
    $created_ids[] = (int) $full->id;
    $created_ids[] = (int) $dep->id;
    foreach ([$full, $dep] as $x) $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $x->id]);
    $full = brb_approve_booking($full->id, false);
    $dep = brb_approve_booking($dep->id, false);
    $full = brb_recalc_booking($full->id);
    $dep = brb_recalc_booking($dep->id);

    // ---- 1. Payment Date label
    $block = brb_receipt_block($full, 1050, 'check', '2026-11-23');
    brb_t(strpos($block, "Amount: \$1,050.00\nPayment Date: November 23, 2026\nMethod: Check\n") === 0, 'the receipt block reads Amount $1,050.00 then Payment Date: November 23, 2026');
    brb_t(strpos($block, "\nDate: ") === false && strpos($block, 'Event date: ') !== false, 'the receipt block has no bare "Date:" line and still has "Event date:"');
    $preview = brb_preview_payment_message($dep, 525, '1001', '2026-11-23', '', 'check');
    brb_t(!is_wp_error($preview) && strpos($preview['body'], 'Payment Date: November 23, 2026') !== false && strpos($preview['body'], "\nDate: ") === false, 'the receipt preview body uses Payment Date');

    // ---- 2. Pay-link email: plain part unchanged, branded HTML added
    $GLOBALS['brb_mails'] = [];
    $sent = brb_send_payment_link($full->id);
    $m = $GLOBALS['brb_mails'][0] ?? null;
    brb_t(!is_wp_error($sent) && $m && stripos((string) $m['to'], 'brandedemail-full@example.com') !== false, 'the pay-link email goes to the guest');
    $plain = (string) ($m['message'] ?? '');
    brb_t(strpos($plain, "Thank you for contacting The Boardroom!\n\nHere is a link to pay for " . brb_ref($full) . ".\n\nRental, paid in full: \$1,050.00\nCard processing fee (3%): \$31.50\nTotal charged to your card: \$1,081.50\n\nPay online: ") === 0, 'the plain-text pay-link wording is exactly as before');
    brb_t(strpos($plain, '<') === false && is_array($m['brand_ctx'] ?? null) && ($m['brand_ctx']['kind'] ?? '') === 'paylink', 'wp_mail still gets plain text; the branded copy is added later, inside wp_mail');
    $html = brb_email_html($plain, (string) $m['subject'], (array) $m['brand_ctx']);
    brb_t(strpos($html, 'max-width:600px') !== false && strpos($html, '<table role="presentation"') !== false && strpos($html, 'boardroom-logo-header-2x.png') !== false && strpos($html, 'alt="The Boardroom QC"') !== false, 'the HTML is a 600px table layout with the site logo');
    brb_t(strpos($html, '#c4912e') !== false && strpos($html, '#161616') !== false && strpos($html, 'EB Garamond') !== false && strpos($html, 'Georgia') !== false, 'the HTML uses the theme gold, ink and EB Garamond with Georgia fallback');
    brb_t(strpos($html, '>Pay now<') !== false && strpos($html, 'br_pay=' . $full->token) !== false && substr_count($html, 'br_sig=') >= 2 && strpos($html, 'Or open this link') !== false, 'the HTML has a Pay now button with the signed link, and "Or open this link" as the fallback (the raw URL is the href, not shown as text)');
    brb_t(strpos($html, "brb-date") !== false && strpos($html, 'white-space:nowrap') !== false && strpos($html, brb_when($full)) !== false, 'the event date on the summary card does not wrap mid-token');
    brb_t(strpos($html, '$1,050.00') !== false && strpos($html, '$31.50') !== false && strpos($html, '$1,081.50') !== false && strpos($html, 'Booking reference') !== false && strpos($html, 'Event date') !== false && strpos($html, brb_ref($full)) !== false, 'the pay-in-full summary card shows $1,050.00 + $31.50 = $1,081.50 with the reference and event date');
    brb_t(strpos($html, 'Boardroom QC LLC') !== false && strpos($html, home_url('/')) !== false, 'the footer names Boardroom QC LLC and links the site');
    $dep_body = brb_payment_link_body($dep);
    $dep_html = brb_email_html(brb_guest_hello_line() . "\n\n" . $dep_body, 'x', ['kind' => 'paylink', 'card_rows' => [['Booking reference', brb_ref($dep)], ['Event date', brb_when($dep)]]]);
    brb_t(strpos($dep_html, '$525.00') !== false && strpos($dep_html, '$15.75') !== false && strpos($dep_html, '$540.75') !== false, 'the deposit pay-link summary card shows $525.00 + $15.75 = $540.75');

    // ---- 3. Receipt
    $rbody = $preview['body'];
    $rhtml = brb_email_html($rbody, $preview['subject'], []);
    brb_t(strpos($rhtml, 'Payment Date') !== false && strpos($rhtml, 'November 23, 2026') !== false && strpos($rhtml, 'Balance remaining') !== false && strpos($rhtml, 'Here is your receipt.') !== false, 'the receipt HTML shows the Amount / Payment Date card and keeps the words');
    $approve_plain = brb_approve_guest_body($dep);
    $ah = brb_email_html($approve_plain, brb_approve_guest_subject($dep), brb_email_guest_brand('approve', $dep));
    $pay_line = brb_pay_online_line($dep);
    brb_t($pay_line !== '' && strpos($approve_plain, $pay_line) !== false && strpos($approve_plain, "\nPay online: ") !== false, 'the approval plain text still has Pay online and the fee sentence on one line');
    brb_t(strpos($ah, '>Pay now<') !== false && strpos($ah, 'Or open this link') !== false && strpos($ah, 'The card charge is') !== false && strpos($ah, 'processing fee') !== false, 'the approval HTML shows a Pay now button plus the fee sentence, not a wrapping raw URL');
    brb_t(strpos($ah, esc_html($pay_line)) === false, 'the approval HTML does not print the whole Pay online line as one wrapping string');
    foreach (['Balance due', 'When'] as $label) {
        if (!preg_match('/' . preg_quote($label, '/') . '<\/td><td class="brb-row-v brb-date"[^>]*white-space:nowrap;/', $ah)) {
            brb_t(false, 'the approval HTML keeps the ' . $label . ' value on one line at phone width, like Event date and Payment Date');
        } else {
            brb_t(true, 'the approval HTML keeps the ' . $label . ' value on one line at phone width, like Event date and Payment Date');
        }
    }
    $draft = brb_sd_draft($full->id, 'paylink');
    brb_t(!is_wp_error($draft) && $draft['body'] === (brb_guest_hello_line() . "\n\n" . brb_payment_link_body($full)) && strpos((string) $draft['html'], '>Pay now<') !== false && strpos((string) $draft['html'], 'Boardroom QC LLC') !== false, 'the staff confirm draft keeps the plain-text body and includes the branded HTML for the preview');
    $bal_fn_ok = function_exists('brb_sd_balance_reminder');
    brb_t($bal_fn_ok && is_array(brb_email_guest_brand('balance', $dep)), 'the balance reminder uses the same brand helper; its send gate stays off until Max approves the wording');

    // ---- 4. Which mails get the wrapper
    $GLOBALS['brb_mails'] = [];
    brb_send_approve_email($dep);
    brb_send_price_email($dep);
    brb_send_cancel_email($dep);
    brb_send_checklist_reminder($dep);
    brb_send_decline_email($dep, '', []);
    $guest_branded = 0;
    $staff_plain = 0;
    $guest_n = 0;
    $staff_n = 0;
    foreach ($GLOBALS['brb_mails'] as $mm) {
        if (stripos((string) $mm['to'], 'brandedemail-deposit@example.com') !== false) {
            $guest_n++;
            if (is_array($mm['brand_ctx'])) $guest_branded++;
        } else {
            $staff_n++;
            if ($mm['brand_ctx'] === null) $staff_plain++;
        }
    }
    brb_t($guest_n === 5 && $guest_branded === 5, 'approval, price, cancel, checklist and decline emails to the guest are all branded');
    brb_t($staff_n > 0 && $staff_plain === $staff_n, 'staff notices stay plain text');
    $GLOBALS['brb_mails'] = [];
    brb_record_payment($dep->id, 525, '1001', brb_now()->format('Y-m-d'), '', true, 'check', ['notify' => true]);
    $guest_receipt = null;
    $staff_receipt = 0;
    foreach ($GLOBALS['brb_mails'] as $mm) {
        if (stripos((string) $mm['to'], 'brandedemail-deposit@example.com') !== false && strpos((string) $mm['message'], 'Payment Date: ') !== false) $guest_receipt = $mm;
        elseif (strpos((string) $mm['message'], 'Payment Date: ') !== false && $mm['brand_ctx'] === null) $staff_receipt++;
    }
    brb_t($guest_receipt && is_array($guest_receipt['brand_ctx']) && $staff_receipt >= 1, 'the receipt to the guest is branded, the staff copy is plain, both say Payment Date');
    $mail_flat = '';
    foreach ($GLOBALS['brb_mails'] as $mm) $mail_flat .= (string) $mm['message'];
    brb_t(strpos($mail_flat, "\nDate: ") === false, 'no receipt email has a bare "Date:" line');

    // ---- 5. Rules: no phone, Airbnb or door codes in any wrapper
    $all = $html . $dep_html . $rhtml;
    $wire = 'air' . 'bnb';
    brb_t(stripos($all, $wire) === false && stripos($all, 'door code') === false && stripos($all, 'lockbox') === false && strpos($all, 'tel:') === false && !preg_match('/\(?\d{3}\)?[-. ]\d{3}[-. ]\d{4}/', $all) && strpos($all, '$250') === false, 'the wrapper has no phone number, Airbnb, door code or $250');
    brb_t(strpos($html, '2212') === false && strpos($dep_html, '2212') === false, 'the pay-link wrapper adds no 2212 address');
    brb_t(strpos($rhtml, 'script') === false && strpos($rhtml, 'javascript:') === false && strpos(brb_email_html('<b>x</b> & "y"', 's', []), '&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;') !== false, 'body text is escaped in the HTML');

    // ---- 6. Real PHPMailer object: multipart/alternative with the plain part, and the one-line revert
    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    $mk = function () use ($plain) {
        $pm = new PHPMailer\PHPMailer\PHPMailer(true);
        $pm->CharSet = 'UTF-8';
        $pm->setFrom('boardroomqc@example.com', 'The Boardroom');
        $pm->addAddress('brandedemail-full@example.com');
        $pm->Subject = 'Your Boardroom payment link';
        $pm->isHTML(false);
        $pm->Body = $plain;
        return $pm;
    };
    $GLOBALS['brb_email_brand_ctx'] = ['kind' => 'paylink'];
    $pm = $mk();
    do_action_ref_array('phpmailer_init', [&$pm]);
    $pm->preSend();
    $mime = $pm->getSentMIMEMessage();
    brb_t(stripos($mime, 'multipart/alternative') !== false && stripos($mime, 'text/plain') !== false && stripos($mime, 'text/html') !== false && $pm->AltBody === $plain, 'the sent message is multipart/alternative with the unchanged plain-text part');
    brb_t(($GLOBALS['brb_email_brand_ctx'] ?? null) === null, 'the brand context is used once');
    add_filter('brb_email_branded', '__return_false');
    $GLOBALS['brb_email_brand_ctx'] = ['kind' => 'paylink'];
    $pm2 = $mk();
    do_action_ref_array('phpmailer_init', [&$pm2]);
    $pm2->preSend();
    brb_t(stripos($pm2->getSentMIMEMessage(), 'text/html') === false && $pm2->Body === $plain, 'add_filter("brb_email_branded", "__return_false") brings back plain-text only');
    remove_filter('brb_email_branded', '__return_false');
    $GLOBALS['brb_email_brand_ctx'] = null;
    $pm3 = $mk();
    do_action_ref_array('phpmailer_init', [&$pm3]);
    brb_t($pm3->Body === $plain, 'an email sent without a brand context (staff, tests) is untouched');
} catch (Throwable $e) {
    brb_t(false, 'branded email tests stopped: ' . $e->getMessage());
} finally {
    brb_be_env('BRB_STRIPE_SECRET_KEY', null);
    if ($created_ids) {
        $in = implode(',', array_map('intval', $created_ids));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        if (function_exists('brb_line_table')) $wpdb->query("DELETE FROM " . brb_line_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}
echo "\n" . $GLOBALS['brb_ok'] . " passed, " . $GLOBALS['brb_fail'] . " failed\n";
exit($GLOBALS['brb_fail'] > 0 ? 1 : 0);
