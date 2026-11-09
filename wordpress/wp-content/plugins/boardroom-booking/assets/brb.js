(function () {
  function boot() {
  var cal = document.getElementById('brb-cal');
  var cfg = window.BRB || {};
  if (cal) {
    if (!cfg.api && cal.getAttribute('data-api')) cfg.api = cal.getAttribute('data-api');
    if (!cfg.nonce && cal.getAttribute('data-nonce')) cfg.nonce = cal.getAttribute('data-nonce');
    if (!cfg.noticeHours && cal.getAttribute('data-notice')) cfg.noticeHours = +cal.getAttribute('data-notice');
    if (!cfg.mode && cal.getAttribute('data-mode')) cfg.mode = cal.getAttribute('data-mode');
  }
  if (!cal || !cfg.api) return;
  window.BRB = cfg;
  var date = document.getElementById('brb-date');
  var busyEl = document.getElementById('brb-busy');
  var noticeEl = document.getElementById('brb-notice');
  var form = document.getElementById('brb-form');
  var startSel = document.getElementById('brb-start');
  var cur = new Date();
  cur.setDate(1);
  var data = {};

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function key(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function dayInfo() {
    if (!date || !date.value) return null;
    return data[date.value] || null;
  }
  function refreshNotice() {
    if (!form) return true;
    var btn = form.querySelector('.brb-btn');
    var info = dayInfo();
    var msg = '';
    var block = false;
    if (date.value && info) {
      if (!info.day_ok) {
        msg = 'A full day on this date starts inside the ' + BRB.noticeHours + '-hour minimum notice. Choose a later date.';
        block = true;
      }
      if (info.status === 'soon' || info.status === 'booked' || info.status === 'blackout' || info.status === 'past') {
        block = true;
        if (!msg) msg = 'That date is not available.';
      }
    }
    if (noticeEl) noticeEl.textContent = msg;
    if (btn) btn.disabled = block;
    return !block;
  }
  function syncType() {
    refreshNotice();
  }
  if (form) {
    form.addEventListener('change', function (e) {
      if (e.target.name === 'type' || e.target === date || e.target === startSel) {
        syncType();
        if (e.target === date) { showBusy(); render(); }
      }
    });
    syncType();
  }
  function showBusy() {
    var d = dayInfo();
    if (!busyEl) return;
    if (d && d.busy && d.busy.length) busyEl.textContent = 'Already booked: ' + d.busy.map(function (b) { return fmt(b[0]) + '–' + fmt(b[1]); }).join(', ');
    else busyEl.textContent = '';
  }
  function fmt(h) { var a = h % 24 < 12 ? 'am' : 'pm', x = h % 12 || 12; return x + a; }
  function render() {
    var h = '<div class="brb-calhead"><button type="button" data-n="-1" aria-label="Previous month">‹</button><strong>' + cur.toLocaleString(undefined, { month: 'long', year: 'numeric' }) + '</strong><button type="button" data-n="1" aria-label="Next month">›</button></div><div class="brb-calgrid">';
    'SMTWTFS'.split('').forEach(function (c) { h += '<span class="dow">' + c + '</span>'; });
    for (var i = 0; i < cur.getDay(); i++) h += '<span></span>';
    var d = new Date(cur);
    while (d.getMonth() === cur.getMonth()) {
      var k = key(d);
      var info = data[k] || {};
      var s = info.status || 'free';
      if (s === 'partial') s = 'booked';
      var off = (s === 'past' || s === 'booked' || s === 'blackout' || s === 'soon');
      var price = info.price ? '<span class="brb-dayprice">' + info.price + '</span>' : '';
      h += '<button type="button" class="d ' + s + (date && date.value === k ? ' sel' : '') + '" data-k="' + k + '"' + (off ? ' disabled' : '') + '><span class="brb-daynum">' + d.getDate() + '</span>' + price + '</button>';
      d.setDate(d.getDate() + 1);
    }
    cal.innerHTML = h + '</div>';
  }
  function load() {
    // A logged-in page nonce is tied to that user. Sending it without the
    // login cookie makes WordPress reject the request. This feed is public,
    // so omit the cookie and do not send the nonce.
    fetch(cfg.api + '?month=' + cur.getFullYear() + '-' + pad(cur.getMonth() + 1), {
      credentials: 'omit',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      if (!r.ok) throw new Error('calendar');
      return r.json();
    }).then(function (j) {
      if (!j || !j.days) throw new Error('calendar');
      Object.assign(data, j.days);
      render();
      showBusy();
      if (form) syncType();
    }).catch(function () {
      cal.textContent = 'The calendar could not be loaded. Please refresh the page.';
    });
  }
  cal.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.n) { cur.setMonth(cur.getMonth() + (+b.dataset.n)); load(); return; }
    if (b.disabled) return;
    var dest = cal.getAttribute('data-book');
    if (dest) {
      window.location = dest.replace(/\/?$/, '/') + '?date=' + encodeURIComponent(b.dataset.k);
      return;
    }
    if (!date) return;
    date.value = b.dataset.k;
    showBusy();
    syncType();
    render();
  });
  if (form) {
    form.addEventListener('submit', function (e) {
      if (form.getAttribute('data-sending') === '1') { e.preventDefault(); return; }
      if (!refreshNotice()) { e.preventDefault(); return; }
      form.setAttribute('data-sending', '1');
      var btn = form.querySelector('.brb-btn');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sending…';
      }
    });
    window.addEventListener('pageshow', function (ev) {
      if (!ev.persisted) return;
      form.removeAttribute('data-sending');
      var btn = form.querySelector('.brb-btn');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Continue';
      }
      refreshNotice();
    });
    var guests = form.querySelector('input[name="guests"]');
    var guestNote = document.getElementById('brb-guest-note');
    function refreshGuests() {
      if (!guests || !guestNote) return;
      var n = parseInt(guests.value, 10);
      guestNote.hidden = !(n > 80);
    }
    if (guests) {
      guests.addEventListener('input', refreshGuests);
      guests.addEventListener('change', refreshGuests);
      refreshGuests();
    }
  }
  if (cal.getAttribute('data-lazy') === '1' && 'IntersectionObserver' in window) {
    var seen = false;
    var watch = new IntersectionObserver(function (entries) {
      if (seen) return;
      if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
      seen = true;
      watch.disconnect();
      load();
    }, { rootMargin: '200px' });
    watch.observe(cal);
  } else {
    load();
  }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  function trackBooking(name) {
    var cfg = window.BRB || {};
    if (!cfg.eventEndpoint) return;
    fetch(cfg.eventEndpoint, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event: name })
    }).catch(function () {});
    if (window.gtag) window.gtag('event', name);
  }
  function trackSteps() {
    if (document.getElementById('brb-cal')) trackBooking('booking_step_date');
    var submit = document.querySelector('form input[name="action"][value="brb_book"]');
    if (!submit || !submit.form) return;
    trackBooking('booking_step_review');
    var bookForm = submit.form;
    bookForm.addEventListener('submit', function (e) {
      if (bookForm.getAttribute('data-sending') === '1') { e.preventDefault(); return; }
      bookForm.setAttribute('data-sending', '1');
      var btn = bookForm.querySelector('.brb-btn');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sending…';
      }
      trackBooking('booking_step_submit');
    });
    window.addEventListener('pageshow', function (ev) {
      if (!ev.persisted || !bookForm) return;
      bookForm.removeAttribute('data-sending');
      var btn = bookForm.querySelector('.brb-btn');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Submit request';
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', trackSteps);
  else trackSteps();
})();
