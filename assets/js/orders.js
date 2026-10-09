/* PrintBoss | orders.js : order dialog helpers (prefill from stock, live totals) */
(function () {
  'use strict';
  const form = document.getElementById('orderForm');
  if (!form) return;
  const sym = { AUD: 'A$', USD: '$', EUR: '€', GBP: '£', NZD: 'NZ$', BRL: 'R$' }[form.getAttribute('data-currency')] || '$';

  function totals() {
    const qty = parseFloat(form.elements.order_quantity.value) || 0;
    const cost = parseFloat(form.elements.unit_cost.value) || 0;
    const price = parseFloat(form.elements.unit_selling_price.value) || 0;
    document.getElementById('t_cost').textContent = fmtMoney(cost * qty, sym);
    document.getElementById('t_price').textContent = fmtMoney(price * qty, sym);
    document.getElementById('t_profit').textContent = fmtMoney((price - cost) * qty, sym);
  }
  form.querySelectorAll('[data-total]').forEach(function (i) { i.addEventListener('input', totals); });
  form.addEventListener('pb:filled', totals);
  form.addEventListener('pb:reset', totals);

  // Picking a stock product fills name, cost and price
  document.getElementById('orderStock').addEventListener('change', function () {
    const o = this.selectedOptions[0];
    if (!o || !o.value) return;
    if (!form.elements.product_name.value) form.elements.product_name.value = o.getAttribute('data-name');
    form.elements.unit_cost.value = o.getAttribute('data-cost');
    form.elements.unit_selling_price.value = o.getAttribute('data-price');
    totals();
  });

  // Picking a customer clears the free-text name (server uses the customer record)
  form.elements.customer_id.addEventListener('change', function () {
    if (this.value) form.elements.customer_name.value = this.selectedOptions[0].textContent;
  });
  totals();
})();
