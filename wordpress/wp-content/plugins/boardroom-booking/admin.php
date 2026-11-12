<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('Bookings', 'Bookings', 'manage_options', 'brb', 'brb_admin_router', 'dashicons-calendar-alt', 25);
    add_submenu_page('brb', 'Bookings', 'All Bookings', 'manage_options', 'brb', 'brb_admin_router');
    add_submenu_page('brb', 'Add booking', 'Add booking', 'manage_options', 'brb-new', 'brb_admin_new');
    add_submenu_page('brb', 'Calendar', 'Calendar', 'manage_options', 'brb-cal', 'brb_admin_cal');
    add_submenu_page('brb', 'Inquiries', 'Inquiries', 'manage_options', 'brb-tours', 'brb_admin_tours');
    add_submenu_page('brb', 'Reports', 'Reports', 'manage_options', 'brb-reports', 'brb_admin_reports');
    add_submenu_page('brb', 'Booking Settings', 'Settings', 'manage_options', 'brb-settings', 'brb_admin_settings');
});
add_action('admin_enqueue_scripts', function ($h) {
    if (strpos($h, 'brb') !== false) wp_enqueue_style('brb-admin', BRB_URL . 'assets/brb.css', [], BRB_VER);
});
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $o = brb_opt();
    $missing = [];
    foreach ([
        'payee' => 'check payable-to name',
        'pay_location' => 'check mail or drop-off address',
    ] as $k => $label) {
        if (strpos((string) $o[$k], '[VERIFY]') !== false) $missing[] = $label;
    }
    if (!$missing) return;
    echo '<div class="notice notice-warning"><p><strong>Boardroom Booking:</strong> Fill in ' . esc_html(implode(', ', $missing)) . ' under <a href="' . esc_url(admin_url('admin.php?page=brb-settings')) . '">Booking Settings</a>. Guests see the [VERIFY] placeholders until you replace them.</p></div>';
});

function brb_email_customer_anchor($booking, $text, $class = '') {
    if (!current_user_can('manage_options') || !is_email((string) ($booking->email ?? ''))) return '';
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    // esc_attr keeps the encoded line breaks. esc_url strips %0A and would flatten the message.
    return '<a' . $class_attr . ' href="' . esc_attr(brb_gmail_compose_url($booking)) . '" target="_blank" rel="noopener">' . esc_html($text) . '</a>';
}
function brb_admin_gate($action) {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    check_admin_referer($action);
}
function brb_admin_back($id, $note, $page = 'brb') {
    $url = admin_url('admin.php?page=' . $page);
    if ($id) $url = add_query_arg('booking', (int) $id, $url);
    if ($note) $url = add_query_arg('brb_note', $note, $url);
    wp_safe_redirect($url);
    exit;
}
function brb_admin_note() {
    if (empty($_GET['brb_note'])) return;
    $class = empty($_GET['brb_note_ok']) ? 'notice-info' : 'notice-success';
    echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html(wp_unslash($_GET['brb_note'])) . '</p></div>';
}
function brb_posted_guest_mail($default_subject, $default_body) {
    $args = [];
    if (array_key_exists('email_subject', $_POST)) $args['subject'] = wp_unslash($_POST['email_subject']);
    if (array_key_exists('email_body', $_POST)) $args['body'] = wp_unslash($_POST['email_body']);
    $mail = brb_custom_guest_mail($args, $default_subject, $default_body);
    if (is_wp_error($mail)) return $mail;
    list($subject, $body) = $mail;
    if (array_key_exists('email_body', $_POST)) {
        $posted_body = brb_sanitize_guest_body(wp_unslash($_POST['email_body']));
        if (is_wp_error($posted_body)) return $posted_body;
        if ($posted_body === '') return new WP_Error('mail', 'The email cannot be empty.');
    }
    return ['subject' => $subject, 'body' => $body];
}
function brb_admin_email_fields($prefix, $intro, $subject, $body) {
    echo '<p>' . esc_html($intro) . '</p>';
    echo '<p><label for="' . esc_attr($prefix . '-subject') . '">Subject<br>';
    echo '<input type="text" class="large-text brb-email-subject" name="email_subject" id="' . esc_attr($prefix . '-subject') . '" value="' . esc_attr($subject) . '" maxlength="200"></label></p>';
    echo '<p><label for="' . esc_attr($prefix . '-preview') . '">Email<br>';
    echo '<textarea class="large-text brb-email-body" name="email_body" id="' . esc_attr($prefix . '-preview') . '" rows="16" maxlength="10000">' . esc_textarea($body) . '</textarea></label></p>';
    echo '<textarea class="brb-email-default-subject" hidden disabled>' . esc_textarea($subject) . '</textarea>';
    echo '<textarea class="brb-email-default-body" hidden disabled>' . esc_textarea($body) . '</textarea>';
    echo '<p><button type="button" class="button brb-email-reset">Reset to default</button></p>';
}
function brb_admin_email_editor_script() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo <<<'JS'
<script>(function(){
  function bind(form){
    var subject = form.querySelector(".brb-email-subject");
    var body = form.querySelector(".brb-email-body");
    var defSubject = form.querySelector(".brb-email-default-subject");
    var defBody = form.querySelector(".brb-email-default-body");
    var reset = form.querySelector(".brb-email-reset");
    if (!body) return;
    var dirty = false;
    function isMailField(el){
      if (!el) return false;
      if (el === subject || el === body || el === reset) return true;
      if (el.classList && (el.classList.contains("brb-email-subject") || el.classList.contains("brb-email-body") || el.classList.contains("brb-email-reset") || el.classList.contains("brb-email-default-subject") || el.classList.contains("brb-email-default-body"))) return true;
      return false;
    }
    if (subject) subject.addEventListener("input", function(){ dirty = true; });
    body.addEventListener("input", function(){ dirty = true; });
    function apply(s, b, force){
      if (defSubject) defSubject.value = s;
      if (defBody) defBody.value = b;
      if (force || !dirty) {
        if (subject) subject.value = s;
        body.value = b;
        if (force) dirty = false;
      }
    }
    function refresh(force){
      var action = form.getAttribute("data-brb-preview");
      if (!action) {
        apply(defSubject ? defSubject.value : (subject ? subject.value : ""), defBody ? defBody.value : body.value, force);
        return;
      }
      if (typeof ajaxurl === "undefined") return;
      var data = new FormData(form);
      data.set("action", action);
      fetch(ajaxurl, {method:"POST", credentials:"same-origin", body:data}).then(function(r){ return r.json(); }).then(function(res){
        if (res && res.success && res.data) apply(res.data.subject || (defSubject ? defSubject.value : ""), res.data.body, force);
      });
    }
    function onFormChange(e){
      if (isMailField(e.target) || !form.getAttribute("data-brb-preview")) return;
      clearTimeout(form._brbTimer);
      form._brbTimer = setTimeout(function(){ refresh(false); }, 200);
    }
    form.addEventListener("input", onFormChange);
    form.addEventListener("change", onFormChange);
    if (reset) reset.addEventListener("click", function(e){ e.preventDefault(); refresh(true); });
  }
  document.querySelectorAll("form.brb-email-editor").forEach(bind);
})();</script>
JS;
}


add_action('admin_post_brb_price', function () {
    brb_admin_gate('brb_price');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_set_agreed_price($id, wp_unslash($_POST['agreed_price'] ?? ''), false);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' agreed price set to ' . brb_money($res->total) . '. No email was sent.');
});
add_action('admin_post_brb_price_email', function () {
    brb_admin_gate('brb_price_email');
    $id = (int) ($_POST['booking'] ?? 0);
    $choice = sanitize_key(wp_unslash($_POST['email_guest'] ?? ''));
    if ($choice !== '1' && $choice !== '0') brb_admin_back($id, 'Choose Send or Don\'t email.');
    $b = brb_booking($id);
    if (!$b) brb_admin_back(0, 'Booking not found.');
    if (!brb_price_is_set($b)) brb_admin_back($id, 'Save the rental price before emailing the guest.');
    if ($choice === '1') {
        $mail = brb_posted_guest_mail(brb_price_guest_subject($b), brb_price_guest_body($b));
        if (is_wp_error($mail)) brb_admin_back($id, $mail->get_error_message());
        brb_send_price_email($b, $mail['subject'], $mail['body']);
        brb_audit_append($id, 'emailed the guest the total from the preview; subject: "' . $mail['subject'] . '"; body: "' . $mail['body'] . '"');
        brb_admin_back($id, brb_ref($b) . ' price email sent.');
    }
    brb_audit_append($id, 'price email skipped from the preview');
    brb_admin_back($id, brb_ref($b) . ' was not emailed.');
});
add_action('admin_post_brb_payment', function () {
    brb_admin_gate('brb_payment');
    $id = (int) ($_POST['booking'] ?? 0);
    $choice = sanitize_key(wp_unslash($_POST['email_guest'] ?? ''));
    if ($choice !== '1' && $choice !== '0') brb_admin_back($id, 'Choose Send or Don\'t email.');
    $method = sanitize_key(wp_unslash($_POST['method'] ?? 'check'));
    $extra = ['notify' => $choice === '1'];
    if ($choice === '1') {
        $booking = brb_booking($id);
        if (!$booking) brb_admin_back(0, 'Booking not found.');
        $preview = brb_preview_payment_message(
            $booking,
            wp_unslash($_POST['amount'] ?? ''),
            wp_unslash($_POST['check_number'] ?? ''),
            sanitize_text_field(wp_unslash($_POST['received_on'] ?? '')),
            wp_unslash($_POST['pay_notes'] ?? ''),
            $method
        );
        if (is_wp_error($preview)) brb_admin_back($id, $preview->get_error_message());
        $mail = brb_posted_guest_mail($preview['subject'], $preview['body']);
        if (is_wp_error($mail)) brb_admin_back($id, $mail->get_error_message());
        $extra['subject'] = $mail['subject'];
        $extra['body'] = $mail['body'];
    }
    $res = brb_record_payment(
        $id,
        wp_unslash($_POST['amount'] ?? ''),
        wp_unslash($_POST['check_number'] ?? ''),
        sanitize_text_field(wp_unslash($_POST['received_on'] ?? '')),
        wp_unslash($_POST['pay_notes'] ?? ''),
        false,
        $method,
        $extra
    );
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    $note = brb_ref($res) . ' updated: ' . brb_status_label($res->status) . '.';
    $note .= $choice === '1' ? ' The guest was emailed.' : ' The guest was not emailed.';
    brb_admin_back($id, $note);
});
add_action('admin_post_brb_line', function () {
    brb_admin_gate('brb_line');
    $id = (int) ($_POST['booking'] ?? 0);
    if (!empty($_POST['remove'])) {
        $res = brb_remove_custom_line($id, (int) ($_POST['line_id'] ?? 0));
    } else {
        $res = brb_save_custom_line($id, wp_unslash($_POST['label'] ?? ''), wp_unslash($_POST['amount'] ?? ''), (int) ($_POST['line_id'] ?? 0));
    }
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, 'Charges updated.');
});
add_action('admin_post_brb_send_pay_link', function () {
    brb_admin_gate('brb_send_pay_link');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_send_payment_link($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, 'Payment link emailed to ' . $res->email . '.');
});
add_action('admin_post_brb_refund_damage', function () {
    brb_admin_gate('brb_refund_damage');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_mark_damage_refunded($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, 'Refundable damage deposit marked refunded. The refund itself is done in the Stripe Dashboard.');
});
add_action('admin_post_brb_mark_paid', function () {
    brb_admin_gate('brb_mark_paid');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_mark_paid_full($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' marked paid in full. The guest was emailed.');
});
add_action('admin_post_brb_flags', function () {
    brb_admin_gate('brb_flags');
    $id = (int) ($_POST['booking'] ?? 0);
    $b = brb_booking($id);
    if (!$b) brb_admin_back(0, 'Booking not found.');
    global $wpdb;
    $fields = [
        'walkthrough_done' => empty($_POST['walkthrough_done']) ? 0 : 1,
    ];
    $wpdb->update(brb_table(), $fields, ['id' => $id]);
    if (!empty($_POST['setup_addon'])) brb_apply_addon($id, 'setup');
    if (!empty($_POST['takedown_addon'])) brb_apply_addon($id, 'takedown');
    brb_admin_back($id, 'Booking record saved.');
});
add_action('admin_post_brb_edit_booking', function () {
    brb_admin_gate('brb_edit_booking');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_save_booking_edit($id, wp_unslash($_POST));
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' updated. No email was sent.');
});
add_action('admin_post_brb_save_changes', function () {
    brb_admin_gate('brb_save_changes');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_save_booking_changes($id, wp_unslash($_POST));
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' saved. No email was sent.');
});
add_action('admin_post_brb_approve', function () {
    brb_admin_gate('brb_approve');
    $id = (int) ($_POST['booking'] ?? 0);
    $choice = sanitize_key(wp_unslash($_POST['email_guest'] ?? ''));
    if ($choice !== '1' && $choice !== '0') brb_admin_back($id, 'Choose Send or Don\'t email.');
    $b = brb_booking($id);
    if (!$b) brb_admin_back(0, 'Booking not found.');
    $mail = [];
    if ($choice === '1') {
        $preview = clone $b;
        if (in_array((string) $preview->status, ['review', 'pending'], true)) $preview->status = 'approved';
        $posted = brb_posted_guest_mail(brb_approve_guest_subject($preview), brb_approve_guest_body($preview));
        if (is_wp_error($posted)) brb_admin_back($id, $posted->get_error_message());
        $mail = $posted;
    }
    $res = brb_approve_booking($id, $choice === '1', $mail);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    $note = brb_ref($res) . ' approved. Held after approval; reserved when the deposit is received.';
    $note .= $choice === '1' ? ' The guest was emailed.' : ' The guest was not emailed.';
    brb_admin_back($id, $note);
});
add_action('admin_post_brb_decline', function () {
    brb_admin_gate('brb_decline');
    $id = (int) ($_POST['booking'] ?? 0);
    $choice = sanitize_key(wp_unslash($_POST['email_guest'] ?? ''));
    if ($choice !== '1' && $choice !== '0') brb_admin_back($id, 'Choose Decline and send email, or Decline without emailing.');
    $b = brb_booking($id);
    if (!$b) brb_admin_back(0, 'Booking not found.');
    $dates = brb_collect_decline_dates(wp_unslash($_POST), $b);
    if (is_wp_error($dates)) brb_admin_back($id, $dates->get_error_message());
    $args = [
        'send' => $choice === '1',
        'message' => sanitize_textarea_field(wp_unslash($_POST['decline_message'] ?? '')),
        'dates' => $dates,
    ];
    if ($choice === '1') {
        $posted = brb_posted_guest_mail(brb_decline_guest_subject($b), brb_decline_guest_body($b, $args['message'], $dates));
        if (is_wp_error($posted)) brb_admin_back($id, $posted->get_error_message());
        $args['subject'] = $posted['subject'];
        $args['body'] = $posted['body'];
    }
    $res = brb_decline_booking($id, $args);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    $note = brb_ref($res) . ' declined. The date is not reserved.';
    $note .= $choice === '1' ? ' The guest was emailed.' : ' The guest was not emailed.';
    brb_admin_back($id, $note);
});
add_action('admin_post_brb_spam', function () {
    brb_admin_gate('brb_spam');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_flag_spam_booking($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' flagged as spam/joke. No email was sent.');
});
add_action('wp_ajax_brb_decline_preview', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sorry, you are not allowed to do that.'], 403);
    check_ajax_referer('brb_decline');
    $id = (int) ($_POST['booking'] ?? 0);
    $b = brb_booking($id);
    if (!$b) wp_send_json_error(['message' => 'Booking not found.'], 404);
    $dates = brb_collect_decline_dates(wp_unslash($_POST), $b);
    if (is_wp_error($dates)) wp_send_json_error(['message' => $dates->get_error_message()]);
    $message = sanitize_textarea_field(wp_unslash($_POST['decline_message'] ?? ''));
    wp_send_json_success(['subject' => brb_decline_guest_subject($b), 'body' => brb_decline_guest_body($b, $message, $dates)]);
});
add_action('wp_ajax_brb_payment_preview', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sorry, you are not allowed to do that.'], 403);
    check_ajax_referer('brb_payment');
    $id = (int) ($_POST['booking'] ?? 0);
    $b = brb_booking($id);
    if (!$b) wp_send_json_error(['message' => 'Booking not found.'], 404);
    $message = brb_preview_payment_message(
        $b,
        wp_unslash($_POST['amount'] ?? ''),
        wp_unslash($_POST['check_number'] ?? ''),
        sanitize_text_field(wp_unslash($_POST['received_on'] ?? '')),
        wp_unslash($_POST['pay_notes'] ?? ''),
        sanitize_key(wp_unslash($_POST['method'] ?? 'check'))
    );
    if (is_wp_error($message)) wp_send_json_error(['message' => $message->get_error_message()]);
    wp_send_json_success(['subject' => $message['subject'], 'body' => $message['body']]);
});
add_action('admin_post_brb_cancel', function () {
    brb_admin_gate('brb_cancel');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_cancel_booking($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' cancelled. The guest was emailed.');
});
add_action('admin_post_brb_delete', function () {
    brb_admin_gate('brb_delete');
    $id = (int) ($_POST['booking'] ?? 0);
    $confirm = sanitize_text_field(wp_unslash($_POST['confirm_ref'] ?? ''));
    $res = brb_delete_booking($id, $confirm);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back(0, 'Booking deleted.');
});
function brb_admin_list_url($status = '') {
    $url = admin_url('admin.php?page=brb');
    $allowed = ['review', 'approved', 'deposit_paid', 'paid', 'declined', 'cancelled', 'expired', 'held', 'card_review', 'spam'];
    if (in_array($status, $allowed, true)) $url = add_query_arg('status', $status, $url);
    return $url;
}
function brb_admin_bulk_redirect($status, $note, $ok = false) {
    $url = brb_admin_list_url($status);
    foreach (['q', 'orderby', 'order', 'paged', 'entries'] as $key) {
        $value = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        if ($value !== '') $url = add_query_arg($key, $value, $url);
    }
    if ($note !== '') $url = add_query_arg('brb_note', $note, $url);
    if ($ok) $url = add_query_arg('brb_note_ok', '1', $url);
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_brb_bulk_delete', function () {
    brb_admin_gate('brb_bulk_delete');
    $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
    $bulk = sanitize_key(wp_unslash($_POST['bulk_action'] ?? ''));
    $raw = wp_unslash($_POST['booking_ids'] ?? []);
    if (!is_array($raw)) $raw = [];
    $ids = [];
    foreach ($raw as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
    }
    if ($bulk !== 'delete' || !$ids) {
        brb_admin_bulk_redirect($status, 'No bookings selected.');
    }
    $n = brb_bulk_delete_bookings(array_values($ids));
    brb_admin_bulk_redirect($status, $n . ' bookings deleted.', true);
});
add_action('admin_post_brb_test_email', function () {
    brb_admin_gate('brb_test_email');
    $res = brb_send_test_email();
    if (is_wp_error($res)) brb_admin_back(0, 'Test email failed: ' . brb_redact_secret($res->get_error_message()), 'brb-settings');
    brb_admin_back(0, 'Test email sent to ' . implode(', ', $res) . '.', 'brb-settings');
});
add_action('admin_post_brb_manual', function () {
    brb_admin_gate('brb_manual');
    $post = wp_unslash($_POST);
    $override = !empty($post['override_notice']);
    $r = brb_build_range($post, ['override_notice' => $override]);
    if (is_wp_error($r)) brb_admin_back(0, $r->get_error_message(), 'brb-new');
    $name = sanitize_text_field($post['name'] ?? '');
    $email = sanitize_email($post['email'] ?? '');
    if (!$name || !is_email($email)) brb_admin_back(0, 'Name and a valid email are required.', 'brb-new');
    $source = sanitize_text_field($post['source'] ?? 'phone');
    $take = !empty($post['take_check']);
    $price_raw = trim((string) ($post['agreed_price'] ?? ''));
    if ($price_raw !== '') {
        $amount = round((float) $price_raw, 2);
        if ($amount <= 0) brb_admin_back(0, 'Enter an agreed price greater than zero, or leave it blank.', 'brb-new');
        $q = brb_agreed_quote($amount);
    } else {
        if ($take) brb_admin_back(0, 'Enter the agreed price before recording a check.', 'brb-new');
        $q = brb_quote($r);
    }
    $event_type = '';
    $screen = brb_collect_screening($post, false);
    if (is_wp_error($screen)) $screen = ['public_event' => '', 'free_admission' => '', 'alcohol' => '', 'end_time' => '', 'host_age' => '', 'event_about' => ''];
    $about = $screen['event_about'] !== '' ? $screen['event_about'] : sanitize_textarea_field($post['notes'] ?? '');
    $b = brb_insert_booking($r, $q, [
        'name' => $name,
        'email' => $email,
        'phone' => sanitize_text_field($post['phone'] ?? ''),
        'notes' => $about,
        'event_type' => $event_type,
        'public_event' => $screen['public_event'],
        'free_admission' => $screen['free_admission'] ?? '',
        'alcohol' => $screen['alcohol'],
        'end_time' => $screen['end_time'],
        'host_age' => $screen['host_age'],
        'event_about' => $about,
    ], $source, !$take);
    if (is_wp_error($b)) {
        if ($b->get_error_code() === 'busy') brb_admin_new_reject($post, $r);
        brb_admin_back(0, $b->get_error_message(), 'brb-new');
    }
    if ($take) {
        $pay = brb_record_payment(
            $b->id,
            $post['amount'] ?? '',
            $post['check_number'] ?? '',
            sanitize_text_field($post['received_on'] ?? ''),
            $post['pay_notes'] ?? '',
            true
        );
        if (is_wp_error($pay)) {
            brb_send_request_emails(brb_booking($b->id));
            brb_admin_back($b->id, 'Request saved as pending review, but the check was not recorded: ' . $pay->get_error_message());
        }
        brb_admin_back($pay->id, brb_ref($pay) . ' saved (' . brb_source_label($source) . '). ' . brb_status_label($pay->status) . '. The guest was emailed.');
    }
    brb_admin_back($b->id, brb_ref($b) . ' saved as pending review (' . brb_source_label($source) . '). The date is not held. The guest was emailed.');
});

function brb_admin_router() {
    if (!empty($_GET['booking'])) brb_admin_detail((int) $_GET['booking']);
    else brb_admin_list();
}
function brb_admin_list_kind() {
    $k = sanitize_key($_GET['entries'] ?? '');
    return in_array($k, ['bookings', 'calendar'], true) ? $k : 'all';
}
function brb_admin_list_calendar_source($type) {
    if ($type === 'reserved') return 'Calendar - Reserved';
    if ($type === 'hold') return 'Calendar - Hold';
    return 'Calendar - Date block';
}
function brb_admin_list_active_bookings() {
    global $wpdb;
    return $wpdb->get_results('SELECT id, start_dt, end_dt, status FROM ' . brb_table() . " WHERE status NOT IN ('cancelled','declined','expired','spam')");
}
function brb_admin_list_range_overlaps($start, $end, $rows) {
    foreach ((array) $rows as $row) {
        if ($row->start_dt < $end && $row->end_dt > $start) return true;
    }
    return false;
}
function brb_admin_list_cover_dates(&$covered, $start_dt, $end_dt) {
    $s = (new DateTimeImmutable($start_dt, brb_tz()))->setTime(0, 0);
    $e = new DateTimeImmutable($end_dt, brb_tz());
    for ($d = $s; $d < $e; $d = $d->modify('+1 day')) $covered[$d->format('Y-m-d')] = true;
    if ($e->format('H:i') !== '00:00') $covered[$e->format('Y-m-d')] = true;
}
function brb_admin_list_calendar_when($start_dt, $end_dt) {
    $s = new DateTimeImmutable($start_dt, brb_tz());
    $e = new DateTimeImmutable($end_dt, brb_tz());
    $all_day = $s->format('H:i') === '00:00' && in_array($e->format('H:i'), ['00:00', '23:59'], true);
    return brb_when((object) [
        'start_dt' => $start_dt,
        'end_dt' => $end_dt,
        'mode' => $all_day ? 'day' : 'hourly',
    ]);
}
function brb_admin_list_calendar_items($q = '') {
    global $wpdb;
    $tz = brb_tz();
    $today = brb_now()->setTime(0, 0);
    $from = $today->format('Y-m-d H:i:s');
    $q = trim((string) $q);
    $active = brb_admin_list_active_bookings();
    $items = [];
    $covered = [];
    $holds = $wpdb->get_results($wpdb->prepare(
        'SELECT id, name, hold_type, start_dt, end_dt FROM ' . brb_hold_table() . ' WHERE end_dt > %s ORDER BY start_dt ASC, id ASC',
        $from
    ));
    foreach ((array) $holds as $h) {
        brb_admin_list_cover_dates($covered, $h->start_dt, $h->end_dt);
        if (brb_admin_list_range_overlaps($h->start_dt, $h->end_dt, $active)) continue;
        $type = isset(brb_hold_types()[$h->hold_type]) ? $h->hold_type : 'blocked';
        $is_block = $type === 'blocked';
        if ($q !== '') {
            if ($is_block) {
                if (stripos('Date block', $q) === false) continue;
            } elseif (stripos((string) $h->name, $q) === false) {
                continue;
            }
        }
        $s = new DateTimeImmutable($h->start_dt, $tz);
        $items[] = [
            'kind' => 'calendar',
            'id' => 0,
            'start_dt' => $h->start_dt,
            'end_dt' => $h->end_dt,
            'name' => $is_block ? 'Date block' : (string) $h->name,
            'hide_name' => $is_block,
            'source' => brb_admin_list_calendar_source($type),
            'status' => $is_block ? 'Date block' : (string) (brb_hold_types()[$type] ?? 'Date block'),
            'href' => brb_cal_url(['m' => $s->format('Y-m'), 'hold' => (int) $h->id, 'date' => $s->format('Y-m-d')]) . '#brb-hold-form',
            'total' => null,
            'amount_paid' => null,
        ];
    }
    foreach (brb_blackouts() as $ymd) {
        if ($ymd < $today->format('Y-m-d')) continue;
        if (!empty($covered[$ymd])) continue;
        $start = $ymd . ' 00:00:00';
        $end = (new DateTimeImmutable($ymd . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d H:i:s');
        if (brb_admin_list_range_overlaps($start, $end, $active)) continue;
        if ($q !== '' && stripos('Date block', $q) === false) continue;
        $items[] = [
            'kind' => 'calendar',
            'id' => 0,
            'start_dt' => $start,
            'end_dt' => $ymd . ' 23:59:00',
            'name' => 'Date block',
            'hide_name' => true,
            'source' => brb_admin_list_calendar_source('blocked'),
            'status' => 'Date block',
            'href' => brb_cal_url(['m' => substr($ymd, 0, 7), 'blackout' => $ymd, 'date' => $ymd]) . '#brb-hold-form',
            'total' => null,
            'amount_paid' => null,
        ];
    }
    return $items;
}
function brb_admin_list_query($extra = []) {
    $args = ['page' => 'brb'];
    foreach (['status', 'q', 'orderby', 'order', 'paged', 'entries'] as $key) {
        if (!isset($extra[$key])) continue;
        if ($extra[$key] === '' || $extra[$key] === null) continue;
        $args[$key] = $extra[$key];
    }
    return admin_url('admin.php?' . http_build_query($args));
}
function brb_admin_list() {
    global $wpdb;
    $allowed = ['review', 'approved', 'deposit_paid', 'paid', 'declined', 'cancelled', 'expired', 'held', 'card_review', 'spam'];
    $f = sanitize_key($_GET['status'] ?? '');
    if (!in_array($f, $allowed, true)) $f = '';
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $kind = brb_admin_list_kind();
    $orderby = sanitize_key($_GET['orderby'] ?? 'when');
    $order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $per = 50;
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $sorts = ['id' => 'id', 'when' => 'start_dt', 'guest' => 'name', 'email' => 'email', 'source' => 'source', 'total' => 'total', 'paid' => 'amount_paid', 'status' => 'status'];
    $col = $sorts[$orderby] ?? 'start_dt';
    $dir = $order === 'asc' ? 'ASC' : 'DESC';
    $where = '1=1';
    $params = [];
    if ($f === 'card_review') {
        $where .= ' AND attention=%s AND status<>%s';
        $params[] = brb_stripe_review_mark();
        $params[] = 'spam';
    } elseif ($f === 'spam') {
        $where .= ' AND status=%s';
        $params[] = 'spam';
    } elseif ($f !== '') {
        $where .= ' AND status=%s';
        $params[] = $f;
    } else {
        $where .= " AND status<>'spam'";
    }
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= ' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s';
        array_push($params, $like, $like, $like);
        $ref_id = brb_ref_id($q);
        if ($ref_id <= 0 && ctype_digit($q)) $ref_id = (int) $q;
        if ($ref_id > 0) {
            $where .= ' OR id=%d';
            $params[] = $ref_id;
        }
        $where .= ')';
    }
    $count_sql = 'SELECT COUNT(*) FROM ' . brb_table() . " WHERE $where";
    $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
    $pages = max(1, (int) ceil($total / $per));
    if ($paged > $pages) $paged = $pages;
    $offset = ($paged - 1) * $per;
    $sql = 'SELECT * FROM ' . brb_table() . " WHERE $where ORDER BY $col $dir, id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per, $offset])));
    $dup_sql = 'SELECT LOWER(email) ek, DATE(start_dt) dk, GROUP_CONCAT(id ORDER BY id) ids FROM ' . brb_table() . " WHERE $where AND email<>'' GROUP BY ek, dk HAVING COUNT(*)>1";
    $dup_rows = $params ? $wpdb->get_results($wpdb->prepare($dup_sql, $params)) : $wpdb->get_results($dup_sql);
    $dup_map = [];
    foreach ((array) $dup_rows as $dup) {
        $ids = array_map('intval', explode(',', (string) $dup->ids));
        foreach ($ids as $id) $dup_map[$id] = $ids;
    }
    $base_args = ['status' => $f, 'q' => $q, 'orderby' => $orderby, 'order' => $order, 'entries' => $kind === 'all' ? '' : $kind];
    $calendar_items = [];
    if ($kind === 'calendar' || ($kind === 'all' && $f === '')) {
        $calendar_items = brb_admin_list_calendar_items($q);
    }
    $sort_link = function ($key, $label) use ($base_args, $orderby, $order) {
        $next = ($orderby === $key && $order === 'asc') ? 'desc' : 'asc';
        $url = brb_admin_list_query($base_args + ['orderby' => $key, 'order' => $next, 'paged' => 1]);
        $mark = $orderby === $key ? ($order === 'asc' ? ' ↑' : ' ↓') : '';
        return '<a href="' . esc_url($url) . '">' . esc_html($label . $mark) . '</a>';
    };
    echo '<div class="wrap"><h1 class="wp-heading-inline">Bookings</h1> <a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=brb-new')) . '">Add booking</a>';
    brb_admin_note();
    echo '<form method="get" class="brb-booking-search" style="margin-top:12px">';
    echo '<input type="hidden" name="page" value="brb">';
    if ($f !== '') echo '<input type="hidden" name="status" value="' . esc_attr($f) . '">';
    if ($kind !== 'all') echo '<input type="hidden" name="entries" value="' . esc_attr($kind) . '">';
    echo '<label for="brb-q">Search <input id="brb-q" name="q" value="' . esc_attr($q) . '" placeholder="Name, email, phone, BR-#, or calendar name"></label> ';
    echo '<button class="button">Search</button></form>';
    echo '<p style="margin-top:12px">';
    foreach (['all' => 'All', 'bookings' => 'Bookings only', 'calendar' => 'Calendar only'] as $k => $l) {
        $url = brb_admin_list_query(['entries' => $k === 'all' ? '' : $k, 'status' => $kind === 'calendar' ? '' : $f, 'q' => $q, 'orderby' => $orderby, 'order' => $order]);
        printf('<a class="button%s" href="%s">%s</a> ', $kind === $k ? ' button-primary' : '', esc_url($url), esc_html($l));
    }
    echo '</p>';
    echo '<p>';
    $filters = ['' => 'All statuses', 'review' => 'Pending review', 'approved' => 'Approved, deposit due', 'deposit_paid' => 'Deposit paid', 'paid' => 'Paid in full', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'spam' => 'Flagged as spam/joke', 'card_review' => 'Needs staff review'];
    foreach ($filters as $k => $l) {
        $url = brb_admin_list_query(['status' => $k, 'entries' => $kind === 'all' ? '' : $kind, 'q' => $q, 'orderby' => $orderby, 'order' => $order]);
        printf('<a class="button%s" href="%s">%s</a> ', $f === $k ? ' button-primary' : '', esc_url($url), esc_html($l));
    }
    echo '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="brb-bulk">';
    echo '<input type="hidden" name="action" value="brb_bulk_delete">';
    echo '<input type="hidden" name="status" value="' . esc_attr($f) . '">';
    echo '<input type="hidden" name="q" value="' . esc_attr($q) . '">';
    echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '">';
    echo '<input type="hidden" name="order" value="' . esc_attr($order) . '">';
    echo '<input type="hidden" name="paged" value="' . (int) $paged . '">';
    if ($kind !== 'all') echo '<input type="hidden" name="entries" value="' . esc_attr($kind) . '">';
    echo '<input type="hidden" name="bulk_action" value="">';
    wp_nonce_field('brb_bulk_delete');
    $nav = function ($which) {
        echo '<div class="tablenav ' . esc_attr($which) . '"><div class="alignleft actions bulkactions">';
        echo '<label for="brb-bulk-action-' . esc_attr($which) . '" class="screen-reader-text">Select bulk action</label>';
        echo '<select id="brb-bulk-action-' . esc_attr($which) . '" class="brb-bulk-action">';
        echo '<option value="">Bulk actions</option><option value="delete">Delete selected</option></select> ';
        echo '<button type="submit" class="button action brb-bulk-apply" disabled>Apply</button>';
        echo '</div></div>';
    };
    $nav('top');
    $head = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" class="brb-select-all" aria-label="Select all bookings"></td><th>' . $sort_link('id', '#') . '</th><th>' . $sort_link('when', 'When') . '</th><th>' . $sort_link('guest', 'Guest') . '</th><th>' . $sort_link('source', 'Source') . '</th><th>' . $sort_link('total', 'Total') . '</th><th>' . $sort_link('paid', 'Paid') . '</th><th>Balance</th><th>' . $sort_link('status', 'Status') . '</th><th>Reserved</th><th></th></tr>';
    echo '<table class="widefat"><thead>' . $head . '</thead><tbody>';
    $display = [];
    if ($kind !== 'calendar') {
        foreach ($rows as $b) {
            $display[] = [
                'kind' => 'booking',
                'row' => $b,
                'id' => (int) $b->id,
                'start_dt' => $b->start_dt,
                'name' => (string) $b->name,
                'source' => brb_source_label($b->source),
                'status' => (string) $b->status,
                'total' => (float) $b->total,
                'amount_paid' => (float) $b->amount_paid,
            ];
        }
    }
    foreach ($calendar_items as $item) $display[] = $item;
    $dir = $order === 'asc' ? 1 : -1;
    $keyed = [];
    foreach ($display as $i => $item) {
        if ($orderby === 'id') $key = (int) ($item['id'] ?? 0);
        elseif ($orderby === 'guest') $key = strtolower((string) ($item['name'] ?? ''));
        elseif ($orderby === 'source') $key = strtolower((string) ($item['source'] ?? ''));
        elseif ($orderby === 'status') $key = strtolower((string) ($item['status'] ?? ''));
        elseif ($orderby === 'total') $key = $item['total'];
        elseif ($orderby === 'paid') $key = $item['amount_paid'];
        else $key = (string) ($item['start_dt'] ?? '');
        $keyed[] = [$item, $key, $i];
    }
    usort($keyed, function ($a, $b) use ($dir, $orderby) {
        $money = in_array($orderby, ['total', 'paid', 'id'], true);
        $cal_a = $a[0]['kind'] === 'calendar' && $money;
        $cal_b = $b[0]['kind'] === 'calendar' && $money;
        if ($cal_a && $cal_b) return $a[2] <=> $b[2];
        if ($cal_a) return 1;
        if ($cal_b) return -1;
        if ($a[1] === $b[1]) return $a[2] <=> $b[2];
        return ($a[1] <=> $b[1]) * $dir;
    });
    $display = array_map(function ($row) { return $row[0]; }, $keyed);
    if (!$display) echo '<tr><td colspan="11">' . esc_html($kind === 'calendar' ? 'No calendar entries.' : 'No bookings yet.') . '</td></tr>';
    foreach ($display as $item) {
        if (($item['kind'] ?? '') === 'calendar') {
            $guest = !empty($item['hide_name']) ? 'Date block' : (string) $item['name'];
            printf(
                '<tr class="brb-cal-list-row"><th scope="row" class="check-column"></th><td>—</td><td><a href="%s">%s</a></td><td><a href="%s">%s</a></td><td>%s</td><td>—</td><td>—</td><td>—</td><td><span class="brb-badge">%s</span></td><td>Yes</td><td></td></tr>',
                esc_url($item['href']),
                esc_html(brb_admin_list_calendar_when($item['start_dt'], $item['end_dt'])),
                esc_url($item['href']),
                esc_html($guest),
                esc_html($item['source']),
                esc_html($item['status'])
            );
            continue;
        }
        $b = $item['row'];
        $set = brb_price_is_set($b);
        $balance = $set ? max(0, round((float) $b->total - (float) $b->amount_paid, 2)) : 0;
        if (in_array($b->status, ['deposit_paid', 'paid', 'confirmed'], true)) $until = 'Yes';
        elseif (in_array($b->status, ['approved', 'held'], true)) $until = 'Held';
        else $until = 'No';
        $guest_email = brb_email_customer_anchor($b, (string) $b->email);
        if ($guest_email === '') $guest_email = esc_html($b->email);
        $dup_note = '';
        if (!empty($dup_map[(int) $b->id])) {
            $others = [];
            foreach ($dup_map[(int) $b->id] as $other) {
                if ($other === (int) $b->id) continue;
                $others[] = brb_ref((object) ['id' => $other]);
            }
            if ($others) $dup_note = '<br><strong class="brb-dup">Same guest and day as ' . esc_html(implode(', ', $others)) . '</strong>';
        }
        if ((string) ($b->attention ?? '') === brb_stripe_review_mark()) {
            $dup_note .= '<br><strong class="brb-review">Needs staff review — payment received for an unavailable date</strong>';
        }
        printf(
            '<tr><th scope="row" class="check-column"><input type="checkbox" class="brb-row-check" name="booking_ids[]" value="%d" aria-label="%s"></th><td>%s</td><td>%s</td><td>%s<br><small>%s %s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="brb-badge %s">%s</span></td><td>%s</td><td><a class="button button-small" href="%s">Open</a></td></tr>',
            (int) $b->id,
            esc_attr('Select ' . brb_ref($b)),
            esc_html(brb_ref($b)),
            esc_html(brb_when($b)),
            esc_html($b->name) . $dup_note,
            $guest_email,
            esc_html($b->phone),
            esc_html(brb_source_label($b->source)),
            esc_html($set ? brb_money($b->total) : 'Price to be confirmed'),
            esc_html(brb_money($b->amount_paid)),
            esc_html($set ? brb_money($balance) : 'Price to be confirmed'),
            esc_attr($b->status),
            esc_html(brb_status_label($b->status)),
            esc_html($until),
            esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id))
        );
    }
    echo '</tbody><tfoot>' . $head . '</tfoot></table>';
    if ($pages > 1) {
        echo '<p class="brb-pager">';
        echo esc_html($total . ' bookings. Page ' . $paged . ' of ' . $pages . '. ');
        if ($paged > 1) echo '<a class="button" href="' . esc_url(brb_admin_list_query($base_args + ['paged' => $paged - 1])) . '">Previous</a> ';
        if ($paged < $pages) echo '<a class="button" href="' . esc_url(brb_admin_list_query($base_args + ['paged' => $paged + 1])) . '">Next</a>';
        echo '</p>';
    }
    $nav('bottom');
    echo '</form><script>
(function(){
  var form = document.getElementById("brb-bulk");
  if (!form) return;
  var rows = function(){ return form.querySelectorAll(".brb-row-check"); };
  var alls = form.querySelectorAll(".brb-select-all");
  var applies = form.querySelectorAll(".brb-bulk-apply");
  var chosen = "";
  function count(){ return form.querySelectorAll(".brb-row-check:checked").length; }
  function sync(){
    var n = count(), total = rows().length;
    alls.forEach(function(box){ box.checked = total > 0 && n === total; box.indeterminate = n > 0 && n < total; });
    applies.forEach(function(btn){ btn.disabled = n === 0; });
  }
  alls.forEach(function(box){
    box.addEventListener("change", function(){
      rows().forEach(function(row){ row.checked = box.checked; });
      sync();
    });
  });
  rows().forEach(function(row){ row.addEventListener("change", sync); });
  applies.forEach(function(btn){
    btn.addEventListener("click", function(){
      var nav = btn.closest(".tablenav");
      var select = nav ? nav.querySelector(".brb-bulk-action") : null;
      chosen = select ? select.value : "";
    });
  });
  form.addEventListener("submit", function(e){
    var n = count();
    form.querySelector("[name=bulk_action]").value = chosen;
    if (chosen !== "delete" || !n) {
      e.preventDefault();
      window.alert(n ? "Choose Delete selected, then Apply." : "No bookings selected.");
      return;
    }
    if (!window.confirm(n + " bookings will be permanently deleted.")) e.preventDefault();
  });
  sync();
})();
</script></div>';
}
function brb_admin_card_payment($b) {
    $link = brb_pay_link($b);
    $blockers = brb_pay_link_blockers($b);
    echo '<h2>Card payment (Stripe)</h2>';
    echo '<p>' . esc_html('The pay link is part of the Approve email preview and is sent only if staff choose Send. Use this button to resend it.') . '</p>';
    if ($link !== '') {
        $due = brb_amount_due_now($b);
        $base_cents = brb_cents_from_amount($due);
        $fee_cents = brb_card_fee_cents($base_cents);
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Amount due now</th><td>' . esc_html(brb_money($due)) . '</td></tr>';
        echo '<tr><th>Card fee (' . esc_html(brb_fee_percent_label()) . ')</th><td>' . esc_html(brb_money($fee_cents / 100)) . '</td></tr>';
        echo '<tr><th>Card total</th><td>' . esc_html(brb_money(($base_cents + $fee_cents) / 100)) . '</td></tr>';
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px">';
        echo '<input type="hidden" name="action" value="brb_send_pay_link"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_send_pay_link');
        echo '<p><button class="button button-primary">Send payment link</button></p>';
        echo '<p><label for="brb-pay-link">Payment link<br><input id="brb-pay-link" class="large-text" type="text" readonly value="' . esc_attr($link) . '"></label></p>';
        echo '</form>';
        return;
    }
    echo '<p><button class="button" type="button" disabled>Send payment link</button></p>';
    echo '<ul>';
    foreach (brb_pay_link_checklist() as $key => $label) {
        $met = !isset($blockers[$key]);
        echo '<li>' . ($met ? '✓' : '✗') . ' ' . esc_html($label);
        if (!$met && $key === 'stripe') {
            echo ' <span class="description">' . esc_html('Add BRB_STRIPE_SECRET_KEY on the server.') . ' <a href="' . esc_url(admin_url('admin.php?page=brb-settings')) . '">Booking Settings</a></span>';
        }
        if (!$met && $key === 'approved' && in_array((string) $b->status, ['review', 'pending'], true)) {
            echo ' <a class="button button-primary" href="#brb-approve">Approve</a>';
        }
        echo '</li>';
    }
    echo '</ul>';
}
function brb_admin_detail($id) {
    $b = brb_booking($id);
    echo '<div class="wrap"><h1>Booking</h1>';
    brb_admin_note();
    if (!$b) {
        echo '<p>Booking not found.</p></div>';
        return;
    }
    $set = brb_price_is_set($b);
    $balance = $set ? max(0, round((float) $b->total - (float) $b->amount_paid, 2)) : 0;
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=brb')) . '">← All bookings</a></p>';
    echo '<h2>' . esc_html(brb_ref($b)) . ' <span class="brb-badge ' . esc_attr($b->status) . '">' . esc_html(brb_status_label($b->status)) . '</span></h2>';
    echo '<table class="widefat" style="max-width:760px"><tbody>';
    $rows = [
        'When' => brb_when($b),
        'Estimated Guests' => (string) (int) $b->guests,
        'Free admission' => (($b->free_admission ?? '') === '1') ? 'Acknowledged' : '—',
        'About the event' => ($b->event_about ?? '') !== '' ? $b->event_about : '—',
    ];
    $saved_type = brb_event_type_label($b->event_type ?? '');
    if ($saved_type !== '') $rows['Event type'] = $saved_type;
    $saved_alcohol = brb_yes_no_label($b->alcohol ?? '');
    if ($saved_alcohol !== '') $rows['Alcohol served'] = $saved_alcohol;
    if (($b->public_event ?? '') === 'no') $rows['Private, invitation-only'] = 'Yes';
    elseif (($b->public_event ?? '') === 'yes') $rows['Private, invitation-only'] = 'No, it is open to the public';
    if (trim((string) ($b->end_time ?? '')) !== '') $rows['Planned end time'] = brb_end_time_label($b->end_time);
    $saved_age = brb_host_age_label($b->host_age ?? '');
    if ($saved_age !== '') $rows['Host age bracket'] = $saved_age;
    $rows += [
        'Source' => brb_source_label($b->source),
        'Name' => $b->name,
        'Email' => $b->email,
        'Phone' => $b->phone,
        'Notes' => $b->notes,
    ];
    if ($set && ((float) $b->cleaning > 0.009 || (float) $b->tax > 0.009)) {
        $rows['Subtotal'] = brb_money($b->subtotal);
        $rows['Cleaning'] = brb_money($b->cleaning);
        $rows['Tax'] = brb_money($b->tax);
    }
    $rows['Walk-through'] = !empty($b->walkthrough_done) ? 'Completed' : 'Not yet';
    $rows['Setup day'] = !empty($b->setup_addon) ? 'Added' : 'No';
    $rows['Takedown day'] = !empty($b->takedown_addon) ? 'Added' : 'No';
    $rows['Date hold'] = brb_date_hold_state($b);
    foreach ($rows as $label => $val) {
        echo '<tr><th style="width:280px">' . esc_html($label) . '</th><td>' . nl2br(esc_html((string) $val));
        if ($label === 'Email') {
            $mail_link = brb_email_customer_anchor($b, 'Email customer', 'button');
            if ($mail_link !== '') echo ' ' . $mail_link;
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    $money = brb_booking_money($b);
    echo '<h2>Money</h2><table class="widefat" style="max-width:760px"><tbody>';
    foreach ($money['rows'] as $label => $val) {
        echo '<tr><th style="width:280px">' . esc_html($label) . '</th><td>' . esc_html((string) $val) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<h2>Edit history</h2>';
    $history = brb_audit_entries($b->audit_log ?? '');
    if (!$history) {
        echo '<p>No edits yet.</p>';
    } else {
        echo '<ol class="brb-audit">';
        foreach ($history as $line) echo '<li>' . esc_html($line) . '</li>';
        echo '</ol>';
    }
    $edit_date = substr((string) $b->start_dt, 0, 10);
    if (empty($b->walkthrough_done)) {
        echo '<div class="notice notice-warning"><p>The in-person walk-through is not marked complete. Approval and the payment link stay off until it is checked.</p></div>';
    }
    echo '<h2>Save changes</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px">';
    echo '<input type="hidden" name="action" value="brb_save_changes"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
    wp_nonce_field('brb_save_changes');
    echo '<table class="form-table">';
    echo '<tr><th><label for="brb-edit-date">Date</label></th><td><input type="date" id="brb-edit-date" name="date" required value="' . esc_attr($edit_date) . '"></td></tr>';
    echo '<tr><th><label for="brb-edit-name">Name</label></th><td><input id="brb-edit-name" name="name" class="regular-text" required value="' . esc_attr($b->name) . '"></td></tr>';
    echo '<tr><th><label for="brb-edit-email">Email</label></th><td><input type="email" id="brb-edit-email" name="email" class="regular-text" required value="' . esc_attr($b->email) . '"></td></tr>';
    echo '<tr><th><label for="brb-edit-phone">Phone</label></th><td><input id="brb-edit-phone" name="phone" class="regular-text" value="' . esc_attr($b->phone) . '"></td></tr>';
    echo '<tr><th><label for="brb-edit-notes">Notes</label></th><td><textarea id="brb-edit-notes" name="notes" rows="4" class="large-text">' . esc_textarea($b->notes) . '</textarea></td></tr>';
    echo '</table>';
    echo '<h2>Walk-through, deposit, and add-ons</h2>';
    echo '<p><label><input type="checkbox" name="walkthrough_done" value="1"' . (!empty($b->walkthrough_done) ? ' checked' : '') . '> Walk-through completed</label></p>';
    echo '<p>The refundable damage deposit is a flat ' . esc_html(brb_money(brb_damage_amount())) . '. It is charged with the booking deposit and is separate from the 50% that holds the date.</p>';
    echo '<p><label><input type="checkbox" name="setup_addon" value="1"' . (!empty($b->setup_addon) ? ' checked' : '') . '> Setup day</label></p>';
    echo '<p><label><input type="checkbox" name="takedown_addon" value="1"' . (!empty($b->takedown_addon) ? ' checked' : '') . '> Takedown day</label></p>';
    if ($b->status !== 'cancelled') {
        echo '<h2>Agreed price</h2>';
        $rental_line = brb_find_line_kind($b->id, 'rental');
        $suggest = $rental_line ? brb_dec($rental_line->amount) : ($set ? brb_dec($b->total) : '');
        echo '<table class="form-table"><tr><th><label for="agreed_price">Rental price</label></th><td><input name="agreed_price" id="agreed_price" type="number" step="0.01" min="0.01" value="' . esc_attr($suggest) . '"> <span class="description">This sets the Rental line only. Extras and discounts below are added on top. Saving does not email the guest.</span></td></tr></table>';
    }
    echo '<p><button class="button button-primary">Save changes</button></p>';
    echo '<p class="description">Save changes stores the booking, the walk-through, add-ons, and the rental price. It never emails the guest.</p></form>';
    if (($b->public_event ?? '') === 'yes') {
        echo '<div class="notice notice-warning"><p>Open to the public. Staff review: all events must be free to attend. The Boardroom does not host concerts or ticketed shows.</p></div>';
    }
    if (!empty($b->attention)) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html($b->attention) . '</strong></p>';
        if ((string) $b->attention === brb_stripe_review_mark()) {
            $review_pay = brb_stripe_review_rows((int) $b->id);
            $review_pay = $review_pay ? $review_pay[0] : null;
            echo '<p>' . esc_html(brb_stripe_review_steps()) . '</p>';
            if ($review_pay) {
                $review_url = brb_stripe_dashboard_url($review_pay->stripe_id, $review_pay->stripe_session);
                echo '<p><a href="' . esc_url($review_url) . '" target="_blank" rel="noopener">Open this payment in the Stripe dashboard</a></p>';
            }
        }
        echo '</div>';
    }
    $card_rows = array_filter(brb_payments($b->id), function ($p) {
        return ($p->method ?? '') === 'card';
    });
    if ($card_rows || !empty($b->attention)) {
        echo '<p>The deposit follows the 60-day written cancellation rule. The card processing fee is non-refundable. Card payments and refunds are resolved manually in the Stripe dashboard.</p>';
    }

    $charge_lines = brb_lines($b->id);
    $coverage = brb_line_coverage($b);
    $running = brb_nonrefundable_total($b->id);
    echo '<h2>Charges</h2>';
    echo '<table class="widefat" style="max-width:960px"><thead><tr><th>Label</th><th>Amount</th><th>Kind</th><th></th></tr></thead><tbody>';
    if (!$charge_lines) echo '<tr><td colspan="4">No charges yet.</td></tr>';
    foreach ($charge_lines as $line) {
        echo '<tr><td>';
        if (empty($line->locked) && in_array((string) $line->kind, ['fee', 'discount'], true)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="brb_line"><input type="hidden" name="booking" value="' . (int) $b->id . '"><input type="hidden" name="line_id" value="' . (int) $line->id . '">';
            wp_nonce_field('brb_line');
            echo '<input name="label" value="' . esc_attr($line->label) . '" required> ';
            echo '<input name="amount" type="number" step="0.01" value="' . esc_attr(brb_dec($line->amount)) . '" required> ';
            echo '<button class="button">Save</button> <button class="button" name="remove" value="1">Remove</button></form>';
        } else {
            $state = brb_line_state_text($coverage[(int) $line->id]['paid'] ?? 0, $coverage[(int) $line->id]['amount'] ?? $line->amount);
            echo esc_html($line->label) . ($state !== '' ? ' <em>(' . esc_html($state) . ')</em>' : '');
        }
        echo '</td><td>' . esc_html(brb_money($line->amount)) . '</td><td>' . esc_html($line->kind) . (!empty($line->refundable) ? ', refundable' : '') . '</td><td></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p>Guest total: <strong>' . esc_html(brb_money($running)) . '</strong>. Refundable damage deposit: <strong>' . esc_html(brb_money(brb_booking_damage_figure($b))) . '</strong>.</p>';
    echo '<h2>Extras and discounts (optional)</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px">';
    echo '<input type="hidden" name="action" value="brb_line"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
    wp_nonce_field('brb_line');
    echo '<table class="form-table"><tr><th><label for="brb-extra-label">Label</label></th><td><input id="brb-extra-label" name="label" class="regular-text" placeholder="e.g. Extra cleaning or Returning guest discount" required></td></tr>';
    echo '<tr><th><label for="brb-extra-amount">Amount</label></th><td><input id="brb-extra-amount" name="amount" type="number" step="0.01" style="width:16em" placeholder="e.g. 150 or -100" required></td></tr></table>';
    echo '<p><button class="button">Add to total</button></p>';
    echo '<p class="description">Optional. Adds a line to the total. Use a minus sign for a discount. The 50% deposit and payment link use the new total. Adding a line does not email the guest. Paid lines can\'t be edited, so add a credit instead.</p></form>';

    if ($set && $b->status !== 'cancelled') {
        $price_body = brb_price_guest_body($b);
        echo '<details id="brb-price-email" style="max-width:720px;margin:16px 0"><summary class="button">Email guest the total</summary>';
        echo '<form class="brb-email-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="brb_price_email"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_price_email');
        brb_admin_email_fields('brb-price', 'This is the email the guest would receive for the saved price. You can edit it before sending. Nothing is sent until you choose Send.', brb_price_guest_subject($b), $price_body);
        echo '<p><button class="button button-primary" name="email_guest" value="1">Send</button> ';
        echo '<button class="button" name="email_guest" value="0">Don\'t email</button></p></form></details>';
    }

    if (!in_array($b->status, ['cancelled', 'declined', 'expired', 'paid'], true)) brb_admin_card_payment($b);

    if (!$set && !in_array($b->status, ['cancelled', 'expired', 'paid'], true)) {
        echo '<h2>Record payment</h2><p>Set the agreed price before recording a payment.</p>';
    }
    $damage_left_now = brb_damage_left($b);
    if ($set && !in_array($b->status, ['cancelled', 'expired', 'paid'], true) && ($balance > 0.009 || $damage_left_now > 0.009)) {
        echo '<h2>Record payment</h2><form id="brb-payment-form" class="brb-email-editor" data-brb-preview="brb_payment_preview" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px">';
        echo '<input type="hidden" name="action" value="brb_payment"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_payment');
        $toward = round((float) $b->deposit_due - (float) $b->amount_paid, 2);
        $rental_due = ($toward > 0.009) ? $toward : brb_rental_left($b);
        $bundle = round($rental_due + $damage_left_now, 2);
        $cap = round(brb_rental_left($b) + $damage_left_now, 2);
        echo '<table class="form-table"><tr><th><label for="amount">Amount</label></th><td><input name="amount" id="amount" type="number" step="0.01" min="0.01" max="' . esc_attr(brb_dec($cap)) . '" value="' . esc_attr(brb_dec($bundle)) . '" required> <span class="description">Suggested amount includes any unpaid refundable damage deposit. On the balance, that deposit is applied first, then the rental balance. Rental balance is ' . esc_html(brb_money($balance)) . '.</span></td></tr>';
        echo '<tr><th><label for="method">Method</label></th><td><select name="method" id="method">';
        foreach (brb_pay_methods() as $key => $label) {
            printf('<option value="%s">%s</option>', esc_attr($key), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label for="check_number">Check number</label></th><td><input name="check_number" id="check_number" class="regular-text"> <span class="description">Only for a check. Leave this blank for cash.</span></td></tr>';
        echo '<tr><th><label for="received_on">Date received</label></th><td><input name="received_on" id="received_on" type="date" value="' . esc_attr(brb_now()->format('Y-m-d')) . '" required></td></tr>';
        echo '<tr><th><label for="pay_notes">Notes</label></th><td><textarea name="pay_notes" id="pay_notes" rows="3" class="large-text"></textarea></td></tr></table>';
        $pay_preview = brb_preview_payment_message($b, $bundle, '', brb_now()->format('Y-m-d'), '', 'check');
        $pay_preview_body = is_wp_error($pay_preview) ? $pay_preview->get_error_message() : $pay_preview['body'];
        $pay_preview_subject = is_wp_error($pay_preview) ? '' : $pay_preview['subject'];
        brb_admin_email_fields('brb-payment', 'This is the receipt for the amount, method, and notes above. You can edit it before sending. Nothing is recorded or emailed until you choose Send or Don\'t email.', $pay_preview_subject, $pay_preview_body);
        echo '<p><button class="button button-primary" name="email_guest" value="1">Send</button> ';
        echo '<button class="button" name="email_guest" value="0">Don\'t email</button></p></form>';
    }

    echo '<h2>Actions</h2><div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">';
    if (in_array($b->status, ['review', 'pending'], true)) {
        echo '<details id="brb-approve"><summary class="button button-primary">Approve</summary>';
        echo '<form class="brb-email-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px;margin-top:12px">';
        echo '<input type="hidden" name="action" value="brb_approve"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_approve');
        brb_admin_email_fields('brb-approve', 'This is the approval email. You can edit it before sending. Nothing is approved or emailed until you choose Send or Don\'t email.', brb_approve_guest_subject($b), brb_approve_preview_body($b));
        echo '<p><button class="button button-primary" name="email_guest" value="1">Send</button> ';
        echo '<button class="button" name="email_guest" value="0">Don\'t email</button></p></form></details>';
    }
    if (in_array($b->status, ['review', 'approved', 'pending', 'held', 'deposit_paid'], true)) {
        $free_from = brb_now()->format('Y-m-d');
        $free_until = brb_now()->modify('+12 months')->format('Y-m-d');
        $own_day = substr((string) $b->start_dt, 0, 10);
        $free_days = array_values(array_filter(brb_free_full_days($free_from, $free_until, (int) $b->id), function ($ymd) use ($own_day) {
            return $ymd !== $own_day;
        }));
        echo '<details id="brb-decline"><summary class="button">Decline</summary>';
        echo '<form id="brb-decline-form" class="brb-email-editor" data-brb-preview="brb_decline_preview" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px;margin-top:12px">';
        echo '<input type="hidden" name="action" value="brb_decline"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_decline');
        echo '<p><label for="brb-decline-message">Personal message (optional)<br><textarea id="brb-decline-message" name="decline_message" rows="4" class="large-text" maxlength="2000"></textarea></label></p>';
        echo '<p>Suggest up to 3 dates. The list only includes dates that are free on the calendar.</p>';
        for ($i = 1; $i <= 3; $i++) {
            echo '<p><label for="brb-alt-date-' . $i . '">Suggested date ' . $i . '<br>';
            echo '<select id="brb-alt-date-' . $i . '" name="alt_date_' . $i . '">';
            echo '<option value="">None</option>';
            foreach ($free_days as $ymd) {
                echo '<option value="' . esc_attr($ymd) . '">' . esc_html($ymd) . '</option>';
            }
            echo '</select></label></p>';
        }
        brb_admin_email_fields('brb-decline', 'This is the email the guest would receive. You can edit it before sending. Nothing is sent until you choose Decline and send email.', brb_decline_guest_subject($b), brb_decline_guest_body($b));
        echo '<p><button class="button button-primary" name="email_guest" value="1">Decline and send email</button> ';
        echo '<button class="button" name="email_guest" value="0">Decline without emailing</button></p></form></details>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_spam"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_spam');
        echo '<button class="button">Flag as spam/joke</button></form>';
    }
    if ((float) ($b->damage_paid ?? 0) > 0.009 && empty($b->damage_refunded)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_refund_damage"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_refund_damage');
        echo '<button class="button">Mark damage deposit refunded</button></form>';
    }
    if ($set && !in_array($b->status, ['paid', 'cancelled', 'expired', 'declined'], true) && $balance > 0.009) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_mark_paid"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_mark_paid');
        echo '<button class="button">Mark paid in full</button></form>';
    }
    if ($b->status !== 'cancelled') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Cancel this booking and release the date?\');"><input type="hidden" name="action" value="brb_cancel"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_cancel');
        echo '<button class="button">Cancel booking</button></form>';
    }
    if (brb_booking_has_recorded_payment($b)) {
        echo '<p>This booking has a recorded payment. Cancel it instead of deleting it.</p>';
    } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_delete"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_delete');
        echo '<p><label>Type <strong>' . esc_html(brb_ref($b)) . '</strong> to confirm<br><input name="confirm_ref" autocomplete="off" required aria-label="' . esc_attr('Type ' . brb_ref($b) . ' to delete') . '"></label></p>';
        echo '<p>This cannot be undone.</p>';
        echo '<button class="button button-link-delete">Delete booking</button></form>';
    }
    echo '</div>';
    brb_admin_email_editor_script();

    $pays = brb_payments($b->id);
    echo '<h2>Payments recorded</h2>';
    if (!$pays) {
        echo '<p>No payments recorded yet.</p>';
    } else {
        echo '<table class="widefat" style="max-width:960px"><thead><tr><th>Date received</th><th>Amount</th><th>Method</th><th>Fee</th><th>Check number</th><th>Stripe payment</th><th>Notes</th></tr></thead><tbody>';
        foreach ($pays as $p) {
            $fee = (float) ($p->fee ?? 0);
            $stripe_id = (string) ($p->stripe_id ?? '');
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($p->received_on),
                esc_html(brb_money($p->amount)),
                esc_html(brb_pay_method_label($p->method ?? 'check')),
                esc_html($fee > 0.009 ? brb_money($fee) : '—'),
                esc_html($p->check_number),
                esc_html($stripe_id !== '' ? $stripe_id : '—'),
                esc_html($p->notes)
            );
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function brb_new_form_key() {
    return 'brb_new_form_' . get_current_user_id();
}
function brb_new_form_defaults() {
    return [
        'error' => '',
        'source' => 'phone',
        'date' => brb_now()->modify('+3 days')->format('Y-m-d'),
        'guests' => '10',
        'name' => '',
        'email' => '',
        'phone' => '',
        'notes' => '',
        'agreed_price' => '',
        'override_notice' => '',
        'take_check' => '',
        'amount' => '',
        'check_number' => '',
        'received_on' => brb_now()->format('Y-m-d'),
        'pay_notes' => '',
    ];
}
function brb_new_form_state_from_post($post, $error) {
    $state = brb_new_form_defaults();
    $state['error'] = (string) $error;
    $state['source'] = sanitize_text_field($post['source'] ?? 'phone');
    $state['date'] = sanitize_text_field($post['date'] ?? '');
    $state['guests'] = (string) max(0, (int) ($post['guests'] ?? 0));
    $state['name'] = sanitize_text_field($post['name'] ?? '');
    $state['email'] = sanitize_email($post['email'] ?? '');
    $state['phone'] = sanitize_text_field($post['phone'] ?? '');
    $state['notes'] = sanitize_textarea_field($post['notes'] ?? '');
    $state['agreed_price'] = sanitize_text_field($post['agreed_price'] ?? '');
    $state['override_notice'] = empty($post['override_notice']) ? '' : '1';
    $state['take_check'] = empty($post['take_check']) ? '' : '1';
    $state['amount'] = sanitize_text_field($post['amount'] ?? '');
    $state['check_number'] = sanitize_text_field($post['check_number'] ?? '');
    $state['received_on'] = sanitize_text_field($post['received_on'] ?? '');
    $state['pay_notes'] = sanitize_textarea_field($post['pay_notes'] ?? '');
    return $state;
}
function brb_admin_taken_message($r) {
    $holder = brb_blocking_booking_for_range($r['s'], $r['e']);
    $ref = $holder ? brb_ref($holder) : '';
    if ($ref !== '') return 'That date is already taken (' . $ref . '). No booking was created and no email was sent.';
    return 'That date is already taken. No booking was created and no email was sent.';
}
function brb_admin_new_reject($post, $r) {
    set_transient(brb_new_form_key(), brb_new_form_state_from_post($post, brb_admin_taken_message($r)), 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=brb-new'));
    exit;
}
function brb_admin_new_note_is_stale_success($note) {
    $note = (string) $note;
    return strpos($note, 'saved as pending review') !== false || strpos($note, 'The guest was emailed') !== false;
}
function brb_admin_new() {
    if (!current_user_can('manage_options')) return;
    $o = brb_opt();
    $state = get_transient(brb_new_form_key());
    if (!is_array($state)) $state = [];
    delete_transient(brb_new_form_key());
    $v = array_merge(brb_new_form_defaults(), $state);
    $error = (string) ($v['error'] ?? '');
    echo '<div class="wrap"><h1>Add booking</h1>';
    if ($error !== '') echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    $note = isset($_GET['brb_note']) ? (string) wp_unslash($_GET['brb_note']) : '';
    if (!($error !== '' && brb_admin_new_note_is_stale_success($note))) brb_admin_note();
    echo '<p>Use this for a phone call or a walk-in. A request does not hold the date. The date is reserved once a deposit is recorded.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px"><input type="hidden" name="action" value="brb_manual">';
    wp_nonce_field('brb_manual');
    $sources = ['phone' => 'Phone', 'walk-in' => 'Walk-in', 'online' => 'Online'];
    echo '<table class="form-table">';
    echo '<tr><th>Source</th><td><select name="source">';
    foreach ($sources as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($v['source'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th><label for="date">Date</label></th><td><input type="date" name="date" id="date" required value="' . esc_attr($v['date']) . '"></td></tr>';
    echo '<tr><th>Type</th><td>Full day, 10:00 AM to 11:30 PM<input type="hidden" name="type" value="day"></td></tr>';
    echo '<tr><th><label for="guests">Estimated Guests</label></th><td><input type="number" name="guests" id="guests" min="1" max="' . (int) $o['max_guests'] . '" value="' . esc_attr($v['guests']) . '"></td></tr>';
    echo '<tr><th><label for="name">Name</label></th><td><input name="name" id="name" class="regular-text" required value="' . esc_attr($v['name']) . '"></td></tr>';
    echo '<tr><th><label for="email">Email</label></th><td><input type="email" name="email" id="email" class="regular-text" required value="' . esc_attr($v['email']) . '"></td></tr>';
    echo '<tr><th><label for="phone">Phone</label></th><td><input name="phone" id="phone" class="regular-text" value="' . esc_attr($v['phone']) . '"></td></tr>';
    echo '<tr><th><label for="notes">Notes</label></th><td><textarea name="notes" id="notes" rows="3" class="large-text">' . esc_textarea($v['notes']) . '</textarea></td></tr>';
    echo '<tr><th><label for="agreed_price">Agreed price ($)</label></th><td><input name="agreed_price" id="agreed_price" type="number" step="0.01" min="0" value="' . esc_attr($v['agreed_price']) . '"> <span class="description">Leave blank to save a pending request with the price to be confirmed. Required if you record a check now. ' . esc_html(brb_price_sentence()) . '</span></td></tr>';
    echo '<tr><th>Minimum notice</th><td><label><input type="checkbox" name="override_notice" value="1"' . checked($v['override_notice'], '1', false) . '> Staff override: allow a start inside the ' . (int) brb_notice_hours() . '-hour notice. The public form cannot do this.</label></td></tr>';
    echo '<tr><th>Check now</th><td><label><input type="checkbox" name="take_check" value="1"' . checked($v['take_check'], '1', false) . '> Record a check with this booking</label></td></tr>';
    echo '<tr><th><label for="amount">Check amount</label></th><td><input name="amount" id="amount" type="number" step="0.01" min="0" value="' . esc_attr($v['amount']) . '"></td></tr>';
    echo '<tr><th><label for="check_number">Check number</label></th><td><input name="check_number" id="check_number" class="regular-text" value="' . esc_attr($v['check_number']) . '"></td></tr>';
    echo '<tr><th><label for="received_on">Date received</label></th><td><input type="date" name="received_on" id="received_on" value="' . esc_attr($v['received_on']) . '"></td></tr>';
    echo '<tr><th><label for="pay_notes">Check notes</label></th><td><textarea name="pay_notes" id="pay_notes" rows="2" class="large-text">' . esc_textarea($v['pay_notes']) . '</textarea></td></tr>';
    echo '</table><p><button class="button button-primary">Save booking</button></p></form></div>';
}

function brb_cal_url($args = []) {
    $base = ['page' => 'brb-cal'];
    return admin_url('admin.php?' . http_build_query(array_merge($base, $args)));
}
function brb_admin_span_class($ymd, $first, $last) {
    if ($first === '' || $last === '' || $first === $last) return '';
    try {
        $day = new DateTimeImmutable($ymd, brb_tz());
    } catch (Exception $e) {
        return '';
    }
    $w = (int) $day->format('w');
    $prev = $day->modify('-1 day')->format('Y-m-d');
    $next = $day->modify('+1 day')->format('Y-m-d');
    $starts = ($ymd === $first) || $w === 0;
    $ends = ($ymd === $last) || $w === 6;
    if ($prev < $first) $starts = true;
    if ($next > $last) $ends = true;
    if ($starts && $ends) return '';
    if ($starts) return ' brb-span-start';
    if ($ends) return ' brb-span-end';
    return ' brb-span-mid';
}
function brb_cal_inclusive_end($end) {
    if ($end->format('H:i') === '00:00') {
        $prev = $end->modify('-1 day');
        if ($prev->format('Y-m-d') < $end->format('Y-m-d')) return $prev->format('Y-m-d');
    }
    return $end->format('Y-m-d');
}
function brb_cal_span_label($start, $end, $mode = '') {
    $all_day = $start->format('H:i') === '00:00' && ($end->format('H:i') === '00:00' || $end->format('H:i') === '23:59');
    if ($mode !== 'hourly' && ($mode === 'day' || $mode === 'multi' || $all_day)) {
        if ($end->format('Y-m-d') === $start->format('Y-m-d') || $end <= $start->modify('+1 day')->setTime(0, 1)) return 'Full day';
    }
    if ($all_day && $end <= $start->modify('+1 day')->setTime(0, 1)) return 'Full day';
    return $start->format('g:i A') . '–' . $end->format('g:i A');
}
function brb_cal_closed_statuses() {
    return ['cancelled', 'declined', 'expired', 'spam'];
}
function brb_admin_cal() {
    global $wpdb;
    $m = (isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', $_GET['m'])) ? $_GET['m'] : brb_now()->format('Y-m');
    $view = (isset($_GET['view']) && $_GET['view'] === 'list') ? 'list' : 'month';
    $show_closed = !empty($_GET['closed']);
    $tz = brb_tz();
    $first = new DateTimeImmutable($m . '-01 00:00:00', $tz);
    $next = $first->modify('+1 month');
    $today = brb_now()->format('Y-m-d');
    $closed = brb_cal_closed_statuses();
    $where = $show_closed ? '' : " AND status NOT IN ('" . implode("','", $closed) . "')";
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s $where ORDER BY start_dt ASC",
        $next->format('Y-m-d H:i:s'),
        $first->format('Y-m-d H:i:s')
    ));
    $holds = brb_holds_overlapping($first->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s'));
    $bo = brb_blackouts();
    $nav = ['m' => $m];
    if ($show_closed) $nav['closed'] = '1';
    if ($view === 'list') $nav['view'] = 'list';
    echo '<div class="wrap brb-cal-wrap"><h1>Calendar</h1>';
    brb_admin_note();
    $switch = $nav;
    if ($view === 'list') unset($switch['view']);
    else $switch['view'] = 'list';
    $closed_nav = $nav;
    if ($show_closed) unset($closed_nav['closed']);
    else $closed_nav['closed'] = '1';
    echo '<p class="brb-cal-toolbar">';
    echo '<a class="button" href="' . esc_url(brb_cal_url($switch)) . '">' . ($view === 'list' ? 'Month view' : 'Upcoming list') . '</a> ';
    echo '<a class="button" href="' . esc_url(brb_cal_url($closed_nav)) . '">' . ($show_closed ? 'Hide cancelled' : 'Show cancelled, declined, and expired') . '</a>';
    echo '</p>';
    echo '<ul class="brb-cal-legend">';
    foreach ([
        'paid' => 'Paid in full',
        'deposit' => 'Deposit paid',
        'approved' => 'Approved, deposit due',
        'review' => 'Pending review',
        'reserved' => 'Reserved, paid elsewhere/cash',
        'hold' => 'Hold, unpaid',
        'blocked' => 'Blocked',
    ] as $key => $label) {
        echo '<li><span class="brb-chip brb-chip-' . esc_attr($key) . '"></span> ' . esc_html($label) . '</li>';
    }
    echo '</ul>';
    echo '<div class="brb-cal-month">';
    echo '<p class="brb-cal-month-nav">';
    echo '<a class="button" href="' . esc_url(brb_cal_url(array_merge($nav, ['m' => $first->modify('-1 month')->format('Y-m')]))) . '">← Prev</a> ';
    echo '<a class="button" href="' . esc_url(brb_cal_url(array_merge($nav, ['m' => $next->format('Y-m')]))) . '">Next →</a> ';
    echo '<a class="button" href="' . esc_url(brb_cal_url(array_merge($nav, ['m' => brb_now()->format('Y-m')]))) . '">Today</a>';
    echo '</p>';
    echo '<h2 class="brb-cal-month-title">' . esc_html($first->format('F Y')) . '</h2>';
    echo '</div>';
    if ($view === 'list') {
        brb_admin_cal_list($show_closed);
    } else {
        echo '<div class="brb-admincal-scroll"><div class="brb-admincal">';
        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d) echo "<div class='h'>$d</div>";
        for ($i = 0; $i < (int) $first->format('w'); $i++) echo '<div class="e"></div>';
        for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $today_class = $k === $today ? ' today' : '';
            $selected = (isset($_GET['date']) && $_GET['date'] === $k) ? ' is-selected' : '';
            $edit_href = brb_cal_url(array_merge($nav, ['date' => $k])) . '#brb-date-editor';
            $rate = brb_rate_for_date($k, 'full');
            $custom = brb_date_override_amount($k) !== null ? ' brb-day-price-custom' : '';
            echo '<div class="day' . $today_class . $selected . '">';
            echo '<a class="brb-day-open" href="' . esc_url($edit_href) . '"><b>' . $d->format('j') . '</b>';
            if ($rate !== null) echo '<span class="brb-day-price' . $custom . '">' . esc_html(brb_whole_money($rate)) . '</span>';
            echo '</a>';
            foreach ($rows as $b) {
                $s = new DateTimeImmutable($b->start_dt, $tz);
                $e = new DateTimeImmutable($b->end_dt, $tz);
                if (!($s < $d->modify('+1 day') && $e > $d)) continue;
                $class = brb_cal_chip_class($b->status);
                $when = brb_cal_span_label($s, $e, (string) $b->mode);
                $status = brb_status_label($b->status);
                $tip = $b->name . "\n" . $when . "\n" . $status . "\n" . brb_ref($b);
                $span = brb_admin_span_class($k, $s->format('Y-m-d'), brb_cal_inclusive_end($e));
                echo '<a class="brb-chip brb-chip-' . esc_attr($class) . $span . '" title="' . esc_attr($tip) . '" href="' . esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id)) . '">';
                if ($span === ' brb-span-mid' || $span === ' brb-span-end') echo '<span class="brb-span-bar" aria-hidden="true"></span>';
                else echo '<strong>' . esc_html($b->name) . '</strong> <span>' . esc_html($when) . '</span> <em>' . esc_html($status) . '</em>';
                echo '</a>';
            }
            foreach ($holds as $h) {
                $s = new DateTimeImmutable($h->start_dt, $tz);
                $e = new DateTimeImmutable($h->end_dt, $tz);
                if (!($s < $d->modify('+1 day') && $e > $d)) continue;
                $type = isset(brb_hold_types()[$h->hold_type]) ? $h->hold_type : 'blocked';
                $when = brb_cal_span_label($s, $e, 'hourly');
                $tip = $h->name . "\n" . brb_hold_types()[$type] . "\n" . $when;
                $edit = brb_cal_url(array_merge($nav, ['hold' => (int) $h->id, 'date' => $s->format('Y-m-d')])) . '#brb-hold-form';
                $span = brb_admin_span_class($k, $s->format('Y-m-d'), brb_cal_inclusive_end($e));
                echo '<a class="brb-chip brb-chip-' . esc_attr($type) . $span . '" title="' . esc_attr($tip) . '" href="' . esc_url($edit) . '">';
                if ($span === ' brb-span-mid' || $span === ' brb-span-end') echo '<span class="brb-span-bar" aria-hidden="true"></span>';
                else echo '<strong>' . esc_html($h->name) . '</strong> <span>' . esc_html($when) . '</span> <em>' . esc_html(brb_hold_types()[$type]) . '</em>';
                echo '</a>';
            }
            if (in_array($k, $bo, true)) {
                $edit = brb_cal_url(array_merge($nav, ['blackout' => $k, 'date' => $k])) . '#brb-hold-form';
                echo '<a class="brb-chip brb-chip-blocked" title="Blocked blackout. Click to give it a name." href="' . esc_url($edit) . '"><strong>Blocked</strong> <span>Full day</span></a>';
            }
            echo brb_admin_day_links($k, $nav);
            echo '</div>';
        }
        echo '</div></div>';
    }
    $edit_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : '';
    if ($edit_date !== '') brb_admin_date_editor($edit_date, $m, $view, $show_closed);
    $blackout_open = (isset($_GET['blackout']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['blackout']));
    if (!empty($_GET['hold']) || !empty($_GET['holdform']) || $blackout_open) brb_admin_cal_editor($m, $view, $show_closed);
    echo '</div>';
}
function brb_blocked_hold_for_day($ymd, $holds) {
    $tz = brb_tz();
    try {
        $day = new DateTimeImmutable($ymd . ' 00:00:00', $tz);
    } catch (Exception $e) {
        return null;
    }
    $end = $day->modify('+1 day');
    foreach ($holds as $hold) {
        if (($hold->hold_type ?? '') !== 'blocked') continue;
        $s = new DateTimeImmutable($hold->start_dt, $tz);
        $e = new DateTimeImmutable($hold->end_dt, $tz);
        if ($s < $end && $e > $day) return $hold;
    }
    return null;
}
function brb_block_day($date) {
    $date = sanitize_text_field((string) $date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Choose a date.');
    try {
        $day = new DateTimeImmutable($date . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return new WP_Error('date', 'Choose a date.');
    }
    if ($day < brb_now()->setTime(0, 0)) return new WP_Error('past', 'That date is in the past.');
    $window_end = $day->modify('+1 day');
    $existing = brb_blocked_hold_for_day($date, brb_holds_overlapping($day->format('Y-m-d H:i:s'), $window_end->format('Y-m-d H:i:s')));
    if ($existing) return $existing;
    if (brb_range_has_booking($day, $window_end, 0) || brb_range_hits_hold($day, $window_end, 0)) {
        return new WP_Error('busy', brb_date_taken_message());
    }
    return brb_save_hold([
        'name' => 'Blocked',
        'hold_type' => 'blocked',
        'start_date' => $date,
        'start_time' => '00:00',
        'end_date' => $date,
        'end_time' => '23:59',
    ]);
}
function brb_unblock_day($id) {
    $hold = brb_hold((int) $id);
    if (!$hold || ($hold->hold_type ?? '') !== 'blocked') return new WP_Error('missing', 'That block was not found.');
    return brb_delete_hold((int) $hold->id);
}
function brb_reopen_date($hold_id = 0, $blackout = '') {
    $did = false;
    $hold_id = (int) $hold_id;
    if ($hold_id && brb_hold($hold_id)) {
        $res = brb_delete_hold($hold_id);
        if (is_wp_error($res)) return $res;
        $did = true;
    }
    $blackout = sanitize_text_field((string) $blackout);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $blackout) && in_array($blackout, brb_blackouts(), true)) {
        brb_drop_blackout_date($blackout);
        $did = true;
    }
    return $did ? 'reopened' : 'noop';
}
function brb_admin_day_links($ymd, $nav) {
    $edit = brb_cal_url(array_merge($nav, ['date' => $ymd])) . '#brb-date-editor';
    $hold = brb_cal_url(array_merge($nav, ['date' => $ymd, 'holdform' => '1'])) . '#brb-hold-form';
    $html = '<p class="brb-day-actions">';
    $html .= '<a class="button button-small" href="' . esc_url($edit) . '">Edit</a> ';
    $html .= '<a class="button button-small" href="' . esc_url($hold) . '">Add a hold</a>';
    $html .= '</p>';
    return $html;
}
function brb_holds_touching_day($ymd) {
    try {
        $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return [];
    }
    $rows = brb_holds_overlapping($day->format('Y-m-d H:i:s'), $day->modify('+1 day')->format('Y-m-d H:i:s'));
    return $rows ?: [];
}
function brb_hold_is_inside_day($hold, $ymd) {
    try {
        $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return false;
    }
    $end = $day->modify('+1 day');
    $start = new DateTimeImmutable($hold->start_dt, brb_tz());
    $finish = new DateTimeImmutable($hold->end_dt, brb_tz());
    return $start >= $day && $finish <= $end;
}
function brb_date_status($ymd) {
    if (in_array($ymd, brb_blackouts(), true)) return 'blocked';
    $rank = ['blocked' => 3, 'reserved' => 2, 'hold' => 1];
    $best = 'open';
    $score = 0;
    foreach (brb_holds_touching_day($ymd) as $hold) {
        $type = (string) ($hold->hold_type ?? '');
        $next = $rank[$type] ?? 3;
        if ($next > $score) {
            $score = $next;
            $best = isset($rank[$type]) ? $type : 'blocked';
        }
    }
    return $best;
}
function brb_primary_same_day_hold($ymd) {
    $status = brb_date_status($ymd);
    $fallback = null;
    foreach (brb_holds_touching_day($ymd) as $hold) {
        if (!brb_hold_is_inside_day($hold, $ymd)) continue;
        if ($fallback === null) $fallback = $hold;
        if (($hold->hold_type ?? '') === $status) return $hold;
    }
    return $fallback;
}
function brb_bookings_on_day($ymd) {
    global $wpdb;
    try {
        $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND status<>'spam' ORDER BY start_dt ASC",
        $day->modify('+1 day')->format('Y-m-d H:i:s'),
        $day->format('Y-m-d H:i:s')
    ));
    return $rows ?: [];
}
function brb_apply_date_editor($input) {
    $date = sanitize_text_field((string) ($input['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Choose a date.');
    $status = sanitize_key((string) ($input['status'] ?? ''));
    if (!in_array($status, ['open', 'hold', 'reserved', 'blocked'], true)) return new WP_Error('status', 'Choose a status for this date.');
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $notes = sanitize_textarea_field((string) ($input['notes'] ?? ''));
    $start_time = trim((string) ($input['start_time'] ?? ''));
    $end_time = trim((string) ($input['end_time'] ?? ''));
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time = '10:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) $end_time = '23:30';
    if (in_array($status, ['hold', 'reserved'], true) && $name === '') return new WP_Error('name', 'Enter a name for this hold.');
    if ($status === 'blocked' && $name === '') $name = 'Blocked';
    $already_blocked = brb_date_status($date) === 'blocked';
    if ($status === 'blocked' && !$already_blocked) {
        try {
            $day = new DateTimeImmutable($date . ' 00:00:00', brb_tz());
        } catch (Exception $e) {
            return new WP_Error('date', 'Choose a date.');
        }
        if ($day < brb_now()->setTime(0, 0)) return new WP_Error('past', 'That date is in the past.');
    }
    if ($status === 'hold' || $status === 'reserved') {
        $start = brb_hold_moment($date, $start_time);
        $end = brb_hold_moment($date, $end_time);
        if (!$start || !$end || $end <= $start) return new WP_Error('when', 'The end must be after the start.');
    }
    if ($status !== 'open') {
        $check_start = ($status === 'blocked') ? brb_hold_moment($date, '00:00') : brb_hold_moment($date, $start_time);
        $check_end = ($status === 'blocked') ? brb_hold_moment($date, '23:59') : brb_hold_moment($date, $end_time);
        $primary_now = brb_primary_same_day_hold($date);
        $exclude_hold = $primary_now ? (int) $primary_now->id : 0;
        if ($check_start && $check_end && (brb_range_has_booking($check_start, $check_end, 0) || brb_range_hits_hold($check_start, $check_end, $exclude_hold))) {
            return new WP_Error('busy', brb_date_taken_message());
        }
    }
    $price_raw = trim((string) ($input['price'] ?? ''));
    $saved_price = brb_save_date_override($date, $price_raw === '' ? null : $price_raw);
    if (is_wp_error($saved_price)) return $saved_price;
    $noted = brb_save_date_note($date, $notes);
    if (is_wp_error($noted)) return $noted;
    $longer = [];
    if ($status === 'open') {
        foreach (brb_holds_touching_day($date) as $hold) {
            if (!brb_hold_is_inside_day($hold, $date)) {
                $longer[] = $hold;
                continue;
            }
            $deleted = brb_delete_hold((int) $hold->id);
            if (is_wp_error($deleted)) return $deleted;
        }
        if (in_array($date, brb_blackouts(), true)) brb_drop_blackout_date($date);
    } else {
        if (in_array($date, brb_blackouts(), true)) brb_drop_blackout_date($date);
        $primary = brb_primary_same_day_hold($date);
        $payload = [
            'name' => $name,
            'hold_type' => $status,
            'start_date' => $date,
            'start_time' => $status === 'blocked' ? '00:00' : $start_time,
            'end_date' => $date,
            'end_time' => $status === 'blocked' ? '23:59' : $end_time,
            'notes' => $notes,
        ];
        if ($primary) $payload['id'] = (int) $primary->id;
        $saved = brb_save_hold($payload);
        if (is_wp_error($saved)) return $saved;
    }
    $parts = ['Date saved.'];
    if ($longer) $parts[] = 'This date is still on a longer hold, so it stays unavailable until that hold is edited.';
    if ($saved_price !== null && $saved_price + 0.001 < brb_price_floor()) $parts[] = 'This price is below the usual ' . brb_whole_money(brb_price_floor()) . ' minimum because it was set for this date.';
    return implode(' ', $parts);
}
function brb_cal_chip_class($status) {
    if (in_array($status, ['paid', 'confirmed'], true)) return 'paid';
    if ($status === 'deposit_paid') return 'deposit';
    if (in_array($status, ['approved', 'held'], true)) return 'approved';
    if (in_array($status, ['review', 'pending'], true)) return 'review';
    return 'closed';
}
function brb_admin_date_editor($ymd, $m, $view, $show_closed) {
    try {
        $when = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return;
    }
    $nav = ['m' => $m, 'date' => $ymd];
    if ($view === 'list') $nav['view'] = 'list';
    if ($show_closed) $nav['closed'] = '1';
    $hold_open = !empty($_GET['holdform']) || !empty($_GET['hold']) || (isset($_GET['blackout']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['blackout']));
    $status = brb_date_status($ymd);
    $primary = brb_primary_same_day_hold($ymd);
    $name = $primary ? (string) $primary->name : '';
    $notes = $primary && (string) $primary->notes !== '' ? (string) $primary->notes : brb_date_note($ymd);
    $start_time = '10:00';
    $end_time = '23:30';
    if ($primary) {
        $start = new DateTimeImmutable($primary->start_dt, brb_tz());
        $end = new DateTimeImmutable($primary->end_dt, brb_tz());
        $start_time = $start->format('H:i');
        $end_time = $end->format('H:i');
    }
    $override = brb_date_override_amount($ymd);
    $seasonal = brb_seasonal_rate_for_date($ymd, 'full');
    $effective = brb_rate_for_date($ymd, 'full');
    $price_value = $override === null ? '' : brb_rate_amount_string($override);
    $clear_holds = ($status !== 'open') ? '1' : '0';
    echo '<div class="brb-date-editor" id="brb-date-editor">';
    echo '<h2>' . esc_html($when->format('l, F j, Y')) . '</h2>';
    if ($effective === null) {
        echo '<p class="brb-date-price-now">Calendar price: price to be confirmed.</p>';
    } elseif ($override !== null) {
        $season = $seasonal === null ? 'no seasonal rate' : 'the seasonal rate is ' . brb_whole_money($seasonal);
        echo '<p class="brb-date-price-now">Calendar price: <strong>' . esc_html(brb_whole_money($effective)) . '</strong> (saved for this date; ' . esc_html($season) . ').</p>';
    } else {
        echo '<p class="brb-date-price-now">Calendar price: <strong>' . esc_html(brb_whole_money($effective)) . '</strong> (seasonal rate).</p>';
    }
    $bookings = brb_bookings_on_day($ymd);
    if ($bookings) {
        echo '<div class="brb-date-bookings"><p><strong>Bookings on this date.</strong> Saving here does not change them.</p><ul>';
        foreach ($bookings as $b) {
            $href = admin_url('admin.php?page=brb&booking=' . (int) $b->id);
            echo '<li><a href="' . esc_url($href) . '">' . esc_html($b->name) . '</a> <span>' . esc_html(brb_status_label($b->status)) . '</span></li>';
        }
        echo '</ul></div>';
    }
    echo '<form method="post" id="brb-date-form" data-clear-holds="' . esc_attr($clear_holds) . '" data-was-blocked="' . ($status === 'blocked' ? '1' : '0') . '" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="brb_date_save">';
    echo '<input type="hidden" name="date" value="' . esc_attr($ymd) . '">';
    echo '<input type="hidden" name="m" value="' . esc_attr($m) . '">';
    echo '<input type="hidden" name="view" value="' . esc_attr($view) . '">';
    echo '<input type="hidden" name="closed" value="' . ($show_closed ? '1' : '') . '">';
    wp_nonce_field('brb_date_save');
    echo '<p><label>Price for this date ($)<br><input type="number" name="price" step="0.01" inputmode="decimal" value="' . esc_attr($price_value) . '" placeholder="' . esc_attr($seasonal === null ? '' : brb_rate_amount_string($seasonal)) . '"></label></p>';
    echo '<p class="description">Leave this blank to use the seasonal rate. The usual lowest rate is ' . esc_html(brb_whole_money(brb_price_floor())) . '. A price saved here replaces that rate for this date on the public Book page, including a price below ' . esc_html(brb_whole_money(brb_price_floor())) . '.</p>';
    echo '<p><label>Availability<br><select name="status" id="brb-date-status">';
    foreach (['open' => 'Open', 'hold' => 'Hold, unpaid', 'reserved' => 'Reserved, paid elsewhere/cash', 'blocked' => 'Blocked'] as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<div id="brb-date-status-fields">';
    echo '<p><label>Name<br><input name="name" class="regular-text" value="' . esc_attr($name) . '" placeholder="Guest or reason"></label></p>';
    echo '<div id="brb-date-times" class="brb-date-grid">';
    echo '<p><label>Starts<br><input type="time" name="start_time" value="' . esc_attr($start_time) . '"></label></p>';
    echo '<p><label>Ends<br><input type="time" name="end_time" value="' . esc_attr($end_time) . '"></label></p>';
    echo '</div></div>';
    echo '<p class="description">Don\'t enter guest phone numbers, emails, or Airbnb guest names here.</p>';
    echo '<p><label>Notes<br><textarea name="notes" rows="3" class="large-text" placeholder="Internal label (no guest phone or Airbnb name)">' . esc_textarea($notes) . '</textarea></label></p>';
    echo '<p class="description">Saving replaces the main hold on this date with the status above. Bookings are left as they are. Use Add a hold for a separate named hold.</p>';
    echo '<p><button class="button button-primary">Save this date</button></p>';
    echo '</form>';
    echo '<script>(function(){var form=document.getElementById("brb-date-form");if(!form)return;var status=document.getElementById("brb-date-status"),extra=document.getElementById("brb-date-status-fields"),times=document.getElementById("brb-date-times"),name=form.querySelector("input[name=name]");function sync(){var v=status.value;if(extra)extra.hidden=v==="open";if(name)name.required=v==="hold"||v==="reserved";if(times)times.hidden=!(v==="hold"||v==="reserved");}status.addEventListener("change",sync);sync();form.addEventListener("submit",function(e){if(status.value==="blocked"&&form.getAttribute("data-was-blocked")!=="1"&&!window.confirm("Block this day? Guests cannot book it until you set it back to open.")){e.preventDefault();return;}if(status.value==="open"&&form.getAttribute("data-clear-holds")==="1"&&!window.confirm("Set this date to open? Holds and blocks that start and end on this day will be removed. Bookings stay."))e.preventDefault();});})();</script>';
    $holds = brb_holds_touching_day($ymd);
    if ($holds) {
        echo '<h3>Holds on this date</h3><ul class="brb-date-holds">';
        foreach ($holds as $hold) {
            $type = brb_hold_types()[$hold->hold_type] ?? 'Blocked';
            $start = new DateTimeImmutable($hold->start_dt, brb_tz());
            $end = new DateTimeImmutable($hold->end_dt, brb_tz());
            $href = brb_cal_url(array_merge($nav, ['hold' => (int) $hold->id])) . '#brb-hold-form';
            echo '<li><a href="' . esc_url($href) . '">' . esc_html($hold->name) . '</a> <span>' . esc_html($type) . '</span> <span>' . esc_html(brb_cal_span_label($start, $end, 'hourly')) . '</span>';
            if ((string) $hold->notes !== '') echo '<span class="description">' . esc_html($hold->notes) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
    if (!$hold_open) {
        $add = brb_cal_url(array_merge($nav, ['holdform' => '1'])) . '#brb-hold-form';
        echo '<p><a class="button" href="' . esc_url($add) . '">Add a hold</a></p>';
    }
    echo '</div>';
}
function brb_admin_cal_editor($m, $view, $show_closed) {
    $tz = brb_tz();
    $chosen = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : '';
    $hold = !empty($_GET['hold']) ? brb_hold((int) $_GET['hold']) : null;
    $blackout = (isset($_GET['blackout']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['blackout'])) ? $_GET['blackout'] : '';
    $name = $hold ? $hold->name : '';
    $type = $hold ? $hold->hold_type : ($blackout ? 'blocked' : 'hold');
    $notes = $hold ? (string) $hold->notes : '';
    if ($hold) {
        $s = new DateTimeImmutable($hold->start_dt, $tz);
        $e = new DateTimeImmutable($hold->end_dt, $tz);
        $start_date = $s->format('Y-m-d');
        $start_time = $s->format('H:i');
        $end_date = $e->format('Y-m-d');
        $end_time = $e->format('H:i');
    } elseif ($blackout) {
        $start_date = $end_date = $blackout;
        $start_time = '00:00';
        $end_time = '23:59';
        $name = '';
    } else {
        $start_date = $chosen;
        $end_date = $chosen;
        $start_time = $chosen !== '' ? '10:00' : '';
        $end_time = $chosen !== '' ? '23:30' : '';
    }
    $title = $hold ? 'Edit hold' : ($blackout ? 'Name this blocked date' : 'Add a hold');
    if (!$hold && !$blackout && $chosen !== '') {
        try {
            $title = 'Add a hold for ' . (new DateTimeImmutable($chosen, $tz))->format('F j, Y');
        } catch (Exception $e) {
            $title = 'Add a hold';
        }
    }
    echo '<div class="brb-cal-editor" id="brb-hold-form"><h2>' . esc_html($title) . '</h2>';
    if ($blackout) echo '<p>This date is a blackout. Saving gives it a name and keeps the date blocked.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-was-blocked="' . ($type === 'blocked' ? '1' : '0') . '" onsubmit="var t=this.hold_type;if(t&&t.value===\'blocked\'&&this.getAttribute(\'data-was-blocked\')!==\'1\'&&!confirm(\'Block this day? Guests cannot book it until you remove the block.\'))return false;">';
    echo '<input type="hidden" name="action" value="brb_hold"><input type="hidden" name="m" value="' . esc_attr($m) . '">';
    echo '<input type="hidden" name="view" value="' . esc_attr($view) . '"><input type="hidden" name="closed" value="' . ($show_closed ? '1' : '') . '">';
    if ($hold) echo '<input type="hidden" name="id" value="' . (int) $hold->id . '">';
    if ($blackout) echo '<input type="hidden" name="blackout" value="' . esc_attr($blackout) . '">';
    if ($chosen !== '') echo '<input type="hidden" name="return_date" value="' . esc_attr($chosen) . '">';
    wp_nonce_field('brb_hold');
    echo '<p><label>Name<br><input name="name" class="regular-text" value="' . esc_attr($name) . '" required placeholder="Guest or reason"></label></p>';
    echo '<p><label>Type<br><select name="hold_type" id="brb-hold-type">';
    foreach (brb_hold_types() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '<option value="open"' . selected($type, 'open', false) . '>Open</option>';
    echo '</select></label></p>';
    echo '<p><label>Starts<br><input type="date" name="start_date" value="' . esc_attr($start_date) . '" required> <input type="time" name="start_time" value="' . esc_attr($start_time) . '"></label></p>';
    echo '<p><label>Ends<br><input type="date" name="end_date" value="' . esc_attr($end_date) . '" required> <input type="time" name="end_time" value="' . esc_attr($end_time) . '"></label></p>';
    echo '<p class="description">Don\'t enter guest phone numbers, emails, or Airbnb guest names here.</p>';
    echo '<p><label>Notes<br><textarea name="notes" rows="2" class="large-text" placeholder="Internal label (no guest phone or Airbnb name)">' . esc_textarea($notes) . '</textarea></label></p>';
    echo '<p><button class="button button-primary">Save hold</button></p></form>';
    echo '<script>(function(){var type=document.getElementById("brb-hold-type"),name=document.querySelector(".brb-cal-editor input[name=name]");if(!type||!name)return;function sync(){name.required=type.value!=="open";}type.addEventListener("change",sync);sync();})();</script>';
    if ($hold) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this hold? This cannot be undone.\');">';
        echo '<input type="hidden" name="action" value="brb_hold_delete"><input type="hidden" name="id" value="' . (int) $hold->id . '"><input type="hidden" name="m" value="' . esc_attr($m) . '">';
        if ($chosen !== '') echo '<input type="hidden" name="return_date" value="' . esc_attr($chosen) . '">';
        wp_nonce_field('brb_hold_delete');
        echo '<p><button class="button button-link-delete">Delete</button></p></form>';
    }
    if ($blackout) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this blocked date? It can be booked again.\');">';
        echo '<input type="hidden" name="action" value="brb_hold_delete"><input type="hidden" name="blackout" value="' . esc_attr($blackout) . '"><input type="hidden" name="m" value="' . esc_attr($m) . '">';
        wp_nonce_field('brb_hold_delete');
        echo '<p><button class="button button-link-delete">Delete</button></p></form>';
    }
    echo '</div>';
}
function brb_admin_cal_list($show_closed) {
    global $wpdb;
    $tz = brb_tz();
    $from = brb_now()->setTime(0, 0)->format('Y-m-d H:i:s');
    $closed = brb_cal_closed_statuses();
    $where = $show_closed ? '' : " AND status NOT IN ('" . implode("','", $closed) . "')";
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE end_dt > %s $where ORDER BY start_dt ASC LIMIT 40",
        $from
    ));
    $holds = brb_holds_overlapping($from, brb_now()->modify('+18 months')->format('Y-m-d H:i:s'));
    echo '<h2>Upcoming</h2><table class="widefat brb-cal-list"><thead><tr><th>When</th><th>Name</th><th>Time</th><th>Status</th></tr></thead><tbody>';
    $items = [];
    foreach ($rows as $b) {
        $s = new DateTimeImmutable($b->start_dt, $tz);
        $e = new DateTimeImmutable($b->end_dt, $tz);
        $items[] = [$s, '<tr><td>' . esc_html($s->format('D, M j, Y')) . '</td><td><a href="' . esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id)) . '">' . esc_html($b->name) . '</a></td><td>' . esc_html(brb_cal_span_label($s, $e, (string) $b->mode)) . '</td><td>' . esc_html(brb_status_label($b->status)) . '</td></tr>'];
    }
    foreach ($holds as $h) {
        $s = new DateTimeImmutable($h->start_dt, $tz);
        $e = new DateTimeImmutable($h->end_dt, $tz);
        $type = brb_hold_types()[$h->hold_type] ?? 'Blocked';
        $href = brb_cal_url(['m' => $s->format('Y-m'), 'hold' => (int) $h->id, 'date' => $s->format('Y-m-d')]) . '#brb-hold-form';
        $items[] = [$s, '<tr><td>' . esc_html($s->format('D, M j, Y')) . '</td><td><a href="' . esc_url($href) . '">' . esc_html($h->name) . '</a></td><td>' . esc_html(brb_cal_span_label($s, $e, 'hourly')) . '</td><td>' . esc_html($type) . '</td></tr>'];
    }
    usort($items, function ($a, $b) { return $a[0] <=> $b[0]; });
    if (!$items) echo '<tr><td colspan="4">Nothing upcoming.</td></tr>';
    foreach (array_slice($items, 0, 40) as $item) echo $item[1];
    echo '</tbody></table>';
}
function brb_cal_redirect($m, $note = '', $extra = []) {
    $args = ['page' => 'brb-cal', 'm' => $m];
    if (!empty($_POST['view']) && $_POST['view'] === 'list') $args['view'] = 'list';
    if (!empty($_POST['closed'])) $args['closed'] = '1';
    $return_date = isset($_POST['return_date']) ? sanitize_text_field(wp_unslash($_POST['return_date'])) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) $args['date'] = $return_date;
    if (is_array($extra)) {
        foreach ($extra as $key => $value) {
            if ($value === '' || $value === null) continue;
            $args[$key] = $value;
        }
    }
    if ($note) $args['brb_note'] = $note;
    wp_safe_redirect(admin_url('admin.php?' . http_build_query($args)));
    exit;
}
add_action('admin_post_brb_date_save', function () {
    brb_admin_gate('brb_date_save');
    $m = (isset($_POST['m']) && preg_match('/^\d{4}-\d{2}$/', $_POST['m'])) ? $_POST['m'] : brb_now()->format('Y-m');
    $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
    $saved = brb_apply_date_editor([
        'date' => wp_unslash($_POST['date'] ?? ''),
        'price' => wp_unslash($_POST['price'] ?? ''),
        'status' => wp_unslash($_POST['status'] ?? ''),
        'name' => wp_unslash($_POST['name'] ?? ''),
        'start_time' => wp_unslash($_POST['start_time'] ?? ''),
        'end_time' => wp_unslash($_POST['end_time'] ?? ''),
        'notes' => wp_unslash($_POST['notes'] ?? ''),
    ]);
    $note = is_wp_error($saved) ? $saved->get_error_message() : (is_string($saved) ? $saved : 'Date saved.');
    brb_cal_redirect($m, $note, ['date' => $date]);
});
add_action('admin_post_brb_hold', function () {
    brb_admin_gate('brb_hold');
    $m = (isset($_POST['m']) && preg_match('/^\d{4}-\d{2}$/', $_POST['m'])) ? $_POST['m'] : brb_now()->format('Y-m');
    if (sanitize_key(wp_unslash($_POST['hold_type'] ?? '')) === 'open') {
        $blackout = isset($_POST['blackout']) ? sanitize_text_field(wp_unslash($_POST['blackout'])) : '';
        $opened = brb_reopen_date((int) ($_POST['id'] ?? 0), $blackout);
        if (is_wp_error($opened)) brb_cal_redirect($m, $opened->get_error_message());
        brb_cal_redirect($m, $opened === 'reopened' ? 'Date reopened.' : '');
    }
    $saved = brb_save_hold([
        'id' => (int) ($_POST['id'] ?? 0),
        'name' => wp_unslash($_POST['name'] ?? ''),
        'hold_type' => wp_unslash($_POST['hold_type'] ?? ''),
        'start_date' => wp_unslash($_POST['start_date'] ?? ''),
        'start_time' => wp_unslash($_POST['start_time'] ?? ''),
        'end_date' => wp_unslash($_POST['end_date'] ?? ''),
        'end_time' => wp_unslash($_POST['end_time'] ?? ''),
        'notes' => wp_unslash($_POST['notes'] ?? ''),
    ]);
    if (is_wp_error($saved)) brb_cal_redirect($m, $saved->get_error_message());
    $blackout = isset($_POST['blackout']) ? sanitize_text_field(wp_unslash($_POST['blackout'])) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $blackout)) brb_drop_blackout_date($blackout);
    brb_cal_redirect($m, 'Hold saved.');
});
add_action('admin_post_brb_block_day', function () {
    brb_admin_gate('brb_block_day');
    $m = (isset($_POST['m']) && preg_match('/^\d{4}-\d{2}$/', $_POST['m'])) ? $_POST['m'] : brb_now()->format('Y-m');
    $saved = brb_block_day(wp_unslash($_POST['date'] ?? ''));
    if (is_wp_error($saved)) brb_cal_redirect($m, $saved->get_error_message());
    brb_cal_redirect($m, 'Day blocked.');
});
add_action('admin_post_brb_unblock_day', function () {
    brb_admin_gate('brb_unblock_day');
    $m = (isset($_POST['m']) && preg_match('/^\d{4}-\d{2}$/', $_POST['m'])) ? $_POST['m'] : brb_now()->format('Y-m');
    $res = brb_unblock_day((int) ($_POST['id'] ?? 0));
    if (is_wp_error($res)) brb_cal_redirect($m, $res->get_error_message());
    brb_cal_redirect($m, 'Day unblocked.');
});
add_action('admin_post_brb_hold_delete', function () {
    brb_admin_gate('brb_hold_delete');
    $m = (isset($_POST['m']) && preg_match('/^\d{4}-\d{2}$/', $_POST['m'])) ? $_POST['m'] : brb_now()->format('Y-m');
    $blackout = isset($_POST['blackout']) ? sanitize_text_field(wp_unslash($_POST['blackout'])) : '';
    if (!(int) ($_POST['id'] ?? 0) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $blackout)) {
        brb_drop_blackout_date($blackout);
        brb_cal_redirect($m, 'Blocked date deleted.');
    }
    $res = brb_delete_hold((int) ($_POST['id'] ?? 0));
    if (is_wp_error($res)) brb_cal_redirect($m, $res->get_error_message());
    brb_cal_redirect($m, 'Hold deleted.');
});

function brb_admin_settings() {
    if (!current_user_can('manage_options')) return;
    $kept = false;
    if (isset($_POST['brb_save']) && check_admin_referer('brb_settings')) {
        $prev = get_option('brb_settings', []);
        if (!is_array($prev)) $prev = [];
        $new = [];
        $defaults = brb_defaults();
        foreach ($defaults as $k => $v) {
            if ($k === 'date_notes' || !array_key_exists($k, $_POST)) {
                $new[$k] = array_key_exists($k, $prev) ? $prev[$k] : $v;
                continue;
            }
            $raw = wp_unslash($_POST[$k]);
            if ($k === 'tour_email') {
                $email = sanitize_email($raw);
                $new[$k] = is_email($email) ? $email : $defaults['tour_email'];
            } else {
                $new[$k] = in_array($k, ['policy', 'blackouts', 'pay_instructions', 'checklist', 'admin_email', 'inquiry_cc', 'weekly_summary_emails'], true)
                    ? sanitize_textarea_field($raw)
                    : sanitize_text_field($raw);
            }
        }
        $new['mode'] = 'day';
        if (!in_array($new['deposit_type'], ['full', 'percent', 'fixed'], true)) $new['deposit_type'] = 'full';
        $new['min_notice_hours'] = (string) max(0, (int) $new['min_notice_hours']);
        $new['hold_hours'] = (string) max(1, (int) $new['hold_hours']);
        $new['balance_due_days'] = (string) max(0, (int) $new['balance_due_days']);
        $new['deposit_pct'] = (string) max(1, min(100, (float) $new['deposit_pct']));
        $new['card_fee_percent'] = (string) max(0, min(100, round((float) ($new['card_fee_percent'] ?? 3), 2)));
        $new['damage_deposit_amount'] = brb_dec(max(0, round((float) ($new['damage_deposit_amount'] ?? 250), 2)));
        $new['deposit_fixed'] = brb_dec(max(0, (float) $new['deposit_fixed']));
        $new['starting_price'] = brb_dec(max(0, (float) $new['starting_price']));
        if (!isset($new['typical_price'])) $new['typical_price'] = (string) ($prev['typical_price'] ?? '');
        if (trim((string) $new['venue_address']) === '') $new['venue_address'] = $defaults['venue_address'];
        if (trim((string) $new['pay_location']) === '') $new['pay_location'] = $defaults['pay_location'];
        $new['min_hours'] = (string) max(1, (int) $new['min_hours']);
        $new['max_guests'] = (string) max(1, (int) $new['max_guests']);
        $new['max_days'] = (string) max(1, (int) $new['max_days']);
        $new['open_hour'] = (string) max(0, min(23, (int) $new['open_hour']));
        $new['close_hour'] = (string) max(1, min(24, (int) $new['close_hour']));
        if (trim($new['blackouts']) === '' && trim((string) ($prev['blackouts'] ?? '')) !== '' && empty($_POST['clear_blackouts'])) {
            $new['blackouts'] = (string) $prev['blackouts'];
            $kept = true;
        }
        foreach (brb_legacy_keys() as $lk) unset($new[$lk]);
        $new = brb_chat_apply_rate_settings($new, $_POST);
        if (isset($_POST['brb_rate_rules']) || isset($_POST['brb_rate_overrides'])) {
            $new['calendar_rates'] = brb_calendar_rates_from_post($_POST);
        }
        update_option('brb_settings', $new);
        echo '<div class="updated"><p>Saved.' . ($kept ? ' Blackout dates were kept because the list was empty and “clear all blackout dates” was not ticked.' : '') . '</p></div>';
    }
    $o = brb_opt();
    if (trim((string) ($o['checklist'] ?? '')) === '') $o['checklist'] = brb_checklist_default();
    $groups = [
        'Pricing shown to guests' => [
            ['starting_price', 'Starting price ($ per day)', 'text', null, 'Guests see “from” this amount per day. Default 850. Whole dollars are shown without cents.'],
        ],
        'Hours and capacity' => [
            ['min_hours', 'Minimum hours', 'number', null, ''],
            ['open_hour', 'Hourly open (0–23)', 'number', null, ''],
            ['close_hour', 'Hourly close (1–24)', 'number', null, ''],
            ['max_days', 'Max consecutive days', 'number', null, ''],
            ['max_guests', 'Max guests', 'number', null, 'Public help says 80 guests, and graduation parties or open houses can go over. The booking form accepts up to 200.'],
            ['currency', 'Currency code', 'text', null, 'Checks are US dollars. This is kept for records. Older day rate, hourly rate, cleaning fee, and tax figures stay stored and are not shown on the website.'],
        ],
        'Notice' => [
            ['min_notice_hours', 'Minimum notice (hours)', 'number', null, 'A booking must start at least this many hours from now. Default 48. The owner described the rule as not renting inside 48 hours; change this number here if that rule changes. Set 0 to allow same-day starts. A request does not hold the date.'],
        ],
        'Check payment' => [
            ['deposit_type', 'Deposit that reserves the date', 'select', ['full' => 'Full amount', 'percent' => 'Percent of the agreed price', 'fixed' => 'Fixed dollar amount'], 'The deposit holds the date. It is refundable if written cancellation is received 60 or more days before the event, less non-refundable processing charges and costs already incurred, and it is not refundable inside 60 days. Staff process refunds manually. Default is 50% of the agreed price. Until staff set a price, the guest is told the price is to be confirmed.'],
            ['deposit_pct', 'Deposit percent', 'number', null, 'Used when the choice above is percent. Default 50.'],
            ['deposit_fixed', 'Fixed deposit amount ($)', 'text', null, 'Used when the choice above is a fixed dollar amount. Cannot exceed the total.'],
            ['balance_due_days', 'Balance due note', 'number', null, 'Guests are told the balance is due no later than one week before the event. A past date is shown as due now. Until a price is set, the guest sees price to be confirmed.'],
            ['damage_deposit_amount', 'Damage deposit amount ($)', 'number', null, 'Flat refundable damage deposit added to new bookings. Default 250. Changing this does not change bookings that already have a damage deposit line. Use 0 if a new booking should not include one.'],
            ['venue_address', 'Venue address', 'text', null, 'The event space. Shown in the footer, on Contact, and in Terms and Privacy. Default is 2220 37th Street, Moline, IL 61265. This is not where checks go.'],
            ['payee', 'Check payable to', 'text', null, 'The name guests write on the payee line. Default is Boardroom QC LLC.'],
            ['pay_location', 'Where to mail or drop off checks', 'text', null, 'Public check instructions use The Boardroom at 2220 37th Street, Moline, IL 61265. The old 2212 check address is replaced on upgrade. Guests are told we meet on demand and set a time together, by appointment. There are no set drop-off hours.'],
            ['pay_instructions', 'Payment instructions', 'textarea', null, 'Optional extra text in the approval email and on an approved booking. Leave blank and this line stays hidden. It is not shown before the request is approved.'],
            ['card_fee_percent', 'Card processing fee (%)', 'number', null, 'Added on top of an online card payment. Default 3. It is not applied to the booking balance. Card payment stays off until the Stripe secret key is set on the server.'],
            ['admin_email', 'Admin notification email', 'textarea', null, 'Request, payment, and contact inquiry emails are sent here. Separate more than one address with a comma, semicolon, or a new line. An address that is not valid is skipped and shown in a warning.'],
            ['tour_email', 'Inquiry email', 'text', null, 'Contact inquiries are also emailed here. Default is boardroomqc@gmail.com.'],
        ],
        'Email' => [
            ['mail_from', 'From address', 'text', null, 'Guest emails use this From address. Default is boardroomqc@gmail.com. Gmail only delivers that From when the SMTP account is this address, or this address is a Send mail as alias on that account. The SMTP password stays on the server.'],
            ['mail_from_name', 'From name', 'text', null, 'Default is The Boardroom.'],
            ['mail_reply_to', 'Reply-To', 'text', null, 'Guest emails use this Reply-To. Default is boardroomqc@gmail.com.'],
            ['inquiry_cc', 'New inquiry CC', 'textarea', null, 'Copies of new contact, tour, and chat inquiries. Separate addresses with a comma, semicolon, or a new line. An address already saved here is kept. You can remove an address later.'],
        ],
        'Reports' => [
            ['ga_measurement_id', 'GA4 measurement ID', 'text', null, 'Example: G-XXXX. The tag is added on the public site only when this is set and search indexing is allowed. Staging stays noindex and does not load the tag.'],
            ['ga_property_id', 'GA4 property ID', 'text', null, 'Numeric property id from Google Analytics Admin. Used only to read reports. It is not the measurement ID.'],
            ['weekly_summary', 'Weekly summary email', 'select', ['0' => 'Off', '1' => 'On'], 'Off by default. A weekly email goes to the list below only when this is On and the list has at least one address.'],
            ['weekly_summary_emails', 'Weekly summary recipients', 'textarea', null, 'Optional team list. Separate addresses with a comma, semicolon, or a new line. Leave empty and nothing is sent.'],
        ],
        'Calendar and policy' => [
            ['blackouts', 'Blackout dates (YYYY-MM-DD, one per line)', 'textarea', null, 'Saved dates stay in the database when the plugin is updated. Emptying this box does not delete them unless you tick the box under it.'],
            ['policy', 'Cancellation policy', 'textarea', null, 'Shown before a guest saves a date, and included in emails.'],
            ['checklist', 'Rental checklist', 'textarea', null, 'Shown on the Rental Checklist page. One item per line. Leave the saved text in place to keep the current list.'],
        ],
    ];
    echo '<div class="wrap"><h1>Booking Settings</h1>';
    $email_warn = brb_admin_email_notice_html();
    if ($email_warn !== '') echo $email_warn;
    brb_admin_note();
    echo '<p>Guests save a date on the website. They can pay by check or in person, or online by card when the Stripe secret key is set. ' . esc_html(brb_price_sentence()) . ' Staff set the final price on each booking. The venue and the check address are separate settings.</p>';
    echo '<p>No overnight stays. The Boardroom is an event space only. ' . esc_html(brb_out_by_line()) . ' ' . esc_html(brb_free_admission_rule()) . ' A booking is one full day, from 10:00 AM to 11:30 PM.</p>';
    $sampleStart = brb_now()->modify('+10 days')->setTime(0, 0);
    echo '<div class="brb-paybox"><strong>What guests see before a price is set</strong>';
    brb_echo_lines(brb_preview_lines(['total' => 0, 'due' => 0, 'price_set' => 0], $sampleStart));
    echo '</div>';
    echo '<form method="post">';
    wp_nonce_field('brb_settings');
    foreach ($groups as $title => $fields) {
        echo '<h2>' . esc_html($title) . '</h2><table class="form-table">';
        foreach ($fields as $d) {
            list($k, $label, $type, $opts, $help) = $d;
            echo '<tr><th><label for="' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td>';
            if ($type === 'select') {
                echo '<select name="' . esc_attr($k) . '" id="' . esc_attr($k) . '">';
                foreach ($opts as $v => $l) printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($o[$k], $v, false), esc_html($l));
                echo '</select>';
            } elseif ($type === 'textarea') {
                printf('<textarea name="%s" id="%s" rows="6" class="large-text">%s</textarea>', esc_attr($k), esc_attr($k), esc_textarea($o[$k]));
            } else {
                printf('<input type="%s" name="%s" id="%s" value="%s" class="regular-text">', $type === 'number' ? 'number' : 'text', esc_attr($k), esc_attr($k), esc_attr($o[$k]));
            }
            if ($help) echo '<p class="description">' . esc_html($help) . '</p>';
            if ($k === 'blackouts') echo '<p><label><input type="checkbox" name="clear_blackouts" value="1"> Clear all blackout dates (only if you mean to delete every saved date)</label></p>';
            echo '</td></tr>';
        }
        echo '</table>';
    }
    brb_admin_calendar_rate_fields();
    brb_admin_quote_rates_fields();
    echo '<p><button class="button button-primary" name="brb_save" value="1">Save settings</button></p></form>';
    echo '<h2>Send test email</h2>';
    $smtp = brb_smtp_config();
    if ($smtp) {
        echo '<p>SMTP is on: ' . esc_html($smtp['host'] . ':' . $smtp['port']) . ' with STARTTLS, from ' . esc_html($smtp['from_name'] . ' <' . $smtp['from'] . '>') . '. The password stays on the server.</p>';
    } else {
        echo '<p>SMTP is off until BRB_SMTP_USER and BRB_SMTP_PASS are set on the server. Mail then uses smtp.gmail.com port 587 with STARTTLS unless BRB_SMTP_HOST or BRB_SMTP_PORT say otherwise.</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="brb_test_email">';
    wp_nonce_field('brb_test_email');
    echo '<p><button class="button">Send test email</button></p>';
    echo '<p class="description">Sends to the admin notification email and shows the mail error if it fails.</p>';
    echo '</form>';
    $stripe_mode = brb_stripe_mode();
    echo '<h2>Card payments</h2>';
    echo '<p>Stripe connected: ' . (brb_stripe_enabled() ? 'yes' : 'no') . '</p>';
    echo '<p>Mode: ' . esc_html($stripe_mode !== '' ? $stripe_mode : '—') . '</p>';
    echo '<p class="description">Online card payment is off until the secret key is set on the server. Refunds are issued manually in the Stripe dashboard and follow the rental agreement.</p>';
    echo '<h2>Google Analytics service account</h2>';
    $saved_ga = brb_ga_credentials();
    echo '<p>' . ($saved_ga ? 'A service account JSON is saved. It is not shown again.' : 'No service account JSON is saved.') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="brb_ga_credentials">';
    wp_nonce_field('brb_ga_credentials');
    echo '<p><label>Replace JSON<br><textarea name="ga_json" rows="6" class="large-text" placeholder="Paste the JSON only when replacing it"></textarea></label></p>';
    echo '<p><label><input type="checkbox" name="ga_clear" value="1"> Remove the saved service account</label></p>';
    echo '<p><button class="button">Save service account</button></p>';
    echo '<p class="description">In Google Analytics Admin, grant the service account Viewer access on the property. The JSON stays on this server and is never printed back.</p>';
    echo '</form>';
    echo '</div>';
}
add_action('admin_post_brb_ga_credentials', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    check_admin_referer('brb_ga_credentials');
    $note = 'Saved.';
    if (!empty($_POST['ga_clear'])) {
        delete_option('brb_ga_credentials');
        $note = 'Removed the service account.';
    } else {
        $json = (string) wp_unslash($_POST['ga_json'] ?? '');
        if (trim($json) !== '') {
            $saved = brb_ga_store_credentials($json);
            if (is_wp_error($saved)) $note = $saved->get_error_message();
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=brb-settings&brb_note=' . rawurlencode($note)));
    exit;
});
