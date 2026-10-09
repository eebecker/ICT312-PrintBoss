/* PrintBoss | dashboard.js : canvas bar charts without any library */
(function () {
  'use strict';
  const rev = document.getElementById('revenueChart');
  const ord = document.getElementById('ordersChart');
  if (!rev) return;
  const series = JSON.parse(rev.getAttribute('data-series') || '[]');
  const sym = { AUD: 'A$', USD: '$', EUR: '€', GBP: '£', NZD: 'NZ$', BRL: 'R$' }[rev.getAttribute('data-currency')] || '$';

  function css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }

  function drawBars(canvas, groups, colors, fmt) {
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth, h = canvas.clientHeight;
    canvas.width = w * dpr; canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);
    const padL = 48, padR = 8, padT = 12, padB = 26;
    const plotW = w - padL - padR, plotH = h - padT - padB;
    let max = 0;
    groups.forEach(function (g) { g.values.forEach(function (v) { if (v > max) max = v; }); });
    if (max === 0) max = 1;
    const nice = niceMax(max);
    ctx.font = '11px ' + css('--font');
    ctx.textBaseline = 'middle';
    // grid lines
    for (let i = 0; i <= 4; i++) {
      const y = padT + plotH - (plotH * i / 4);
      ctx.strokeStyle = css('--border'); ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
      ctx.fillStyle = css('--muted'); ctx.textAlign = 'right';
      ctx.fillText(fmt(nice * i / 4), padL - 6, y);
    }
    const groupW = plotW / groups.length;
    const barW = Math.min(26, (groupW * 0.7) / colors.length);
    groups.forEach(function (g, gi) {
      const x0 = padL + groupW * gi + (groupW - barW * colors.length) / 2;
      g.values.forEach(function (v, vi) {
        const bh = plotH * (v / nice);
        const x = x0 + barW * vi, y = padT + plotH - bh;
        ctx.fillStyle = colors[vi];
        roundRect(ctx, x, y, barW - 3, bh, 4);
      });
      ctx.fillStyle = css('--muted'); ctx.textAlign = 'center';
      ctx.fillText(g.label, padL + groupW * gi + groupW / 2, h - padB / 2);
    });
  }
  function roundRect(ctx, x, y, w, h, r) {
    if (h <= 0) return;
    r = Math.min(r, h / 2, w / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.lineTo(x + w - r, y); ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h); ctx.lineTo(x, y + h); ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath(); ctx.fill();
  }
  function niceMax(v) {
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / p;
    const m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
    return m * p;
  }
  function render() {
    drawBars(rev, series.map(function (s) { return { label: s.month, values: [s.revenue, s.profit] }; }),
      [css('--primary'), css('--success')], function (v) { return sym + Math.round(v); });
    if (ord) drawBars(ord, series.map(function (s) { return { label: s.month, values: [s.orders] }; }),
      [css('--info')], function (v) { return String(Math.round(v)); });
  }
  render();
  window.addEventListener('resize', render);
})();
