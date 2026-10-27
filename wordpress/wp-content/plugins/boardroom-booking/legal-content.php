<?php
if (!defined('ABSPATH')) exit;

function brb_cancellation_summary() {
    return 'A date saved on the website is held for 48 hours. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. If the check is not received by then, the hold is released. You can pay by check or in person, or online by card with a 3% processing fee. Under the Rental Agreement, 50% of the invoice is due within 1 day after signing unless the Owner agrees otherwise in writing. The invoice notes require full payment 30 days before the event. If you cancel in writing 90 days or more before the rental period, amounts paid may be refunded, less any non-refundable processing charges or costs already incurred. If you cancel less than 90 days before the rental period, security deposits and cleaning fees already paid are non-refundable. Rental fees are non-refundable if cancellation is received less than 30 days before the rental period, unless the Owner agrees otherwise in writing. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney]. The signed Rental Agreement controls if it differs from this summary.';
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
    return <<<'TERMS_HTML'
<p>These Rental Terms are a plain-language summary of the Facility Event Space Rental Agreement between MAD Productions, LLC, the Owner of The Boardroom QC, and the person or entity renting the space. They follow that agreement section by section. They do not replace it. If this page and the signed agreement differ, the signed agreement controls. The full text, with signature lines, is on the <a href="/rental-agreement/">Rental Agreement</a> page, which you can print. The venue is at 2220 37th Street, Moline, IL 61265.</p>

<h2>1. Rental period and limited use</h2>
<p>You may use the venue only during the date or dates and the times stated on the invoice, the booking confirmation, or a written approval from the Owner. That window is the rental period. You may not enter early, stay after the rental period ends, or use the venue for any purpose other than the approved event, unless the Owner agrees in writing first. Entering early, leaving late, or using the space without approval can bring additional charges, loss of the deposit, and any other remedy available to the Owner.</p>

<h2>2. Access, keys, and codes</h2>
<p>The Owner may give you one key, one access code, or another way to enter, as the Owner decides is needed. You must safeguard every key, code, and access credential. You may not duplicate them or share them except with approved event representatives. You must return every physical access item immediately when the rental period ends. You are responsible for the cost of lost keys, rekeying, lock changes, code changes, and unauthorized access caused by you or by your guests, invitees, vendors, contractors, agents, or representatives.</p>

<h2>3. Payment, deposit, and fees</h2>
<p>Before the rental period begins, you must pay all rental fees, cleaning fees, security deposits, and any other charges the Owner states. Unless the Owner agrees otherwise in writing, you must pay fifty percent (50%) of the invoice within one (1) day after signing the agreement. If you do not pay on time, the reservation may be cancelled without further notice.</p>

<h2>4. Occupancy, conduct, and supervision</h2>
<p>No more than seventy-five (75) persons may occupy the venue unless the Owner approves a different limit in writing. You must be present, or you must name a responsible adult who is present, for the entire event. You alone supervise every guest, invitee, child, vendor, contractor, and anyone else who enters during the rental period. Pets and other animals are not allowed, except a documented service animal when the law requires it.</p>

<h2>5. Required event liability insurance</h2>
<p>You must buy and keep, at your own cost, special event liability insurance or party liability insurance for the event and the rental period. It must cover bodily injury, property damage, and the activities of you and your guests. The Owner may require proof before giving you access. If alcohol is served, sold, distributed, or consumed, you must also obtain liquor liability coverage, or a special event liquor liability endorsement, if that coverage is available and applies. The Owner may require that MAD Productions, LLC, The Boardroom QC, the venue owner, and any related parties the Owner names be listed as additional insureds. If you do not provide acceptable proof of insurance, the Owner may cancel the event or refuse access. Amounts you already owe are not waived.</p>

<h2>6. Outside vendors, caterers, and contractors</h2>
<p>If you use a caterer, bartender, entertainer, decorator, rental company, vendor, contractor, or any other outside party, you must make sure each one is properly licensed, insured, and qualified for the work. At least seven (7) days before the rental period, or sooner if the Owner asks, you must give proof of insurance for any outside vendor. When the Owner asks, the Owner must be listed as an additional insured. You remain fully responsible for the acts, omissions, damage, injuries, cleanup, violations, and charges of your vendors and contractors.</p>

<h2>7. Food, alcohol, kitchen, and equipment</h2>
<p>The Owner does not provide food, beverages, alcohol, catering, bartending, food service, security, medical services, or event supervision. You are solely responsible for following every law, rule, and regulation about food, alcohol, service, consumption, sales, licensing, age limits, intoxication, and transportation after the event. You accept all risk and liability from using kitchen utensils, appliances, equipment, furniture, fixtures, décor, or any other property at the venue, whether it is used properly or improperly.</p>

<h2>8. Strictly prohibited items and activities</h2>
<p>The following are strictly prohibited inside the venue, outside the venue, on sidewalks, in parking areas, and anywhere on or around the premises: glitter; party confetti; confetti cannons; rice; birdseed; loose sequins; fireworks; sparklers; smoke bombs; pyrotechnics; explosive devices; explosive props; combustible props; open flame that the Owner has not expressly approved in writing; hazardous materials; illegal substances; weapons except as the law permits; and any activity that creates an unusual mess, a fire risk, an explosion risk, a property-damage risk, an injury risk, a nuisance, or a disturbance.</p>
<p>Breaking this section can end the event immediately, forfeit the deposit, add cleaning or repair charges, and leave you liable for resulting damages, claims, fines, attorney fees, and costs.</p>

<h2>9. Condition of the premises</h2>
<p>You accept the venue in its existing “AS IS” condition when you take possession. Inspect it when you arrive and tell the Owner immediately about any damage or concern that was already there. You must leave the venue, fixtures, furniture, restrooms, kitchen areas, floors, walls, décor, equipment, sidewalks, entry areas, and parking-related areas in the same condition and arrangement as when you arrived, ordinary wear and tear excepted. The venue must be left clean, orderly, secure, and free of personal property, decorations, trash, food, spills, stains, debris, glitter, confetti, and anything you, your guests, or your vendors brought in.</p>

<h2>10. Cleanup, trash, and personal property</h2>
<p>Before the rental period ends, remove all personal property, decorations, trash, food, beverages, vendor property, rental items, and anything else that was not there when you took control. Take the trash out. Anything left behind may be treated as abandoned and may be thrown away or stored at your expense. Excessive cleaning, specialty cleaning, remediation, odor removal, stain removal, or disposal caused by you, your guests, your invitees, or your vendors may be charged to you, deducted from the security deposit, or billed separately.</p>

<h2>11. Damage, security deposit, and legal action</h2>
<p>You are financially responsible for all damage, loss, theft, excessive cleaning, fines, citations, violations, business interruption, loss of use, loss of reputation, and any other cost or damage from your event, your use of the venue, or the acts or omissions of your guests, invitees, vendors, contractors, agents, representatives, or anyone who enters during the rental period. Damage beyond ordinary wear and tear can mean loss of the security deposit, further charges, civil liability, and legal action. The Owner may apply the security deposit to any amount you owe. Applying the deposit does not limit what you owe above the deposit.</p>

<h2>12. Assumption of risk</h2>
<p>Event activities and use of the venue, furniture, fixtures, equipment, parking areas, sidewalks, curbs, entrances, exits, kitchen areas, and food or alcohol can involve risk, including serious injury, temporary or permanent disability, death, and property damage. You voluntarily assume all risks from the event and from use of the venue by you, your guests, invitees, vendors, contractors, agents, and representatives. The Owner is not responsible for accidents, injuries, death, loss, theft, or property damage involving you or any guest, invitee, child, vendor, contractor, agent, representative, or other person connected with your event, except where the law does not allow that limit.</p>

<h2>13. Responsibility for guests</h2>
<p>You take full responsibility for every guest, invitee, child, vendor, contractor, agent, representative, and other person who enters or tries to enter in connection with your event, whether or not you invited them or knew they were there. You are responsible for their conduct, their safety, their supervision, their compliance with the agreement, and any damage, injury, claim, or violation they cause.</p>

<h2>14. Indemnification, hold harmless, and release</h2>
<p>To the fullest extent the law allows, you will defend, indemnify, release, and hold harmless the Owner, MAD Productions, LLC, The Boardroom QC, the venue, the property owner, and their members, managers, officers, employees, agents, representatives, affiliates, successors, and assigns. This covers every claim, demand, cause of action, damage, loss, liability, injury, death, property damage, fine, citation, judgment, settlement, attorney fee, court cost, and expense arising from or related to your event, your use of the venue, the presence or conduct of any guest or vendor, alcohol or food service, prohibited items, cleanup, damage, or any breach of the agreement.</p>

<h2>15. Attorney fees, costs, and collection</h2>
<p>If the Owner has to enforce the agreement, collect what you owe, defend or pursue a claim related to your event, recover damages, respond to a third-party claim, or take legal action because you breached the agreement or because of acts or omissions by you or your guests, invitees, vendors, contractors, agents, or representatives, you are responsible for the Owner’s reasonable attorney fees, court costs, collection costs, expert fees, investigation costs, and other expenses, in addition to the damages and other amounts owed.</p>

<h2>16. Cancellation by the renter</h2>
<p>You may cancel by giving the Owner written notice. If the Owner receives that notice ninety (90) days or more before the rental period begins, you may be entitled to a refund of amounts paid, less any non-refundable processing charges or costs the Owner has already incurred. If the notice arrives less than ninety (90) days before the rental period begins, security deposits and cleaning fees already paid are non-refundable. Rental fees are non-refundable if the cancellation is received less than thirty (30) days before the rental period begins, unless the Owner agrees otherwise in writing.</p>

<h2>17. Cancellation by the Owner</h2>
<p>The Owner may cancel at any time by written notice to you. If the Owner cancels for a reason that is not your breach, the Owner will refund the amounts you paid under the agreement. If the Owner cancels because you breached the agreement, failed to pay, failed to provide proof of insurance, misrepresented something, created a safety concern, engaged in illegal or prohibited activity, or otherwise violated the agreement, the Owner may keep the amounts the agreement allows and may pursue any other available remedy.</p>

<h2>18. Compliance with laws and rules</h2>
<p>You must comply with all applicable federal, state, county, and municipal laws, and with fire, building, health, alcohol, noise, occupancy, and safety laws, rules, ordinances, and regulations. That includes the rules of the City of Moline, Rock Island County, and the State of Illinois. You must also follow reasonable rules and instructions the Owner gives before, during, or after the event.</p>

<h2>19. Illinois law and Rock Island County</h2>
<p>The agreement is governed by the laws of the State of Illinois. Any lawsuit, claim, collection action, or dispute about the agreement, the venue, the event, or the parties’ rights must be filed in a court of competent jurisdiction in Rock Island County, Illinois, unless the law requires another court.</p>

<h2>20. No waiver, changes, and severability</h2>
<p>A waiver, amendment, or change is effective only if it is in writing and signed by an authorized representative of the party against whom it would be enforced. Not enforcing a provision once is not a waiver of later enforcement. If a provision is found invalid or unenforceable, the rest stays in effect to the fullest extent the law allows.</p>

<h2>21. Entire agreement and authority to sign</h2>
<p>The agreement, together with any invoice, booking confirmation, written addendum, or written rules from the Owner, is the entire agreement about renting and using the venue. The person who signs for the renter represents that they have authority to bind the renter, and accepts personal responsibility if they do not.</p>

<h2>House rules for food and drink</h2>
<div class="rules">
<p>These house rules are in addition to section 7 of the agreement.</p>
<ul>
<li>No cooking is allowed in the venue.</li>
<li>The stove top may not be used.</li>
<li>Food warmers (for keeping prepared food warm) and the bar area may be used.</li>
<li>There are no taps. Draft taps are not available.</li>
<li>BYOB is allowed.</li>
<li>The renter must obtain a one-day liquor license from the City of Moline, fee $50.</li>
</ul>
</div>

<h2>Booking and payment on this website</h2>
<p>Saving a date on this website holds it for 48 hours. If the check is not received within those 48 hours, the hold is released. You can pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265.</p>
<p>The card processing fee is added on top of the amount being paid and is not part of the booking balance. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney].</p>
<p>As the agreement states, 50% of the invoice is due within 1 day after signing. The invoice notes require full payment 30 days before the event.</p>
<p>The invoice notes also state that a refund of the 50% deposit is available only if the event is cancelled 90 days or more before the event, and that a cancellation inside that window may not be refunded. Section 16 above is more specific about rental fees, cleaning fees, and security deposits. The damage deposit described on the invoice is refundable after inspection. Section 11 above allows the Owner to apply the security deposit to amounts owed, and you remain responsible for anything above the deposit.</p>
<p>The room is not set up when you arrive. Seating and layout are specific to your event. Staff can help with setup for an extra fee. Decorations are not provided, except that Christmas decorations are up in December. The maximum is 75 people unless the Owner approves a different limit in writing.</p>

<h2>The signed agreement controls</h2>
<p>This page is a summary for guests of The Boardroom QC, operated by MAD Productions, LLC. If anything here differs from the signed Rental Agreement, the signed Rental Agreement controls. The full agreement is available to print at <a href="/rental-agreement/">the Rental Agreement</a>.</p>
<p class="updated">Last updated: October 27, 2026</p>
<!--[VERIFY: attorney review]-->
TERMS_HTML;
}

function brb_privacy_html() {
    return <<<'PRIVACY_HTML'
<p>This policy describes what MAD Productions, LLC, doing business as The Boardroom QC, at 2220 37th Street, Moline, IL 61265, collects on this website and how it is used.</p>

<h2>Information we collect</h2>
<p>The booking form asks for your name, email, event type, event date and time, party size, and notes. The contact form asks for your name, email, and a message. You can also say what the message is about, and a preferred date or time. When a check is received, staff enter the check number and the payment record by hand. When a card is used, Stripe processes the payment and this site stores the amount, the processing fee, and the payment id. We never see or store card numbers. That record is stored with the booking. A contact inquiry is stored with the inquiry.</p>

<h2>How we use it</h2>
<p>We use this information only to manage reservations and contact inquiries, and to communicate with you about them. Email about a booking or a contact inquiry is sent through this site’s mail system.</p>

<h2>Payment</h2>
<p>You can pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. Stripe processes card payments. We never see or store card numbers. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney].</p>

<h2>Cookies</h2>
<p>This site sets no advertising, analytics, or tracking cookies of its own, but the embedded Google Map on the Contact page may set cookies from Google, and Google's privacy policy applies to those cookies. Stripe may set cookies on its own checkout page, and Stripe's privacy policy applies to those cookies. Guests are not asked to accept cookies. The contact form does not set a cookie. WordPress sets cookies for staff when they log in to the admin area.</p>

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
<p class="updated">Last updated: October 27, 2026</p>
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

1. Rental Period and Limited Use. Renter shall have access to and use of the Venue only during the date(s) and time(s) stated on the invoice, booking confirmation, or written approval issued by Owner (the "Rental Period"). Renter shall not enter early, remain after the end of the Rental Period, or use the Venue for any purpose other than the approved event without Owner's prior written consent. Any unauthorized early entry, late departure, or unapproved use may result in additional charges, loss of deposit, and any other remedies available to Owner.

2. Access, Keys, and Codes. Owner may provide Renter with one (1) key, access code, or other entry method as deemed necessary by Owner. Renter shall safeguard all keys, codes, and access credentials, shall not duplicate or share them except with approved event representatives, and shall return all physical access items immediately at the end of the Rental Period. Renter is responsible for all costs related to lost keys, rekeying, lock changes, code changes, or unauthorized access caused by Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives.

3. Payment, Deposit, and Fees. Before the beginning of the Rental Period, Renter shall pay all rental fees, cleaning fees, security deposits, and any other charges stated by Owner. Unless Owner agrees otherwise in writing, Renter must pay fifty percent (50%) of the invoice within one (1) day after signing this Agreement. Failure to timely pay may result in cancellation of the reservation without further notice.

4. Occupancy, Conduct, and Supervision. The Venue shall not be occupied by more than seventy-five (75) persons unless Owner gives written approval for a different limit. Renter shall be present or shall designate a responsible adult representative who is present during the entire event. Renter is solely responsible for supervising all guests, invitees, children, vendors, contractors, and any other persons entering the Venue during the Rental Period. No pets or animals of any kind are permitted, except documented service animals as required by applicable law.

5. Required Event Liability Insurance. As a condition of using the Venue, Renter shall obtain and maintain, at Renter's sole cost, special event liability insurance or party liability insurance covering the event, the Rental Period, bodily injury, property damage, and the activities of Renter and Renter's guests. Owner may require proof of such insurance before granting access to the Venue. If alcohol is served, sold, distributed, or consumed, Renter shall also obtain liquor liability coverage or a special event liquor liability endorsement if available and applicable. Owner may require that MAD Productions, LLC, The Boardroom QC, the Venue owner, and any related parties designated by Owner be named as additional insureds. Failure to provide acceptable proof of insurance may result in cancellation or denial of access, without waiver of any amounts owed.

6. Outside Vendors, Caterers, and Contractors. If Renter uses any caterer, bartender, entertainer, decorator, rental company, vendor, contractor, or other third party, Renter shall ensure that each third party is properly licensed, insured, and qualified for the services provided. At least seven (7) days before the Rental Period, or earlier if requested by Owner, Renter shall provide proof of insurance for any outside vendor, with Owner listed as an additional insured when requested. Renter remains fully responsible for all acts, omissions, damages, injuries, cleanup, violations, and charges caused by Renter's vendors or contractors.

7. Food, Alcohol, Kitchen, and Equipment. Owner does not provide food, beverages, alcohol, catering, bartending, food service, security, medical services, or event supervision. Renter is solely responsible for compliance with all laws, rules, and regulations involving food, alcohol, service, consumption, sales, licensing, age restrictions, intoxication, and transportation after the event. Renter accepts all risk and liability arising from the use of kitchen utensils, appliances, equipment, furniture, fixtures, décor, or other property at the Venue, whether used properly or improperly.

8. Strictly Prohibited Items and Activities. The following are strictly prohibited inside the Venue, outside the Venue, on sidewalks, in parking areas, and anywhere on or around the premises: glitter; party confetti; confetti cannons; rice; birdseed; loose sequins; fireworks; sparklers; smoke bombs; pyrotechnics; explosive devices; explosive props; combustible props; open flame not expressly approved in writing by Owner; hazardous materials; illegal substances; weapons except as permitted by law; and any activity that creates unusual mess, fire risk, explosion risk, property damage risk, injury risk, nuisance, or disturbance. Violation of this section may result in immediate termination of the event, forfeiture of deposit, additional cleaning or repair charges, and liability for all resulting damages, claims, fines, attorney fees, and costs.

9. Condition of Premises; Return in Same Condition. Renter accepts the Venue in its existing "AS IS" condition at the time Renter takes possession. Renter shall inspect the Venue upon arrival and shall immediately report any pre-existing damage or concern to Owner. Renter shall leave the Venue, fixtures, furniture, restrooms, kitchen areas, floors, walls, décor, equipment, sidewalks, entry areas, and parking-related areas in the same condition and arrangement as when Renter arrived, ordinary wear and tear excepted. The Venue must be left clean, orderly, secure, and free of personal property, decorations, trash, food, spills, stains, debris, glitter, confetti, and any items brought in by Renter or Renter's guests or vendors.

10. Cleanup, Trash, and Personal Property. Before the Rental Period expires, Renter shall remove all personal property, decorations, trash, food, beverages, vendor property, rental items, and any other items not present when Renter took control of the Venue. Any property left behind may be deemed abandoned and may be discarded or stored at Renter's expense. Any required excessive cleaning, specialty cleaning, remediation, odor removal, stain removal, or disposal caused by Renter, guests, invitees, or vendors may be charged to Renter and deducted from the security deposit or billed separately.

11. Damage, Security Deposit, Liability, and Legal Action. Renter is financially responsible for all damage, loss, theft, excessive cleaning, fines, citations, violations, business interruption, loss of use, loss of reputation, and any other costs or damages arising from or related to Renter's event, Renter's use of the Venue, or the acts or omissions of Renter's guests, invitees, vendors, contractors, agents, representatives, or any person entering the Venue during the Rental Period. Damage outside ordinary wear and tear may result in forfeiture of the security deposit, additional charges, civil liability, and legal action. Owner may apply the security deposit toward any amounts owed, but application of the deposit does not limit Renter's responsibility for amounts exceeding the deposit.

12. Assumption of Risk; Accidents and Injuries. Renter understands that event activities, use of the Venue, use of furniture, fixtures, equipment, parking areas, sidewalks, curbs, entrances, exits, kitchen areas, and alcohol or food consumption may involve risks, including serious injury, temporary or permanent disability, death, and property damage. Renter voluntarily assumes all risks arising from the event and the use of the Venue by Renter, Renter's guests, invitees, vendors, contractors, agents, and representatives. Owner is not responsible for accidents, injuries, death, loss, theft, or property damage involving Renter or any guest, invitee, child, vendor, contractor, agent, representative, or other person connected to Renter's event, except to the extent such limitation is prohibited by applicable law.

13. Responsibility for Guests. Renter assumes full responsibility for all guests, invitees, children, vendors, contractors, agents, representatives, and other persons who enter or attempt to enter the Venue in connection with Renter's event, whether or not Renter personally invited them or knew of their presence. Renter is responsible for their conduct, safety, supervision, compliance with this Agreement, and any damages, injuries, claims, or violations they cause.

14. Indemnification, Hold Harmless, and Release. To the fullest extent permitted by law, Renter shall defend, indemnify, release, and hold harmless Owner, MAD Productions, LLC, The Boardroom QC, the Venue, the property owner, and their respective members, managers, officers, employees, agents, representatives, affiliates, successors, and assigns from and against any and all claims, demands, causes of action, damages, losses, liabilities, injuries, deaths, property damage, fines, citations, judgments, settlements, attorney fees, court costs, and expenses arising from or related to Renter's event, Renter's use of the Venue, the presence or conduct of any guest or vendor, alcohol or food service, prohibited items, cleanup, damage, or any breach of this Agreement.

15. Attorney Fees, Costs, and Collection. If Owner must enforce this Agreement, collect amounts owed, defend or pursue any claim related to Renter's event, recover damages, respond to third-party claims, or take legal action because of Renter's breach or the acts or omissions of Renter or Renter's guests, invitees, vendors, contractors, agents, or representatives, Renter shall be responsible for all reasonable attorney fees, court costs, collection costs, expert fees, investigation costs, and other expenses incurred by Owner, in addition to all damages and amounts otherwise owed.

16. Cancellation by Renter. Renter may cancel this Agreement by providing written notice to Owner. If Owner receives written cancellation ninety (90) days or more before the Rental Period begins, Renter may be entitled to a refund of amounts paid, less any non-refundable processing charges or costs already incurred by Owner. If Owner receives cancellation less than ninety (90) days before the Rental Period begins, security deposits and cleaning fees are non-refundable to the extent already paid. Rental fees are non-refundable if cancellation is received less than thirty (30) days before the Rental Period begins, unless Owner agrees otherwise in writing.

17. Cancellation by Owner. Owner may cancel this Agreement at any time by providing written notice to Renter. If Owner cancels for reasons unrelated to Renter's breach, Owner shall refund amounts paid by Renter under this Agreement. If Owner cancels because of Renter's breach, failure to pay, failure to provide proof of insurance, misrepresentation, safety concern, illegal activity, prohibited activity, or violation of this Agreement, Owner may retain amounts permitted under this Agreement and pursue any other available remedy.

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
