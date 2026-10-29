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
  var turns = [];

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
    if (launch) launch.setAttribute('aria-expanded', 'true');
    if (closeBtn) closeBtn.focus();
    else if (input) input.focus();
  }
  function closePanel() {
    panel.hidden = true;
    if (launch) {
      launch.setAttribute('aria-expanded', 'false');
      launch.focus();
    }
  }
  function addMsg(role, text) {
    var p = document.createElement('p');
    p.className = 'brb-chat-msg ' + (role === 'user' ? 'user' : 'bot');
    var who = document.createElement('span');
    who.className = 'brb-chat-who';
    who.textContent = role === 'user' ? 'You' : 'Boardroom';
    p.appendChild(who);
    p.appendChild(document.createTextNode(text));
    log.appendChild(p);
    var scroller = log.closest('.brb-chat-scroll') || log;
    scroller.scrollTop = scroller.scrollHeight;
  }
  function showLead() {
    lead.hidden = false;
  }
  if (launch) {
    launch.addEventListener('click', function () {
      if (panel.hidden) openPanel();
      else closePanel();
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  leadToggle.addEventListener('click', function () {
    showLead();
    var name = lead.querySelector('[name="name"]');
    if (name) name.focus();
  });
  panel.addEventListener('keydown', function (e) {
    if (!launch) return;
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
  function clearSuggestions() {
    var old = log.querySelectorAll('.brb-chat-suggestions');
    for (var i = 0; i < old.length; i++) old[i].parentNode.removeChild(old[i]);
  }
  function addSuggestions(items) {
    clearSuggestions();
    if (!items || !items.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'brb-chat-suggestions';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Suggested questions');
    items.slice(0, 3).forEach(function (item) {
      if (!item || !item.question) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'brb-chat-chip';
      b.textContent = item.label || item.question;
      b.setAttribute('aria-label', 'Ask: ' + (item.label || item.question));
      b.addEventListener('click', function () { sendMessage(item.question); });
      wrap.appendChild(b);
    });
    log.appendChild(wrap);
    var scroller = log.closest('.brb-chat-scroll') || log;
    scroller.scrollTop = scroller.scrollHeight;
  }
  function sendMessage(text) {
    text = (text || '').trim();
    if (!text) return;
    clearSuggestions();
    addMsg('user', text);
    input.value = '';
    turns.push({ role: 'user', text: text });
    function askChat(attempt) {
      return fetch(BRB_CHAT.endpoint, {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json', 'X-BRB-Chat': BRB_CHAT.nonce },
        body: JSON.stringify({ message: text, history: history })
      }).then(function (r) {
        if ((r.status === 502 || r.status === 503 || r.status === 504) && attempt < 1) {
          return askChat(attempt + 1);
        }
        return r.json().then(function (data) {
          if (!r.ok || !data || !data.reply) throw new Error('chat');
          return data;
        });
      });
    }
    return askChat(0).then(function (data) {
      addMsg('bot', data.reply);
      addSuggestions(data.suggestions);
      turns.push({ role: 'assistant', text: data.reply });
      history.push({ role: 'user', text: text });
      if (history.length > 8) history = history.slice(-8);
      if (data.lead) showLead();
    }).catch(function () {
      addMsg('bot', 'Please try that once more in a moment. You can also use the Contact form.');
    });
  }
  ask.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value);
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
      turns: turns.slice(-12)
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
