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
  function currentType() {
    var t = form.querySelector('input[name=type]:checked') || form.querySelector('input[name=type]');
    return t ? t.value : 'day';
  }
  function dayInfo() { return data[date.value] || null; }
  function refreshHours() {
    if (!startSel) return;
    var info = dayInfo();
    var first = info && info.first_hour !== null && info.first_hour !== undefined ? +info.first_hour : null;
    var picked = null;
    Array.prototype.forEach.call(startSel.options, function (op) {
      var off = !info || first === null || (+op.value) < first;
      op.disabled = off;
      if (!off && picked === null) picked = op.value;
    });
    if (picked !== null && startSel.selectedOptions.length && startSel.selectedOptions[0].disabled) startSel.value = picked;
  }
  function refreshNotice() {
    var btn = form.querySelector('.brb-btn');
    var info = dayInfo();
    var msg = '';
    var block = false;
    if (date.value && info) {
      var hourly = currentType() === 'hourly';
      if (!hourly && !info.day_ok) {
        msg = 'A full day on this date starts inside the ' + BRB.noticeHours + '-hour minimum notice. Choose a later date' + (BRB.mode !== 'day' ? ', or switch to hourly if a later start time is open' : '') + '.';
        block = BRB.mode === 'day' || currentType() !== 'hourly';
      }
      if (hourly && (info.first_hour === null || info.first_hour === undefined)) {
        msg = 'No start time on this date is at least ' + BRB.noticeHours + ' hours from now.';
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
    var hourly = currentType() === 'hourly';
    var dayBox = form.querySelector('.brb-day');
    var hourBox = form.querySelector('.brb-hourly');
    if (dayBox) dayBox.style.display = hourly ? 'none' : '';
    if (hourBox) hourBox.style.display = hourly ? '' : 'none';
    refreshHours();
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
      var k = key(d), s = (data[k] || {}).status || 'free';
      var off = (s === 'past' || s === 'booked' || s === 'blackout' || s === 'soon');
      h += '<button type="button" class="d ' + s + (date.value === k ? ' sel' : '') + '" data-k="' + k + '"' + (off ? ' disabled' : '') + '>' + d.getDate() + '</button>';
      d.setDate(d.getDate() + 1);
    }
    cal.innerHTML = h + '</div>';
  }
  function load() {
    var headers = { 'Accept': 'application/json' };
    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    fetch(cfg.api + '?month=' + cur.getFullYear() + '-' + pad(cur.getMonth() + 1), {
      credentials: 'omit',
      cache: 'no-store',
      headers: headers
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
    date.value = b.dataset.k;
    showBusy();
    syncType();
    render();
  });
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!refreshNotice()) e.preventDefault();
    });
  }
  load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  function bindEnd() {
    var input = document.querySelector('input[name="end_time"]');
    if (!input || !input.form || input.dataset.brbBound) return;
    input.dataset.brbBound = '1';
    input.setAttribute('max', '23:30');
    input.setAttribute('step', '60');
    input.form.addEventListener('submit', function (e) {
      var v = input.value || '';
      var parts = v.split(':');
      var msg = '';
      if (parts.length >= 2) {
        var mins = (+parts[0]) * 60 + (+parts[1]);
        if (mins > 23 * 60 + 30 || mins < 5 * 60) msg = 'Everyone must be out of The Boardroom by 11:30 PM. Please choose an end time no later than 11:30 PM so there is time to wrap up. An early-morning time is not available.';
        var type = input.form.querySelector('input[name="type"]');
        var start = input.form.querySelector('input[name="start_hour"]');
        if (!msg && type && type.value === 'hourly' && start && mins <= (+start.value) * 60) msg = 'That end time is before the booking starts. Please choose a time after the start and no later than 11:30 PM. Everyone must be out by 11:30 PM.';
      }
      var alert = input.form.querySelector('.brb-end-err');
      if (!msg) {
        if (alert) alert.remove();
        return;
      }
      e.preventDefault();
      if (!alert) {
        alert = document.createElement('div');
        alert.className = 'brb-alert brb-err brb-end-err';
        input.form.insertBefore(alert, input.form.firstChild);
      }
      alert.textContent = msg;
      input.focus();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindEnd);
  else bindEnd();
})();
