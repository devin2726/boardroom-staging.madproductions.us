(function () {
  var root = document.querySelector('.brb-chat');
  if (!root || !window.BRB_CHAT) return;
  var launch = root.querySelector('.brb-chat-launch');
  var panel = root.querySelector('.brb-chat-panel');
  var closeBtn = root.querySelector('.brb-chat-close');
  var ask = root.querySelector('.brb-chat-ask');
  var input = root.querySelector('.brb-chat-input');
  var log = root.querySelector('.brb-chat-log');
  var lead = root.querySelector('.brb-chat-lead');
  var leadToggle = root.querySelector('.brb-chat-lead-toggle');
  var history = [];
  var transcript = [];

  function focusable() {
    var items = panel.querySelectorAll('button, [href], input, select, textarea');
    var list = [];
    for (var i = 0; i < items.length; i++) {
      var el = items[i];
      if (el.disabled || el.tabIndex === -1) continue;
      if (el.closest('[hidden]')) continue;
      list.push(el);
    }
    return list;
  }
  function openPanel() {
    panel.hidden = false;
    launch.setAttribute('aria-expanded', 'true');
    closeBtn.focus();
  }
  function closePanel() {
    panel.hidden = true;
    launch.setAttribute('aria-expanded', 'false');
    launch.focus();
  }
  function addMsg(role, text) {
    var p = document.createElement('p');
    p.className = 'brb-chat-msg ' + (role === 'user' ? 'user' : 'bot');
    p.textContent = text;
    log.appendChild(p);
    log.scrollTop = log.scrollHeight;
  }
  function showLead() {
    lead.hidden = false;
  }
  launch.addEventListener('click', function () {
    if (panel.hidden) openPanel();
    else closePanel();
  });
  closeBtn.addEventListener('click', closePanel);
  leadToggle.addEventListener('click', function () {
    showLead();
    var name = lead.querySelector('[name="name"]');
    if (name) name.focus();
  });
  panel.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      closePanel();
      return;
    }
    if (e.key !== 'Tab') return;
    var list = focusable();
    if (!list.length) return;
    var first = list[0];
    var last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });
  ask.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    addMsg('user', text);
    input.value = '';
    transcript.push('Guest: ' + text);
    fetch(BRB_CHAT.endpoint, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json', 'X-BRB-Chat': BRB_CHAT.nonce },
      body: JSON.stringify({ message: text, history: history })
    }).then(function (r) { return r.json(); }).then(function (data) {
      var reply = data && data.reply ? data.reply : 'Please try again in a moment, or use the Contact form.';
      addMsg('bot', reply);
      transcript.push('Assistant: ' + reply);
      history.push({ role: 'user', text: text });
      if (history.length > 8) history = history.slice(-8);
      if (data && data.lead) showLead();
    }).catch(function () {
      addMsg('bot', 'Please try again in a moment, or use the Contact form.');
    });
  });
  lead.addEventListener('submit', function (e) {
    e.preventDefault();
    var data = new FormData(lead);
    var payload = {
      company: data.get('company') || '',
      name: data.get('name') || '',
      email: data.get('email') || '',
      phone: data.get('phone') || '',
      event_date: data.get('event_date') || '',
      event_type: data.get('event_type') || '',
      note: data.get('note') || '',
      consent: lead.querySelector('[name="consent"]').checked ? '1' : '',
      transcript: transcript.slice(-12).join('\n').slice(0, 2000)
    };
    fetch(BRB_CHAT.leadEndpoint, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json', 'X-BRB-Chat': BRB_CHAT.nonce },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); }).then(function (res) {
      if (res.ok && res.body && res.body.message) {
        addMsg('bot', res.body.message);
        lead.reset();
        lead.hidden = true;
      } else {
        var msg = res.body && res.body.message ? res.body.message : 'Please check the form and try again.';
        addMsg('bot', msg);
      }
    }).catch(function () {
      addMsg('bot', 'Please try again in a moment, or use the Contact form.');
    });
  });
})();
