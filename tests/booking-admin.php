<?php
/**
 * Run inside WordPress: wp eval-file tests/booking-admin.php
 * Booking detail: decline message, money block, date hold, email previews, save, delete, spam, audit.
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

function brb_admin_case($email, $day, $name) {
    return brb_process_public_booking([
        'date' => $day,
        'type' => 'day',
        'days' => 1,
        'guests' => 8,
        'name' => $name,
        'email' => $email,
        'phone' => '555-0100',
        'policy' => '1',
        'waiver' => '1',
        'free_admission' => '1',
        'event_about' => 'A private dinner for family.',
    ]);
}

add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];

global $wpdb;
$snapshot = get_option('brb_settings');
$created_ids = [];
$admin = get_user_by('login', 'admin');
if (!$admin) {
    $admin_id = wp_insert_user([
        'user_login' => 'admin',
        'user_pass' => wp_generate_password(24, true, true),
        'user_email' => 'admin@example.com',
        'role' => 'administrator',
    ]);
    $admin = is_wp_error($admin_id) ? null : get_user_by('id', $admin_id);
}
if ($admin) wp_set_current_user($admin->ID);

try {
    brb_t((bool) $admin && current_user_can('manage_options'), 'an administrator is available for the booking screen');
    $opt = brb_opt();
    $opt['deposit_type'] = 'percent';
    $opt['deposit_pct'] = '50';
    $opt['damage_deposit_amount'] = '250';
    $opt['card_fee_percent'] = '3';
    update_option('brb_settings', $opt);

    $died = false;
    $die_handler = function () use (&$died) {
        return function () use (&$died) {
            $died = true;
            throw new RuntimeException('stopped');
        };
    };
    add_filter('wp_die_handler', $die_handler);
    $kept_user = get_current_user_id();
    wp_set_current_user(0);
    try { brb_admin_gate('brb_spam'); } catch (RuntimeException $e) {}
    brb_t($died, 'a logged-out staff action is rejected');
    wp_set_current_user($kept_user);
    $died = false;
    unset($_REQUEST['_wpnonce']);
    try { brb_admin_gate('brb_decline'); } catch (RuntimeException $e) {}
    brb_t($died, 'a staff action without a nonce is rejected');
    $_REQUEST['_wpnonce'] = wp_create_nonce('brb_save_changes');
    $died = false;
    $passed = false;
    try {
        brb_admin_gate('brb_save_changes');
        $passed = true;
    } catch (RuntimeException $e) {}
    brb_t($passed && !$died, 'an administrator with a matching nonce is allowed');
    unset($_REQUEST['_wpnonce']);
    remove_filter('wp_die_handler', $die_handler);

    $day = brb_now()->modify('+30 days')->format('Y-m-d');
    $money_b = brb_admin_case('admin-money@example.com', $day, 'Money Guest');
    brb_t(!is_wp_error($money_b) && $money_b, 'a booking can be created for the money block');
    if ($money_b && !is_wp_error($money_b)) {
        $created_ids[] = (int) $money_b->id;
        $mail_before = count($GLOBALS['brb_mails']);
        $priced = brb_set_agreed_price($money_b->id, 850, false);
        brb_t(!is_wp_error($priced) && count($GLOBALS['brb_mails']) === $mail_before, 'saving a rental price without the preview sends no email');
        $wpdb->update(brb_table(), ['damage_deposit' => '0.00'], ['id' => (int) $money_b->id]);
        $priced = brb_booking($money_b->id);
        $money = brb_booking_money($priced);
        brb_t(abs($money['rental'] - 850) < 0.001 && abs($money['damage'] - 250) < 0.001 && abs($money['deposit'] - 425) < 0.001, 'the money block reads the rental, the damage line, and the stored deposit');
        brb_t($money['due_text'] === '$425.00 + $250.00 = $675.00' && abs($money['due_now'] - 675) < 0.001 && abs($money['balance'] - 425) < 0.001, 'due now is the deposit plus the damage deposit, and the balance is the rest');
        brb_t($money['card_fee'] === null && strpos($money['rows']['Refundable damage deposit'], '$250.00') !== false && strpos($money['rows']['Refundable damage deposit'], '$0.00') === false, 'a stale zero damage column does not hide the $250 line, and a check booking has no card fee');
        ob_start();
        brb_admin_detail((int) $priced->id);
        $html = ob_get_clean();
        brb_t(strpos($html, '<h2>Money</h2>') !== false && strpos($html, '$425.00 + $250.00 = $675.00') !== false && strpos($html, 'Balance due') !== false && strpos($html, 'Card processing fee') === false && strpos($html, '$0.00 not paid yet') === false, 'the booking page shows one money block and no card fee for a check booking');
        brb_t(strpos($html, 'Not held yet. Held after approval; reserved when the deposit is received.') !== false && strpos($html, 'No, not until the deposit is received') === false, 'a request says it is not held yet and that approval holds the date');
        brb_t(strpos($html, '<h2>Edit history</h2>') !== false && strpos($html, 'guest email skipped') !== false, 'edit history records the price save and that no email was sent');
        $mail_before = count($GLOBALS['brb_mails']);
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $priced->id]);
        $approved = brb_approve_booking($priced->id, false);
        brb_t(!is_wp_error($approved) && $approved->status === 'approved' && count($GLOBALS['brb_mails']) === $mail_before, 'approving with Don\'t email changes the status and sends nothing');
        brb_t(brb_date_hold_state($approved) === 'Held after approval; reserved when the deposit is received.', 'an approved booking is held and reserved only when the deposit arrives');
        $paid = brb_record_payment($approved->id, 425, '1001', brb_now()->format('Y-m-d'), 'Deposit', false, 'check', ['notify' => false]);
        brb_t(!is_wp_error($paid) && count($GLOBALS['brb_mails']) === $mail_before && brb_date_hold_state($paid) === 'Reserved. The deposit has been received.', 'recording a payment with Don\'t email sends nothing and the date is reserved');
        $fresh = brb_booking($approved->id);
        brb_t($fresh && strpos((string) $fresh->audit_log, 'guest email skipped') !== false && strpos((string) $fresh->audit_log, 'recorded Check payment') !== false, 'the audit log records the skipped payment email');
    }

    $card_day = brb_now()->modify('+32 days')->format('Y-m-d');
    $card_b = brb_admin_case('admin-card@example.com', $card_day, 'Card Guest');
    if ($card_b && !is_wp_error($card_b)) {
        $created_ids[] = (int) $card_b->id;
        $card_b = brb_set_agreed_price($card_b->id, 200, false);
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $card_b->id]);
        $card_b = brb_approve_booking($card_b->id, false);
        $before_fee = brb_booking_money($card_b);
        $card_b = brb_record_payment($card_b->id, 100, '', brb_now()->format('Y-m-d'), 'Card deposit', false, 'card', ['notify' => false, 'fee' => 3]);
        $after_fee = !is_wp_error($card_b) ? brb_booking_money($card_b) : null;
        brb_t($before_fee['card_fee'] === null && $after_fee && abs($after_fee['card_fee'] - 3) < 0.001, 'the 3 percent card fee is shown only after a card payment');
        ob_start();
        brb_admin_detail((int) $card_b->id);
        $card_html = ob_get_clean();
        brb_t(strpos($card_html, 'Card processing fee (3%)') !== false && strpos($card_html, '$3.00') !== false && strpos($card_html, 'not part of the rental or the damage deposit') !== false, 'the money block lists the card fee apart from the rental and the damage deposit');
    } else {
        brb_t(false, 'a booking can be created for the card fee');
    }

    $mail_day = brb_now()->modify('+34 days')->format('Y-m-d');
    $mail_b = brb_admin_case('admin-mail@example.com', $mail_day, 'Mail Guest');
    if ($mail_b && !is_wp_error($mail_b)) {
        $created_ids[] = (int) $mail_b->id;
        $GLOBALS['brb_mails'] = [];
        $mail_b = brb_set_agreed_price($mail_b->id, 400, true);
        $price_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'admin-mail@example.com') !== false && stripos($m['subject'], 'price is confirmed') !== false) $price_mail = $m;
        }
        brb_t($price_mail && $price_mail['message'] === brb_price_guest_body($mail_b), 'Send on the price preview uses the exact guest email');
        ob_start();
        brb_admin_detail((int) $mail_b->id);
        $mail_html = ob_get_clean();
        $shown = count($GLOBALS['brb_mails']);
        ob_start();
        brb_admin_detail((int) $mail_b->id);
        ob_end_clean();
        brb_t(strpos($mail_html, esc_textarea(brb_price_guest_body($mail_b))) !== false && strpos($mail_html, 'name="email_body"') !== false && strpos($mail_html, 'name="email_subject"') !== false && strpos($mail_html, 'Reset to default') !== false && strpos($mail_html, 'name="email_guest" value="1"') !== false && strpos($mail_html, 'Don\'t email') !== false && count($GLOBALS['brb_mails']) === $shown, 'the price preview is an editable email with Send and Don\'t email, and opening it sends nothing');
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $mail_b->id]);
        $mail_b = brb_booking($mail_b->id);
        ob_start();
        brb_admin_detail((int) $mail_b->id);
        $approve_html = ob_get_clean();
        brb_t(strpos($approve_html, 'name="action" value="brb_approve"') !== false && strpos($approve_html, esc_textarea(brb_approve_preview_body($mail_b))) !== false && strpos($approve_html, 'name="email_body"') !== false && strpos($approve_html, 'Reset to default') !== false && strpos($approve_html, '>Send<') !== false && strpos($approve_html, 'Don\'t email') !== false, 'Approve opens an editable email with Send and Don\'t email');
        $pay_preview = brb_preview_payment_message($mail_b, 200, '44', brb_now()->format('Y-m-d'), 'Preview', 'check');
        $GLOBALS['brb_mails'] = [];
        $recorded = brb_record_payment($mail_b->id, 200, '44', brb_now()->format('Y-m-d'), 'Preview', false, 'check', ['notify' => true]);
        $receipt = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'admin-mail@example.com') !== false && stripos($m['subject'], 'receipt') !== false) $receipt = $m;
        }
        brb_t(!is_wp_error($pay_preview) && $receipt && $receipt['message'] === $pay_preview['body'], 'the payment preview matches the receipt that Send delivers');
        brb_t(strpos($approve_html, 'name="action" value="brb_payment"') !== false && strpos($approve_html, 'id="brb-payment-preview"') !== false && strpos($approve_html, 'name="email_body"') !== false && strpos($approve_html, 'Reset to default') !== false, 'Record payment shows an editable receipt before Send');
    } else {
        brb_t(false, 'a booking can be created for the email previews');
    }

    $save_day = brb_now()->modify('+36 days')->format('Y-m-d');
    $save_b = brb_admin_case('admin-save@example.com', $save_day, 'Save Guest');
    if ($save_b && !is_wp_error($save_b)) {
        $created_ids[] = (int) $save_b->id;
        $GLOBALS['brb_mails'] = [];
        $saved = brb_save_booking_changes($save_b->id, [
            'date' => $save_day,
            'name' => 'Saved Guest',
            'email' => 'admin-save@example.com',
            'phone' => '555-0199',
            'notes' => 'Updated note',
            'walkthrough_done' => '1',
            'agreed_price' => '640',
        ]);
        $saved = is_wp_error($saved) ? $saved : brb_booking($save_b->id);
        brb_t(!is_wp_error($saved) && $saved->name === 'Saved Guest' && abs((float) $saved->total - 640) < 0.01 && (int) $saved->walkthrough_done === 1 && $GLOBALS['brb_mails'] === [], 'Save changes stores the booking and the price and never emails');
        $log = (string) $saved->audit_log;
        brb_t(strpos($log, 'name from "Save Guest" to "Saved Guest"') !== false && strpos($log, 'phone from "555-0100" to "555-0199"') !== false && strpos($log, 'walk-through from "Not yet" to "Completed"') !== false && strpos($log, 'saved rental price from "') !== false && strpos($log, 'guest email skipped') !== false, 'the audit log stores before and after values for the save and says no price email was sent');
        ob_start();
        brb_admin_detail((int) $save_b->id);
        $save_html = ob_get_clean();
        brb_t(strpos($save_html, '>Save changes<') !== false && strpos($save_html, 'value="brb_save_changes"') !== false && strpos($save_html, 'Save booking record') === false && strpos($save_html, 'Save rental price and email guest the total') === false, 'the three save buttons are one Save changes button');
    } else {
        brb_t(false, 'a booking can be created for Save changes');
    }

    $free = brb_free_full_days(brb_now()->modify('+50 days')->format('Y-m-d'), brb_now()->modify('+70 days')->format('Y-m-d'));
    $alt = $free ? $free[0] : '';
    brb_t($alt !== '' && brb_public_date_is_open($alt), 'suggested dates come from the same free-date check as the public calendar');
    $decline_day = brb_now()->modify('+38 days')->format('Y-m-d');
    $decline_b = brb_admin_case('admin-decline@example.com', $decline_day, 'Decline Guest');
    if ($decline_b && !is_wp_error($decline_b) && $alt !== '') {
        $created_ids[] = (int) $decline_b->id;
        ob_start();
        brb_admin_detail((int) $decline_b->id);
        $decline_html = ob_get_clean();
        brb_t(strpos($decline_html, 'Decline and send email') !== false && strpos($decline_html, 'Decline without emailing') !== false && strpos($decline_html, 'does not meet our event rules or the date is not available') !== false && strpos($decline_html, 'name="email_body"') !== false && strpos($decline_html, 'Reset to default') !== false && strpos($decline_html, 'value="' . $alt . '"') !== false && strpos($decline_html, 'Flag as spam/joke') !== false, 'Decline opens the message form, free dates, an editable email, and the spam action');
        $GLOBALS['brb_mails'] = [];
        $bad = brb_decline_booking($decline_b->id, ['send' => true, 'dates' => ['1999-01-01']]);
        brb_t(is_wp_error($bad) && brb_booking($decline_b->id)->status === 'review' && $GLOBALS['brb_mails'] === [], 'a date that is not free is rejected and nothing is emailed');
        $quiet_day = brb_now()->modify('+40 days')->format('Y-m-d');
        $quiet = brb_admin_case('admin-quiet@example.com', $quiet_day, 'Quiet Guest');
        if ($quiet && !is_wp_error($quiet)) {
            $created_ids[] = (int) $quiet->id;
            $GLOBALS['brb_mails'] = [];
            $quiet = brb_decline_booking($quiet->id, ['send' => false, 'message' => 'Try a different weekend.', 'dates' => [$alt]]);
            $quiet_log = !is_wp_error($quiet) ? (string) $quiet->audit_log : '';
            brb_t(!is_wp_error($quiet) && $quiet->status === 'declined' && $GLOBALS['brb_mails'] === [] && strpos($quiet_log, 'Try a different weekend.') !== false && strpos($quiet_log, $alt) !== false && strpos($quiet_log, 'guest email skipped') !== false, 'Decline without emailing stores the message and dates and sends nothing');
        }
        $GLOBALS['brb_mails'] = [];
        $sent = brb_decline_booking($decline_b->id, ['send' => true, 'message' => 'Saturday is open.', 'dates' => [$alt]]);
        $guest_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'admin-decline@example.com') !== false) $guest_mail = $m;
        }
        $url = brb_reserve_date_url($alt);
        brb_t($guest_mail && strpos($guest_mail['message'], 'does not meet our event rules or the date is not available') === false && strpos($guest_mail['message'], 'Saturday is open.') !== false && strpos($guest_mail['message'], $url) !== false && strpos($guest_mail['message'], $alt) !== false, 'a decline with a message replaces the vague line with the message, the date, and a reserve link');
        brb_t(!is_wp_error($sent) && strpos((string) $sent->audit_log, 'Saturday is open.') !== false && strpos((string) $sent->audit_log, $alt) !== false && strpos((string) $sent->audit_log, 'guest email sent') !== false, 'the decline message and suggested date are in the audit log');

        $edit_day = brb_now()->modify('+44 days')->format('Y-m-d');
        $edit_b = brb_admin_case('admin-edit-mail@example.com', $edit_day, 'Edit Mail Guest');
        if ($edit_b && !is_wp_error($edit_b)) {
            $created_ids[] = (int) $edit_b->id;
            $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $edit_b->id]);
            $GLOBALS['brb_mails'] = [];
            $custom_subject = 'Custom approval subject';
            $custom_body = "Hello,\nThis is an edited approval email.";
            $approved = brb_approve_booking($edit_b->id, true, ['subject' => $custom_subject, 'body' => $custom_body]);
            $guest_mail = null;
            foreach ($GLOBALS['brb_mails'] as $m) {
                if (stripos($m['to'], 'admin-edit-mail@example.com') !== false) $guest_mail = $m;
            }
            $approved_log = !is_wp_error($approved) ? (string) $approved->audit_log : '';
            brb_t(!is_wp_error($approved) && $guest_mail && $guest_mail['subject'] === $custom_subject && $guest_mail['message'] === $custom_body, 'approving with an edited email sends the edited subject and body');
            brb_t(strpos($approved_log, $custom_subject) !== false && strpos($approved_log, 'This is an edited approval email.') !== false, 'the approval audit log stores the sent email text');

            $GLOBALS['brb_mails'] = [];
            $receipt_subject = 'Custom receipt subject';
            $receipt_body = "Hello,\nThis is an edited receipt.";
            $paid = brb_record_payment($approved->id, 50, '55', brb_now()->format('Y-m-d'), 'Edited', false, 'check', [
                'notify' => true,
                'subject' => $receipt_subject,
                'body' => $receipt_body,
            ]);
            $receipt = null;
            foreach ($GLOBALS['brb_mails'] as $m) {
                if (stripos($m['to'], 'admin-edit-mail@example.com') !== false) $receipt = $m;
            }
            $pay_log = !is_wp_error($paid) ? (string) $paid->audit_log : '';
            brb_t(!is_wp_error($paid) && $receipt && $receipt['subject'] === $receipt_subject && $receipt['message'] === $receipt_body, 'recording a payment with an edited receipt sends the edited text');
            brb_t(strpos($pay_log, $receipt_subject) !== false && strpos($pay_log, 'This is an edited receipt.') !== false, 'the payment audit log stores the sent receipt text');
        } else {
            brb_t(false, 'a booking can be created for an edited guest email');
        }

        $decline_edit_day = brb_now()->modify('+46 days')->format('Y-m-d');
        $decline_edit = brb_admin_case('admin-edit-decline@example.com', $decline_edit_day, 'Edit Decline Guest');
        if ($decline_edit && !is_wp_error($decline_edit) && $alt !== '') {
            $created_ids[] = (int) $decline_edit->id;
            $GLOBALS['brb_mails'] = [];
            $decline_subject = 'Custom decline subject';
            $decline_body = "Hello,\nThis date is not available after all.";
            $declined = brb_decline_booking($decline_edit->id, [
                'send' => true,
                'message' => 'Please pick another day.',
                'dates' => [$alt],
                'subject' => $decline_subject,
                'body' => $decline_body,
            ]);
            $guest_mail = null;
            foreach ($GLOBALS['brb_mails'] as $m) {
                if (stripos($m['to'], 'admin-edit-decline@example.com') !== false) $guest_mail = $m;
            }
            $decline_log = !is_wp_error($declined) ? (string) $declined->audit_log : '';
            brb_t(!is_wp_error($declined) && $declined->status === 'declined' && $guest_mail && $guest_mail['subject'] === $decline_subject && $guest_mail['message'] === $decline_body, 'declining with an edited email sends the edited refusal text, not the generated default');
            brb_t(strpos($decline_log, $decline_subject) !== false && strpos($decline_log, 'This date is not available after all.') !== false && strpos($decline_log, 'Please pick another day.') !== false, 'the decline audit log stores the sent email text');
        } else {
            brb_t(false, 'a booking can be created for an edited decline email');
        }

        $GLOBALS['brb_mails'] = [];
        $price_day = brb_now()->modify('+48 days')->format('Y-m-d');
        $price_edit = brb_admin_case('admin-edit-price@example.com', $price_day, 'Edit Price Guest');
        if ($price_edit && !is_wp_error($price_edit)) {
            $created_ids[] = (int) $price_edit->id;
            $price_edit = brb_set_agreed_price($price_edit->id, 500, false);
            $GLOBALS['brb_mails'] = [];
            $price_subject = 'Custom price subject';
            $price_body = "Hello,\nThe price is ready to review.";
            $sent_price = brb_send_price_email($price_edit, $price_subject, $price_body);
            $guest_mail = null;
            foreach ($GLOBALS['brb_mails'] as $m) {
                if (stripos($m['to'], 'admin-edit-price@example.com') !== false) $guest_mail = $m;
            }
            brb_t($guest_mail && $guest_mail['subject'] === $price_subject && $guest_mail['message'] === $price_body && $sent_price['body'] === $price_body, 'the price email send uses the edited subject and body');
            $scrubbed = brb_sanitize_guest_body("Hello <script>alert(1)</script>\nNext line");
            brb_t(strpos($scrubbed, '<script>') === false && strpos($scrubbed, 'Hello') !== false && strpos($scrubbed, "\n") !== false, 'guest email bodies are sanitized as plain text and keep newlines');
        } else {
            brb_t(false, 'a booking can be created for an edited price email');
        }

        $vague_day = brb_now()->modify('+42 days')->format('Y-m-d');
        $vague = brb_admin_case('admin-vague@example.com', $vague_day, 'Vague Guest');
        if ($vague && !is_wp_error($vague)) {
            $created_ids[] = (int) $vague->id;
            $GLOBALS['brb_mails'] = [];
            brb_decline_booking($vague->id);
            $vague_mail = null;
            foreach ($GLOBALS['brb_mails'] as $m) {
                if (stripos($m['to'], 'admin-vague@example.com') !== false) $vague_mail = $m;
            }
            brb_t($vague_mail && strpos($vague_mail['message'], 'does not meet our event rules or the date is not available') !== false, 'a decline with no message keeps the original reason');
        }
    } else {
        brb_t(false, 'a booking can be created for decline');
    }

    $delete_day = brb_now()->modify('+44 days')->format('Y-m-d');
    $delete_b = brb_admin_case('admin-delete@example.com', $delete_day, 'Delete Guest');
    if ($delete_b && !is_wp_error($delete_b)) {
        $created_ids[] = (int) $delete_b->id;
        ob_start();
        brb_admin_detail((int) $delete_b->id);
        $delete_html = ob_get_clean();
        brb_t(strpos($delete_html, 'name="confirm_ref"') !== false && strpos($delete_html, esc_html(brb_ref($delete_b))) !== false && strpos($delete_html, 'value="brb_delete"') !== false && strpos($delete_html, 'This cannot be undone.') !== false, 'delete asks for the booking number');
        $wrong = brb_delete_booking($delete_b->id, 'BR-0');
        $wrong_row = brb_booking($delete_b->id);
        brb_t(is_wp_error($wrong) && $wrong->get_error_code() === 'confirm' && $wrong_row && strpos((string) $wrong_row->audit_log, 'delete blocked') !== false, 'the wrong booking number is refused and the attempt is logged');
        $ref = brb_ref($delete_b);
        $gone = brb_delete_booking($delete_b->id, $ref);
        $surviving = (string) get_option('brb_delete_log', '');
        brb_t($gone === true && !brb_booking($delete_b->id) && strpos($surviving, 'deleted ' . $ref) !== false, 'the matching booking number deletes it and the record survives');
    } else {
        brb_t(false, 'a booking can be created for delete');
    }

    $paid_day = brb_now()->modify('+46 days')->format('Y-m-d');
    $paid_b = brb_admin_case('admin-paid-delete@example.com', $paid_day, 'Paid Delete');
    if ($paid_b && !is_wp_error($paid_b)) {
        $created_ids[] = (int) $paid_b->id;
        brb_set_agreed_price($paid_b->id, 500, false);
        brb_record_payment($paid_b->id, 100, '77', brb_now()->format('Y-m-d'), 'Keep', false, 'check', ['notify' => false]);
        ob_start();
        brb_admin_detail((int) $paid_b->id);
        $paid_html = ob_get_clean();
        $blocked = brb_delete_booking($paid_b->id, brb_ref(brb_booking($paid_b->id)));
        brb_t(is_wp_error($blocked) && strpos($blocked->get_error_message(), 'Cancel') !== false && brb_booking($paid_b->id) && strpos($paid_html, 'Cancel it instead of deleting it.') !== false && strpos($paid_html, 'value="brb_delete"') === false, 'a recorded payment blocks delete and points staff to Cancel');
    }

    $spam_day = brb_now()->modify('+48 days')->format('Y-m-d');
    $spam_b = brb_admin_case('admin-spam@example.com', $spam_day, 'Spam Guest');
    if ($spam_b && !is_wp_error($spam_b)) {
        $created_ids[] = (int) $spam_b->id;
        $spam_start = new DateTimeImmutable($spam_b->start_dt, brb_tz());
        $spam_end = new DateTimeImmutable($spam_b->end_dt, brb_tz());
        $GLOBALS['brb_mails'] = [];
        $flagged = brb_flag_spam_booking($spam_b->id);
        brb_t(!is_wp_error($flagged) && $flagged->status === 'spam' && $GLOBALS['brb_mails'] === [] && strpos((string) $flagged->audit_log, 'flagged as spam/joke') !== false && strpos((string) $flagged->audit_log, 'no email sent') !== false, 'Flag as spam/joke closes the request, logs it, and sends no email');
        brb_t(brb_is_free($spam_start, $spam_end), 'a spam flag does not hold the date');
        ob_start();
        brb_admin_list();
        $default_list = ob_get_clean();
        $_GET['status'] = 'spam';
        ob_start();
        brb_admin_list();
        $spam_list = ob_get_clean();
        unset($_GET['status']);
        brb_t(strpos($default_list, 'Spam Guest') === false && strpos($spam_list, 'Spam Guest') !== false && strpos($spam_list, 'Flagged as spam/joke') !== false, 'flagged requests are hidden from the default list and shown by the spam filter');
        $report = brb_report_build('2000-01-01', '2100-01-01');
        $upcoming_ids = [];
        foreach ((array) $report['bookings']['upcoming'] as $row) $upcoming_ids[] = (int) $row->id;
        brb_t(!isset($report['bookings']['by_status']['spam']) && !in_array((int) $spam_b->id, $upcoming_ids, true), 'flagged requests are hidden from reports');
    } else {
        brb_t(false, 'a booking can be created for the spam flag');
    }

    $history_day = brb_now()->modify('+52 days')->format('Y-m-d');
    $history_b = brb_admin_case('admin-history@example.com', $history_day, 'History Guest');
    if ($history_b && !is_wp_error($history_b)) {
        $created_ids[] = (int) $history_b->id;
        ob_start();
        brb_admin_detail((int) $history_b->id);
        $empty_html = ob_get_clean();
        brb_t(strpos($empty_html, '<h2>Edit history</h2>') !== false && strpos($empty_html, 'No edits yet.') !== false, 'edit history is on the booking page before anything is logged');
        brb_audit_append($history_b->id, 'history-older-marker');
        brb_audit_append($history_b->id, 'history-newer-marker <script>alert(1)</script>');
        ob_start();
        brb_admin_detail((int) $history_b->id);
        $history_html = ob_get_clean();
        $older = strpos($history_html, 'history-older-marker');
        $newer = strpos($history_html, 'history-newer-marker');
        brb_t($newer !== false && $older !== false && $newer < $older, 'edit history is newest first');
        brb_t(strpos($history_html, '&lt;script&gt;') !== false && strpos($history_html, '<script>alert(1)</script>') === false, 'edit history escapes its text');
    }

    $taken_day = brb_now()->modify('+40 days')->format('Y-m-d');
    $taken = brb_admin_case('admin-taken@example.com', $taken_day, 'Taken Guest');
    brb_t($taken && !is_wp_error($taken), 'a booking exists so its date can be taken');
    if ($taken && !is_wp_error($taken)) {
        $created_ids[] = (int) $taken->id;
        $wpdb->update(brb_table(), ['status' => 'deposit_paid'], ['id' => (int) $taken->id]);
        $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_table());
        $mails_before = count($GLOBALS['brb_mails']);
        $_POST = [
            'action' => 'brb_manual',
            'source' => 'phone',
            'date' => $taken_day,
            'type' => 'day',
            'guests' => '12',
            'name' => 'Rejected Guest',
            'email' => 'admin-rejected@example.com',
            'phone' => '555-0199',
            'notes' => 'Please keep this note',
            'agreed_price' => '',
            '_wpnonce' => wp_create_nonce('brb_manual'),
            '_wp_http_referer' => '/wp-admin/admin.php?page=brb-new',
        ];
        $_REQUEST = $_POST;
        $redirect = '';
        $catch = function ($location) use (&$redirect) {
            $redirect = (string) $location;
            throw new RuntimeException('redirect');
        };
        add_filter('wp_redirect', $catch, 1);
        try {
            do_action('admin_post_brb_manual');
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'redirect') throw $e;
        }
        remove_filter('wp_redirect', $catch, 1);
        $after = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_table());
        $new_mails = 0;
        foreach (array_slice($GLOBALS['brb_mails'], $mails_before) as $mail) {
            if (stripos((string) ($mail['to'] ?? ''), 'admin-rejected@example.com') !== false) $new_mails++;
        }
        $_GET = [
            'page' => 'brb-new',
            'brb_note' => brb_ref($taken) . ' saved as pending review (Phone). The date is not held. The guest was emailed.',
        ];
        ob_start();
        brb_admin_new();
        $form = ob_get_clean();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        brb_t(strpos($redirect, 'page=brb-new') !== false && strpos($redirect, 'brb_note') === false, 'a taken date stays on the add-booking screen');
        brb_t($after === $before && $new_mails === 0, 'a taken date does not create a booking or send email');
        brb_t(strpos($form, 'That date is already taken (' . brb_ref($taken) . '). No booking was created and no email was sent.') !== false, 'the taken-date error names the existing booking');
        brb_t(strpos($form, 'saved as pending review') === false && strpos($form, 'The guest was emailed') === false, 'a rejection does not keep the previous success notice');
        brb_t(strpos($form, 'value="' . $taken_day . '"') !== false && strpos($form, 'value="Rejected Guest"') !== false && strpos($form, 'admin-rejected@example.com') !== false && strpos($form, 'Please keep this note') !== false && strpos($form, 'value="12"') !== false, 'the add-booking form keeps the entered values');
    }
} finally {
    if ($created_ids) {
        $in = implode(',', array_map('intval', $created_ids));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_line_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    foreach (['admin-money@example.com', 'admin-card@example.com', 'admin-mail@example.com', 'admin-save@example.com', 'admin-decline@example.com', 'admin-quiet@example.com', 'admin-vague@example.com', 'admin-delete@example.com', 'admin-paid-delete@example.com', 'admin-spam@example.com', 'admin-history@example.com', 'admin-taken@example.com', 'admin-rejected@example.com'] as $email) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_pay_table() . ' WHERE booking_id IN (SELECT id FROM ' . brb_table() . ' WHERE email=%s)', $email));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_line_table() . ' WHERE booking_id IN (SELECT id FROM ' . brb_table() . ' WHERE email=%s)', $email));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_table() . ' WHERE email=%s', $email));
    }
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
