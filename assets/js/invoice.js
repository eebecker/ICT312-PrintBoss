/* PrintBoss | invoice.js : dynamic line items and totals (mirrors invoice_totals() in PHP) */
(function () {
  'use strict';
  const form = document.getElementById('invoiceForm');
  if (!form) return;
  const sym = { AUD: 'A$', USD: '$', EUR: '€', GBP: '£', NZD: 'NZ$', BRL: 'R$' }[form.getAttribute('data-currency')] || '$';
  const parsedGstRate = parseFloat(form.getAttribute('data-gst'));
  const gstRate = Number.isFinite(parsedGstRate) ? parsedGstRate : 10;
  const tbody = document.querySelector('#lineTable tbody');
  const round2 = function (n) { return Math.round(n * 100) / 100; };

  function recalc() {
    let subtotal = 0;
    tbody.querySelectorAll('tr.line').forEach(function (tr) {
      const qty = parseFloat(tr.querySelector('[name="item_qty[]"]').value) || 0;
      const price = parseFloat(tr.querySelector('[name="item_price[]"]').value) || 0;
      const t = round2(qty * price);
      tr.querySelector('[data-line-total]').textContent = fmtMoney(t, sym);
      subtotal += t;
    });
    const discount = Math.max(0, parseFloat(form.elements.discount.value) || 0);
    const after = Math.max(0, subtotal - discount);
    const gst = form.elements.gst_enabled.checked ? round2(after * gstRate / 100) : 0;
    const total = round2(after + gst);
    const paid = Math.max(0, parseFloat(form.elements.amount_paid.value) || 0);
    document.getElementById('s_subtotal').textContent = fmtMoney(subtotal, sym);
    document.getElementById('s_discount').textContent = '-' + fmtMoney(discount, sym);
    document.getElementById('s_gst').textContent = fmtMoney(gst, sym);
    document.getElementById('s_total').textContent = fmtMoney(total, sym);
    document.getElementById('s_balance').textContent = fmtMoney(round2(total - paid), sym);
  }

  function bindRow(tr) {
    tr.querySelectorAll('[data-line]').forEach(function (i) { i.addEventListener('input', recalc); });
    tr.querySelector('[data-remove-line]').addEventListener('click', function () {
      if (tbody.querySelectorAll('tr.line').length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = i.type === 'number' ? (i.name === 'item_qty[]' ? 1 : 0) : ''; });
      recalc();
    });
    // Picking a stock product from the datalist fills the price
    const nameInput = tr.querySelector('[data-line-name]');
    nameInput.addEventListener('change', function () {
      const opt = Array.prototype.find.call(document.querySelectorAll('#stockList option'), function (o) { return o.value === nameInput.value; });
      if (opt) { tr.querySelector('[name="item_price[]"]').value = opt.getAttribute('data-price'); recalc(); }
    });
  }

  document.getElementById('addLine').addEventListener('click', function () {
    const tr = tbody.querySelector('tr.line').cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_qty[]' ? 1 : (i.type === 'number' ? 0 : ''); });
    tr.querySelector('[data-line-total]').textContent = fmtMoney(0, sym);
    tbody.appendChild(tr);
    bindRow(tr);
    tr.querySelector('input').focus();
  });
  tbody.querySelectorAll('tr.line').forEach(bindRow);
  form.querySelectorAll('.card-highlight [data-line]').forEach(function (i) { i.addEventListener('input', recalc); i.addEventListener('change', recalc); });

  const pick = document.getElementById('customerPick');
  pick.addEventListener('change', function () {
    const o = pick.selectedOptions[0];
    if (!o || !o.value) return;
    const d = JSON.parse(o.getAttribute('data-json'));
    form.elements.customer_name.value = d.name || '';
    form.elements.customer_email.value = d.email || '';
    form.elements.customer_phone.value = d.phone || '';
    form.elements.customer_address.value = d.address || '';
  });
  recalc();
})();
