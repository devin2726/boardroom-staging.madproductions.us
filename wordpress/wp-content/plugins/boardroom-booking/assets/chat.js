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
  function trackEvent(name) {
    if (!window.BRB_CHAT || !BRB_CHAT.eventEndpoint) return;
    fetch(BRB_CHAT.eventEndpoint, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json', 'X-BRB-Chat': BRB_CHAT.nonce },
      body: JSON.stringify({ event: name })
    }).catch(function () {});
    if (window.gtag) window.gtag('event', name);
  }
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function armClip(video) {
    if (!video || video.dataset.ready) return;
    var webm = video.getAttribute('data-webm');
    var mp4 = video.getAttribute('data-mp4');
    if (webm) {
      var source = document.createElement('source');
      source.src = webm;
      source.type = 'video/webm';
      video.appendChild(source);
    }
    if (mp4) {
      var fallback = document.createElement('source');
      fallback.src = mp4;
      fallback.type = 'video/mp4';
      video.appendChild(fallback);
    }
    video.muted = true;
    video.defaultMuted = true;
    video.dataset.ready = '1';
    video.load();
  }
  function showClip(stage, name) {
    if (!stage) return;
    var clips = stage.querySelectorAll('video');
    for (var i = 0; i < clips.length; i++) {
      clips[i].onended = null;
      clips[i].pause();
    }
    stage.classList.remove('is-wave', 'is-idle', 'is-eyes');
    if (name) stage.classList.add('is-' + name);
  }
  function playClip(stage, name, done) {
    if (!stage || reduceMotion) {
      showClip(stage, '');
      if (done) done();
      return;
    }
    var video = stage.querySelector('.brb-walter-' + name);
    if (!video) {
      if (done) done();
      return;
    }
    armClip(video);
    showClip(stage, name);
    var begin = function () {
      try { video.currentTime = 0; } catch (err) {}
      video.onended = function () { if (done) done(); };
      var started = video.play();
      if (started && started.catch) started.catch(function () { showClip(stage, ''); if (done) done(); });
    };
    if (video.readyState >= 2) begin();
    else video.addEventListener('loadeddata', begin, { once: true });
  }
  var launchStage = root.querySelector('.brb-walter-launch');
  var headStage = root.querySelector('.brb-walter-head');
  var typing = root.querySelector('.brb-typing');
  function setTyping(on) {
    if (typing) typing.hidden = !on;
    if (on) playClip(headStage, 'eyes');
    else if (headStage && headStage.classList.contains('is-eyes')) playClip(headStage, 'idle');
  }
  function greetHead() {
    playClip(headStage, 'wave', function () {
      if (typing && !typing.hidden) return;
      playClip(headStage, 'idle');
    });
    root.classList.add('is-open');
  }
  if (launchStage && !reduceMotion) {
    playClip(launchStage, 'wave', function () {
      var still = launchStage.querySelector('.brb-walter-still');
      var idle = launchStage.querySelector('.brb-walter-idle');
      var poster = idle && idle.getAttribute('poster');
      if (still && poster) still.src = poster;
      showClip(launchStage, '');
    });
  }
  if (root.classList.contains('brb-chat-inline')) greetHead();
  function openPanel() {
    panel.hidden = false;
    trackEvent('chat_open');
    greetHead();
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
    var bot = role !== 'user';
    p.className = 'brb-chat-msg ' + (bot ? 'bot' : 'user');
    if (bot && root.getAttribute('data-walter')) {
      var img = document.createElement('img');
      img.className = 'brb-walter brb-walter-bubble';
      img.src = root.getAttribute('data-walter');
      img.alt = 'Walter, the Boardroom butler';
      img.width = 64;
      img.height = 64;
      p.appendChild(img);
    }
    var copy = document.createElement('span');
    copy.className = 'brb-chat-copy';
    var who = document.createElement('span');
    who.className = 'brb-chat-who';
    who.textContent = bot ? 'Walter' : 'You';
    copy.appendChild(who);
    copy.appendChild(document.createTextNode(text));
    p.appendChild(copy);
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
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-brb-open-chat]');
    if (!opener) return;
    e.preventDefault();
    if (root.classList.contains('brb-chat-inline')) {
      root.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (input) input.focus();
      return;
    }
    if (panel.hidden) openPanel();
  });
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
    setTyping(true);
    return askChat(0).then(function (data) {
      setTyping(false);
      addMsg('bot', data.reply);
      addSuggestions(data.suggestions);
      turns.push({ role: 'assistant', text: data.reply });
      history.push({ role: 'user', text: text });
      history.push({ role: 'assistant', text: data.reply });
      if (history.length > 16) history = history.slice(-16);
      if (data.lead) showLead();
    }).catch(function () {
      setTyping(false);
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
