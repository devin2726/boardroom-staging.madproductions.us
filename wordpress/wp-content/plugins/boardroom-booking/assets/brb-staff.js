/* Boardroom staff dashboard (2.5.89). Talks only to brb/v1/staff/* with the REST + staff nonces.
   A guest email sends only from the confirm screen (full message, recipient, Send/Cancel). Nothing sends automatically. */
(function () {
  'use strict';
  var C = window.BRB_STAFF || {};
  var $ = function (id) { return document.getElementById(id); };
  var state = { month: C.month, panel: 'calendar', modalStack: [] };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function api(path, body) {
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.wpNonce, 'Accept': 'application/json' } };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-BRB-Staff-Nonce'] = C.staffNonce;
      opts.body = JSON.stringify(body);
    }
    return fetch(C.rest + path, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Something went wrong. Reload the page.' }; });
    }).then(function (j) {
      if (j && j.ok) return j.data;
      var err = new Error((j && j.message) || 'Something went wrong. Reload the page.');
      throw err;
    });
  }
  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.classList.remove('show'); }, 3800);
  }
  function chip(c) { return c ? '<span class="chip ' + esc(c['class']) + '">' + esc(c.text) + '</span>' : ''; }

  /* ---------- modal ---------- */
  function openModal(html, onReady) {
    var o = $('overlay'), m = $('modal');
    m.innerHTML = html;
    o.hidden = false;
    o.classList.add('open');
    var first = m.querySelector('[data-autofocus]') || m.querySelector('button, input, select, textarea');
    if (first) first.focus();
    m.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', closeModal); });
    if (onReady) onReady(m);
  }
  function closeModal() {
    var o = $('overlay');
    o.classList.remove('open');
    o.hidden = true;
    var modal = $('modal');
    modal.classList.remove('mail-wide');
    modal.innerHTML = '';
  }
  $('overlay').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('overlay').hidden) closeModal(); });

  /** Every action that saves something asks first. */
  function confirmStep(title, lines, yesLabel, run) {
    var html = '<h3 id="modal-title">' + esc(title) + '</h3><ul class="facts">' +
      lines.map(function (l) { return '<li><span>' + esc(l[0]) + '</span><span>' + esc(l[1]) + '</span></li>'; }).join('') +
      '</ul><div class="btn-row"><button type="button" class="btn btn-primary" id="cf-yes" data-autofocus>' + esc(yesLabel) + '</button>' +
      '<button type="button" class="btn btn-quiet" data-close>Go back</button></div>';
    openModal(html, function (m) {
      m.querySelector('#cf-yes').addEventListener('click', function () {
        this.disabled = true;
        run().then(function (msg) {
          closeModal(); refreshAll();
          if (msg && typeof msg === 'object') { toast(msg.text); if (msg.then) msg.then(); } else toast(msg);
        })
          .catch(function (err) { closeModal(); toast(err.message); refreshAll(); });
      });
    });
  }
  function emailChoice(name, what) {
    return '<p class="field-label">Guest email</p><div class="choice">' +
      '<label><input type="radio" name="' + name + '" value="0" checked> Don\'t email the guest</label>' +
      '<label><input type="radio" name="' + name + '" value="1"> Email the guest: show me the ' + esc(what) + ' to check and send</label></div>';
  }
  function picked(m, name) { var el = m.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : ''; }
  function emailLine(on) { return on ? 'Next you see the full email and choose Send or Cancel' : 'Not emailed'; }

  /* ---------- booking card ---------- */
  function bookingFacts(b) {
    var m = b.money || {};
    var rows = [
      ['Date', b.when || ''],
      ['Hours booked', b.hours || ''],
    ];
    if (b.end_time) rows.push(['Guest says the event ends', b.end_time]);
    if (b.walk_pref) rows.push(['Walk-through preference', b.walk_pref]);
    if (b.event) rows.push(['Event', b.event]);
    rows.push(['Guests', b.guests ? String(b.guests) : 'Not yet']);
    rows.push(['Email', b.email || 'Not yet']);
    rows.push(['Status', b.status_label || '']);
    rows.push(['Details checked', b.details_checked ? 'Yes' : 'Not yet']);
    if (b.returning_renter) rows.push(['Returning renter', 'Yes (walk-through not needed)']);
    if (b.hold) {
      if (b.hold.clock === 'none') rows.push(['Pay link', 'Not sent yet (no pay deadline in the walk-through flow)']);
      else if (b.hold.waiting_for_link) rows.push(['Pay window', 'Starts when the pay link is sent']);
      else {
        rows.push(['Pay window ends', b.hold.until_text]);
        rows.push(['Time left', b.hold.text]);
        if (b.hold.link_sent_text) rows.push(['Pay link sent', b.hold.link_sent_text]);
      }
    }
    if (m.priced) {
      rows.push(['Price', m.total_text]);
      rows.push(['Charge now', m.charge_now]);
      rows.push(['Paid', m.paid_text]);
      rows.push(['Still owed', m.left_text]);
      rows.push(['Balance due', m.balance_due]);
      if (m.card_fees_text) rows.push(['Card fees', m.card_fees_text]);
    } else {
      rows.push(['Price', m.total_text || 'Price to be confirmed']);
    }
    if (b.notes) rows.push(['Notes', b.notes]);
    if (b.sent && b.sent.length) rows.push(['Sent from here', b.sent.join(', ')]);
    return '<ul class="facts">' + rows.filter(function (r) { return r[1] !== ''; }).map(function (r) {
      return '<li><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></li>';
    }).join('') + '</ul>';
  }
  function fillInNote(b) {
    if (!b.needs_fill) return '';
    return '<div class="flag" role="status">Needs guest details<small>Add or fix the guest email, guest name, guest count and price so you can send an invoice. Saving does not email the guest.</small></div>';
  }
  function holdBanner(b) {
    if (!b.hold) return '';
    if (b.hold.waiting_for_link) return '<div class="flag" role="status">Pay link not sent<small>' + esc(b.hold.text) + ' Nothing happens on its own and the guest is not emailed.</small></div>';
    if (b.hold.lapsed) return '<div class="flag" role="alert">Pay window passed, release this date?<small>' + esc(b.hold.text) + '. Nothing happens on its own and the guest is not emailed.</small></div>';
    return '<div class="countdown">' + esc(b.hold.text) + '</div>';
  }
  function bookingButtons(b) {
    var out = '';
    if (b.can_edit) out += '<button type="button" class="btn" data-act="edit" data-id="' + b.id + '">Edit details</button>';
    if (b.hold && b.hold.lapsed) out += '<button type="button" class="btn btn-danger" data-act="release" data-id="' + b.id + '">Release this date…</button>';
    if (b.can_approve) out += '<button type="button" class="btn btn-good" data-act="approve" data-id="' + b.id + '">Approve</button>';
    if (b.can_approve || b.can_decline) out += '<button type="button" class="btn" data-act="decline" data-id="' + b.id + '">Decline</button>';
    if (b.can_pay) {
      out += '<button type="button" class="btn btn-primary" data-act="pay" data-id="' + b.id + '">Record payment</button>';
      out += '<button type="button" class="btn" data-act="sendlink" data-id="' + b.id + '">Send invoice / pay link</button>';
      out += '<button type="button" class="btn" data-act="link" data-id="' + b.id + '">Copy pay link</button>';
    }
    return out ? '<div class="btn-row">' + out + '</div>' : '';
  }
  function wireActions(root, byId) {
    root.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var act = btn.getAttribute('data-act');
        var b = byId[btn.getAttribute('data-id')];
        if (!b) return;
        if (act === 'approve') approveFlow(b);
        else if (act === 'decline') declineFlow(b);
        else if (act === 'pay') payFlow(b);
        else if (act === 'link') linkFlow(b);
        else if (act === 'sendlink') sendFlow(b.id, 'paylink', '');
        else if (act === 'edit') editFlow(b);
        else if (act === 'release') releaseFlow(b);
        else if (act === 'rmhold') removeHoldFlow(b);
        else if (act === 'open') openRecord(b.type, b.id);
        else if (act === 'send') sendFlow(b.booking_id, b.kind, '');
      });
    });
  }
  /** Compact list/calendar item. Tapping it opens the one shared record view. */
  function itemRow(it) {
    var isB = it.type === 'booking';
    var c = isB ? chip(it.chip) : '<span class="chip ' + (it.hold_type === 'hold' ? 'held' : (it.hold_type === 'reserved' ? 'paid' : 'block')) + '">' + esc(it.label) + '</span>';
    var sub = isB ? [it.when, it.event, it.guests ? it.guests + ' guests' : ''].filter(Boolean).join(' · ') : [it.when, it.time].filter(Boolean).join(' · ');
    var key = (isB ? 'b' : 'h') + it.id;
    if (!it.id) return '<article class="card item"><div class="item-head"><h4>' + esc(it.name) + '</h4>' + c + '</div><p class="muted">' + esc(sub) + '</p></article>';
    return '<button type="button" class="card item item-open" data-act="open" data-id="' + key + '" aria-label="Open ' + esc(it.name) + '">' +
      '<span class="item-head"><span class="h4">' + esc(it.name) + (it.ref && isB ? ' <span class="muted">' + esc(it.ref) + '</span>' : '') + '</span>' + c + '</span>' +
      '<span class="muted">' + esc(sub) + '</span><span class="open-hint" aria-hidden="true">Open ›</span></button>';
  }
  function indexItems(list) { var o = {}; list.forEach(function (it) { if (it && it.id) o[(it.type === 'booking' ? 'b' : 'h') + it.id] = it; }); return o; }
  /** The single record view for a booking, request or hold, from anywhere on the page. */
  function openRecord(type, id) {
    var path = type === 'booking' ? 'booking/' + id : 'hold/' + id;
    api(path).then(function (r) {
      var html;
      if (type === 'booking') {
        html = '<h3 id="modal-title">' + esc(r.name) + ' <span class="muted">' + esc(r.ref) + '</span></h3><p>' + chip(r.chip) + '</p>' + holdBanner(r) + fillInNote(r) + bookingFacts(r) + bookingButtons(r);
        if (!r.can_pay && !r.can_approve && r.status !== 'paid') html += '';
      } else {
        html = '<h3 id="modal-title">' + esc(r.name) + '</h3><p><span class="chip ' + (r.hold_type === 'hold' ? 'held' : (r.hold_type === 'reserved' ? 'paid' : 'block')) + '">' + esc(r.label) + '</span></p>' +
          '<ul class="facts"><li><span>Date</span><span>' + esc(r.when) + '</span></li>' + (r.time ? '<li><span>Time</span><span>' + esc(r.time) + '</span></li>' : '') +
          (r.notes ? '<li><span>Notes</span><span>' + esc(r.notes) + '</span></li>' : '') + '</ul>' +
          '<div class="btn-row"><button type="button" class="btn" disabled>Send invoice / pay link</button>' + (r.can_remove ? '<button type="button" class="btn btn-danger" data-act="rmhold" data-id="' + r.id + '">Remove this ' + esc(r.label.toLowerCase()) + '…</button>' : '') + '</div><p class="muted">' + esc(r.pay_note) + '</p>';
      }
      html += '<div class="btn-row"><button type="button" class="btn btn-quiet" data-close>Close</button></div>';
      openModal(html, function (m) { var o = {}; o[r.id] = r; wireActions(m, o); });
    }).catch(function (e) { toast(e.message); });
  }
  function indexById(list) { var o = {}; list.forEach(function (b) { if (b && b.id) o[b.id] = b; }); return o; }

  /* ---------- flows ---------- */
  function val(m, id) { var el = m.querySelector('#' + id); return el ? String(el.value).trim() : ''; }
  function editPayload(m) {
    return {
      name: val(m, 'ed-name'),
      email: val(m, 'ed-email'),
      guests: val(m, 'ed-guests'),
      price: val(m, 'ed-price'),
      date: val(m, 'ed-date'),
      start_time: val(m, 'ed-start'),
      end_time: val(m, 'ed-end'),
      notes: val(m, 'ed-notes')
    };
  }
  function editFlow(b) {
    var e = b.edit || {};
    var max = e.max_guests || (C && C.maxGuests) || 80;
    var locked = !!e.price_locked;
    var html = '<h3 id="modal-title">Edit ' + esc(b.name || 'booking') + '</h3>' +
      '<p class="muted">' + esc((b.ref || '') + (b.when ? ' · ' + b.when : '')) + '. Saving does not email the guest.</p>' +
      '<form id="ed-form" novalidate>' +
      '<label class="field" for="ed-name">Guest name</label>' +
      '<input type="text" id="ed-name" name="name" autocomplete="off" maxlength="120" value="' + esc(e.name || '') + '">' +
      '<label class="field" for="ed-email">Guest email</label>' +
      '<input type="email" id="ed-email" name="email" autocomplete="off" maxlength="190" value="' + esc(e.email || '') + '">' +
      '<label class="field" for="ed-guests">How many guests? (up to ' + esc(String(max)) + ')</label>' +
      '<input type="number" id="ed-guests" name="guests" min="0" max="' + esc(String(max)) + '" inputmode="numeric" value="' + esc(String(e.guests != null ? e.guests : '')) + '">' +
      '<label class="field" for="ed-price">Agreed price' + (locked ? ' (locked)' : '') + '</label>' +
      '<input type="text" id="ed-price" name="price" inputmode="decimal" autocomplete="off"' + (locked ? ' disabled' : '') + ' value="' + esc(e.price || '') + '">' +
      (locked ? '<p class="muted">A payment is already recorded, so the price is locked.</p>' : '') +
      '<label class="field" for="ed-date">Event date</label>' +
      '<input type="date" id="ed-date" name="date" value="' + esc(e.date || '') + '">' +
      '<div class="row-2">' +
      '<div><label class="field" for="ed-start">Start time</label><input type="time" id="ed-start" name="start_time" value="' + esc(e.start_time || '') + '"></div>' +
      '<div><label class="field" for="ed-end">End time</label><input type="time" id="ed-end" name="end_time" value="' + esc(e.end_time || '') + '"></div>' +
      '</div>' +
      '<label class="field" for="ed-notes">Notes</label>' +
      '<textarea id="ed-notes" name="notes" maxlength="5000">' + esc(e.notes || '') + '</textarea>' +
      '<p class="warn" id="ed-err" hidden></p>' +
      '<div class="btn-row"><button type="submit" class="btn btn-primary" id="ed-save">Review changes</button>' +
      '<button type="button" class="btn btn-quiet" data-close>Cancel</button></div></form>';
    openModal(html, function (m) {
      var form = m.querySelector('#ed-form');
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var err = m.querySelector('#ed-err');
        err.hidden = true;
        var payload = editPayload(m);
        var btn = m.querySelector('#ed-save');
        btn.disabled = true;
        api('booking/' + b.id + '/edit-preview', payload).then(function (prev) {
          var lines = (prev.changes || []).map(function (c) {
            return [c.label, (c.from ? c.from : '(empty)') + ' → ' + (c.to ? c.to : '(empty)')];
          });
          confirmStep('Save these changes?', lines.concat([['Guest email', 'Not emailed']]), 'Yes, save', function () {
            payload.confirmed = true;
            return api('booking/' + b.id + '/edit', payload).then(function () {
              return { text: 'Saved. No email was sent to the guest.', then: function () { openRecord('booking', b.id); } };
            });
          });
        }).catch(function (e) {
          err.hidden = false;
          err.textContent = e.message || 'Could not save.';
        }).then(function () { btn.disabled = false; });
      });
    });
  }
  function approveFlow(b) {
    var html = '<h3 id="modal-title">Approve ' + esc(b.name) + '</h3><p class="muted">' + esc(b.ref + ' · ' + b.when) + '</p>' +
      '<label class="check"><input type="checkbox" id="ap-walk"' + (b.details_checked ? ' checked disabled' : '') + '> I checked the details</label>' +
      (C.walkthroughMode ? '<label class="check"><input type="checkbox" id="ap-return"' + (b.returning_renter ? ' checked' : '') + '> Returning renter (walk-through not needed)</label>' : '') +
      '<p class="field-label">Charge now</p><div class="choice">' +
      '<label><input type="radio" name="ap-charge" value="deposit"' + (b.money && /^Pay in full/.test(b.money.charge_now) ? '' : ' checked') + '> Deposit ' + esc(C.depositPct) + '% (holds the date)</label>' +
      '<label><input type="radio" name="ap-charge" value="full"' + (b.money && /^Pay in full/.test(b.money.charge_now) ? ' checked' : '') + '> Pay in full (100%)</label></div>' +
      emailChoice('ap-email', 'approval email') +
      '<p class="warn" id="ap-err" hidden></p>' +
      '<div class="btn-row"><button type="button" class="btn btn-good" id="ap-go">Approve</button><button type="button" class="btn btn-quiet" data-close>Cancel</button></div>';
    openModal(html, function (m) {
      m.querySelector('#ap-go').addEventListener('click', function () {
        var walk = m.querySelector('#ap-walk').checked;
        var ret = !!(C.walkthroughMode && m.querySelector('#ap-return') && m.querySelector('#ap-return').checked);
        if (!walk && !ret) { var e = m.querySelector('#ap-err'); e.hidden = false; e.textContent = C.walkthroughMode ? 'Tick "I checked the details" or "Returning renter (walk-through not needed)" first.' : 'Tick "I checked the details" first.'; return; }
        var charge = picked(m, 'ap-charge'), mail = picked(m, 'ap-email') === '1';
        confirmStep('Approve this request?', [
          ['Guest', b.name + ' (' + b.ref + ')'], ['Date', b.when],
          ['Charge now', charge === 'full' ? 'Pay in full (100%)' : 'Deposit ' + C.depositPct + '%'],
          ['Guest email', emailLine(mail)]
        ], 'Yes, approve', function () {
          return api('booking/' + b.id + '/approve', { walkthrough: walk, returning_renter: ret, charge_now: charge, email_guest: mail })
            .then(function () { return { text: b.name + ' approved.' + (mail ? '' : ' The guest was not emailed.'), then: mail ? function () { sendFlow(b.id, 'approve', ''); } : null }; });
        });
      });
    });
  }
  function releaseFlow(b) {
    confirmStep('Release this date?', [['Guest', b.name + ' (' + b.ref + ')'], ['Date', b.when], ['Pay window', b.hold ? b.hold.text : ''], ['What happens', 'The date opens up on the calendar'], ['Guest email', 'None. Nothing is emailed.']], 'Yes, release the date', function () {
      return api('booking/' + b.id + '/release', { confirmed: true }).then(function () { return { text: b.when + ' released. The guest was not emailed.' }; });
    });
  }
  function removeHoldFlow(h) {
    confirmStep('Remove this ' + String(h.label || 'hold').toLowerCase() + '?', [['Date', h.when], ['Name', h.name], ['What happens', 'It is taken off the calendar and the date opens up']], 'Yes, remove it', function () {
      return api('hold/' + h.id + '/remove', { confirmed: true }).then(function () { return { text: 'Removed. ' + h.when + ' is open.' }; });
    });
  }
  function declineFlow(b) {
    var html = '<h3 id="modal-title">Decline ' + esc(b.name) + '</h3><p class="muted">' + esc(b.ref + ' · ' + b.when) + '</p>' +
      '<label class="field" for="dc-msg">Message to the guest (optional, used only if you email them)</label><textarea id="dc-msg" maxlength="2000"></textarea>' +
      emailChoice('dc-email', 'decline email') +
      '<div class="btn-row"><button type="button" class="btn btn-primary" id="dc-go">Decline</button><button type="button" class="btn btn-quiet" data-close>Cancel</button></div>';
    openModal(html, function (m) {
      m.querySelector('#dc-go').addEventListener('click', function () {
        var msg = m.querySelector('#dc-msg').value, mail = picked(m, 'dc-email') === '1';
        confirmStep('Decline this request?', [['Guest', b.name + ' (' + b.ref + ')'], ['Date', b.when], ['Guest email', emailLine(mail)]], 'Yes, decline', function () {
          return api('booking/' + b.id + '/decline', { message: msg, email_guest: mail })
            .then(function () { return { text: b.name + ' declined.' + (mail ? '' : ' The guest was not emailed.'), then: mail ? function () { sendFlow(b.id, 'decline', msg); } : null }; });
        });
      });
    });
  }
  function payFlow(b) {
    var m0 = b.money || {};
    var dflt = m0.due_now > 0 ? m0.due_now : m0.left;
    var html = '<h3 id="modal-title">Record payment</h3><p class="muted">' + esc(b.name + ' · ' + b.ref + ' · still owed ' + (m0.left_text || '')) + '</p>' +
      '<label class="field" for="py-amt">Amount for the booking</label><input type="number" id="py-amt" min="0.01" step="0.01" inputmode="decimal" value="' + esc(dflt ? Number(dflt).toFixed(2) : '') + '">' +
      '<label class="field" for="py-method">How did they pay?</label><select id="py-method"><option value="check">Check</option><option value="cash">Cash</option><option value="card">Card</option></select>' +
      '<div id="py-check-wrap"><label class="field" for="py-check">Check number</label><input type="text" id="py-check" maxlength="40" autocomplete="off"></div>' +
      '<label class="field" for="py-date">Received on</label><input type="date" id="py-date" value="' + esc(C.today) + '">' +
      '<div class="price-box" id="py-preview" aria-live="polite"></div>' +
      emailChoice('py-email', 'receipt') +
      '<p class="warn" id="py-err" hidden></p>' +
      '<div class="btn-row"><button type="button" class="btn btn-primary" id="py-go">Record payment</button><button type="button" class="btn btn-quiet" data-close>Cancel</button></div>';
    openModal(html, function (m) {
      var last = null;
      function preview() {
        var method = m.querySelector('#py-method').value;
        m.querySelector('#py-check-wrap').hidden = method !== 'check';
        api('booking/' + b.id + '/payment-preview', { amount: m.querySelector('#py-amt').value, method: method }).then(function (f) {
          last = f;
          m.querySelector('#py-preview').innerHTML =
            '<div class="line"><span>Payment for the booking</span><strong class="money">' + esc(f.text.amount) + '</strong></div>' +
            '<div class="line fee"><span>Card fee</span><span class="money">' + esc(f.text.fee) + '</span></div>' +
            '<div class="line"><span>Total taken</span><span class="money">' + esc(f.text.charged) + '</span></div>' +
            '<div class="line fee"><span>Still owed after this</span><span class="money">' + esc(f.text.left_after) + '</span></div>';
        }).catch(function () {});
      }
      ['#py-amt', '#py-method'].forEach(function (s) { m.querySelector(s).addEventListener('input', preview); m.querySelector(s).addEventListener('change', preview); });
      preview();
      m.querySelector('#py-go').addEventListener('click', function () {
        var method = m.querySelector('#py-method').value, amt = m.querySelector('#py-amt').value, chk = m.querySelector('#py-check').value.trim();
        var err = m.querySelector('#py-err');
        if (!(Number(amt) > 0)) { err.hidden = false; err.textContent = 'Enter the amount.'; return; }
        if (method === 'check' && !chk) { err.hidden = false; err.textContent = 'Enter the check number.'; return; }
        var mail = picked(m, 'py-email') === '1', f = last || { text: {} };
        confirmStep('Record this payment?', [
          ['Guest', b.name + ' (' + b.ref + ')'], ['Payment for the booking', f.text.amount || ''], ['Card fee', f.text.fee || ''],
          ['Total taken', f.text.charged || ''], ['Method', method + (chk && method === 'check' ? ' #' + chk : '')], ['Guest email', emailLine(mail)]
        ], 'Yes, record it', function () {
          return api('booking/' + b.id + '/payment', { amount: amt, method: method, check_number: chk, received_on: m.querySelector('#py-date').value, email_guest: mail })
            .then(function (nb) { return { text: 'Payment recorded. ' + (nb.chip ? nb.chip.text + '.' : '') + (mail ? '' : ' The guest was not emailed.'), then: mail ? function () { sendFlow(b.id, 'receipt', ''); } : null }; });
        });
      });
    });
  }
  function linkFlow(b) {
    api('booking/' + b.id + '/pay-link', {}).then(function (r) {
      if (!r.url) {
        openModal('<h3 id="modal-title">No pay link yet</h3><p class="muted">Before a link can be made:</p><ul class="facts">' +
          r.blockers.map(function (x) { return '<li><span>' + esc(x) + '</span><span>Needed</span></li>'; }).join('') +
          '</ul><div class="btn-row"><button type="button" class="btn" data-close>Close</button></div>');
        return;
      }
      openModal('<h3 id="modal-title">Pay link for ' + esc(b.name) + '</h3>' +
        '<p class="muted">Copying does not email anyone.</p>' +
        '<ul class="facts"><li><span>Due now</span><span>' + esc(r.due) + '</span></li><li><span>Card fee (' + esc(C.feeLabel) + ')</span><span>' + esc(r.fee) + '</span></li><li><span>Total by card</span><span>' + esc(r.charged) + '</span></li></ul>' +
        '<textarea class="linkbox" id="pl-url" readonly>' + esc(r.url) + '</textarea>' +
        '<div class="btn-row"><button type="button" class="btn btn-primary" id="pl-copy" data-autofocus>Copy link</button><button type="button" class="btn" id="pl-mail">Email it…</button><button type="button" class="btn btn-quiet" data-close>Close</button></div>', function (m) {
        m.querySelector('#pl-mail').addEventListener('click', function () { closeModal(); sendFlow(b.id, 'paylink', ''); });
        m.querySelector('#pl-copy').addEventListener('click', function () {
          var ta = m.querySelector('#pl-url');
          var done = function () { toast('Pay link copied. Nothing was emailed.'); api('booking/' + b.id + '/pay-link-copied', {}).then(refreshAll).catch(function () {}); };
          if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(done, function () { ta.select(); document.execCommand('copy'); done(); });
          else { ta.select(); document.execCommand('copy'); done(); }
        });
      });
    }).catch(function (e) { toast(e.message); });
  }
  /** The only way a guest email leaves this page: full message + recipient, then Send or Cancel. */
  function sendFlow(bookingId, kind, note) {
    api('booking/' + bookingId + '/draft', { kind: kind, note: note || '' }).then(function (d) {
      var hasHtml = !!(d.html && String(d.html).indexOf('<') !== -1);
      var html = '<h3 id="modal-title">Send ' + esc(d.title.toLowerCase()) + '?</h3>' +
        '<ul class="facts"><li><span>To</span><span>' + esc(d.name) + ' &lt;' + esc(d.to) + '&gt;</span></li><li><span>Booking</span><span>' + esc(d.ref) + '</span></li>' +
        '<li><span>Subject</span><span>' + esc(d.subject) + '</span></li></ul>' +
        '<div class="mailview">' +
          (hasHtml ? '<div class="mail-toggle" role="tablist" aria-label="Email preview">' +
            '<button type="button" class="mail-tab" id="sd-view-html" role="tab" aria-selected="true">Branded email</button>' +
            '<button type="button" class="mail-tab" id="sd-view-plain" role="tab" aria-selected="false">Plain text</button>' +
          '</div>' +
          '<iframe class="mailframe" id="sd-html" title="Branded email preview" sandbox="allow-same-origin"></iframe>' : '') +
          '<pre class="mailbody" id="sd-plain" tabindex="0"' + (hasHtml ? ' hidden' : '') + '>' + esc(d.body) + '</pre>' +
        '</div>' +
        (d.notice ? '<p class="draft-tag" role="alert">' + esc(d.notice) + '</p>' : '') +
        '<p class="muted">This goes to the guest as shown. Nothing has been sent yet.</p>' +
        '<div class="btn-row"><button type="button" class="btn btn-primary" id="sd-send"' + (d.can_send === false ? ' disabled' : '') + '>Send</button><button type="button" class="btn btn-quiet" data-close data-autofocus>Cancel</button></div>';
      var modal = document.getElementById('modal');
      if (hasHtml && modal) modal.classList.add('mail-wide');
      openModal(html, function (m) {
        if (hasHtml) {
          var frame = m.querySelector('#sd-html'), plain = m.querySelector('#sd-plain');
          var tabHtml = m.querySelector('#sd-view-html'), tabPlain = m.querySelector('#sd-view-plain');
          var sizeFrame = function () {
            try {
              var doc = frame.contentDocument;
              if (!doc) return;
              var h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
              frame.style.height = Math.min(Math.max(h + 4, 240), Math.round(window.innerHeight * 0.62)) + 'px';
            } catch (err) {}
          };
          frame.addEventListener('load', sizeFrame);
          frame.srcdoc = d.html;
          var show = function (which) {
            var branded = which === 'html';
            frame.hidden = !branded;
            plain.hidden = branded;
            tabHtml.setAttribute('aria-selected', branded ? 'true' : 'false');
            tabPlain.setAttribute('aria-selected', branded ? 'false' : 'true');
            if (branded) sizeFrame();
          };
          tabHtml.addEventListener('click', function () { show('html'); });
          tabPlain.addEventListener('click', function () { show('plain'); });
        }
        m.querySelector('#sd-send').addEventListener('click', function () {
          this.disabled = true;
          api('booking/' + bookingId + '/send', { kind: d.kind, to: d.to, subject: d.subject, body: d.body, confirm: d.confirm, confirmed: true })
            .then(function () { closeModal(); toast('Sent to ' + d.to + '.'); refreshAll(); })
            .catch(function (e) { closeModal(); toast(e.message); });
        });
      });
    }).catch(function (e) { toast(e.message); });
  }

  /* ---------- panels ---------- */
  function showPanel(name) {
    state.panel = name;
    document.querySelectorAll('.panel').forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === name); });
    document.querySelectorAll('.nav button').forEach(function (b) {
      if (b.getAttribute('data-jump') === name) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    // Today summary on the home (calendar) view only.
    $('summary').hidden = name !== 'calendar';
    loadPanel(name);
    window.scrollTo(0, 0);
  }
  document.querySelectorAll('[data-jump]').forEach(function (b) { b.addEventListener('click', function () { showPanel(b.getAttribute('data-jump')); }); });

  function loadSummary() {
    return api('summary').then(function (s) {
      $('today-line').textContent = 'Today: ' + s.today;
      var extra = [];
      if (s.lapsed) extra.push(s.lapsed + ' pay window passed');
      if (s.awaiting_link) extra.push(s.awaiting_link + ' pay link not sent');
      $('sum-requests').innerHTML = '<span class="k">Requests waiting</span><span class="v">' + esc(s.requests) + '</span><span class="d">' + esc(s.requests_text + (extra.length ? ' · ' + extra.join(' · ') : '')) + '</span>';
      $('sum-payments').innerHTML = '<span class="k">Payments open</span><span class="v">' + esc(s.payments) + '</span><span class="d">' + esc(s.payments_text) + '</span>';
      $('sum-next').innerHTML = '<span class="k">Next event</span><span class="v">' + esc(s.next) + '</span>';
    }).catch(function (e) { toast(e.message); });
  }
  function loadCalendar() {
    return api('calendar?month=' + encodeURIComponent(state.month)).then(function (cal) {
      state.cal = cal;
      $('cal-title').textContent = cal.title;
      var g = $('cal-grid'), html = '';
      var lead = cal.days.length ? cal.days[0].dow : 0;
      for (var i = 0; i < lead; i++) html += '<button type="button" class="day" disabled aria-hidden="true" tabindex="-1"></button>';
      cal.days.forEach(function (d) {
        var cls = 'day ' + (d.kind === 'open' ? '' : d.kind) + (d.lapsed ? ' lapsed' : '') + (d.date === cal.today ? ' today' : '');
        html += '<button type="button" class="' + cls + '" data-date="' + esc(d.date) + '" aria-label="' + esc(d.date + ', ' + d.label + (d.tag && d.tag !== d.label ? ', ' + d.tag : '')) + '">' +
          '<span class="n">' + d.day + '</span>' + (d.kind !== 'open' ? '<span class="tag">' + esc(d.tag) + '</span>' : '') + (d.clock ? '<span class="clock' + (d.lapsed ? ' bad' : '') + '">' + esc(d.clock) + '</span>' : '') + '</button>';
      });
      g.innerHTML = html;
      g.querySelectorAll('.day[data-date]').forEach(function (b) { b.addEventListener('click', function () { openDay(b.getAttribute('data-date')); }); });
      loadMigration();
    }).catch(function (e) { toast(e.message); });
  }
  $('cal-prev').addEventListener('click', function () { if (state.cal) { state.month = state.cal.prev; loadCalendar(); } });
  $('cal-next').addEventListener('click', function () { if (state.cal) { state.month = state.cal.next; loadCalendar(); } });

  function openDay(date) {
    api('day?date=' + encodeURIComponent(date)).then(function (d) {
      var bookings = d.items.filter(function (x) { return x.type === 'booking'; });
      var html = '<h3 id="modal-title">' + esc(d.title) + '</h3><p><span class="chip ' + esc({ booked: 'paid', held: 'held', waiting: 'review', closed: 'block', open: 'open' }[d.kind]) + '">' + esc(d.label) + '</span>' +
        (d.rate_text ? ' <span class="muted">' + esc(d.rate_text) + '</span>' : '') + '</p>';
      html += d.items.length ? d.items.map(function (it) { if (!it.when) it.when = d.title; return itemRow(it); }).join('') : '<p class="empty">Nothing on this day.</p>';
      html += '<div class="btn-row">' + (d.can_add ? '<button type="button" class="btn btn-primary" id="day-add">Add a booking or hold</button>' : '') +
        '<button type="button" class="btn btn-quiet" data-close>Close</button></div>';
      if (!d.can_add && d.add_blocked) html += '<p class="muted">' + esc(d.add_blocked) + '</p>';
      openModal(html, function (m) {
        wireActions(m, indexItems(d.items));
        var add = m.querySelector('#day-add');
        if (add) add.addEventListener('click', function () { closeModal(); $('add-date').value = date; showPanel('add'); addPreview(); });
      });
    }).catch(function (e) { toast(e.message); });
  }
  function listInto(el, rows, empty) {
    el.innerHTML = rows.length ? rows.map(itemRow).join('') : '<p class="empty">' + esc(empty) + '</p>';
    wireActions(el, indexItems(rows));
  }
  function loadLapsed() {
    return api('lapsed').then(function (rows) {
      var el = $('lapsed-list');
      if (!rows.length) { el.innerHTML = ''; return; }
      var idx = {};
      el.innerHTML = '<h3>Pay window passed</h3>' + rows.map(function (b) {
        idx[b.id] = b;
        return '<article class="card"><div class="item-head"><h4>' + esc(b.name) + '</h4><span class="muted">' + esc(b.ref) + '</span></div><div class="flag" role="alert">Pay window passed, release this date?<small>' + esc(b.when + ' · ' + b.hold.text) + '</small></div>' +
          '<div class="btn-row"><button type="button" class="btn btn-danger" data-act="release" data-id="' + b.id + '">Release…</button><button type="button" class="btn" data-act="open" data-id="' + b.id + '">Open record</button></div></article>';
      }).join('');
      el.querySelectorAll('[data-act]').forEach(function (btn) { btn.addEventListener('click', function () {
        var b = idx[btn.getAttribute('data-id')];
        if (btn.getAttribute('data-act') === 'release') releaseFlow(b); else openRecord('booking', b.id);
      }); });
    }).catch(function (e) { toast(e.message); });
  }
  function loadMigration() {
    var el = $('migration-card');
    if (!C.isAdmin || !el) return Promise.resolve();
    return api('migration').then(function (s) {
      if (!s.pending.length && !s.converted) { el.innerHTML = ''; return; }
      var html = '<div class="card"><h3>Calendar holds to turn into bookings</h3>';
      if (s.pending.length) {
        html += '<p class="muted">' + esc(s.to_convert) + ' date hold' + (s.to_convert === 1 ? '' : 's') + ' can become a booking in Held status. The date stays blocked the whole time and nobody is emailed. Closed days are not touched.' + (s.skipped ? ' ' + esc(s.skipped) + ' will be skipped because a booking already covers the date.' : '') + '</p><ul class="facts">' +
          s.pending.slice(0, 12).map(function (r) { return '<li><span>' + esc(r.date + ' · ' + r.name) + '</span><span>' + esc(r.action === 'skip' ? 'Skipped' : 'Will convert') + '</span></li>'; }).join('') + '</ul>' +
          '<div class="btn-row"><button type="button" class="btn btn-primary" id="mg-run"' + (s.to_convert ? '' : ' disabled') + '>Convert them…</button></div>';
      }
      if (s.converted) html += '<p class="muted">' + esc(s.converted) + ' already converted. The old hold rows are kept, so this can be undone.</p><div class="btn-row"><button type="button" class="btn" id="mg-undo">Undo conversion…</button></div>';
      el.innerHTML = html + '</div>';
      var run = $('mg-run'), undo = $('mg-undo');
      if (run) run.addEventListener('click', function () {
        confirmStep('Turn these holds into bookings?', [['Holds', String(s.to_convert)], ['Dates', 'Stay blocked the whole time'], ['Guest email', 'None. Nobody is emailed.'], ['Undo', 'Possible afterward']], 'Yes, convert', function () {
          return api('migration/run', { confirmed: true }).then(function (r) { return { text: r.converted.length + ' converted, ' + r.skipped.length + ' skipped.' }; });
        });
      });
      if (undo) undo.addEventListener('click', function () {
        confirmStep('Undo the conversion?', [['What happens', 'Holds nobody has worked on go back to calendar holds'], ['Worked-on bookings', 'Stay as bookings'], ['Guest email', 'None']], 'Yes, undo', function () {
          return api('migration/undo', { confirmed: true }).then(function (r) { return { text: r.undone.length + ' put back, ' + r.kept.length + ' kept as bookings.' }; });
        });
      });
    }).catch(function () {});
  }
  function loadAwaiting() {
    return api('awaiting-link').then(function (rows) {
      var el = $('awaiting-list');
      if (!el) return;
      if (!rows.length) { el.innerHTML = ''; return; }
      var idx = {};
      el.innerHTML = '<h3>Pay link not sent</h3>' + rows.map(function (b) {
        idx[b.id] = b;
        return '<article class="card"><div class="item-head"><h4>' + esc(b.name) + '</h4><span class="muted">' + esc(b.ref) + '</span></div><div class="flag" role="status">Pay link not sent<small>' + esc(b.when) + '. The 48-hour window starts when the pay link is sent. Nothing happens on its own.</small></div>' +
          '<div class="btn-row"><button type="button" class="btn" data-act="open" data-id="' + b.id + '">Open record</button></div></article>';
      }).join('');
      el.querySelectorAll('[data-act]').forEach(function (btn) {
        btn.addEventListener('click', function () { openRecord('booking', idx[btn.getAttribute('data-id')].id); });
      });
    }).catch(function (e) { toast(e.message); });
  }
  function loadRequests() { return Promise.all([loadLapsed(), loadAwaiting(), api('requests').then(function (r) { listInto($('request-list'), r, 'No requests waiting.'); })]).catch(function (e) { toast(e.message); }); }
  function loadPayments() { return api('payments').then(function (r) { listInto($('payment-list'), r, 'No money owed right now.'); }).catch(function (e) { toast(e.message); }); }
  function loadUpcoming() {
    return api('upcoming').then(function (rows) { listInto($('upcoming-list'), rows, 'Nothing coming up.'); }).catch(function (e) { toast(e.message); });
  }
  function loadMessages() {
    return api('messages').then(function (m) {
      var idx = {}, html = '<h3>Reminders to preview first</h3>';
      html += (m.reminders && m.reminders.length) ? m.reminders.map(function (r, i) {
        idx['r' + i] = { id: 'r' + i, booking_id: r.booking_id, kind: r.kind, title: r.title };
        return '<article class="card"><div class="item-head"><h4>' + esc(r.title) + '</h4><span class="muted">' + esc(r.ref) + '</span></div>' +
          '<p class="muted">' + esc(r.detail) + (r.opens_text && !r.ready ? ' · queued from ' + esc(r.opens_text) : '') + '</p>' +
          (r.draft && !r.can_send ? '<p class="draft-tag">DRAFT WORDING, needs Max approval. Sending is off until it is approved.</p>' : '') +
          (r.has_email ? '' : '<p class="muted">No guest email on this booking.</p>') + (r.has_link === false ? '<p class="muted">No pay link yet, so this cannot be previewed.</p>' : '') +
          '<div class="btn-row"><button type="button" class="btn btn-primary" data-act="send" data-id="r' + i + '"' + ((r.has_email && r.has_link !== false && r.ready !== false) ? '' : ' disabled') + '>Preview…</button></div></article>';
      }).join('') : '<p class="empty">No reminders waiting.</p>';
      html += '<h3>Emails you may want to send</h3>';
      html += m.suggested.length ? m.suggested.map(function (s, i) {
        var ready = !(s.blocked && s.blocked.length);
        idx['s' + i] = { id: 's' + i, booking_id: s.booking_id, kind: s.kind, title: s.title };
        return '<article class="card"><div class="item-head"><h4>' + esc(s.title) + '</h4><span class="muted">' + esc(s.ref) + '</span></div><p class="muted">' + esc(s.preview) + '</p>' +
          (ready ? '' : '<p class="muted">Pay link not ready: ' + esc(s.blocked.join(', ')) + '</p>') +
          '<div class="btn-row"><button type="button" class="btn btn-primary" data-act="send" data-id="s' + i + '"' + (ready ? '' : ' disabled') + '>Send…</button></div></article>';
      }).join('') : '<p class="empty">Nothing to send right now.</p>';
      html += '<h3>Sent from this page</h3>';
      html += m.sent.length ? m.sent.map(function (r) {
        return '<article class="card"><div class="item-head"><h4>' + esc(r.title) + '</h4><span class="chip paid">Sent</span></div><p class="muted">' + esc(r.ref + ' · ' + r.who + ' · ' + r.at) + '</p></article>';
      }).join('') : '<p class="empty">Nothing sent yet.</p>';
      var el = $('message-list');
      el.innerHTML = html;
      wireActions(el, idx);
    }).catch(function (e) { toast(e.message); });
  }

  /* ---------- add ---------- */
  var addForm = $('add-form');
  var sel = $('add-event');
  sel.innerHTML = '<option value="">Choose…</option>' + Object.keys(C.eventTypes || {}).map(function (k) { return '<option value="' + esc(k) + '">' + esc(C.eventTypes[k]) + '</option>'; }).join('');
  function addKind() { var v = picked(addForm, 'kind'); return v || 'booking'; }
  function addPayload() {
    var k = addKind();
    return {
      kind: k === 'booking' ? 'booking' : 'hold', hold_type: k === 'blocked' ? 'blocked' : 'hold',
      date: $('add-date').value, name: $('add-name').value.trim(), email: $('add-email').value.trim(),
      guests: $('add-guests').value, event_type: sel.value, notes: $('add-notes').value,
      charge_now: picked(addForm, 'charge_now') || 'deposit', no_guest_email: $('add-no-email').checked
    };
  }
  var previewSeq = 0;
  function addPreview() {
    var p = addPayload(), note = $('add-date-note'), box = $('add-price'), seq = ++previewSeq;
    $('booking-only').hidden = p.kind !== 'booking';
    $('add-name-label').textContent = p.kind === 'booking' ? 'Guest name' : 'Label (optional)';
    if (!p.date) { note.textContent = ''; box.innerHTML = ''; return; }
    if (p.kind === 'booking' && !(Number(p.guests) > 0)) p.guests = 1;
    api('add-preview', p).then(function (q) {
      if (seq !== previewSeq) return; // an older answer arrived late
      note.textContent = q.blocked || 'This date is open.';
      note.className = 'day-note' + (q.blocked ? ' bad' : '');
      $('add-submit').disabled = !!q.blocked;
      if (q.kind !== 'booking') { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="line"><span>Price for the day</span><strong class="money">' + esc(q.text.total) + '</strong></div>' +
        '<div class="line"><span>' + esc(q.charge_label) + '</span><span class="money">' + esc(q.text.due_now) + '</span></div>' +
        '<div class="line fee"><span>If paid by card, plus ' + esc(C.feeLabel) + ' fee</span><span class="money">' + esc(q.text.card_fee) + ' (' + esc(q.text.card_total) + ' by card)</span></div>' +
        (q.balance > 0 ? '<div class="line fee"><span>Balance, due by ' + esc(q.text.balance_due_on) + '</span><span class="money">' + esc(q.text.balance) + '</span></div>' : '');
    }).catch(function (e) { if (seq !== previewSeq) return; note.textContent = e.message; note.className = 'day-note bad'; box.innerHTML = ''; $('add-submit').disabled = true; });
  }
  addForm.addEventListener('change', addPreview);
  addForm.addEventListener('reset', function () { setTimeout(function () { $('add-no-email').checked = true; addPreview(); }, 0); });
  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var p = addPayload();
    if (!p.date) return toast('Choose a date.');
    if (p.kind === 'booking') {
      if (!p.name) return toast('Enter the guest name.');
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(p.email)) return toast('Enter a valid guest email.');
      var g = Number(p.guests);
      if (!(g >= 1 && g <= C.maxGuests)) return toast('Guests: 1 to ' + C.maxGuests + '.');
    }
    // The confirm screen always shows a fresh quote from the same server function the save uses.
    api('add-preview', p).then(function (q) {
      if (q.blocked) { toast(q.blocked); addPreview(); return; }
      var lines = [['Date', p.date], ['What', p.kind === 'booking' ? 'Booking' : (p.hold_type === 'blocked' ? 'Close the day' : 'Hold the date')]];
      if (p.kind === 'booking') {
        lines.push(['Guest', p.name], ['Guests', String(p.guests)], ['Price', q.text.total], [q.charge_label, q.text.due_now],
          ['If paid by card', q.text.card_fee + ' fee, ' + q.text.card_total + ' in all'], ['Guest email', emailLine(!p.no_guest_email)]);
      }
      confirmStep('Save this?', lines, 'Yes, save', function () {
        return api('add', p).then(function (r) {
          addForm.reset();
          if (!r.booking) return 'Saved. The date is now ' + (p.hold_type === 'blocked' ? 'closed.' : 'held.');
          var id = r.booking.id;
          return { text: r.booking.ref + ' saved as a request waiting for ' + r.booking.name + '.' + (p.no_guest_email ? ' The guest was not emailed.' : ''), then: p.no_guest_email ? null : function () { sendFlow(id, 'request', ''); } };
        });
      });
    }).catch(function (err) { toast(err.message); });
  });

  function loadPanel(name) {
    if (name === 'calendar') { loadSummary(); return loadCalendar(); }
    if (name === 'requests') return loadRequests();
    if (name === 'payments') return loadPayments();
    if (name === 'upcoming') return loadUpcoming();
    if (name === 'messages') return loadMessages();
    if (name === 'add') return addPreview();
  }
  function refreshAll() { loadPanel(state.panel); if (state.panel !== 'calendar') loadSummary(); }

  $('add-guests').value = '';
  showPanel('calendar');
})();
