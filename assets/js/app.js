/* ============================================================
   PrintBoss  |  app.js  (vanilla JavaScript, shared by all pages)
   - mobile sidebar
   - <dialog> open/close helpers
   - edit dialogs prefilled from data-json attributes
   - delete confirmation
   - client-side table search and filters
   - toast auto-hide
   ============================================================ */
(function () {
  'use strict';

  // ---------- sidebar (mobile) ----------
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const menuBtn = document.getElementById('menuBtn');
  function closeSidebar() { sidebar && sidebar.classList.remove('open'); backdrop && backdrop.classList.remove('show'); }
  if (menuBtn) {
    menuBtn.addEventListener('click', function () { sidebar.classList.toggle('open'); backdrop.classList.toggle('show'); });
    backdrop.addEventListener('click', closeSidebar);
  }

  // ---------- toast ----------
  const toast = document.getElementById('toast');
  if (toast) { setTimeout(function () { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 400); }, 4500); }

  // ---------- dialogs ----------
  window.openDialog = function (id) {
    const d = document.getElementById(id);
    if (!d) return;
    if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
    const first = d.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
    if (first) setTimeout(function () { first.focus(); }, 50);
  };
  window.closeDialog = function (id) {
    const d = typeof id === 'string' ? document.getElementById(id) : id;
    if (d) d.close ? d.close() : d.removeAttribute('open');
  };
  document.querySelectorAll('[data-open]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      ev.preventDefault();
      const id = el.getAttribute('data-open');
      const dlg = document.getElementById(id);
      if (!dlg) return;
      // Reset to "create" mode unless the trigger carries data
      const form = dlg.querySelector('form');
      if (form && !el.hasAttribute('data-json')) {
        form.reset();
        const idInput = form.querySelector('input[name=id]');
        if (idInput) idInput.value = '';
        const title = dlg.querySelector('[data-title-create]');
        if (title) title.textContent = title.getAttribute('data-title-create');
        form.querySelectorAll('[data-default]').forEach(function (f) { f.value = f.getAttribute('data-default'); });
        form.dispatchEvent(new Event('pb:reset', { bubbles: true }));
      }
      if (el.hasAttribute('data-json')) {
        fillForm(dlg, JSON.parse(el.getAttribute('data-json')));
        const title = dlg.querySelector('[data-title-edit]');
        if (title) title.textContent = title.getAttribute('data-title-edit');
      }
      openDialog(id);
    });
  });
  document.querySelectorAll('[data-close]').forEach(function (el) {
    el.addEventListener('click', function () { closeDialog(el.closest('dialog')); });
  });
  document.querySelectorAll('dialog').forEach(function (d) {
    d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });
  });

  function fillForm(dlg, data) {
    const form = dlg.querySelector('form');
    if (!form) return;
    form.reset();
    form.querySelectorAll('[data-default]').forEach(function (f) { f.value = f.getAttribute('data-default'); });
    Object.keys(data).forEach(function (k) {
      const field = form.elements[k];
      if (!field) return;
      const v = data[k] === null || data[k] === undefined ? '' : data[k];
      if (field.type === 'checkbox') field.checked = !!Number(v) || v === true;
      else if (field.type === 'datetime-local' && v) field.value = String(v).replace(' ', 'T').slice(0, 16);
      else field.value = v;
    });
    form.dispatchEvent(new CustomEvent('pb:filled', { bubbles: true, detail: data }));
  }
  window.fillForm = fillForm;

  // ---------- confirm before destructive submit ----------
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // ---------- search / filter ----------
  function applyFilters(table) {
    const rows = table.querySelectorAll('tbody tr[data-search]');
    const q = (table._search || '').toLowerCase();
    const filters = table._filters || {};
    let visible = 0;
    rows.forEach(function (tr) {
      let ok = !q || tr.getAttribute('data-search').toLowerCase().indexOf(q) !== -1;
      Object.keys(filters).forEach(function (attr) {
        const want = filters[attr];
        if (ok && want && tr.getAttribute('data-' + attr) !== want) ok = false;
      });
      tr.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    const empty = table.parentElement.querySelector('[data-empty-filter]');
    if (empty) empty.style.display = visible === 0 && rows.length > 0 ? '' : 'none';
  }
  document.querySelectorAll('[data-search-table]').forEach(function (input) {
    const table = document.getElementById(input.getAttribute('data-search-table'));
    if (!table) return;
    input.addEventListener('input', function () { table._search = input.value; applyFilters(table); });
  });
  document.querySelectorAll('[data-filter-table]').forEach(function (sel) {
    const table = document.getElementById(sel.getAttribute('data-filter-table'));
    if (!table) return;
    sel.addEventListener('change', function () {
      table._filters = table._filters || {};
      table._filters[sel.getAttribute('data-filter-attr')] = sel.value;
      applyFilters(table);
    });
  });

  // ---------- money formatting helper ----------
  window.fmtMoney = function (n, sym) {
    n = Number(n) || 0;
    return (sym || '$') + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
})();
