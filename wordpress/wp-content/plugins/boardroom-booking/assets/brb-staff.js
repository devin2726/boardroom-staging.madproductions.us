/* Boardroom staff dashboard (2.5.85). Talks only to brb/v1/staff/* with the REST + staff nonces.
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
    $('modal').innerHTML = '';
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
    if (b.event) rows.push(['Event', b.event]);
    rows.push(['Guests', String(b.guests || '')]);
    if (b.email) rows.push(['Email', b.email]);
    rows.push(['Status', b.status_label || '']);
    rows.push(['Walk-through', b.walkthrough ? 'Done' : 'Not yet']);
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
  function bookingButtons(b) {
    var out = '';
    if (b.can_approve) out += '<button type="button" class="btn btn-good" data-act="approve" data-id="' + b.id + '">Approve</button>';
    if (b.can_approve || b.can_decline) out += '<button type="button" class="btn" data-act="decline" data-id="' + b.id + '">Decline</button>';
    if (b.can_pay) {
      out += '<button type="button" class="btn btn-primary" data-act="pay" data-id="' + b.id + '">Record payment</button>';
      out += '<button type="button" class="btn" data-act="link" data-id="' + b.id + '">Copy pay link</button>';
    }
    return out ? '<div class="btn-row">' + out + '</div>' : '';
  }
  function bookingCard(b) {
    return '<article class="card"><div class="item-head"><h4>' + esc(b.name) + ' <span class="muted">' + esc(b.ref) + '</span></h4>' + chip(b.chip) + '</div>' +
      bookingFacts(b) + bookingButtons(b) + '</article>';
  }
  function holdCard(h) {
    var cls = h.hold_type === 'hold' ? 'held' : (h.hold_type === 'reserved' ? 'paid' : 'block');
    return '<article class="card"><div class="item-head"><h4>' + esc(h.name) + '</h4><span class="chip ' + cls + '">' + esc(h.label) + '</span></div>' +
      '<ul class="facts">' + (h.time ? '<li><span>Time</span><span>' + esc(h.time) + '</span></li>' : '') +
      (h.notes ? '<li><span>Notes</span><span>' + esc(h.notes) + '</span></li>' : '') + '</ul></article>';
  }
  function wireActions(root, byId) {
    root.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var b = byId[btn.getAttribute('data-id')];
        if (!b) return;
        var act = btn.getAttribute('data-act');
        if (act === 'approve') approveFlow(b);
        else if (act === 'decline') declineFlow(b);
        else if (act === 'pay') payFlow(b);
        else if (act === 'link') linkFlow(b);
        else if (act === 'send') sendFlow(b.booking_id, b.kind, '');
      });
    });
  }
  function indexById(list) { var o = {}; list.forEach(function (b) { if (b && b.id) o[b.id] = b; }); return o; }

  /* ---------- flows ---------- */
  function approveFlow(b) {
    var html = '<h3 id="modal-title">Approve ' + esc(b.name) + '</h3><p class="muted">' + esc(b.ref + ' · ' + b.when) + '</p>' +
      '<label class="check"><input type="checkbox" id="ap-walk"' + (b.walkthrough ? ' checked disabled' : '') + '> Walk-through done</label>' +
      '<p class="field-label">Charge now</p><div class="choice">' +
      '<label><input type="radio" name="ap-charge" value="deposit"' + (b.money && /^Pay in full/.test(b.money.charge_now) ? '' : ' checked') + '> Deposit ' + esc(C.depositPct) + '% (holds the date)</label>' +
      '<label><input type="radio" name="ap-charge" value="full"' + (b.money && /^Pay in full/.test(b.money.charge_now) ? ' checked' : '') + '> Pay in full (100%)</label></div>' +
      emailChoice('ap-email', 'approval email') +
      '<p class="warn" id="ap-err" hidden></p>' +
      '<div class="btn-row"><button type="button" class="btn btn-good" id="ap-go">Approve</button><button type="button" class="btn btn-quiet" data-close>Cancel</button></div>';
    openModal(html, function (m) {
      m.querySelector('#ap-go').addEventListener('click', function () {
        var walk = m.querySelector('#ap-walk').checked;
        if (!walk) { var e = m.querySelector('#ap-err'); e.hidden = false; e.textContent = 'Tick "Walk-through done" first.'; return; }
        var charge = picked(m, 'ap-charge'), mail = picked(m, 'ap-email') === '1';
        confirmStep('Approve this request?', [
          ['Guest', b.name + ' (' + b.ref + ')'], ['Date', b.when],
          ['Charge now', charge === 'full' ? 'Pay in full (100%)' : 'Deposit ' + C.depositPct + '%'],
          ['Guest email', emailLine(mail)]
        ], 'Yes, approve', function () {
          return api('booking/' + b.id + '/approve', { walkthrough: true, charge_now: charge, email_guest: mail })
            .then(function () { return { text: b.name + ' approved.' + (mail ? '' : ' The guest was not emailed.'), then: mail ? function () { sendFlow(b.id, 'approve', ''); } : null }; });
        });
      });
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
          var done = function () { toast('Pay link copied. Nothing was emailed.'); };
          if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(done, function () { ta.select(); document.execCommand('copy'); done(); });
          else { ta.select(); document.execCommand('copy'); done(); }
        });
      });
    }).catch(function (e) { toast(e.message); });
  }
  /** The only way a guest email leaves this page: full message + recipient, then Send or Cancel. */
  function sendFlow(bookingId, kind, note) {
    api('booking/' + bookingId + '/draft', { kind: kind, note: note || '' }).then(function (d) {
      var html = '<h3 id="modal-title">Send ' + esc(d.title.toLowerCase()) + '?</h3>' +
        '<ul class="facts"><li><span>To</span><span>' + esc(d.name) + ' &lt;' + esc(d.to) + '&gt;</span></li><li><span>Booking</span><span>' + esc(d.ref) + '</span></li>' +
        '<li><span>Subject</span><span>' + esc(d.subject) + '</span></li></ul>' +
        '<p class="field-label">Full message</p><pre class="mailbody" tabindex="0">' + esc(d.body) + '</pre>' +
        '<p class="muted">This goes to the guest as shown. Nothing has been sent yet.</p>' +
        '<div class="btn-row"><button type="button" class="btn btn-primary" id="sd-send">Send</button><button type="button" class="btn btn-quiet" data-close data-autofocus>Cancel</button></div>';
      openModal(html, function (m) {
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
      $('sum-requests').innerHTML = '<span class="k">Requests waiting</span><span class="v">' + esc(s.requests) + '</span><span class="d">' + esc(s.requests_text) + '</span>';
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
        var cls = 'day ' + (d.kind === 'open' ? '' : d.kind) + (d.date === cal.today ? ' today' : '');
        html += '<button type="button" class="' + cls + '" data-date="' + esc(d.date) + '" aria-label="' + esc(d.date + ', ' + d.label + (d.tag && d.tag !== d.label ? ', ' + d.tag : '')) + '">' +
          '<span class="n">' + d.day + '</span>' + (d.kind !== 'open' ? '<span class="tag">' + esc(d.tag) + '</span>' : '') + '</button>';
      });
      g.innerHTML = html;
      g.querySelectorAll('.day[data-date]').forEach(function (b) { b.addEventListener('click', function () { openDay(b.getAttribute('data-date')); }); });
    }).catch(function (e) { toast(e.message); });
  }
  $('cal-prev').addEventListener('click', function () { if (state.cal) { state.month = state.cal.prev; loadCalendar(); } });
  $('cal-next').addEventListener('click', function () { if (state.cal) { state.month = state.cal.next; loadCalendar(); } });

  function openDay(date) {
    api('day?date=' + encodeURIComponent(date)).then(function (d) {
      var bookings = d.items.filter(function (x) { return x.type === 'booking'; });
      var html = '<h3 id="modal-title">' + esc(d.title) + '</h3><p><span class="chip ' + esc({ booked: 'paid', held: 'held', waiting: 'review', closed: 'block', open: 'open' }[d.kind]) + '">' + esc(d.label) + '</span>' +
        (d.rate_text ? ' <span class="muted">' + esc(d.rate_text) + '</span>' : '') + '</p>';
      html += d.items.length ? d.items.map(function (it) { return it.type === 'booking' ? bookingCard(it) : holdCard(it); }).join('') : '<p class="empty">Nothing on this day.</p>';
      html += '<div class="btn-row">' + (d.can_add ? '<button type="button" class="btn btn-primary" id="day-add">Add a booking or hold</button>' : '') +
        '<button type="button" class="btn btn-quiet" data-close>Close</button></div>';
      if (!d.can_add && d.add_blocked) html += '<p class="muted">' + esc(d.add_blocked) + '</p>';
      openModal(html, function (m) {
        wireActions(m, indexById(bookings));
        var add = m.querySelector('#day-add');
        if (add) add.addEventListener('click', function () { closeModal(); $('add-date').value = date; showPanel('add'); addPreview(); });
      });
    }).catch(function (e) { toast(e.message); });
  }
  function listInto(el, rows, empty, render) {
    el.innerHTML = rows.length ? rows.map(render).join('') : '<p class="empty">' + esc(empty) + '</p>';
    wireActions(el, indexById(rows));
  }
  function loadRequests() { return api('requests').then(function (r) { listInto($('request-list'), r, 'No requests waiting.', bookingCard); }).catch(function (e) { toast(e.message); }); }
  function loadPayments() { return api('payments').then(function (r) { listInto($('payment-list'), r, 'No money owed right now.', bookingCard); }).catch(function (e) { toast(e.message); }); }
  function loadUpcoming() {
    return api('upcoming').then(function (rows) {
      $('upcoming-list').innerHTML = rows.length ? rows.map(function (r) {
        var tagc = r.type === 'booking' ? chip(r.chip) : '<span class="chip ' + (r.hold_type === 'hold' ? 'held' : (r.hold_type === 'reserved' ? 'paid' : 'block')) + '">' + esc(r.label) + '</span>';
        var line = r.type === 'booking' ? (r.hours ? 'Hours booked ' + r.hours : '') + (r.end_time ? ' · guest says it ends ' + r.end_time : '') + (r.guests ? ' · ' + r.guests + ' guests' : '') : (r.time || '');
        return '<article class="card"><div class="item-head"><h4>' + esc(r.when) + ' · ' + esc(r.name) + '</h4>' + tagc + '</div><p class="muted">' + esc(line) + '</p></article>';
      }).join('') : '<p class="empty">Nothing coming up.</p>';
    }).catch(function (e) { toast(e.message); });
  }
  function loadMessages() {
    return api('messages').then(function (m) {
      var idx = {}, html = '<h3>Emails you may want to send</h3>';
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
