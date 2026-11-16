/* Event Organizers: choosing attendees, and scanning at the door, online or off. */
(function () {
  'use strict';

  var all = document.getElementById('eo-all');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.eo-pick').forEach(function (b) { b.checked = all.checked; });
    });
  }
  document.querySelectorAll('[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      if (!window.confirm(b.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });

  var door = document.getElementById('eo-door');
  if (!door) { return; }
  var url = door.getAttribute('data-url');
  var csrf = door.getAttribute('data-csrf');
  var action = door.getAttribute('data-action') || '';
  var result = document.getElementById('eo-result');
  var day = document.getElementById('eo-day');
  var counts = JSON.parse((document.getElementById('eo-counts') || {}).textContent || '{}');
  var inNow = document.getElementById('eo-in');
  var inDay = document.getElementById('eo-inday');
  var food = document.getElementById('eo-food');
  var offlineBox = document.getElementById('eo-offline');
  var last = '', lastAt = 0, busy = false;
  var store = 'eo-scans:' + url;

  var words = {
    ok: ['good', 'Checked in'],
    again: ['warn', 'Already checked in'],
    used: ['warn', 'Already used'],
    unpaid: ['warn', 'Payment not confirmed'],
    cancelled: ['risk', 'Cancelled'],
    other_event: ['risk', 'For another event'],
    unknown: ['risk', 'Not valid'],
    queued: ['warn', 'Saved, will sync'],
    error: ['risk', 'Could not check in. Try again.']
  };

  function show(r) {
    var w = words[r.state] || words.error;
    result.className = 'eo-result ' + w[0];
    result.textContent = '';
    var head = document.createElement('strong');
    head.textContent = w[1];
    result.appendChild(head);
    if (r.name) {
      var who = document.createElement('div');
      who.textContent = r.name;
      result.appendChild(who);
    }
    if (r.at) {
      var when = document.createElement('div');
      when.className = 'small';
      when.textContent = 'At ' + r.at;
      result.appendChild(when);
    }
  }

  function tally() {
    if (inNow && inDay) {
      inNow.textContent = Number(counts[day.value] || 0).toLocaleString();
      inDay.textContent = 'on day ' + day.value;
    }
  }

  function post(fields) {
    var body = new URLSearchParams();
    body.set('csrf', csrf);
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { throw new Error('status'); }
      return r.json();
    });
  }

  /* Offline: the roster says who may get in; scans wait here until the network is back. */
  function queue() {
    try { return JSON.parse(localStorage.getItem(store) || '[]'); } catch (e) { return []; }
  }
  function keep(q) {
    try { localStorage.setItem(store, JSON.stringify(q)); } catch (e) { /* a full store loses nothing already sent */ }
    if (offlineBox) { offlineBox.textContent = q.length ? q.length + ' scan' + (q.length === 1 ? '' : 's') + ' waiting to sync' : ''; }
  }
  function roster() {
    try { return JSON.parse(localStorage.getItem('eo-roster:' + url) || '{}'); } catch (e) { return {}; }
  }
  function saveOffline(code) {
    var m = code.match(/[a-f0-9]{24}/i);
    var key = m ? m[0].toLowerCase() : '';
    var names = roster();
    if (!key || !names[key]) { show({ state: 'unknown' }); return; }
    var q = queue();
    if (q.some(function (s) { return s.code === key && s.day === day.value; })) { show({ state: 'again', name: names[key] }); return; }
    q.push({ code: key, day: day.value, at: new Date().toISOString(), id: key + '-' + day.value + '-' + Date.now() });
    keep(q);
    show({ state: 'queued', name: names[key] });
  }
  function sync() {
    var q = queue();
    if (!q.length || !navigator.onLine) { return; }
    post({ action: 'sync', scans: JSON.stringify(q) }).then(function (r) {
      keep([]);
      if (r.counts) { counts = r.counts; tally(); }
    }).catch(function () { /* still offline: they stay queued */ });
  }
  if (offlineBox) {
    post({ action: 'roster' }).then(function (r) {
      try { localStorage.setItem('eo-roster:' + url, JSON.stringify(r.roster || {})); } catch (e) { /* no store, no offline */ }
    }).catch(function () {});
    window.addEventListener('online', sync);
    keep(queue());
    sync();
  }

  function send(code) {
    if (busy) { return; }
    if (offlineBox && !navigator.onLine) { saveOffline(code); return; }
    busy = true;
    var isFood = food && food.checked;
    var fields = { code: code, day: day.value, action: isFood ? 'food' : action };
    var notes = document.getElementById('eo-notes');
    if (notes) { fields.notes = notes.value; }
    post(fields)
      .then(function (r) {
        show(r);
        if (r.counts) { counts = r.counts; tally(); }
      })
      .catch(function () {
        if (offlineBox) { saveOffline(code); } else { show({ state: 'error' }); }
      })
      .then(function () { busy = false; });
  }

  if (day) { day.addEventListener('change', tally); }

  document.getElementById('eo-typed').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var box = document.getElementById('eo-code');
    if (box.value.trim()) { send(box.value.trim()); box.value = ''; }
    box.focus();
  });

  var cam = document.getElementById('eo-cam');
  var scanner = null;
  if (!cam) { return; }
  cam.addEventListener('click', function () {
    if (scanner) {
      scanner.stop().then(function () { scanner = null; cam.textContent = 'Start the camera'; });
      return;
    }
    if (!window.Html5Qrcode) { show({ state: 'error' }); return; }
    // A phone's own barcode reader, where it has one, beats the bundled decoder.
    scanner = new window.Html5Qrcode('eo-reader', { experimentalFeatures: { useBarCodeDetectorIfSupported: true } });
    scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, function (text) {
      // The camera sees the same code many times a second: once every few seconds is one scan.
      var now = Date.now();
      if (text === last && now - lastAt < 4000) { return; }
      last = text;
      lastAt = now;
      send(text);
    }, function () {}).then(function () {
      cam.textContent = 'Stop the camera';
    }).catch(function () {
      scanner = null;
      result.className = 'eo-result risk';
      result.textContent = 'The camera could not start. Allow it for this site, or type the code.';
    });
  });
}());
