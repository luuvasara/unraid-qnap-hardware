/* qnaphw-charts.js - History tab: one graph per enclosure, a line per bay, from include/history.php (POST, read-only).
 * Below each graph the same numbers as a table (last, min, max per bay), so nothing is only in the picture. Colours come from
 * the page (currentColor) and a fixed palette checked against Unraid's light and dark backgrounds; text uses the theme's colour. */
(function () {
  'use strict';
  var root = document.getElementById('qhw-history');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  var url = root.dataset.endpoint, box = root.querySelector('[data-qhw="charts"]'), msg = root.querySelector('[data-qhw="msg"]');
  var PALETTE = ['#1f77b4', '#d62728', '#2ca02c', '#9467bd', '#e377c2', '#17becf', '#bcbd22', '#ff7f0e', '#8c564b', '#7f7f7f', '#0b6e4f', '#c2185b', '#3949ab', '#6d4c41', '#00897b', '#5e35b1'];
  var plots = [];
  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x !== undefined) e.textContent = x; return e; }
  function say(t) { msg.textContent = t; }
  function range() { var r = root.querySelector('input[name="qhw-range"]:checked'); return r ? r.value : '86400'; }
  function table(u) {
    var t = el('table', 'unraid qhw-t qhw-hist-t'), cap = el('caption', 'qhw-cap', u.name + ': temperatures in this range (°C)');
    t.appendChild(cap);
    var th = el('thead'), tr = el('tr');
    ['Bay (drive)', 'Last', 'Min', 'Max'].forEach(function (h) { var c = el('th', null, h); c.scope = 'col'; tr.appendChild(c); });
    th.appendChild(tr); t.appendChild(th);
    var tb = el('tbody');
    u.series.forEach(function (s) {
      var vals = s.v.filter(function (x) { return typeof x === 'number'; }), last = null;
      for (var i = s.v.length - 1; i >= 0; i--) { if (typeof s.v[i] === 'number') { last = s.v[i]; break; } if (s.standby[i]) { last = 'standby'; break; } }
      var r = el('tr'), h = el('th', null, s.label); h.scope = 'row'; r.appendChild(h);
      [last === null ? '-' : last, vals.length ? Math.min.apply(null, vals) : '-', vals.length ? Math.max.apply(null, vals) : '-'].forEach(function (x) { r.appendChild(el('td', null, String(x))); });
      tb.appendChild(r);
    });
    t.appendChild(tb);
    return t;
  }
  function draw(j) {
    plots.forEach(function (p) { p.destroy(); }); plots = [];
    box.textContent = '';
    if (!j.units.length || !j.units.some(function (u) { return u.t.length; })) { say('No samples in this range yet. The first one is taken within a minute after the install or a boot (Overview, Temperature history must be Yes).'); return; }
    say('');
    j.units.forEach(function (u) {
      var sec = el('section', 'qhw-hist'), h = el('h3', 'qhw-cap', u.name + ': drive temperatures (°C), ' + u.t.length + ' samples');
      h.id = 'qhw-hist-h-' + u.unit; sec.appendChild(h);
      var holder = el('div', 'qhw-chart'); holder.setAttribute('role', 'img'); holder.setAttribute('aria-labelledby', h.id);
      holder.setAttribute('aria-describedby', 'qhw-hist-t-' + u.unit);
      sec.appendChild(holder);
      var t = table(u); t.id = 'qhw-hist-t-' + u.unit;
      box.appendChild(sec);
      var fg = getComputedStyle(root).color;
      var opts = {
        width: Math.max(320, Math.min(holder.clientWidth || 900, 1200)), height: 300,
        scales: { x: { time: true } },
        axes: [{ stroke: fg, grid: { stroke: 'rgba(128,128,128,.25)' } }, { stroke: fg, grid: { stroke: 'rgba(128,128,128,.25)' }, values: function (s, v) { return v.map(function (x) { return x + ' °C'; }); } }],
        series: [{}].concat(u.series.map(function (s, i) { return { label: s.label, stroke: PALETTE[i % PALETTE.length], width: 2, spanGaps: false, value: function (x, v) { return v == null ? '-' : v + ' °C'; } }; })),
        legend: { live: true }
      };
      plots.push(new window.uPlot(opts, [u.t].concat(u.series.map(function (s) { return s.v; })), holder));
      sec.appendChild(t);
    });
  }
  function load() {
    say('Loading...');
    var b = new URLSearchParams({ action: 'history', range: range() });
    if (typeof csrf_token !== 'undefined') b.append('csrf_token', csrf_token);
    fetch(url, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'unexpected reply'); if (!window.uPlot) throw new Error('the graph library did not load'); draw(j); })
      .catch(function (e) { say('The history could not be loaded: ' + e.message + '.'); });
  }
  root.querySelectorAll('input[name="qhw-range"]').forEach(function (r) { r.addEventListener('change', load); });
  load();
  setInterval(function () { if (!document.hidden && root.offsetParent !== null) load(); }, 60000);
}());
