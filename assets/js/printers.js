/* PrintBoss | printers.js : live countdown timers and the start-job dialog */
(function () {
  'use strict';

  // ---------- countdown timers ----------
  const timers = document.querySelectorAll('[data-timer]');
  function pad(n) { return String(n).padStart(2, '0'); }
  function tick() {
    timers.forEach(function (el) {
      if (el.getAttribute('data-paused') === '1') return;
      let rem = parseInt(el.getAttribute('data-remaining'), 10) - 1;
      el.setAttribute('data-remaining', rem);
      const a = Math.abs(rem);
      el.textContent = (rem < 0 ? '-' : '') + pad(Math.floor(a / 3600)) + ':' + pad(Math.floor((a % 3600) / 60)) + ':' + pad(a % 60);
      el.classList.toggle('overdue', rem < 0);
      const total = parseInt(el.getAttribute('data-total'), 10) || 1;
      const bar = el.parentElement.querySelector('[data-progress]');
      if (bar) bar.style.width = Math.max(0, Math.min(100, 100 - rem / total * 100)) + '%';
    });
  }
  if (timers.length) setInterval(tick, 1000);

  // ---------- start-job dialog ----------
  const jobForm = document.getElementById('jobForm');
  if (!jobForm) return;
  const sourceSel = document.getElementById('sourceType');
  function showSource() {
    jobForm.querySelectorAll('[data-source]').forEach(function (el) {
      el.classList.toggle('hidden', el.getAttribute('data-source') !== sourceSel.value);
    });
  }
  sourceSel.addEventListener('change', showSource);
  jobForm.addEventListener('pb:filled', function (ev) {
    const d = ev.detail || {};
    document.getElementById('jobPrinterName').textContent = d.printer_name ? 'on ' + d.printer_name : '';
    sourceSel.value = 'stock';
    showSource();
  });
  // Prefill from a chosen order
  jobForm.elements.order_id.addEventListener('change', function () {
    const o = this.selectedOptions[0];
    if (!o || !o.value) return;
    jobForm.elements.planned_quantity.value = o.getAttribute('data-qty') || 1;
    if (o.getAttribute('data-material')) jobForm.elements.material_used.value = o.getAttribute('data-material');
    const grams = parseFloat(o.getAttribute('data-grams')) || 0, qty = parseInt(o.getAttribute('data-qty'), 10) || 1;
    if (grams) jobForm.elements.estimated_material_quantity.value = Math.round(grams * qty);
    const mins = parseInt(o.getAttribute('data-minutes'), 10) || 0;
    if (mins) jobForm.elements.estimated_duration_minutes.value = mins * qty;
  });
  showSource();

  // Confirm dialog: show job title and planned quantity
  const confirmDlg = document.getElementById('confirmDialog');
  if (confirmDlg) confirmDlg.addEventListener('pb:filled', function (ev) {
    const d = ev.detail || {};
    confirmDlg.querySelectorAll('[data-show]').forEach(function (el) { el.textContent = d[el.getAttribute('data-show')] || ''; });
    confirmDlg.querySelector('[name=successful_quantity]').max = d.planned_quantity;
  });
})();
