<?php
/**
 * Rental Terms and Rental Agreement cleanup and damage deposit clauses (2.5.78).
 * The renter cleans up. Boardroom QC LLC may keep the $250 damage deposit for
 * cleaning crews, trash or item pickup, and labor, with an itemized statement
 * and the refund within 7 days. The deposit is not a cap. Section 3 no longer
 * names a separate cleaning fee. Read-only: nothing is saved except what the
 * plugin's own upgrade already wrote.
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
function brb_tc_plain($html) {
    $html = preg_replace('#</(p|li|div|h\d|ul)>#', "\n", (string) $html);
    return html_entity_decode(preg_replace('/[ \t]+/', ' ', strip_tags($html)), ENT_QUOTES, 'UTF-8');
}
function brb_tc_between($text, $start, $end) {
    $a = strpos($text, $start);
    if ($a === false) return '';
    $b = $end === null ? false : strpos($text, $end, $a + strlen($start));
    return $b === false ? substr($text, $a) : substr($text, $a, $b - $a);
}

global $wpdb;
$kept_show = $wpdb->hide_errors();
try {
    brb_t(get_option('brb_version') === '2.5.78', 'plugin storage version is 2.5.78');

    $terms_src = brb_tc_plain(brb_terms_html());
    $terms_page = get_page_by_path('terms');
    $terms_live = $terms_page ? brb_tc_plain($terms_page->post_content) : '';
    $ra_src = html_entity_decode(brb_agreement_html(), ENT_QUOTES, 'UTF-8');
    $ra_page = get_page_by_path('rental-agreement');
    $ra_live = $ra_page ? html_entity_decode((string) $ra_page->post_content, ENT_QUOTES, 'UTF-8') : '';

    foreach (['source' => $terms_src, 'stored page' => $terms_live] as $label => $t) {
        $s3 = brb_tc_between($t, '3. Payment and deposit', '4. Guests and supervision');
        $s10 = brb_tc_between($t, '10. Cleanup', '11. Damage and your damage deposit');
        $s11 = brb_tc_between($t, '11. Damage and your damage deposit', '12. Your risk');
        brb_t($s3 !== '' && stripos($s3, 'cleaning fee') === false && strpos($s3, 'rental, cleaning,') === false, "terms $label: section 3 names no separate cleaning fee");
        brb_t(strpos($s3, 'Before your event starts: all rental amounts and the damage deposit must be paid.') !== false, "terms $label: section 3 says rental amounts and the damage deposit are paid before the event");
        brb_t(strpos($s10, "You're responsible for leaving the space clean.") !== false, "terms $label: section 10 makes cleanup the renter's duty");
        brb_t(strpos($s10, 'Bag all trash and take it to the dumpster on site, or wherever we direct.') !== false, "terms $label: section 10 trash is bagged and taken where directed");
        brb_t(strpos($s10, 'decorations, food, drinks, rentals, vendor items, and personal belongings') !== false && strpos($s10, 'Clean up spills') !== false, "terms $label: section 10 covers decorations, belongings, and spills");
        brb_t(strpos($s10, 'same condition and arrangement you found it in, normal wear and tear excepted') !== false, "terms $label: section 10 same condition, wear and tear excepted");
        brb_t(strpos($s10, 'hire a cleaning crew, hauler, or other help') !== false && strpos($s10, 'the pickup or disposal of items left behind, and the labor') !== false && strpos($s10, 'taken from your damage deposit (section 11) or billed to you') !== false, "terms $label: section 10 cleaning crew, item pickup, and labor come from the deposit or are billed");
        brb_t(strpos($s11, 'Your refundable damage deposit is a flat $250.') !== false && strpos($s11, 'covers both cleanup and damage') !== false, "terms $label: section 11 the flat \$250 deposit covers cleanup and damage");
        brb_t(strpos($s11, 'Boardroom QC LLC may keep all or part of your damage deposit') !== false && strpos($s11, 'cleaning crews or extra cleaning') !== false && strpos($s11, 'pickup, removal, storage, or disposal of items left behind') !== false, "terms $label: section 11 Boardroom QC LLC may keep the deposit for crews and item pickup");
        brb_t(strpos($s11, 'itemized statement of what we kept and why') !== false && strpos($s11, 'within 7 days after your event') !== false, "terms $label: section 11 itemized statement and refund within 7 days");
        brb_t(strpos($s11, 'The deposit is not a cap on what you owe.') !== false && strpos($s11, 'even if they\'re more than the deposit') !== false && strpos($s11, 'bill you for the rest') !== false, "terms $label: section 11 deposit is not a cap and the rest is billed");
        brb_t(strpos($s11, 'through Stripe for card payments (less the card processing fee) or by check') !== false, "terms $label: section 11 keeps the original-method refund");
        preg_match_all('/\$\s?[0-9][0-9,]*(?:\.[0-9]+)?/', $s10 . $s11, $m);
        brb_t(array_values(array_unique($m[0])) === ['$250'], "terms $label: sections 10 and 11 name no amount other than \$250");
        brb_t(!preg_match('/air' . 'bnb|overnight|\b14 days|fourteen/i', $s10 . $s11), "terms $label: sections 10 and 11 mention no Airbnb, overnight stays, or a 14-day period");
        brb_t(strpos($t, 'Last updated: November 13, 2026') !== false && strpos($t, 'November 9, 2026') === false, "terms $label: last updated November 13, 2026");
    }

    foreach (['source' => $ra_src, 'stored page' => $ra_live] as $label => $t) {
        $r3 = brb_tc_between($t, '3. Payment, Deposit, and Fees.', '4. Occupancy');
        $r10 = brb_tc_between($t, '10. Cleanup, Trash, and Personal Property.', '11. Damage, Damage Deposit');
        $r11 = brb_tc_between($t, '11. Damage, Damage Deposit, Liability, and Legal Action.', '12. Assumption of Risk');
        brb_t($r3 !== '' && stripos($r3, 'cleaning fee') === false, "agreement $label: section 3 names no separate cleaning fee");
        brb_t(strpos($r3, 'Renter shall pay all rental fees, the damage deposit, and any other charges stated by Owner.') !== false, "agreement $label: section 3 lists rental fees and the damage deposit");
        brb_t(strpos($r3, 'secures Renter\'s cleanup and damage obligations under Sections 9 through 11. It is refunded, less any amounts retained under Section 11, by Renter\'s original payment method') !== false, "agreement $label: section 3 deposit secures cleanup and is refunded less retained amounts");
        brb_t(strpos($r10, '(b) place all trash, garbage, and debris in closed bags and deposit them in the on-site dumpster or another location directed by Owner') !== false, "agreement $label: section 10 (b) bagged trash where directed");
        brb_t(strpos($r10, '(a) leave the Venue clean and in the same condition') !== false && strpos($r10, '(c) remove all personal property, decorations') !== false && strpos($r10, '(d) clean all spills') !== false, "agreement $label: section 10 (a) same condition, (c) removal, (d) spills");
        brb_t(strpos($r10, 'including cleaning crews, haulers, and disposal services') !== false && strpos($r10, 'item pickup') !== false && strpos($r10, '(collectively, "Cleanup Costs")') !== false && strpos($r10, 'deducted from the damage deposit under Section 11 or billed to Renter separately') !== false, "agreement $label: section 10 defines Cleanup Costs for crews and item pickup");
        brb_t(strpos($r11, 'Owner may retain all or any part of the damage deposit to pay Cleanup Costs') !== false, "agreement $label: section 11 Owner may retain the deposit for Cleanup Costs");
        brb_t(strpos($r11, 'Within seven (7) days after the end of the Rental Period, Owner shall refund the damage deposit, less any amounts retained') !== false && strpos($r11, 'itemized statement of the amounts retained and the reasons for them') !== false, "agreement $label: section 11 refund within seven (7) days with an itemized statement");
        brb_t(strpos($r11, 'is not liquidated damages and is not a limit or cap on Renter\'s liability') !== false && strpos($r11, 'does not limit Renter\'s responsibility for amounts exceeding the deposit') !== false && strpos($r11, 'upon Owner\'s written demand') !== false, "agreement $label: section 11 deposit is no cap and excess is owed on demand");
        preg_match_all('/\$\s?[0-9][0-9,]*(?:\.[0-9]+)?/', $r10 . $r11, $m);
        brb_t(empty($m[0]), "agreement $label: sections 10 and 11 name no dollar amount");
        brb_t(!preg_match('/air' . 'bnb|overnight|fourteen/i', $r10 . $r11), "agreement $label: sections 10 and 11 mention no Airbnb, overnight stays, or fourteen days");
        brb_t(strpos($t, 'Last updated: November 13, 2026') !== false, "agreement $label: last updated November 13, 2026");
    }

    $faq = brb_faq_markup();
    // The live FAQ page holds the 2.5.77 copy until the upgrade's brb_refresh_faq()
    // rewrites it. The harness DB has no FAQ page, so make one with the old copy,
    // run the refresh, check it, and remove it again.
    $faq_page = get_page_by_path('faq');
    $temp_faq = 0;
    $old_faq = str_replace([
        ' If the space needs a cleaning crew, trash or debris removal, or pickup of items left behind, those costs come out of the deposit, and anything beyond it may be charged.',
        ' Bag the trash for the dumpster, take your decorations and belongings with you, and wipe up any spills.',
    ], '', $faq);
    brb_t($old_faq !== $faq && strpos($old_faq, 'How many guests can The Boardroom hold') !== false, 'the 2.5.77 FAQ copy is rebuilt for the refresh check');
    if (!$faq_page) {
        $temp_faq = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'faq', 'post_title' => 'FAQ', 'post_content' => $old_faq]);
    } else {
        $kept_faq = (string) $faq_page->post_content;
        $wpdb->update($wpdb->posts, ['post_content' => $old_faq], ['ID' => (int) $faq_page->ID]);
        clean_post_cache((int) $faq_page->ID);
    }
    brb_refresh_faq();
    $refreshed = get_page_by_path('faq');
    $refreshed_html = $refreshed ? (string) $refreshed->post_content : '';
    if ($temp_faq) {
        wp_delete_post($temp_faq, true);
    } elseif ($faq_page) {
        $wpdb->update($wpdb->posts, ['post_content' => $kept_faq], ['ID' => (int) $faq_page->ID]);
        clean_post_cache((int) $faq_page->ID);
    }
    foreach (['source' => $faq, 'page after the upgrade refresh' => $refreshed_html] as $label => $f) {
        brb_t(strpos($f, 'If the space needs a cleaning crew, trash or debris removal, or pickup of items left behind, those costs come out of the deposit, and anything beyond it may be charged.') !== false, "faq $label: the deposit answer covers crews and item pickup");
        brb_t(strpos($f, 'Please leave it like you got it. Clean and tidy the space, and take the garbage out. Bag the trash for the dumpster, take your decorations and belongings with you, and wipe up any spills.') !== false, "faq $label: the before-we-leave answer keeps 'leave it like you got it' and 'garbage' and adds the cleanup steps");
        brb_t(stripos($f, 'cleaning fee') === false, "faq $label: no separate cleaning fee");
    }

    $new_line = 'Please clean up, bag the garbage and take it to the dumpster, and take all decorations and belongings with you. Cleanup we have to do or hire out comes out of the damage deposit.';
    brb_t(in_array($new_line, brb_checklist_lines(), true) && !in_array('Please clean up and take the garbage to the dumpster.', brb_checklist_lines(), true), 'checklist shows the new cleanup line once and drops the old one');
    brb_t(strpos(brb_checklist_policy_text("Balloons are fine.\nPlease clean up and take the garbage to the dumpster."), $new_line) !== false, 'a saved checklist with the old cleanup line is upgraded to the new one');
    brb_t(strpos(brb_damage_public_line(), 'mainly covers cleaning') !== false, 'Walter\'s damage deposit line is unchanged');
} finally {
    $wpdb->show_errors($kept_show);
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
