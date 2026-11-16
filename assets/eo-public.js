/* Event Organizers, public pages: the assistant, and the live room's refresh. */
(function () {
  'use strict';

  var live = document.getElementById('ev-live');
  if (live) {
    // Every 20 seconds, unless someone is in the middle of typing or choosing.
    setInterval(function () {
      var a = document.activeElement;
      var busy = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
      if (!busy && !document.querySelector('input[type=radio]:checked')) { window.location.reload(); }
    }, 20000);
  }

  var box = document.getElementById('ev-chat');
  if (!box) { return; }
  var open = document.createElement('button');
  open.type = 'button';
  open.className = 'ev-chat-open';
  open.textContent = 'Ask a question';
  box.appendChild(open);
  var panel = document.createElement('div');
  panel.className = 'ev-chat-panel';
  panel.hidden = true;
  var log = document.createElement('div');
  log.className = 'ev-chat-log';
  log.setAttribute('aria-live', 'polite');
  var form = document.createElement('form');
  var input = document.createElement('input');
  input.type = 'text';
  input.maxLength = 500;
  input.placeholder = 'Ask about events or tickets';
  input.setAttribute('aria-label', 'Your question');
  var send = document.createElement('button');
  send.className = 'btn';
  send.textContent = 'Send';
  form.appendChild(input);
  form.appendChild(send);
  panel.appendChild(log);
  panel.appendChild(form);
  box.appendChild(panel);

  function say(text, who) {
    var p = document.createElement('p');
    p.className = who;
    p.textContent = text;
    log.appendChild(p);
    log.scrollTop = log.scrollHeight;
  }
  open.addEventListener('click', function () {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) { input.focus(); }
  });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var q = input.value.trim();
    if (!q) { return; }
    input.value = '';
    say(q, 'me');
    var body = new URLSearchParams();
    body.set('csrf', box.getAttribute('data-csrf'));
    body.set('q', q);
    fetch(box.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) { say(r.reply || 'Please try again.', 'bot'); })
      .catch(function () { say('The assistant is not available right now.', 'bot'); });
  });
}());
