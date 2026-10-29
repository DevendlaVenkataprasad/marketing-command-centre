/*
 * Dragging a sheet's columns into the order somebody wants them in.
 *
 * The table on Sheet Management's Columns tab is the list of columns, so the
 * order is arranged by moving its rows rather than in a separate control that
 * would have to be kept in step with it.
 *
 * Nothing is saved until Save order is pressed. Until then the rows have moved
 * and the button is live, so an arrangement can be abandoned by leaving the
 * page — which matters, because a drag is easy to do by accident.
 *
 * The handle is a button, not just a draggable row: rows hold text inputs, and
 * making those draggable takes away selecting and correcting the text in them.
 * The same handle takes arrow keys, so the order can be arranged without a
 * mouse and without a drag anybody has to be able to perform.
 */
(function () {
  'use strict';

  var table = document.getElementById('sbcols');
  var form  = document.getElementById('sborder');
  if (!table || !form) { return; }

  var body  = table.tBodies[0];
  var field = form.querySelector('input[name="order"]');
  var save  = form.querySelector('[data-role="save"]');
  if (!body || !field || !save) { return; }

  /* The order as the page was served, kept here rather than read back from
     the input's defaultValue. A hidden input's value mode is "default", so
     assigning to .value also rewrites the value attribute and defaultValue
     follows it -- which would leave nothing to compare against and a Save
     button that never woke up. */
  var saved = field.value;

  /* The id column is the row's identity and is always shown first, whatever
     is done to the rest, so it does not move and nothing moves above it. */
  function movable(row) {
    return row && row.parentNode === body && row.dataset.pinned !== '1';
  }

  function rows() {
    return Array.prototype.slice.call(body.rows);
  }

  /* Renumber the # column and write the order into the form. Called after
     every move so what is on screen and what would be saved cannot drift. */
  function sync() {
    var order = [];
    rows().forEach(function (row, i) {
      var n = row.querySelector('[data-role="n"]');
      if (n) { n.textContent = String(i + 1); }
      /* The pinned id column is left out: it is always first and is not part
         of the arrangement, so counting it would make the form look changed
         the moment the page loaded. */
      if (row.dataset.field && row.dataset.pinned !== '1') {
        order.push(row.dataset.field);
      }
    });
    field.value = order.join(',');
    var changed = field.value !== saved;
    save.disabled = !changed;
    form.classList.toggle('sb-dirty', changed);
  }

  /* ------------------------------------------------------------- dragging */

  var dragging = null;

  function clearMarks() {
    rows().forEach(function (r) {
      r.classList.remove('sb-over', 'sb-dragging');
    });
  }

  body.addEventListener('dragstart', function (e) {
    var handle = e.target.closest ? e.target.closest('[data-role="grip"]') : null;
    var row    = handle && handle.closest('tr');
    if (!movable(row)) { e.preventDefault(); return; }
    dragging = row;
    row.classList.add('sb-dragging');
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      /* Firefox will not start a drag without data set on it. The row is
         found through `dragging`, so what the payload says does not matter. */
      try { e.dataTransfer.setData('text/plain', row.dataset.field || ''); } catch (err) { }
    }
  });

  body.addEventListener('dragover', function (e) {
    if (!dragging) { return; }
    var row = e.target.closest ? e.target.closest('tr') : null;
    if (!movable(row) || row === dragging) { return; }
    e.preventDefault();                      // says this is somewhere it may drop
    if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
    rows().forEach(function (r) { r.classList.remove('sb-over'); });
    row.classList.add('sb-over');

    /* Dropped on the top half of a row it goes above it, on the bottom half
       below — so a column can be put at either end of the table. */
    var box = row.getBoundingClientRect();
    if (e.clientY < box.top + box.height / 2) {
      body.insertBefore(dragging, row);
    } else {
      body.insertBefore(dragging, row.nextSibling);
    }
  });

  body.addEventListener('drop', function (e) {
    if (!dragging) { return; }
    e.preventDefault();
    clearMarks();
    dragging = null;
    sync();
  });

  body.addEventListener('dragend', function () {
    clearMarks();
    dragging = null;
    sync();
  });

  /* ----------------------------------------------------------- the keyboard */

  body.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') { return; }
    var handle = e.target.closest ? e.target.closest('[data-role="grip"]') : null;
    var row    = handle && handle.closest('tr');
    if (!movable(row)) { return; }
    e.preventDefault();

    if (e.key === 'ArrowUp') {
      var before = row.previousElementSibling;
      if (movable(before)) { body.insertBefore(row, before); }
    } else {
      var after = row.nextElementSibling;
      if (movable(after)) { body.insertBefore(after, row); }
    }
    sync();
    handle.focus();                          // keep the keys on the row moved
  });

  /* Leaving with an arrangement unsaved loses it, which is worth one question
     — but only when something has actually been moved. */
  window.addEventListener('beforeunload', function (e) {
    if (field.value === saved || form.dataset.saving === '1') { return; }
    e.preventDefault();
    e.returnValue = '';
  });
  form.addEventListener('submit', function () { form.dataset.saving = '1'; });

  sync();
})();
