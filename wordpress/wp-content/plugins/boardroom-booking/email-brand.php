<?php
/**
 * 2.5.88: branded HTML guest emails.
 *
 * Presentation only. The plain-text body each email builds today is not changed: it is sent as the
 * plain-text part, and the HTML part is the same words laid out in The Boardroom's colors, fonts and
 * logo (table layout, inline CSS, 600px max width, email-safe for Gmail, Outlook and Apple Mail).
 *
 * Easy to revert, with no setting:
 *   add_filter('brb_email_branded', '__return_false');   // back to plain text only
 * or delete the require_once for this file in boardroom-booking.php.
 *
 * Only brb_mail() calls that pass a $brand array (guest booking emails) are wrapped. Staff copies,
 * test emails and inquiry notices stay plain text.
 */
if (!defined('ABSPATH')) exit;

/** Brand values taken from the live theme (style.css): gold #c4912e, dark gold #996f1d, ink #161616. */
function brb_email_brand() {
    return apply_filters('brb_email_brand_tokens', [
        'ink' => '#161616',
        'gold' => '#c4912e',
        'gold_dark' => '#996f1d',
        'text' => '#1a1a1a',
        'muted' => '#6b6b6b',
        'page' => '#f1eee8',
        'card' => '#faf6ec',
        'line' => '#e6dcc3',
        'font' => "'EB Garamond',Georgia,'Times New Roman',serif",
    ]);
}
/** The site's hosted header logo (the same file the live header uses, 2x for sharp screens). */
function brb_email_logo_url() {
    $base = function_exists('get_template_directory_uri') ? get_template_directory_uri() : home_url('/wp-content/themes/boardroom');
    return apply_filters('brb_email_logo_url', $base . '/assets/img/boardroom-logo-header-2x.png');
}
function brb_email_enabled($ctx = []) {
    return (bool) apply_filters('brb_email_branded', true, $ctx);
}
/**
 * Brand context for a guest email, passed as brb_mail()'s $brand argument (and used to render the staff preview).
 * Pay-link emails also get Booking reference and Event date rows on the summary card (HTML only).
 */
function brb_email_guest_brand($kind = '', $b = null) {
    $ctx = ['kind' => sanitize_key((string) $kind)];
    if ($b && $ctx['kind'] === 'paylink') {
        $ctx['card_rows'] = [
            ['Booking reference', brb_ref($b)],
            ['Event date', brb_when($b)],
        ];
    }
    return $ctx;
}
/** The words on the pay button for a "Label: URL" line. 'Pay online' becomes 'Pay now' (new wording); others keep their own words. */
function brb_email_button_label($label) {
    $label = trim((string) $label);
    if (strcasecmp($label, 'Pay online') === 0) return 'Pay now';
    return $label;
}
/** Short label for the raw-URL fallback under a button. New wording, for Max's approval. */
function brb_email_link_fallback_label() {
    return apply_filters('brb_email_link_fallback_label', 'Or open this link');
}
function brb_email_linkify($escaped) {
    return preg_replace_callback('~https?://[^\s<]+~i', function ($m) {
        $url = $m[0];
        $tail = '';
        while ($url !== '' && preg_match('/[.,;:!?)]$/', $url)) {
            $tail = substr($url, -1) . $tail;
            $url = substr($url, 0, -1);
        }
        return '<a href="' . $url . '" style="color:#996f1d;text-decoration:underline;word-break:break-all;">' . $url . '</a>' . $tail;
    }, $escaped);
}
function brb_email_text_html($text) {
    return str_replace("\n", '<br>', brb_email_linkify(esc_html($text)));
}
/** One "Label: value" card. $rows is a list of [label, value]. */
function brb_email_card_html($rows) {
    $t = brb_email_brand();
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:6px 0 22px;background:' . $t['card'] . ';border:1px solid ' . $t['line'] . ';border-left:4px solid ' . $t['gold'] . ';"><tr><td style="padding:10px 18px;">';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">';
    $n = count($rows);
    foreach ($rows as $i => $row) {
        $strong = (bool) preg_match('/^(Total|Amount)\b/i', $row[0]);
        $border = ($i < $n - 1) ? 'border-bottom:1px solid ' . $t['line'] . ';' : '';
        if ($strong && $i > 0) $border .= 'border-top:2px solid ' . $t['gold'] . ';';
        $is_date = (bool) preg_match('/\bdate\b|due by/i', $row[0]);
        $html .= '<tr><td class="brb-row-l" style="padding:9px 12px 9px 0;' . $border . 'font-family:' . $t['font'] . ';font-size:16px;line-height:1.35;color:' . $t['muted'] . ';vertical-align:top;">' . esc_html($row[0]) . '</td>'
            . '<td class="brb-row-v' . ($is_date ? ' brb-date' : '') . '" align="right" style="padding:9px 0;' . $border . 'font-family:' . $t['font'] . ';font-size:' . ($strong ? '20' : '17') . 'px;line-height:1.35;font-weight:700;color:' . ($strong ? $t['gold_dark'] : $t['ink']) . ';text-align:right;vertical-align:top;' . ($is_date ? 'white-space:nowrap;' : '') . '">' . esc_html($row[1]) . '</td></tr>';
    }
    return $html . '</table></td></tr></table>';
}
/** Big gold button with a short fallback link under it (the signed URL is the href, not shown as raw text). */
function brb_email_button_html($label, $url) {
    $t = brb_email_brand();
    $href = esc_url($url);
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:8px 0 6px;"><tr><td align="center" style="padding:0;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="brb-btn" style="margin:0 auto;"><tr>'
        . '<td align="center" bgcolor="' . $t['gold'] . '" style="background:' . $t['gold'] . ';border-radius:3px;mso-padding-alt:17px 44px;">'
        . '<a href="' . $href . '" target="_blank" style="display:inline-block;padding:17px 44px;font-family:' . $t['font'] . ';font-size:20px;line-height:1.2;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#ffffff;text-decoration:none;">' . esc_html($label) . '</a>'
        . '</td></tr></table></td></tr>'
        . '<tr><td align="center" style="padding:12px 0 18px;font-family:' . $t['font'] . ';font-size:13px;line-height:1.4;color:' . $t['muted'] . ';"><a href="' . $href . '" target="_blank" style="color:' . $t['gold_dark'] . ';text-decoration:underline;">' . esc_html(brb_email_link_fallback_label()) . '</a></td></tr></table>';
    return $html;
}
function brb_email_para_html($text, $greeting = false) {
    $t = brb_email_brand();
    $size = $greeting ? '20' : '17';
    $weight = $greeting ? '600' : '500';
    return '<p style="margin:0 0 16px;font-family:' . $t['font'] . ';font-size:' . $size . 'px;line-height:1.6;font-weight:' . $weight . ';color:' . $t['text'] . ';">' . brb_email_text_html($text) . '</p>';
}
/**
 * Turns a plain-text email body into the HTML blocks. Blank lines separate blocks:
 *  - "Label: https://link" (optionally followed by more words on the same line, as in brb_pay_online_line())
 *    becomes a button, with any words after the URL kept as a paragraph;
 *  - two or more "Label: value" lines become a summary card;
 *  - "- item" lines become a bullet list; everything else is a paragraph.
 * $ctx['card_rows'] are extra [label, value] rows put at the top of the first card that has a Total row.
 */
function brb_email_body_html($body, $ctx = []) {
    $t = brb_email_brand();
    $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $body));
    $blocks = preg_split("/\n{2,}/", $body);
    $extra = (isset($ctx['card_rows']) && is_array($ctx['card_rows'])) ? $ctx['card_rows'] : [];
    $html = '';
    foreach ($blocks as $i => $block) {
        $block = trim($block, "\n");
        if (trim($block) === '') continue;
        $lines = explode("\n", $block);
        // "Pay online: <url> The card charge is ..." (approval email) still gets the Pay now button;
        // the fee sentence after the URL is kept as a paragraph. Plain text is unchanged.
        if (preg_match('~^([^:\n]{1,48}):\s*(https?://\S+)(?:[ \t]+([\s\S]*))?$~', implode("\n", $lines), $m)) {
            $html .= brb_email_button_html(brb_email_button_label($m[1]), $m[2]);
            $rest = trim((string) ($m[3] ?? ''));
            if ($rest !== '') $html .= brb_email_para_html($rest);
            continue;
        }
        $rows = [];
        foreach ($lines as $line) {
            if (strpos($line, 'http') === false && preg_match('/^([^:\n]{1,48}):\s+(\S.*)$/', $line, $m)) $rows[] = [$m[1], $m[2]];
        }
        if (count($lines) >= 2 && count($rows) === count($lines)) {
            if ($extra) {
                foreach ($rows as $r) {
                    if (preg_match('/^Total\b/i', $r[0])) {
                        $rows = array_merge($extra, $rows);
                        $extra = [];
                        break;
                    }
                }
            }
            $html .= brb_email_card_html($rows);
            continue;
        }
        if (preg_match('/^- /m', $block)) {
            $intro = [];
            $items = [];
            foreach ($lines as $line) {
                if (strpos($line, '- ') === 0) $items[] = substr($line, 2);
                elseif (!$items) $intro[] = $line;
                else $items[count($items) - 1] .= ' ' . trim($line);
            }
            if ($intro) {
                $html .= '<p style="margin:8px 0 8px;font-family:' . $t['font'] . ';font-size:19px;line-height:1.4;font-weight:700;color:' . $t['ink'] . ';">' . brb_email_text_html(implode("\n", $intro)) . '</p>';
            }
            $html .= '<ul style="margin:0 0 18px;padding:0 0 0 22px;">';
            foreach ($items as $item) {
                $html .= '<li style="margin:0 0 7px;font-family:' . $t['font'] . ';font-size:16px;line-height:1.5;font-weight:500;color:' . $t['text'] . ';">' . brb_email_text_html($item) . '</li>';
            }
            $html .= '</ul>';
            continue;
        }
        $html .= brb_email_para_html($block, $i === 0);
    }
    return $html;
}
/** The whole email: header with logo, body blocks, footer. */
function brb_email_html($body, $subject = '', $ctx = []) {
    $t = brb_email_brand();
    $logo = esc_url(brb_email_logo_url());
    $site = home_url('/');
    $host = preg_replace('~^https?://(www\.)?~i', '', untrailingslashit($site));
    $content = brb_email_body_html($body, $ctx);
    $first = trim((string) strtok(trim((string) $body), "\n"));
    $pre = esc_html(mb_substr($first, 0, 120));
    $css = '@media only screen and (max-width:620px){.brb-wrap{width:100%!important}.brb-pad{padding:24px 18px!important}.brb-btn,.brb-btn td{width:100%!important}.brb-btn a{display:block!important;padding:17px 10px!important}.brb-row-l,.brb-row-v{font-size:15px!important}.brb-date{white-space:nowrap!important}}';
    $html = '<!DOCTYPE html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
        . '<title>' . esc_html($subject) . '</title><style type="text/css">body{margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}' . $css . '</style></head>'
        . '<body style="margin:0;padding:0;background:' . $t['page'] . ';">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . $t['page'] . ';font-size:1px;line-height:1px;">' . $pre . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $t['page'] . '" style="width:100%;background:' . $t['page'] . ';"><tr><td align="center" style="padding:24px 12px;">'
        . '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->'
        . '<table role="presentation" class="brb-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;margin:0 auto;background:#ffffff;">'
        . '<tr><td align="center" bgcolor="' . $t['ink'] . '" style="background:' . $t['ink'] . ';padding:18px 20px 16px;border-bottom:3px solid ' . $t['gold'] . ';"><a href="' . esc_url($site) . '" target="_blank" style="text-decoration:none;"><img src="' . $logo . '" width="98" height="100" alt="The Boardroom QC" style="display:block;margin:0 auto;border:0;width:98px;height:100px;"></a></td></tr>'
        . '<tr><td class="brb-pad" style="padding:32px 40px 18px;font-family:' . $t['font'] . ';color:' . $t['text'] . ';">' . $content . '</td></tr>'
        . '<tr><td align="center" bgcolor="' . $t['ink'] . '" style="background:' . $t['ink'] . ';padding:20px 20px 22px;font-family:' . $t['font'] . ';font-size:15px;line-height:1.6;color:#d8d8d8;">'
        . '<span style="font-weight:700;letter-spacing:.06em;color:#ffffff;">Boardroom QC LLC</span><br><a href="' . esc_url($site) . '" target="_blank" style="color:' . $t['gold'] . ';text-decoration:none;">' . esc_html($host) . '</a></td></tr>'
        . '</table><!--[if mso]></td></tr></table><![endif]-->'
        . '</td></tr></table></body></html>';
    return apply_filters('brb_email_html', $html, $body, $subject, $ctx);
}
/**
 * Runs inside wp_mail() (after any test short-circuit), so the plain body that wp_mail received stays the
 * plain-text part and the HTML wrapper is added as the other part (multipart/alternative).
 */
function brb_email_apply_brand($phpmailer) {
    $ctx = $GLOBALS['brb_email_brand_ctx'] ?? null;
    $GLOBALS['brb_email_brand_ctx'] = null;
    if (!is_array($ctx) || !brb_email_enabled($ctx)) return;
    $plain = (string) $phpmailer->Body;
    if ($plain === '') return;
    $phpmailer->isHTML(true);
    $phpmailer->CharSet = 'UTF-8';
    $phpmailer->Body = brb_email_html($plain, (string) $phpmailer->Subject, $ctx);
    $phpmailer->AltBody = $plain;
}
add_action('phpmailer_init', 'brb_email_apply_brand', 20);
