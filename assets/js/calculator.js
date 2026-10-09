/* PrintBoss | calculator.js : live cost calculation (mirrors includes/functions.php calculate_cost) */
(function () {
  'use strict';
  const form = document.getElementById('calcForm');
  if (!form) return;
  const sym = { AUD: 'A$', USD: '$', EUR: '€', GBP: '£', NZD: 'NZ$', BRL: 'R$' }[document.querySelector('[data-currency]').getAttribute('data-currency')] || '$';
  const n = function (id) { return parseFloat(form.elements[id].value) || 0; };
  const set = function (id, v) { document.getElementById(id).textContent = v; };

  function calculate() {
    const material = (n('grams_used') / 1000) * n('filament_cost_per_kg');
    const electricity = (n('printer_power_watts') / 1000) * n('print_time_hours') * n('electricity_cost_per_kwh');
    const depreciation = n('print_time_hours') * n('depreciation_per_hour');
    const labour = n('labour_time_hours') * n('labour_hourly_rate');
    const packaging = n('packaging_cost');
    const base = material + electricity + depreciation + labour + packaging;
    const total = base * (1 + n('failed_risk_percent') / 100);
    const margin = Math.min(Math.max(n('profit_margin_percent'), 0), 95) / 100;
    const price = margin >= 1 ? total : total / (1 - margin);
    const profit = price - total;
    set('r_material', fmtMoney(material, sym));
    set('r_electricity', fmtMoney(electricity, sym));
    set('r_depreciation', fmtMoney(depreciation, sym));
    set('r_labour', fmtMoney(labour, sym));
    set('r_packaging', fmtMoney(packaging, sym));
    set('r_buffer', fmtMoney(total - base, sym));
    set('r_total', fmtMoney(total, sym));
    set('r_price', fmtMoney(price, sym));
    set('r_profit', fmtMoney(profit, sym));
    set('r_margin', (price > 0 ? (profit / price) * 100 : 0).toFixed(1) + '%');
  }
  form.querySelectorAll('[data-calc]').forEach(function (i) { i.addEventListener('input', calculate); });

  const filamentPick = document.getElementById('filamentPick');
  if (filamentPick) filamentPick.addEventListener('change', function () {
    const opt = filamentPick.selectedOptions[0];
    if (!opt || !opt.value) return;
    form.elements.filament_cost_per_kg.value = opt.value;
    form.elements.filament_type.value = opt.getAttribute('data-material');
    calculate();
  });
  const printerPick = document.getElementById('printerPick');
  if (printerPick) printerPick.addEventListener('change', function () {
    const opt = printerPick.selectedOptions[0];
    if (!opt || !opt.value) return;
    form.elements.printer_power_watts.value = opt.getAttribute('data-watts');
    form.elements.depreciation_per_hour.value = opt.getAttribute('data-dep');
    calculate();
  });
  calculate();
})();
