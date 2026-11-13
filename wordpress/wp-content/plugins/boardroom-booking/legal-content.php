<?php
if (!defined('ABSPATH')) exit;

function brb_cancellation_summary() {
    return 'Bookings are subject to approval. No payment is due until the request is approved. The owner may decline any request. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, 2220 37th Street, Moline, IL 61265. You can pay by check or in person, or online by card with a 3% processing fee once the request is approved. The card processing fee is non-refundable. Under the Rental Agreement, 50% of the invoice is due within 1 day after signing unless the Owner agrees otherwise in writing. The remaining balance is due no later than one week before the event. The signed Rental Agreement controls if it differs from this summary.';
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
    // [CONFIRM with Chris] Guests out by 11:30 PM. Smoking is outside only. Service animals only.
    $html = <<<'TERMS_HTML'
<p>Thanks for choosing The Boardroom! This page sums up our Facility Event Space Rental Agreement in plain language, section by section. It doesn't replace the agreement. If the two ever differ, the signed agreement wins. You can read and print the full <a href="/rental-agreement/">Rental Agreement</a> anytime.</p>
<p>The Boardroom QC is operated by Boardroom QC LLC ("we" or "the Owner") at 2220 37th Street, Moline, IL 61265.</p>

<h2>The quick version</h2>
<ul>
<li>Hours: Each booking is one full day, 10:00 AM to 11:30 PM. Book at least 48 hours ahead.</li>
<li>Out by 11:30 PM. This one's firm, no exceptions.</li>
<li>You set up and tear down. Our staff can't set the room for you. Setup starts at 8:00 AM on full days.</li>
<li>Full days start at $850. The price for a date is on the calendar.</li>
<li>Up to 80 guests. Open houses and graduation parties can go over, since guests come and go. The host must be 25 or older.</li>
<li>Free events only. Live music and bands are welcome. Tickets, cover charges, and concerts aren't.</li>
<li>BYOB is fine. The microwave, blender, food warmers and bar area are yours to use. The stovetops and oven can't be used. There are no draft taps.</li>
<li>Renters insurance covering your event is required.</li>
<li>You may bring and put up your own decorations. Balloons, yes. Glitter, confetti, confetti cannons, rice, birdseed, and loose sequins, no. Wall tape, no.</li>
<li>No smoking or vaping inside. Smoking is outside only.</li>
<li>Service animals only. No pets, and no overnight stays.</li>
</ul>

<h2>1. Your rental time</h2>
<p>Your rental period is the date and times on your invoice, booking confirmation, or written OK from us. Each booking is one full day, from 10:00 AM to 11:30 PM. Book at least 48 hours ahead. Please don't arrive before setup, stay past your end time, or use the space for anything other than your approved event without checking with us in writing first. Doing so can mean extra charges, a lost deposit, or other remedies.</p>

<h2>2. Keys and codes</h2>
<p>We'll give you a key, code, or other way in. Please keep it safe, don't copy it, and only share it with your approved event helpers. Return any physical keys as soon as your rental ends. If something is lost or used without permission by you or anyone with your event, you'll cover rekeying, lock or code changes, and related costs.</p>

<h2>3. Payment and deposit</h2>
<ul>
<li>Within 1 day of signing: 50% of your invoice. This deposit holds your date. A request alone doesn't.</li>
<li>The remaining balance is due no later than one week before your event.</li>
<li>Before your event starts: all rental amounts and the damage deposit must be paid.</li>
<li>Sales tax is included in the price.</li>
<li>Full days start at $850. The price for your date is on the calendar, and we confirm the final price by email.</li>
<li>Pay by check, in person, or online by card once approved. Card payments add a 3% processing fee on top of the amount paid, and that fee isn't refundable.</li>
<li>Make checks payable to Boardroom QC LLC at The Boardroom, 2220 37th Street, Moline, IL 61265.</li>
<li>Late payment may cancel your reservation without further notice.</li>
</ul>

<h2>4. Guests and supervision</h2>
<p>Up to 80 guests. Open houses and graduation parties can go over, since guests come and go. Any other number over 80 needs our approval in writing. The host must be 25 or older, and you (or a responsible adult you name) must be on site the whole time. You're in charge of everyone who comes in: guests, kids, vendors, and anyone else. Service animals are welcome where the law requires; pets aren't.</p>

<h2>5. Event insurance</h2>
<p>You must carry renters insurance that covers your event, including injury, property damage and your guests' activities. We may ask for proof before giving you access, and may ask that Boardroom QC LLC, Mad Rentals LLC, and Mad Productions LLC, and each of their members and managers (collectively, the "Released Parties"), The Boardroom QC, the venue owner, and related parties be listed as additional insureds where your policy allows. That coverage should apply to all covered claims, including claims arising from the negligence of the Released Parties, except for gross negligence or willful misconduct. We also recommend special event or party liability insurance, plus liquor liability coverage if alcohol is involved. Without acceptable proof, we may cancel or refuse access, and amounts already owed still apply.</p>

<h2>6. Vendors</h2>
<p>Bringing in a caterer, bartender, DJ, decorator, or rental company? Make sure they're licensed, insured, and qualified, and send us their proof of insurance at least 7 days before your event (or sooner if we ask). We may ask to be listed as an additional insured. Your vendors are your responsibility, including their work, their cleanup, and any damage or charges.</p>

<h2>7. Food, drinks, and equipment</h2>
<p>We provide the space; you bring the rest. We don't supply food, drinks, catering, bartending, security, medical services, or event staff.</p>
<p>BYOB is welcome. You're responsible for following all food and alcohol laws, including age limits and safe rides home.</p>
<p>You use the kitchen, furniture, and equipment at your own risk. The microwave, blender and food warmers are fine. The stovetops and oven can't be used. We're not responsible for equipment hiccups, like the ice maker or wifi, that affect your event.</p>

<h2>8. Please leave these at home</h2>
<p>To keep everyone safe and the space clean, these aren't allowed inside, outside, on the sidewalks, or in the parking lot:</p>
<ul>
<li>Glitter, confetti, confetti cannons, rice, birdseed, or loose sequins</li>
<li>Wall tape</li>
<li>Fireworks, sparklers, smoke bombs, pyrotechnics, or explosive or combustible props</li>
<li>Open flame, unless we approve it in writing</li>
<li>Hazardous materials or illegal substances</li>
<li>Weapons, except as the law allows</li>
<li>Anything that creates a big mess, a fire or safety risk, a nuisance, or a disturbance</li>
</ul>
<p>Tickets, cover charges, paid admission, door fees, and ticket or merchandise sales aren't allowed either.</p>
<p>Breaking these rules can end your event on the spot, forfeit your deposit, and add cleaning, repair, legal, or other costs.</p>

<h2>9. Condition of the space</h2>
<p>The space is rented as-is. Take a quick look when you arrive and let us know right away about anything already damaged. Please leave everything (rooms, restrooms, kitchen, floors, walls, furniture, décor, equipment, entry, sidewalks, and parking area) as clean and arranged as you found it. Normal wear and tear is fine.</p>

<h2>10. Cleanup</h2>
<p>You're responsible for leaving the space clean. Before your rental time ends, you and your vendors must:</p>
<ul>
<li>Bag all trash and take it to the dumpster on site, or wherever we direct.</li>
<li>Take out everything you brought: decorations, food, drinks, rentals, vendor items, and personal belongings.</li>
<li>Clean up spills, food, and debris on floors, tables, counters, the kitchen, and restrooms.</li>
<li>Leave the space in the same condition and arrangement you found it in, normal wear and tear excepted (see section 9).</li>
</ul>
<p>If you don't, we may do the work ourselves or hire a cleaning crew, hauler, or other help. You'll owe the reasonable cost of the cleaning, the trash or debris removal, the pickup or disposal of items left behind, and the labor ("cleanup costs"). Anything left behind may be treated as abandoned and tossed or stored at your cost. Cleanup costs may be taken from your damage deposit (section 11) or billed to you.</p>

<h2>11. Damage and your damage deposit</h2>
<p>Your refundable damage deposit is a flat $250. It is charged with the booking deposit and covers both cleanup and damage. It's separate from the 50% booking deposit that holds your date.</p>
<p><strong>What we may keep.</strong> Boardroom QC LLC may keep all or part of your damage deposit for reasonable costs tied to your event. That includes cleaning crews or extra cleaning; trash, garbage, or debris removal; pickup, removal, storage, or disposal of items left behind; stain, odor, or vape residue removal; repair of damage beyond normal wear and tear; and the labor for any of these.</p>
<p><strong>Your refund.</strong> After your event, once the space is checked, we'll refund the deposit, minus anything we keep, by your original payment method: through Stripe for card payments (less the card processing fee) or by check. If we keep any of it, we'll send you an itemized statement of what we kept and why. The refund and any statement go out within 7 days after your event.</p>
<p><strong>Costs beyond the deposit.</strong> The deposit is not a cap on what you owe. You're responsible for any damage, loss, theft, extra cleaning, fines, lost use of the venue, or other costs tied to your event or anyone connected with it, even if they're more than the deposit. We may apply your deposit to what's owed and bill you for the rest. Section 15 covers collection costs.</p>

<h2>12. Your risk</h2>
<p>Events come with some risk, including injury, property damage, and in rare cases serious harm. By renting, you accept those risks for yourself, your guests, and your vendors. We're not responsible for accidents, injuries, loss, theft, or damage connected to your event, except where the law says otherwise.</p>

<h2>13. Your guests</h2>
<p>You're responsible for everyone connected to your event, invited or not, including their conduct, safety, and any damage or claims they cause.</p>

<h2>14. Covering us (indemnification)</h2>
<p>To the fullest extent the law allows, you agree to defend, cover, and hold harmless the Released Parties, The Boardroom QC, the venue, the property owner, and their team against any claims, losses, injuries, fines, legal fees, or costs related to your event, your guests or vendors, food or alcohol, prohibited items, cleanup, damage, or any breach of the agreement. This covers all such claims, including claims arising from the negligence of the Released Parties, except for gross negligence or willful misconduct.</p>

<h2>15. Legal and collection costs</h2>
<p>If we have to enforce the agreement, collect what's owed, or handle a claim related to your event, you'll cover our reasonable attorney fees, court and collection costs, and related expenses, on top of what you owe.</p>

<h2>16. If you need to cancel</h2>
<p>Just let us know in writing.</p>
<ul>
<li>60 or more days before your event: your deposit is refunded, minus the 3% card fee and any costs already incurred.</li>
<li>Less than 60 days before: the deposit isn't refundable.</li>
</ul>
<p>Refunds are processed by hand.</p>

<h2>17. If we need to cancel</h2>
<p>We may decline any request, or cancel with written notice. If we decline before you've paid, nothing is charged. If a payment was already made, we'll sort it out with you directly.</p>
<p>If weather or an emergency outside our control (like a snowstorm, severe storm, or power outage) cancels your event, you'll get a refund. Personal issues, like a flat tire, aren't refundable.</p>
<p>If we cancel because of a missed payment, missing insurance, misrepresentation, a safety concern, prohibited activity, or another breach, we may keep what the agreement allows and pursue other remedies.</p>

<h2>18. Following the rules</h2>
<p>Please follow all federal, Illinois, Rock Island County, and City of Moline laws, including fire, building, health, alcohol, noise, occupancy, and safety rules, plus any reasonable instructions from our team.</p>

<h2>19. Governing law</h2>
<p>Illinois law applies. Any legal dispute goes to court in Rock Island County, Illinois, unless the law requires otherwise.</p>

<h2>20. Changes in writing</h2>
<p>Any change or waiver has to be in writing and signed. If we let something slide once, that doesn't waive it later. If any part is found invalid, the rest still stands.</p>

<h2>21. The whole agreement</h2>
<p>The signed agreement, together with your invoice, booking confirmation, and any written addendum or rules from us, is the entire deal. Whoever signs for the renter confirms they're authorized to, and is personally responsible if they're not.</p>

<h2>22. Recording</h2>
<p>The venue may use audio and video recording, including security cameras in common areas and outside. Cameras are not used in restrooms. By entering, you and your guests consent to that recording. Please tell your guests before they come.</p>

<h2>How booking works</h2>
TERMS_STEPS
<p>One heads-up: the room isn't set up when you arrive. It doesn't come decorated, though our Christmas décor is up in December. You may bring and put up your own decorations. Glitter, confetti, confetti cannons, rice, birdseed, and loose sequins aren't allowed.</p>

<h2>The signed agreement controls</h2>
<p>This page is a friendly summary. If anything here differs from your signed <a href="/rental-agreement/">Rental Agreement</a>, the agreement controls.</p>
<p>Questions? We're happy to help. <a href="/contact/">Get in touch</a>.</p>
<p class="updated">Last updated: November 13, 2026</p>
<p>The Boardroom QC · 2220 37th Street, Moline, IL 61265 · <a href="https://maps.app.goo.gl/nVqUsGTcy48nPzc18" rel="noopener noreferrer">Get directions</a></p>
TERMS_HTML;
    $steps = '';
    foreach (brb_reserve_explainer_steps() as $step) $steps .= '<li>' . $step . '</li>';
    return str_replace('TERMS_STEPS', '<ol>' . $steps . '</ol>', $html);
}

function brb_privacy_html() {
    return <<<'PRIVACY_HTML'
<p>This policy describes what Boardroom QC LLC, doing business as The Boardroom QC, at 2220 37th Street, Moline, IL 61265, collects on this website and how it is used.</p>

<h2>Information we collect</h2>
<p>The booking form asks for your name, email, event date and time, party size, confirmation that the event is free to attend, and a description of the event. The contact form asks for your name, email, and a message. You can also give preferred dates. When a check is received, staff enter the check number and the payment record by hand. When a card is used, Stripe processes the payment and this site stores the amount, the processing fee, and the payment id. We never see or store card numbers. That record is stored with the booking. A contact inquiry is stored with the inquiry. Messages submitted through the chat assistant are stored with the inquiry.</p>

<h2>How we use it</h2>
<p>We use this information only to manage reservations and contact inquiries, and to communicate with you about them. Email about a booking or a contact inquiry is sent through this site’s mail system.</p>

<h2>Payment</h2>
<p>You can pay by check or in person, or online by card with a 3% processing fee, after a request is approved. Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, 2220 37th Street, Moline, IL 61265. Stripe processes card payments. We never see or store card numbers. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. The card processing fee is non-refundable.</p>

<h2>Cookies</h2>
<p>This site sets no advertising, analytics, or tracking cookies of its own, but the embedded Google Maps in the footer and on the Contact page may set cookies from Google, and Google's privacy policy applies to those cookies. Stripe may set cookies on its own checkout page, and Stripe's privacy policy applies to those cookies. Guests are not asked to accept cookies. The contact form does not set a cookie. The chat assistant does not set a cookie. WordPress sets cookies for staff when they log in to the admin area.</p>
<p>Staff reports also remember the first page and the referring site in a first-visit cookie. That cookie does not store a name or an email. Page views are counted on the server. If a Google Analytics measurement id is saved, and this site is allowed to be indexed, Google's tag may set cookies. Google's privacy policy applies to those cookies.</p>

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
<p class="updated">Last updated: November 3, 2026</p>
PRIVACY_HTML;
}

function brb_agreement_html() {
    $raw = <<<'AGREEMENT_BODY'
FACILITY EVENT SPACE RENTAL AGREEMENT

County of Rock Island | City of Moline | State of Illinois

This Facility Event Space Rental Agreement (the "Agreement") is made and entered into as of the date signed below by and between Boardroom QC LLC, an Illinois limited liability company (the "Owner"), and the individual or entity identified below as the renter (the "Renter"). Owner and Renter may be referred to individually as a "Party" and collectively as the "Parties."

WHEREAS, Owner controls and operates the event space commonly known as The Boardroom QC, located at 2220 37th Street, Moline, Illinois 61265 (the "Venue");

WHEREAS, Renter desires to temporarily rent, occupy, and use the Venue for the limited event purpose described in the related invoice, booking confirmation, or written approval from Owner; and

WHEREAS, Owner agrees to allow such temporary use only under the terms, conditions, covenants, waivers, insurance requirements, indemnities, and restrictions stated in this Agreement.

NOW, THEREFORE, in consideration of the mutual promises and covenants stated herein, the Parties agree as follows:

1. Rental Period and Limited Use. Renter shall have access to and use of the Venue only during the date(s) and time(s) stated on the invoice, booking confirmation, or written approval issued by Owner (the "Rental Period"). Renter shall not enter before the setup time, remain after the end of the Rental Period, or use the Venue for any purpose other than the approved event without Owner's prior written consent. Setup on the morning of the event is included. Renters set up and tear down. Staff cannot set the room up. The Venue does not come decorated, except that Owner's Christmas décor stays up in December. Renter may bring and put up Renter's own decorations. Glitter and confetti are not allowed. On a full day, setup can begin at 8:00 AM. Balloons are allowed. Wall tape is not allowed. Overnight stays are not permitted. The Venue is an event space only. Each booking is one full day only. Half-day and hourly rentals are not offered. A booking must begin at least 48 hours after it is requested, unless Owner agrees otherwise in writing. A full day runs from 10:00 AM to 11:30 PM. Everyone must be out of the Venue by 11:30 PM. This is firm, with no exceptions. All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. Guests may have a band or live music, as long as they are not charging for the event. Owner does not host concerts or ticketed shows. Any entry before the setup time, late departure, or unapproved use may result in additional charges, loss of deposit, and any other remedies available to Owner.

2. Access, Keys, and Codes. Owner may provide Renter with one (1) key, access code, or other entry method as deemed necessary by Owner. Renter shall safeguard all keys, codes, and access credentials, shall not duplicate or share them except with approved event representatives, and shall return all physical access items immediately at the end of the Rental Period. Renter is responsible for all costs related to lost keys, rekeying, lock changes, code changes, or unauthorized access caused by Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives.

3. Payment, Deposit, and Fees. Before the beginning of the Rental Period, Renter shall pay all rental fees, the damage deposit, and any other charges stated by Owner. Unless Owner agrees otherwise in writing, Renter must pay fifty percent (50%) of the invoice within one (1) day after signing this Agreement. That deposit holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. A request does not reserve the date until the deposit is received. The remaining balance is due no later than one week before the event. Published full-day rates start at $850. The price for a date is the rate shown on the booking calendar, and Owner confirms the final price in writing. Sales tax is included in the price. The refundable damage deposit is a flat $250. It is charged with the booking deposit, is separate from the 50% deposit that holds the date, and secures Renter's cleanup and damage obligations under Sections 9 through 11. It is refunded, less any amounts retained under Section 11, by Renter's original payment method: through Stripe for card payments, less the card processing fee, or by check. Failure to timely pay may result in cancellation of the reservation without further notice.

4. Occupancy, Conduct, and Supervision. The Venue shall not be occupied by more than 80 guests unless the event is an open house or a graduation party, in which case guests may come and go above that number, or Owner gives written approval for a different limit. The minimum host age is 25 for every booking. Renter shall be present or shall designate a responsible adult representative who is present during the entire event. Renter is solely responsible for supervising all guests, invitees, children, vendors, contractors, and any other persons entering the Venue during the Rental Period. No pets or animals of any kind are permitted, except documented service animals as required by applicable law.

5. Required Renters Insurance. As a condition of using the Venue, Renter shall obtain and maintain, at Renter's sole cost, renters insurance covering the event and the Rental Period, including bodily injury, property damage, and the activities of Renter and Renter's guests. Owner may require proof of such insurance before granting access to the Venue. Owner recommends that Renter also obtain special event liability or party liability insurance, and liquor liability coverage or a special event liquor liability endorsement if alcohol is served. Owner may require that Boardroom QC LLC, Mad Rentals LLC, and Mad Productions LLC, and each of their members and managers (collectively, the "Released Parties"), The Boardroom QC, the Venue owner, and any related parties designated by Owner be named as additional insureds where the policy allows. Such insurance and additional-insured coverage shall apply to all covered claims, including claims arising from the negligence of the Released Parties, except for gross negligence or willful misconduct. Failure to provide acceptable proof of insurance may result in cancellation or denial of access, without waiver of any amounts owed.

6. Outside Vendors, Caterers, and Contractors. If Renter uses any caterer, bartender, entertainer, decorator, rental company, vendor, contractor, or other third party, Renter shall ensure that each third party is properly licensed, insured, and qualified for the services provided. At least seven (7) days before the Rental Period, or earlier if requested by Owner, Renter shall provide proof of insurance for any outside vendor, with Owner listed as an additional insured when requested. Renter remains fully responsible for all acts, omissions, damages, injuries, cleanup, violations, and charges caused by Renter's vendors or contractors.

7. Food, Alcohol, Kitchen, and Equipment. Owner does not provide food, beverages, alcohol, catering, bartending, food service, security, medical services, or event supervision. Renter is solely responsible for compliance with all laws, rules, and regulations involving food, alcohol, service, consumption, sales, licensing, age restrictions, intoxication, and transportation after the event. BYOB is allowed. Renter may use the microwave, blender, and food warmers. The stovetops and oven may not be used. Renter accepts all risk and liability arising from the use of kitchen utensils, appliances, equipment, furniture, fixtures, décor, or other property at the Venue, whether used properly or improperly.

8. Strictly Prohibited Items and Activities. The following are strictly prohibited inside the Venue, outside the Venue, on sidewalks, in parking areas, and anywhere on or around the premises: glitter; wall tape; party confetti; confetti cannons; rice; birdseed; loose sequins; fireworks; sparklers; smoke bombs; pyrotechnics; explosive devices; explosive props; combustible props; open flame not expressly approved in writing by Owner; hazardous materials; illegal substances; weapons except as permitted by law; ticketed events; cover charges; paid admission; door fees; ticket or merchandise sales; concerts; and any activity that creates unusual mess, fire risk, explosion risk, property damage risk, injury risk, nuisance, or disturbance. A band or live music is allowed only when guests are not charged for the event. Violation of this section may result in immediate termination of the event, forfeiture of deposit, additional cleaning or repair charges, and liability for all resulting damages, claims, fines, attorney fees, and costs.

9. Condition of Premises; Return in Same Condition. Renter accepts the Venue in its existing "AS IS" condition at the time Renter takes possession. Renter shall inspect the Venue upon arrival and shall immediately report any pre-existing damage or concern to Owner. Renter shall leave the Venue, fixtures, furniture, restrooms, kitchen areas, floors, walls, décor, equipment, sidewalks, entry areas, and parking-related areas in the same condition and arrangement as when Renter arrived, ordinary wear and tear excepted. The Venue must be left clean, orderly, secure, and free of personal property, decorations, trash, food, spills, stains, debris, glitter, confetti, and any items brought in by Renter or Renter's guests or vendors.

10. Cleanup, Trash, and Personal Property. Before the Rental Period expires, and at Renter's sole cost, Renter shall: (a) leave the Venue clean and in the same condition and arrangement as when Renter took possession, ordinary wear and tear excepted, as further provided in Section 9; (b) place all trash, garbage, and debris in closed bags and deposit them in the on-site dumpster or another location directed by Owner; (c) remove all personal property, decorations, food, beverages, vendor property, rental items, and any other items not present when Renter took control of the Venue; and (d) clean all spills, food, residue, and debris from floors, furniture, counters, kitchen areas, restrooms, entry areas, sidewalks, and parking-related areas. Renter shall cause Renter's guests, invitees, vendors, and contractors to comply with this Section. Any property left behind may be deemed abandoned and may be discarded, removed, or stored at Renter's expense, and Owner shall have no liability for that property. If Renter fails to comply with this Section, Owner may, without further notice to Renter, perform, or engage one or more third parties (including cleaning crews, haulers, and disposal services) to perform, any cleaning, trash or debris removal, item pickup, removal, storage, or disposal, odor or stain removal, specialty cleaning, or remediation, including any excessive cleaning caused by Renter, guests, invitees, or vendors. Renter shall be liable for the reasonable cost of all such work, including labor, materials, hauling, and disposal fees (collectively, "Cleanup Costs"). Cleanup Costs may be deducted from the damage deposit under Section 11 or billed to Renter separately.

11. Damage, Damage Deposit, Liability, and Legal Action. Renter is financially responsible for all damage, loss, theft, excessive cleaning, fines, citations, violations, business interruption, loss of use, loss of reputation, and any other costs or damages arising from or related to Renter's event, Renter's use of the Venue, or the acts or omissions of Renter's guests, invitees, vendors, contractors, agents, representatives, or any person entering the Venue during the Rental Period. Damage outside ordinary wear and tear may result in forfeiture of the damage deposit, additional charges, civil liability, and legal action. The damage deposit secures Renter's obligations under Sections 8, 9, and 10 and this Section 11. Owner may retain all or any part of the damage deposit to pay Cleanup Costs, the cost of repairing damage beyond ordinary wear and tear, and any other amounts owed under this Agreement. Within seven (7) days after the end of the Rental Period, Owner shall refund the damage deposit, less any amounts retained, by Renter's original payment method as provided in Section 3, and, if any amount is retained, shall provide Renter with an itemized statement of the amounts retained and the reasons for them. The damage deposit is not liquidated damages and is not a limit or cap on Renter's liability. Owner may apply the damage deposit toward any amounts owed, but application of the deposit does not limit Renter's responsibility for amounts exceeding the deposit. Renter shall pay any such excess upon Owner's written demand, and Section 15 applies to its collection.

12. Assumption of Risk; Accidents and Injuries. Renter understands that event activities, use of the Venue, use of furniture, fixtures, equipment, parking areas, sidewalks, curbs, entrances, exits, kitchen areas, and alcohol or food consumption may involve risks, including serious injury, temporary or permanent disability, death, and property damage. Renter voluntarily assumes all risks arising from the event and the use of the Venue by Renter, Renter's guests, invitees, vendors, contractors, agents, and representatives. Owner is not responsible for accidents, injuries, death, loss, theft, or property damage involving Renter or any guest, invitee, child, vendor, contractor, agent, representative, or other person connected to Renter's event, except to the extent such limitation is prohibited by applicable law. Renter and guests assume all risk. Owner is not responsible for injury, loss, or equipment issues, such as the ice maker or wifi, that affect an event.

13. Responsibility for Guests. Renter assumes full responsibility for all guests, invitees, children, vendors, contractors, agents, representatives, and other persons who enter or attempt to enter the Venue in connection with Renter's event, whether or not Renter personally invited them or knew of their presence. Renter is responsible for their conduct, safety, supervision, compliance with this Agreement, and any damages, injuries, claims, or violations they cause.

14. Indemnification, Hold Harmless, and Release. To the fullest extent permitted by law, Renter shall defend, indemnify, release, and hold harmless Owner, the Released Parties, The Boardroom QC, the Venue, the property owner, and their respective members, managers, officers, employees, agents, representatives, affiliates, successors, and assigns from and against any and all claims, demands, causes of action, damages, losses, liabilities, injuries, deaths, property damage, fines, citations, judgments, settlements, attorney fees, court costs, and expenses arising from or related to Renter's event, Renter's use of the Venue, the presence or conduct of any guest or vendor, alcohol or food service, prohibited items, cleanup, damage, or any breach of this Agreement. This release and indemnity applies to all such claims, including claims arising from the negligence of the Released Parties, except for gross negligence or willful misconduct.

15. Attorney Fees, Costs, and Collection. If Owner must enforce this Agreement, collect amounts owed, defend or pursue any claim related to Renter's event, recover damages, respond to third-party claims, or take legal action because of Renter's breach or the acts or omissions of Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives, Renter shall be responsible for all reasonable attorney fees, court costs, collection costs, expert fees, investigation costs, and other expenses incurred by Owner, in addition to all damages and amounts otherwise owed.

16. Cancellation by Renter. Renter may cancel this Agreement by providing written notice to Owner. The deposit of fifty percent (50%) of the invoice holds the date. If Owner receives written cancellation sixty (60) days or more before the event, that deposit is refundable, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. If cancellation is received less than sixty (60) days before the event, the deposit is not refundable. The card processing fee is non-refundable. Refunds are issued manually.

17. Cancellation by Owner. Owner may decline any request and may cancel this Agreement at any time by providing written notice to Renter. If Owner declines or cancels a request before any payment is made, no money is taken. If a payment was recorded before a decline, staff resolve it manually. Guest cancellation of the deposit follows section 16. If Owner cancels because of Renter's breach, failure to pay, failure to provide proof of insurance, misrepresentation, safety concern, illegal activity, prohibited activity, or violation of this Agreement, Owner may retain amounts permitted under this Agreement and pursue any other available remedy.

18. Compliance with Laws and Rules. Renter shall comply with all applicable federal, state, county, municipal, fire, building, health, alcohol, noise, occupancy, and safety laws, rules, ordinances, and regulations, including those applicable in the City of Moline, Rock Island County, and the State of Illinois. Renter shall also comply with all reasonable rules and instructions provided by Owner before, during, or after the event.

19. Governing Law; Venue and Jurisdiction. This Agreement shall be governed by and interpreted under the laws of the State of Illinois. Any lawsuit, claim, collection action, or dispute arising out of or relating to this Agreement, the Venue, the event, or the Parties' rights and obligations shall be filed in a court of competent jurisdiction located in Rock Island County, Illinois, unless applicable law requires otherwise.

20. No Waiver; Modification; Severability. No waiver, amendment, or modification of this Agreement is effective unless in writing and signed by an authorized representative of the Party against whom enforcement is sought. Failure to enforce any provision is not a waiver of future enforcement. If any provision is found invalid or unenforceable, the remaining provisions shall remain in full force and effect to the fullest extent permitted by law.

21. Entire Agreement; Authority to Sign. This Agreement, together with any invoice, booking confirmation, written addendum, or written rules provided by Owner, constitutes the entire agreement between the Parties regarding the rental and use of the Venue. The person signing for Renter represents that he or she has authority to bind Renter and accepts personal responsibility if such authority does not exist.

22. Recording Notice. The Venue may use audio and video recording, including security cameras in common areas and exterior areas. Cameras are not used in restrooms. By entering the Venue, Renter and Renter's guests, vendors, and other attendees consent to that recording. Renter shall inform Renter's guests, vendors, and other attendees that recording may be in use.

IN WITNESS WHEREOF, the Parties acknowledge that they have read, understand, and agree to be bound by this Agreement as of the date(s) signed below.

THE RENTER

Name: __________________________________

Entity, if any: __________________________________

Signature: __________________________________

Title/Capacity: __________________________________

Phone/Email: __________________________________

Date: __________________________________

THE OWNER

Boardroom QC LLC

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
    $html .= '<p class="updated">Last updated: November 13, 2026</p>';
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
