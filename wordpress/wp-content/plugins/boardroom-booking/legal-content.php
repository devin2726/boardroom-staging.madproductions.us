<?php
if (!defined('ABSPATH')) exit;

function brb_cancellation_summary() {
    return 'Bookings are subject to approval. No payment is due until the request is approved. The owner may decline any request. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. You can pay by check or in person, or online by card with a 3% processing fee once the request is approved. The card processing fee is non-refundable. Under the Rental Agreement, 50% of the invoice is due within 1 day after signing unless the Owner agrees otherwise in writing. The remaining balance is due the week of the event, no later than 48 hours before the event. The signed Rental Agreement controls if it differs from this summary.';
}

function brb_policy_needs_refresh($text) {
    $text = (string) $text;
    if (trim($text) === '') return true;
    if (stripos($text, 'PLACEHOLDER') !== false) return true;
    if (stripos($text, 'owner to supply') !== false) return true;
    if (stripos($text, 'Full refund if cancelled 14+') !== false) return true;
    return false;
}

function brb_terms_html() {
    // [CONFIRM with Chris] Guests out by 11:30 PM. No smoking or vaping. Service animals only.
    return <<<'TERMS_HTML'
<p>These Rental Terms are a plain-language summary of the Facility Event Space Rental Agreement for The Boardroom QC. The venue is at 2220 37th Street, Moline, IL 61265. They do not replace the signed agreement. The full text, with signature lines, is on the <a href="/rental-agreement/">Rental Agreement</a> page, which you can print.</p>

<h2>Booking and approval</h2>
<p>Send a request on the website. A request does not hold the date. No payment is due until the request is approved. We may decline a request. All bookings are subject to review and approval by the Boardroom, which includes an in-person meeting with the renter. Approval is based on the event details provided, availability and our house rules. Your date is reserved once the meeting is complete and your deposit is received and approved by the Boardroom.</p>

<h2>Rental period, hours and setup</h2>
<p>You may use the venue during the dates and times on the invoice, the booking confirmation, or a written approval. That window is the rental period. A full day runs from 10:00 AM to 11:30 PM. Half days are generally available in June, from 10:00 AM to 4:00 PM or from 5:00 PM to 11:30 PM. Everyone must be out of The Boardroom by 11:30 PM. This is firm, with no exceptions.</p>
<p>The room is not set up when you arrive. Renters set up and tear down. Staff cannot set the room up. On a full day, setup can begin at 8:00 AM. On a half day, setup can begin one hour before the start. Please do not enter before that setup time, stay after the rental period, or use the space for something other than the approved event, unless we agree in writing first. Coming in early, leaving late, or using the space without approval can bring extra charges and loss of the deposit. Decorations are not provided, except that Christmas decorations are up in December.</p>

<h2>Capacity, host age and supervision</h2>
<p>No more than seventy-five (75) persons may occupy the venue unless we approve a different limit in writing. The minimum host age is 25 for every booking. Please be present, or name a responsible adult who is present, for the whole event. You supervise every guest, invitee, child, vendor, and contractor.</p>

<h2>Events we host</h2>
<p>All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. We do not host concerts or ticketed shows. Guests may have a band or live music, as long as they are not charging for the event.</p>

<h2>Payments, deposit, balance, and tax</h2>
<p>Unless we agree otherwise in writing, 50% of the invoice is due within one day after you sign. That deposit holds the date. The remaining balance is due the week of the event, no later than 48 hours before the event. Sales tax is included in the price. Please pay the rental fees, cleaning fees, and security deposit before the rental period begins. If a payment is late, the reservation may be cancelled.</p>
<p>You can pay by check or in person, or online by card with a 3% processing fee once the request is approved. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. The card processing fee is added on top of the amount being paid and is not part of the booking balance. The card processing fee is non-refundable. Card payments get an emailed receipt, and we can provide an invoice or receipt for any payment on request.</p>

<h2>Cancellation</h2>
<p>You may cancel by giving us written notice. If we receive written cancellation 60 or more days before the event, the deposit is refundable, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. If cancellation is received less than 60 days before the event, the deposit is not refundable. Refunds are issued manually.</p>
<p>We may decline a request, and we may cancel with written notice. If we decline before any payment, no money is taken. If a payment was recorded before a decline, staff resolve it manually. If we cancel because of a breach, a missed payment, missing insurance, a misrepresentation, a safety concern, or a prohibited activity, we may keep the amounts the agreement allows.</p>
<p>Bad weather or an emergency out of our control, such as a hurricane, a snowstorm, or a power outage, is refundable. A personal issue, such as a flat tire, is not refundable.</p>

<h2>Damage deposit and cleanup</h2>
<p>The damage deposit is $100 to $500 depending on event size, mainly for cleaning. It may be applied to excessive cleaning or garbage. A dumpster is on site. It is returned within 72 hours when Venmo or PayPal is on file. A check takes 1 to 2 weeks. Anything beyond that deposit may be charged.</p>
<p>Please leave the venue, furniture, restrooms, kitchen, floors, and entry areas as you found them, ordinary wear excepted. Take the garbage out. Anything left behind may be thrown away or stored at your expense. You are responsible for damage, loss, fines, and extra cleaning from the event, beyond ordinary wear. We may apply the security deposit to amounts owed. That does not limit what you owe above the deposit.</p>

<h2>House rules</h2>
<p>No overnight stays. The Boardroom is an event space only. The space is quiet after 11:30 PM, and nothing is outdoors.</p>
<p>All smoking, including cigars and cigarettes, is outside only. Vaping leaves a residue and can affect the damage deposit. No glitter. Wall tape is not allowed. Balloons are allowed. Confetti, rice, birdseed, loose sequins, fireworks, sparklers, smoke bombs, and open flame that we have not approved in writing are not allowed.</p>
<p>Service animals only. No pets.</p>

<h2>Food, kitchen and alcohol</h2>
<p>No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. Please bring your own plates, cups, and utensils. Buffet pans and some serving spoons are available when you clean them before you leave. You can bring your own food, and any caterer is welcome. The Boardroom does not provide catering.</p>
<p>BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004).</p>

<h2>Insurance and vendors</h2>
<p>Please carry special event liability insurance, or party liability insurance, for the event. It should cover bodily injury, property damage, and the activities of you and your guests. We may ask for proof before access. If alcohol is served, please also carry liquor liability coverage when it is available. We may ask that MAD Productions, LLC, The Boardroom QC, and related parties be listed as additional insureds. If proof is not acceptable, we may cancel or refuse access. Amounts already owed are not waived.</p>
<p>If you bring a caterer, bartender, entertainer, decorator, or other vendor, they need to be licensed, insured, and qualified. Please send proof of insurance at least seven days before the rental, and list us as an additional insured when we ask. You remain responsible for your vendors.</p>

<h2>Access and keys</h2>
<p>We may give you one key, one access code, or another way to enter. Please keep every key and code safe. Do not copy or share them except with approved event representatives. Please return every physical access item when the rental period ends. You are responsible for the cost of lost keys, rekeying, lock changes, and unauthorized access caused by you or your guests or vendors.</p>

<h2>Liability and assumption of risk [ATTORNEY REVIEW]</h2>
<p>You accept the venue in its existing condition. Please look it over when you arrive and tell us right away about any damage that was already there.</p>
<p>[ATTORNEY REVIEW] The renter and every guest assume all risk of the event. The Boardroom is not responsible for injury, loss, or equipment issues, such as the ice maker or wifi, that affect an event. Renters are encouraged to carry their own home or renters policy. If event insurance is needed, the renter carries that policy.</p>
<p>To the fullest extent the law allows, you will defend, indemnify, release, and hold harmless the Boardroom, MAD Productions, LLC, and their members, managers, and agents from claims, injuries, property damage, fines, and costs arising from the event, alcohol or food service, prohibited items, cleanup, damage, or a breach of the agreement. If we have to enforce the agreement or collect amounts owed, you are responsible for reasonable attorney fees and costs, in addition to the amounts owed.</p>

<h2>Laws and Illinois venue</h2>
<p>Please follow federal, state, county, and city laws, including fire, building, health, alcohol, noise, occupancy, and safety rules in the City of Moline, Rock Island County, and the State of Illinois. Also follow reasonable instructions we give before, during, or after the event.</p>
<p>The agreement is governed by the laws of the State of Illinois. A lawsuit or dispute about the agreement, the venue, or the event is filed in a court in Rock Island County, Illinois, unless the law requires another court.</p>

<h2>General</h2>
<p>A change is effective only if it is in writing and signed by the party who would be bound by it. Not enforcing a provision once is not a waiver of later enforcement. If one provision cannot be enforced, the rest stays in effect. The agreement, together with any invoice, booking confirmation, or written rules, is the entire agreement. The person who signs represents that they can bind the renter.</p>

<h2>The signed agreement controls</h2>
<p>This page is a summary for guests of The Boardroom QC, operated by MAD Productions, LLC. If anything here differs from the signed Rental Agreement, the signed Rental Agreement controls. The full agreement is available to print at <a href="/rental-agreement/">the Rental Agreement</a>.</p>
<p class="updated">Last updated: October 27, 2026</p>
TERMS_HTML;
}

function brb_privacy_html() {
    return <<<'PRIVACY_HTML'
<p>This policy describes what MAD Productions, LLC, doing business as The Boardroom QC, at 2220 37th Street, Moline, IL 61265, collects on this website and how it is used.</p>

<h2>Information we collect</h2>
<p>The booking form asks for your name, email, event type, event date and time, party size, confirmation that the event is free to attend, whether the gathering is private and invitation-only, whether alcohol will be served, what time the event will end, a host age bracket, and a description of the event. The contact form asks for your name, email, and a message. You can also say what the message is about, and a preferred date or time. When a check is received, staff enter the check number and the payment record by hand. When a card is used, Stripe processes the payment and this site stores the amount, the processing fee, and the payment id. We never see or store card numbers. That record is stored with the booking. A contact inquiry is stored with the inquiry. Messages submitted through the chat assistant are stored with the inquiry.</p>

<h2>How we use it</h2>
<p>We use this information only to manage reservations and contact inquiries, and to communicate with you about them. Email about a booking or a contact inquiry is sent through this site’s mail system.</p>

<h2>Payment</h2>
<p>You can pay by check or in person, or online by card with a 3% processing fee, after a request is approved. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. Stripe processes card payments. We never see or store card numbers. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. The card processing fee is non-refundable.</p>

<h2>Cookies</h2>
<p>This site sets no advertising, analytics, or tracking cookies of its own, but the embedded Google Maps in the footer and on the Contact page may set cookies from Google, and Google's privacy policy applies to those cookies. Stripe may set cookies on its own checkout page, and Stripe's privacy policy applies to those cookies. Guests are not asked to accept cookies. The contact form does not set a cookie. The chat assistant does not set a cookie. WordPress sets cookies for staff when they log in to the admin area.</p>

<h2>How long we keep it</h2>
<p>We keep booking, payment, and contact inquiry records for as long as we need them for our records and for tax. A contact inquiry is removed only when a staff member deletes it. If you ask us to delete your information, we will, unless the law requires us to keep it.</p>

<h2>Sharing</h2>
<p>We do not sell your information. We share it only with service providers that host this site or deliver email, with Stripe when you pay by card, and when the law requires it.</p>

<h2>Your requests</h2>
<p>You can ask to see or delete your information by emailing <a href="mailto:boardroomqc@gmail.com">boardroomqc@gmail.com</a>.</p>

<h2>Children</h2>
<p>This site is not directed to children, and we do not seek information from children.</p>

<h2>Changes</h2>
<p>If this policy changes, the date below will change.</p>
<p class="updated">Last updated: October 28, 2026</p>
PRIVACY_HTML;
}

function brb_agreement_html() {
    $raw = <<<'AGREEMENT_BODY'
FACILITY EVENT SPACE RENTAL AGREEMENT

County of Rock Island | City of Moline | State of Illinois

This Facility Event Space Rental Agreement (the "Agreement") is made and entered into as of the date signed below by and between MAD Productions, LLC, an Illinois limited liability company (the "Owner"), and the individual or entity identified below as the renter (the "Renter"). Owner and Renter may be referred to individually as a "Party" and collectively as the "Parties."

WHEREAS, Owner controls and operates the event space commonly known as The Boardroom QC, located at 2220 37th Street, Moline, Illinois 61265 (the "Venue");

WHEREAS, Renter desires to temporarily rent, occupy, and use the Venue for the limited event purpose described in the related invoice, booking confirmation, or written approval from Owner; and

WHEREAS, Owner agrees to allow such temporary use only under the terms, conditions, covenants, waivers, insurance requirements, indemnities, and restrictions stated in this Agreement.

NOW, THEREFORE, in consideration of the mutual promises and covenants stated herein, the Parties agree as follows:

1. Rental Period and Limited Use. Renter shall have access to and use of the Venue only during the date(s) and time(s) stated on the invoice, booking confirmation, or written approval issued by Owner (the "Rental Period"). Renter shall not enter before the setup time, remain after the end of the Rental Period, or use the Venue for any purpose other than the approved event without Owner's prior written consent. Setup on the morning of the event is included. Renters set up and tear down. Staff cannot set the room up. On a full day, setup can begin at 8:00 AM. On a half day, setup can begin one hour before the start. Balloons are allowed. Wall tape is not allowed. Overnight stays are not permitted. The Venue is an event space only. A full day runs from 10:00 AM to 11:30 PM. Half days are generally available in June, from 10:00 AM to 4:00 PM or from 5:00 PM to 11:30 PM. Everyone must be out of the Venue by 11:30 PM. This is firm, with no exceptions. All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. Guests may have a band or live music, as long as they are not charging for the event. Owner does not host concerts or ticketed shows. Any entry before the setup time, late departure, or unapproved use may result in additional charges, loss of deposit, and any other remedies available to Owner.

2. Access, Keys, and Codes. Owner may provide Renter with one (1) key, access code, or other entry method as deemed necessary by Owner. Renter shall safeguard all keys, codes, and access credentials, shall not duplicate or share them except with approved event representatives, and shall return all physical access items immediately at the end of the Rental Period. Renter is responsible for all costs related to lost keys, rekeying, lock changes, code changes, or unauthorized access caused by Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives.

3. Payment, Deposit, and Fees. Before the beginning of the Rental Period, Renter shall pay all rental fees, cleaning fees, security deposits, and any other charges stated by Owner. Unless Owner agrees otherwise in writing, Renter must pay fifty percent (50%) of the invoice within one (1) day after signing this Agreement. That deposit holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. A request does not reserve the date until the deposit is received. The remaining balance is due the week of the event, no later than 48 hours before the event. Failure to timely pay may result in cancellation of the reservation without further notice.

4. Occupancy, Conduct, and Supervision. The Venue shall not be occupied by more than seventy-five (75) persons unless Owner gives written approval for a different limit. The minimum host age is 25 for every booking. Renter shall be present or shall designate a responsible adult representative who is present during the entire event. Renter is solely responsible for supervising all guests, invitees, children, vendors, contractors, and any other persons entering the Venue during the Rental Period. No pets or animals of any kind are permitted, except documented service animals as required by applicable law.

5. Required Event Liability Insurance. As a condition of using the Venue, Renter shall obtain and maintain, at Renter's sole cost, special event liability insurance or party liability insurance covering the event, the Rental Period, bodily injury, property damage, and the activities of Renter and Renter's guests. Owner may require proof of such insurance before granting access to the Venue. If alcohol is served, sold, distributed, or consumed, Renter shall also obtain liquor liability coverage or a special event liquor liability endorsement if available and applicable. Owner may require that MAD Productions, LLC, The Boardroom QC, the Venue owner, and any related parties designated by Owner be named as additional insureds. Failure to provide acceptable proof of insurance may result in cancellation or denial of access, without waiver of any amounts owed.

6. Outside Vendors, Caterers, and Contractors. If Renter uses any caterer, bartender, entertainer, decorator, rental company, vendor, contractor, or other third party, Renter shall ensure that each third party is properly licensed, insured, and qualified for the services provided. At least seven (7) days before the Rental Period, or earlier if requested by Owner, Renter shall provide proof of insurance for any outside vendor, with Owner listed as an additional insured when requested. Renter remains fully responsible for all acts, omissions, damages, injuries, cleanup, violations, and charges caused by Renter's vendors or contractors.

7. Food, Alcohol, Kitchen, and Equipment. Owner does not provide food, beverages, alcohol, catering, bartending, food service, security, medical services, or event supervision. Renter is solely responsible for compliance with all laws, rules, and regulations involving food, alcohol, service, consumption, sales, licensing, age restrictions, intoxication, and transportation after the event. BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: https://moline.il.us/244/Liquor-Licensing (City Clerk, 309-524-2004). Renter accepts all risk and liability arising from the use of kitchen utensils, appliances, equipment, furniture, fixtures, décor, or other property at the Venue, whether used properly or improperly.

8. Strictly Prohibited Items and Activities. The following are strictly prohibited inside the Venue, outside the Venue, on sidewalks, in parking areas, and anywhere on or around the premises: glitter; wall tape; party confetti; confetti cannons; rice; birdseed; loose sequins; fireworks; sparklers; smoke bombs; pyrotechnics; explosive devices; explosive props; combustible props; open flame not expressly approved in writing by Owner; hazardous materials; illegal substances; weapons except as permitted by law; ticketed events; cover charges; paid admission; door fees; ticket or merchandise sales; concerts; and any activity that creates unusual mess, fire risk, explosion risk, property damage risk, injury risk, nuisance, or disturbance. A band or live music is allowed only when guests are not charged for the event. Violation of this section may result in immediate termination of the event, forfeiture of deposit, additional cleaning or repair charges, and liability for all resulting damages, claims, fines, attorney fees, and costs.

9. Condition of Premises; Return in Same Condition. Renter accepts the Venue in its existing "AS IS" condition at the time Renter takes possession. Renter shall inspect the Venue upon arrival and shall immediately report any pre-existing damage or concern to Owner. Renter shall leave the Venue, fixtures, furniture, restrooms, kitchen areas, floors, walls, décor, equipment, sidewalks, entry areas, and parking-related areas in the same condition and arrangement as when Renter arrived, ordinary wear and tear excepted. The Venue must be left clean, orderly, secure, and free of personal property, decorations, trash, food, spills, stains, debris, glitter, confetti, and any items brought in by Renter or Renter's guests or vendors.

10. Cleanup, Trash, and Personal Property. Before the Rental Period expires, Renter shall remove all personal property, decorations, trash, food, beverages, vendor property, rental items, and any other items not present when Renter took control of the Venue. Any property left behind may be deemed abandoned and may be discarded or stored at Renter's expense. Any required excessive cleaning, specialty cleaning, remediation, odor removal, stain removal, or disposal caused by Renter, guests, invitees, or vendors may be charged to Renter and deducted from the security deposit or billed separately.

11. Damage, Security Deposit, Liability, and Legal Action. Renter is financially responsible for all damage, loss, theft, excessive cleaning, fines, citations, violations, business interruption, loss of use, loss of reputation, and any other costs or damages arising from or related to Renter's event, Renter's use of the Venue, or the acts or omissions of Renter's guests, invitees, vendors, contractors, agents, representatives, or any person entering the Venue during the Rental Period. Damage outside ordinary wear and tear may result in forfeiture of the security deposit, additional charges, civil liability, and legal action. Owner may apply the security deposit toward any amounts owed, but application of the deposit does not limit Renter's responsibility for amounts exceeding the deposit.

12. Assumption of Risk; Accidents and Injuries. Renter understands that event activities, use of the Venue, use of furniture, fixtures, equipment, parking areas, sidewalks, curbs, entrances, exits, kitchen areas, and alcohol or food consumption may involve risks, including serious injury, temporary or permanent disability, death, and property damage. Renter voluntarily assumes all risks arising from the event and the use of the Venue by Renter, Renter's guests, invitees, vendors, contractors, agents, and representatives. Owner is not responsible for accidents, injuries, death, loss, theft, or property damage involving Renter or any guest, invitee, child, vendor, contractor, agent, representative, or other person connected to Renter's event, except to the extent such limitation is prohibited by applicable law. [ATTORNEY REVIEW] Renter and guests assume all risk. Owner is not responsible for injury, loss, or equipment issues, such as the ice maker or wifi, that affect an event.

13. Responsibility for Guests. Renter assumes full responsibility for all guests, invitees, children, vendors, contractors, agents, representatives, and other persons who enter or attempt to enter the Venue in connection with Renter's event, whether or not Renter personally invited them or knew of their presence. Renter is responsible for their conduct, safety, supervision, compliance with this Agreement, and any damages, injuries, claims, or violations they cause.

14. Indemnification, Hold Harmless, and Release. To the fullest extent permitted by law, Renter shall defend, indemnify, release, and hold harmless Owner, MAD Productions, LLC, The Boardroom QC, the Venue, the property owner, and their respective members, managers, officers, employees, agents, representatives, affiliates, successors, and assigns from and against any and all claims, demands, causes of action, damages, losses, liabilities, injuries, deaths, property damage, fines, citations, judgments, settlements, attorney fees, court costs, and expenses arising from or related to Renter's event, Renter's use of the Venue, the presence or conduct of any guest or vendor, alcohol or food service, prohibited items, cleanup, damage, or any breach of this Agreement.

15. Attorney Fees, Costs, and Collection. If Owner must enforce this Agreement, collect amounts owed, defend or pursue any claim related to Renter's event, recover damages, respond to third-party claims, or take legal action because of Renter's breach or the acts or omissions of Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives, Renter shall be responsible for all reasonable attorney fees, court costs, collection costs, expert fees, investigation costs, and other expenses incurred by Owner, in addition to all damages and amounts otherwise owed.

16. Cancellation by Renter. Renter may cancel this Agreement by providing written notice to Owner. The deposit of fifty percent (50%) of the invoice holds the date. If Owner receives written cancellation sixty (60) days or more before the event, that deposit is refundable, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. If cancellation is received less than sixty (60) days before the event, the deposit is not refundable. The card processing fee is non-refundable. Refunds are issued manually.

17. Cancellation by Owner. Owner may decline any request and may cancel this Agreement at any time by providing written notice to Renter. If Owner declines or cancels a request before any payment is made, no money is taken. If a payment was recorded before a decline, staff resolve it manually. Guest cancellation of the deposit follows section 16. If Owner cancels because of Renter's breach, failure to pay, failure to provide proof of insurance, misrepresentation, safety concern, illegal activity, prohibited activity, or violation of this Agreement, Owner may retain amounts permitted under this Agreement and pursue any other available remedy.

18. Compliance with Laws and Rules. Renter shall comply with all applicable federal, state, county, municipal, fire, building, health, alcohol, noise, occupancy, and safety laws, rules, ordinances, and regulations, including those applicable in the City of Moline, Rock Island County, and the State of Illinois. Renter shall also comply with all reasonable rules and instructions provided by Owner before, during, or after the event.

19. Governing Law; Venue and Jurisdiction. This Agreement shall be governed by and interpreted under the laws of the State of Illinois. Any lawsuit, claim, collection action, or dispute arising out of or relating to this Agreement, the Venue, the event, or the Parties' rights and obligations shall be filed in a court of competent jurisdiction located in Rock Island County, Illinois, unless applicable law requires otherwise.

20. No Waiver; Modification; Severability. No waiver, amendment, or modification of this Agreement is effective unless in writing and signed by an authorized representative of the Party against whom enforcement is sought. Failure to enforce any provision is not a waiver of future enforcement. If any provision is found invalid or unenforceable, the remaining provisions shall remain in full force and effect to the fullest extent permitted by law.

21. Entire Agreement; Authority to Sign. This Agreement, together with any invoice, booking confirmation, written addendum, or written rules provided by Owner, constitutes the entire agreement between the Parties regarding the rental and use of the Venue. The person signing for Renter represents that he or she has authority to bind Renter and accepts personal responsibility if such authority does not exist.

IN WITNESS WHEREOF, the Parties acknowledge that they have read, understand, and agree to be bound by this Agreement as of the date(s) signed below.

THE RENTER

Name: __________________________________

Entity, if any: __________________________________

Signature: __________________________________

Title/Capacity: __________________________________

Phone/Email: __________________________________

Date: __________________________________

THE OWNER

MAD Productions, LLC

By: 

Name: 

Title: 

Date:

Rental Date:________________________________

Facility Event Space Rental Agreement | The Boardroom QC | 2220 37th Street, Moline, IL 61265
AGREEMENT_BODY;
    $html = '<p class="noprint"><button type="button" class="btn" onclick="window.print()">Print this agreement</button></p>';
    foreach (preg_split("/\n\s*\n/", trim($raw)) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $html .= '<p>' . esc_html($part) . '</p>';
    }
    return $html;
}

function brb_upsert_legal_page($slug, $legacy, $title, $html) {
    $page = get_page_by_path($slug);
    $old = null;
    foreach ((array) $legacy as $prior) {
        if ($prior === $slug) continue;
        $found = get_page_by_path($prior);
        if (!$found) continue;
        if (!$page) {
            $page = $found;
        } elseif ((int) $found->ID !== (int) $page->ID) {
            $old = $found;
        }
    }
    kses_remove_filters();
    // Page writes must not depend on WP_POST_REVISIONS. That constant is not
    // defined until after plugins_loaded, and a revision save there fatals the site.
    remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
    remove_action('post_updated', 'wp_save_post_revision', 10);
    $fields = [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $html,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ];
    if (!$page) {
        $id = wp_insert_post($fields, true);
    } else {
        $fields['ID'] = (int) $page->ID;
        $id = wp_update_post($fields, true);
    }
    add_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9, 3);
    add_action('post_updated', 'wp_save_post_revision', 10, 1);
    kses_init_filters();
    if (is_wp_error($id) || !$id) return 0;
    clean_post_cache((int) $id);
    if ($old) {
        kses_remove_filters();
        remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
        remove_action('post_updated', 'wp_save_post_revision', 10);
        wp_update_post(['ID' => (int) $old->ID, 'post_status' => 'draft']);
        add_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9, 3);
        add_action('post_updated', 'wp_save_post_revision', 10, 1);
        kses_init_filters();
        clean_post_cache((int) $old->ID);
    }
    return (int) $id;
}

function brb_install_checklist_page() {
    brb_upsert_legal_page('rental-checklist', [], 'Rental Checklist', brb_checklist_stored_markup());
}
add_shortcode('brb_checklist', function () {
    return brb_checklist_html();
});
function brb_install_legal_pages() {
    $privacy = brb_upsert_legal_page('privacy', ['privacy-policy'], 'Privacy Policy', brb_privacy_html());
    brb_upsert_legal_page('terms', ['terms-and-conditions'], 'Rental Terms', brb_terms_html());
    brb_upsert_legal_page('rental-agreement', [], 'Rental Agreement', brb_agreement_html());
    if ($privacy) update_option('wp_page_for_privacy_policy', $privacy);
}

function brb_legacy_legal_redirect() {
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $home_path = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home_path !== '' && strpos($path, $home_path . '/') === 0) {
        $path = substr($path, strlen($home_path) + 1);
    }
    $map = [
        'privacy-policy' => '/privacy/',
        'terms-and-conditions' => '/terms/',
    ];
    if (!isset($map[$path])) return;
    wp_safe_redirect(home_url($map[$path]), 301);
    exit;
}
