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
  var leadCancel = root.querySelector('.brb-chat-lead-cancel');
  var history = [];
  var turns = [];

  function focusEl(el) {
    if (el && el.focus) el.focus({ preventScroll: true });
  }
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
    if (closeBtn) focusEl(closeBtn);
    else if (input) focusEl(input);
  }
  function closePanel() {
    panel.hidden = true;
    if (launch) {
      launch.setAttribute('aria-expanded', 'false');
      focusEl(launch);
    }
  }
  function scrollLogTo(node) {
    if (!log || !node) return;
    var top = node.getBoundingClientRect().top - log.getBoundingClientRect().top + log.scrollTop;
    log.scrollTop = Math.max(0, top - 8);
  }
  function linkify(el, text) {
    var re = /https?:\/\/[^\s]+/g;
    var last = 0;
    var match;
    while ((match = re.exec(text))) {
      if (match.index > last) el.appendChild(document.createTextNode(text.slice(last, match.index)));
      var a = document.createElement('a');
      a.href = match[0];
      a.textContent = match[0];
      el.appendChild(a);
      last = match.index + match[0].length;
    }
    el.appendChild(document.createTextNode(text.slice(last)));
  }
  function addMsg(role, text) {
    var row = document.createElement('div');
    var bot = role !== 'user';
    row.className = 'brb-chat-msg ' + (bot ? 'bot' : 'user');
    if (bot) {
      var face = document.createElement('img');
      face.className = 'brb-chat-avatar';
      face.alt = '';
      face.width = 40;
      face.height = 40;
      face.src = root.getAttribute('data-walter') || '';
      row.appendChild(face);
    }
    var copy = document.createElement('span');
    copy.className = 'brb-chat-copy';
    var who = document.createElement('span');
    who.className = 'brb-chat-who';
    who.textContent = bot ? 'Walter' : 'You';
    copy.appendChild(who);
    if (bot) linkify(copy, text);
    else copy.appendChild(document.createTextNode(text));
    row.appendChild(copy);
    log.appendChild(row);
    if (role === 'user') scrollLogTo(row);
    return row;
  }
  function keepWindow() {
    var x = window.scrollX;
    var y = window.scrollY;
    return function () { window.scrollTo(x, y); };
  }
  function showLead() {
    var restore = keepWindow();
    lead.hidden = false;
    if (leadToggle) leadToggle.setAttribute('aria-expanded', 'true');
    var name = lead.querySelector('[name="name"]');
    if (name) focusEl(name);
    restore();
  }
  function hideLead() {
    var restore = keepWindow();
    lead.hidden = true;
    if (leadToggle) leadToggle.setAttribute('aria-expanded', 'false');
    if (leadToggle) focusEl(leadToggle);
    restore();
  }
  if (launch) {
    launch.addEventListener('click', function () {
      if (panel.hidden) openPanel();
      else closePanel();
    });
  }
  function scrollToChat(chat) {
    var field = chat.querySelector('.brb-chat-input');
    if (field) field.focus({ preventScroll: true });
    var target = document.getElementById('ask-walter') || chat.querySelector('.brb-chat-panel') || chat;
    var header = document.querySelector('.site-header');
    var offset = header ? header.getBoundingClientRect().height + 16 : 96;
    var top = target.getBoundingClientRect().top + window.scrollY - offset;
    var rootEl = document.documentElement;
    var prev = rootEl.style.scrollBehavior;
    rootEl.style.scrollBehavior = 'auto';
    window.scrollTo(0, Math.max(0, top));
    rootEl.style.scrollBehavior = prev;
  }
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-brb-open-chat]');
    if (!opener || opener.closest('.brb-chat')) return;
    e.preventDefault();
    var inline = document.querySelector('.brb-chat-inline');
    if (inline) {
      scrollToChat(inline);
      return;
    }
    if (panel && panel.hidden) openPanel();
    else if (input) focusEl(input);
  });
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  leadToggle.addEventListener('click', function () {
    if (lead.hidden) showLead();
    else hideLead();
  });
  if (leadCancel) leadCancel.addEventListener('click', hideLead);
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
      last.focus({ preventScroll: true });
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus({ preventScroll: true });
    }
  });
  var chipRow = root.querySelector('.brb-chat-chip-row');
  var starterHTML = chipRow ? chipRow.innerHTML : '';
  function clearSuggestions() {
    var old = root.querySelectorAll('.brb-chat-suggestions');
    for (var i = 0; i < old.length; i++) old[i].parentNode.removeChild(old[i]);
  }
  function restoreStarters() {
    if (chipRow) chipRow.innerHTML = starterHTML;
  }
  function addSuggestions(items) {
    clearSuggestions();
    if (!items || !items.length) return;
    var questions = document.createElement('div');
    questions.className = 'brb-chat-suggestions';
    questions.setAttribute('role', 'group');
    questions.setAttribute('aria-label', 'Suggested questions');
    var actions = document.createElement('div');
    actions.className = 'brb-chat-suggestions brb-chat-actions';
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', 'Suggested questions');
    var qCount = 0;
    var aCount = 0;
    items.slice(0, 3).forEach(function (item) {
      if (!item || (!item.question && !item.href)) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'brb-chat-chip' + (item.href ? ' brb-chat-action' : '');
      b.textContent = item.label || item.question;
      if (item.href) {
        b.setAttribute('aria-label', item.label || 'Open');
        b.addEventListener('click', function () {
          var url = new URL(item.href, window.location.href);
          var same = url.origin === window.location.origin && url.pathname.replace(/\/$/, '') === window.location.pathname.replace(/\/$/, '');
          if (same && url.hash) {
            var target = document.querySelector(url.hash);
            if (target) {
              window.location.hash = url.hash;
              return;
            }
          }
          window.location.assign(url.href);
        });
        actions.appendChild(b);
        aCount++;
      } else {
        b.setAttribute('aria-label', 'Ask: ' + (item.label || item.question));
        b.addEventListener('click', function () { sendMessage(item.question); });
        questions.appendChild(b);
        qCount++;
      }
    });
    if (qCount) {
      if (chipRow) chipRow.appendChild(questions);
      else log.appendChild(questions);
    }
    if (aCount) log.appendChild(actions);
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
      var reply = addMsg('bot', data.reply);
      scrollLogTo(reply);
      addSuggestions(data.suggestions);
      turns.push({ role: 'assistant', text: data.reply });
      history.push({ role: 'user', text: text });
      history.push({ role: 'assistant', text: data.reply });
      if (history.length > 16) history = history.slice(-16);
    }).catch(function () {
      setTyping(false);
      scrollLogTo(addMsg('bot', 'Please try that once more in a moment. You can also use the Contact form.'));
    });
  }
  ask.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value);
  });
  if (chipRow) {
    chipRow.addEventListener('click', function (e) {
      var chip = e.target.closest('.brb-chat-chip[data-question]');
      if (!chip) return;
      sendMessage(chip.getAttribute('data-question'));
    });
  }
  var popular = root.querySelectorAll('.brb-chat-q');
  for (var q = 0; q < popular.length; q++) {
    popular[q].addEventListener('click', function () {
      sendMessage(this.getAttribute('data-question'));
    });
  }
  var resetBtn = root.querySelector('.brb-chat-reset');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      history = [];
      turns = [];
      input.value = '';
      if (lead && !lead.hidden) hideLead();
      log.innerHTML = '';
      addMsg('bot', root.getAttribute('data-opening') || '');
      clearSuggestions();
      restoreStarters();
    });
  }
  var grab = root.querySelector('.brb-chat-grab');
  if (grab && panel && !root.classList.contains('brb-chat-inline')) {
    grab.addEventListener('pointerdown', function (e) {
      var rect = panel.getBoundingClientRect();
      var ox = e.clientX;
      var oy = e.clientY;
      var left = rect.left;
      var top = rect.top;
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
      grab.setPointerCapture(e.pointerId);
      function move(ev) {
        var x = Math.max(8, Math.min(window.innerWidth - rect.width - 8, left + ev.clientX - ox));
        var y = Math.max(8, Math.min(window.innerHeight - 80, top + ev.clientY - oy));
        panel.style.left = x + 'px';
        panel.style.top = y + 'px';
      }
      function up() {
        grab.removeEventListener('pointermove', move);
        grab.removeEventListener('pointerup', up);
      }
      grab.addEventListener('pointermove', move);
      grab.addEventListener('pointerup', up);
    });
  }
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
        scrollLogTo(addMsg('bot', res.body.message));
        lead.reset();
        hideLead();
      } else {
        var msg = res.body && res.body.message ? res.body.message : 'Please check the form and try again.';
        scrollLogTo(addMsg('bot', msg));
      }
    }).catch(function () {
      scrollLogTo(addMsg('bot', 'Please try again in a moment, or use the Contact form.'));
    });
  });
})();
