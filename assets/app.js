/*!
 * Net Traffic — biểu đồ SVG & tương tác cho trang thống kê MikroTik.
 * Không phụ thuộc thư viện ngoài. Màu lấy từ biến CSS nên đổi giao diện
 * sáng/tối không cần vẽ lại.
 */
(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var doc = document;
  var root = doc.documentElement;
  var body = doc.body;
  var tzOffset = +body.getAttribute('data-tz-offset') || 25200;
  var skew = (+body.getAttribute('data-server-now') || Date.now() / 1000) - Date.now() / 1000;
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var unitBase = 1024;
  var uid = 0;

  function nowSec() { return Date.now() / 1000 + skew; }
  function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
  function on(el, type, fn, opt) { if (el) el.addEventListener(type, fn, opt || false); }

  /* ------------------------------------------------------------------
   * Định dạng số & thời gian (kiểu Việt Nam, giờ GMT+7)
   * ------------------------------------------------------------------ */

  var nfCache = {};
  function fmtNum(v, dec) {
    dec = dec || 0;
    if (!nfCache[dec]) {
      try {
        nfCache[dec] = new Intl.NumberFormat('vi-VN', { minimumFractionDigits: dec, maximumFractionDigits: dec });
      } catch (e) {
        nfCache[dec] = { format: function (x) { return x.toFixed(dec).replace('.', ','); } };
      }
    }
    return nfCache[dec].format(v);
  }
  function autoDec(v) { v = Math.abs(v); return v < 10 ? 2 : (v < 100 ? 1 : 0); }

  var BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  var RATE_UNITS = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];

  function unitIndex(v, base, len) {
    var i = 0;
    v = Math.abs(v);
    while (v >= base && i < len - 1) { v /= base; i++; }
    return i;
  }
  function partsOf(v, base, units) {
    var i = unitIndex(v, base, units.length);
    var x = v / Math.pow(base, i);
    return [fmtNum(x, i === 0 ? 0 : autoDec(x)), units[i], i];
  }
  function bytesParts(b) { return partsOf(b, unitBase, BYTE_UNITS); }
  function fmtBytes(b) { if (b == null) return '—'; var p = bytesParts(b); return p[0] + ' ' + p[1]; }
  function fmtRate(r) { if (r == null) return '—'; var p = partsOf(r, 1000, RATE_UNITS); return p[0] + ' ' + p[1]; }

  var WD = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
  var WDS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  /** Date theo giờ địa phương của server — đọc bằng getUTC*(). */
  function lt(ts) { return new Date((ts + tzOffset) * 1000); }
  function hm(ts) { var d = lt(ts); return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); }
  function hms(ts) { var d = lt(ts); return hm(ts) + ':' + pad(d.getUTCSeconds()); }
  function dm(ts) { var d = lt(ts); return pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1); }
  function dmy(ts) { return dm(ts) + '/' + lt(ts).getUTCFullYear(); }
  function wday(ts) { return lt(ts).getUTCDay(); }
  function ymdTs(s) { var p = String(s).split('-'); return Date.UTC(+p[0], (+p[1] || 1) - 1, +p[2] || 1) / 1000 - tzOffset; }
  function tsYmd(ts) { var d = lt(ts); return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }

  function relTime(ts) {
    var diff = Math.max(0, Math.round(nowSec() - ts));
    if (diff < 5) return 'vừa xong';
    if (diff < 60) return diff + ' giây trước';
    if (diff < 3600) return Math.floor(diff / 60) + ' phút trước';
    if (diff < 86400) return Math.floor(diff / 3600) + ' giờ trước';
    return Math.floor(diff / 86400) + ' ngày trước';
  }
  function durText(sec) {
    if (sec < 60) return sec + ' giây';
    if (sec < 3600) return Math.round(sec / 60) + ' phút';
    if (sec < 86400) return Math.round(sec / 3600) + ' giờ';
    return Math.round(sec / 86400) + ' ngày';
  }

  /* ------------------------------------------------------------------
   * Thang đo trục Y: số tròn, đơn vị tự chọn
   * ------------------------------------------------------------------ */

  function decimalsFor(step) { return step >= 1 ? 0 : Math.min(3, Math.ceil(-Math.log(step) / Math.LN10 - 1e-9)); }

  function axisScale(max, kind) {
    var base = kind === 'rate' ? 1000 : unitBase;
    var units = kind === 'rate' ? RATE_UNITS : BYTE_UNITS;
    if (!(max > 0)) max = kind === 'rate' ? 1e6 : base * base;
    var i = unitIndex(max, base, units.length);
    var div = Math.pow(base, i);
    var m = max / div;
    var p = Math.pow(10, Math.floor(Math.log(m / 4) / Math.LN10));
    var best = null;
    [p / 2, p, p * 2, p * 2.5, p * 5, p * 10, p * 20].forEach(function (s) {
      var top = Math.ceil(m / s - 1e-9) * s;
      var n = Math.round(top / s);
      if (n < 3 || n > 6) return;
      if (!best || top < best.top - 1e-9 || (Math.abs(top - best.top) < 1e-9 && n < best.n)) best = { step: s, top: top, n: n };
    });
    if (!best) best = { step: p * 10, top: Math.ceil(m / (p * 10)) * p * 10, n: Math.ceil(m / (p * 10)) };
    var dec = decimalsFor(best.step);
    var ticks = [];
    for (var k = 0; k <= best.n; k++) {
      var t = k * best.step;
      ticks.push({ v: t * div, label: k === 0 ? '0' : fmtNum(t, dec) + ' ' + units[i] });
    }
    return { max: best.top * div, ticks: ticks };
  }

  /* ------------------------------------------------------------------
   * Tiện ích SVG
   * ------------------------------------------------------------------ */

  function S(tag, attrs, parent) {
    var el = doc.createElementNS(NS, tag);
    if (attrs) {
      for (var k in attrs) {
        if (Object.prototype.hasOwnProperty.call(attrs, k) && attrs[k] != null) el.setAttribute(k, attrs[k]);
      }
    }
    if (parent) parent.appendChild(el);
    return el;
  }
  function svgText(parent, x, y, str, cls, anchor) {
    var t = S('text', { x: x, y: y, 'class': cls || 'c-tick', 'text-anchor': anchor || 'start' }, parent);
    t.textContent = str;
    return t;
  }
  var measureCtx = null;
  var fontFamily = null;
  function textW(str, size, weight) {
    if (!measureCtx) {
      measureCtx = doc.createElement('canvas').getContext('2d');
      fontFamily = getComputedStyle(body).fontFamily;
    }
    measureCtx.font = (weight || 400) + ' ' + (size || 11) + 'px ' + fontFamily;
    return measureCtx.measureText(String(str)).width;
  }
  function maxTextW(list, size, weight) {
    var w = 0;
    list.forEach(function (s) { w = Math.max(w, textW(s, size, weight)); });
    return w;
  }
  function f1(n) { return Math.round(n * 10) / 10; }
  /** Hình chữ nhật bo 4px ở đầu dữ liệu (trên), vuông ở đường đáy. */
  function topRoundRect(x, y, w, h, r) {
    r = Math.max(0, Math.min(r, w / 2, h));
    return 'M' + f1(x) + ',' + f1(y + h) + 'V' + f1(y + r)
      + 'A' + r + ',' + r + ' 0 0 1 ' + f1(x + r) + ',' + f1(y)
      + 'H' + f1(x + w - r)
      + 'A' + r + ',' + r + ' 0 0 1 ' + f1(x + w) + ',' + f1(y + r)
      + 'V' + f1(y + h) + 'Z';
  }

  /* ------------------------------------------------------------------
   * Tooltip (dùng textContent — không chèn HTML)
   * ------------------------------------------------------------------ */

  var tipEl = doc.getElementById('tooltip');
  var tipOwner = null;

  function tipBuild(title, rows, foot) {
    var frag = doc.createDocumentFragment();
    var t = doc.createElement('div');
    t.className = 'tt-title';
    t.textContent = title;
    frag.appendChild(t);
    (rows || []).forEach(function (r) {
      var row = doc.createElement('div');
      row.className = 'tt-row' + (r.total ? ' total' : '');
      var k = doc.createElement('i');
      k.className = 'lk' + (r.key ? ' ' + r.key : '');
      row.appendChild(k);
      var v = doc.createElement('span');
      v.className = 'tt-val';
      v.textContent = r.value;
      row.appendChild(v);
      var n = doc.createElement('span');
      n.className = 'tt-name';
      n.textContent = r.name;
      row.appendChild(n);
      frag.appendChild(row);
    });
    if (foot) {
      var f = doc.createElement('div');
      f.className = 'tt-foot';
      f.textContent = foot;
      frag.appendChild(f);
    }
    return frag;
  }
  function tipShow(owner, content, x, y) {
    if (!tipEl) return;
    tipOwner = owner;
    tipEl.textContent = '';
    tipEl.appendChild(content);
    tipEl.hidden = false;
    var w = tipEl.offsetWidth, h = tipEl.offsetHeight;
    var vw = window.innerWidth, vh = window.innerHeight;
    var left = x + 16, top = y + 16;
    if (left + w > vw - 8) left = x - w - 16;
    if (left < 8) left = 8;
    if (top + h > vh - 8) top = y - h - 16;
    if (top < 8) top = 8;
    tipEl.style.transform = 'translate(' + Math.round(left) + 'px,' + Math.round(top) + 'px)';
  }
  function tipHide(owner) {
    if (!tipEl || (owner && tipOwner !== owner)) return;
    tipEl.hidden = true;
    tipOwner = null;
  }
  on(doc, 'pointerdown', function (e) {
    if (tipOwner && !(e.target.closest && e.target.closest('.chart, [data-tip]'))) tipHide();
  });
  on(window, 'scroll', function () { tipHide(); }, { passive: true });

  function seriesRows(dl, ul, kind, withTotal) {
    var f = kind === 'rate' ? fmtRate : fmtBytes;
    var rows = [
      { key: 'dl', value: f(dl), name: 'Download' },
      { key: 'ul', value: f(ul), name: 'Upload' }
    ];
    if (withTotal) rows.push({ value: f(dl + ul), name: 'Tổng', total: true });
    return rows;
  }

  /**
   * Gắn tương tác chung: rê chuột/chạm chọn phần tử gần nhất, phím mũi tên
   * duyệt từng giá trị khi biểu đồ được focus.
   */
  function bindNav(el, svg, hitEl, opt) {
    var cur = -1;
    function pick(clientX, clientY) {
      var r = svg.getBoundingClientRect();
      var scale = r.width ? opt.width / r.width : 1;
      return opt.indexAt((clientX - r.left) * scale, (clientY - r.top) * scale);
    }
    function show(i, cx, cy) {
      if (i == null || i < 0) { hide(); return; }
      cur = i;
      opt.highlight(i);
      if (cx == null) {
        var r = svg.getBoundingClientRect();
        var pt = opt.anchor(i);
        var scale = r.width ? r.width / opt.width : 1;
        cx = r.left + pt[0] * scale;
        cy = r.top + pt[1] * scale;
      }
      tipShow(el, opt.tooltip(i), cx, cy);
    }
    function hide() { cur = -1; opt.highlight(-1); tipHide(el); }
    on(hitEl, 'pointermove', function (e) { show(pick(e.clientX, e.clientY), e.clientX, e.clientY); });
    on(hitEl, 'pointerdown', function (e) { show(pick(e.clientX, e.clientY), e.clientX, e.clientY); });
    on(hitEl, 'pointerleave', function (e) { if (e.pointerType === 'mouse') hide(); });
    el.onkeydown = function (e) {
      var n = opt.count;
      if (!n) return;
      var k = e.key;
      if (k === 'ArrowRight' || k === 'ArrowDown') cur = cur < 0 ? 0 : Math.min(n - 1, cur + 1);
      else if (k === 'ArrowLeft' || k === 'ArrowUp') cur = cur < 0 ? n - 1 : Math.max(0, cur - 1);
      else if (k === 'Home') cur = 0;
      else if (k === 'End') cur = n - 1;
      else if (k === 'Escape') { hide(); return; }
      else return;
      e.preventDefault();
      show(cur);
    };
    el.onblur = hide;
  }

  /* ------------------------------------------------------------------
   * Quản lý biểu đồ (vẽ lại khi đổi kích thước, chế độ bảng)
   * ------------------------------------------------------------------ */

  var charts = {};
  var ro = window.ResizeObserver ? new ResizeObserver(function (entries) {
    entries.forEach(function (en) {
      var c = charts[en.target.id];
      if (c && (Math.abs(c.el.clientWidth - c.lastW) > 1 || (c.fill && Math.abs(c.el.clientHeight - c.lastH) > 1))) schedule(c);
    });
  }) : null;

  function schedule(c) {
    if (c.raf) return;
    c.raf = requestAnimationFrame(function () { c.raf = 0; draw(c, false); });
  }

  function mount(id, render, cfg, table, aria) {
    var el = doc.getElementById(id);
    if (!el) return;
    var c = charts[id];
    if (!c) {
      c = charts[id] = { el: el, first: true, fill: el.hasAttribute('data-fill') };
      el.tabIndex = 0;
      el.setAttribute('role', 'group');
      if (ro) ro.observe(el);
    }
    if (aria) el.setAttribute('aria-label', aria + ' — dùng phím mũi tên để xem từng giá trị, hoặc bấm nút Bảng.');
    c.render = render;
    c.cfg = cfg;
    c.table = table;
    draw(c, c.first && !reduceMotion);
    c.first = false;
  }

  function draw(c, animate) {
    var el = c.el;
    var w = el.clientWidth;
    if (!w) return;
    c.lastW = w;
    c.lastH = el.clientHeight;
    var tbl = $('.chart-table', el);
    Array.prototype.slice.call(el.childNodes).forEach(function (n) { if (n !== tbl) el.removeChild(n); });
    tipHide(el);
    c.render(el, c.cfg, w, animate);
    if (tbl) {
      el.appendChild(tbl);
      if (el.classList.contains('is-table')) fillTable(c);
    }
  }

  function emptyState(el, msg) {
    var d = doc.createElement('div');
    d.className = 'chart-empty';
    d.textContent = msg;
    el.appendChild(d);
  }

  function fillTable(c) {
    var el = c.el;
    var box = $('.chart-table', el);
    if (!box) {
      box = doc.createElement('div');
      box.className = 'chart-table';
      el.appendChild(box);
    }
    box.textContent = '';
    if (!c.table) return;
    var data = c.table(c.cfg);
    var table = doc.createElement('table');
    table.className = 'data-table';
    var thead = doc.createElement('thead');
    var tr = doc.createElement('tr');
    data.head.forEach(function (h, i) {
      var th = doc.createElement('th');
      th.scope = 'col';
      th.textContent = h;
      if (data.num[i]) th.className = 'n';
      tr.appendChild(th);
    });
    thead.appendChild(tr);
    table.appendChild(thead);
    var tbody = doc.createElement('tbody');
    if (!data.rows.length) {
      var etr = doc.createElement('tr');
      var etd = doc.createElement('td');
      etd.colSpan = data.head.length;
      etd.className = 'empty';
      etd.textContent = 'Không có dữ liệu.';
      etr.appendChild(etd);
      tbody.appendChild(etr);
    }
    data.rows.forEach(function (row) {
      var r = doc.createElement('tr');
      row.forEach(function (v, i) {
        var td = doc.createElement('td');
        td.textContent = v;
        if (data.num[i]) td.className = 'n';
        r.appendChild(td);
      });
      tbody.appendChild(r);
    });
    table.appendChild(tbody);
    box.appendChild(table);
  }

  on(doc, 'click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-table-toggle]') : null;
    if (!btn) return;
    var c = charts[btn.getAttribute('data-table-toggle')];
    if (!c) return;
    var showTable = !c.el.classList.contains('is-table');
    c.el.classList.toggle('is-table', showTable);
    btn.setAttribute('aria-pressed', showTable ? 'true' : 'false');
    var label = btn.lastChild;
    if (label && label.nodeType === 3) label.nodeValue = showTable ? 'Biểu đồ' : 'Bảng';
    if (showTable) fillTable(c);
    tipHide();
  });

  /* ------------------------------------------------------------------
   * Biểu đồ vùng: tốc độ theo thời gian
   * cfg: { from, to, bucket, points: [[ts, dlBps|null, ulBps|null], ...] }
   * ------------------------------------------------------------------ */

  function renderArea(el, cfg, W, animate) {
    var H = chartHeight(el, 260);
    var pts = (cfg.points || []).filter(function (p) { return p[0] >= cfg.from && p[0] <= cfg.to; });
    var real = pts.filter(function (p) { return p[1] != null; });
    if (!real.length) { emptyState(el, 'Chưa có dữ liệu tốc độ trong khoảng thời gian này.'); return; }

    var max = 0;
    real.forEach(function (p) { max = Math.max(max, p[1], p[2]); });
    var compact = !!cfg.compact;
    var sc = axisScale(max * 1.04, 'rate');
    if (compact) sc = { max: max > 0 ? max * 1.12 : 1e6, ticks: [{ v: 0, label: '0' }] };
    var m = compact ? { t: 16, r: 6, b: 18, l: 4 }
      : { t: 12, r: 16, b: 30, l: Math.ceil(maxTextW(sc.ticks.map(function (t) { return t.label; }))) + 14 };
    var iw = Math.max(20, W - m.l - m.r);
    var ih = H - m.t - m.b;
    var span = Math.max(1, cfg.to - cfg.from);
    function X(t) { return m.l + (t - cfg.from) / span * iw; }
    function Y(v) { return m.t + ih - v / sc.max * ih; }

    var svg = S('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H });
    var id = 'nt' + (++uid);
    var defs = S('defs', null, svg);
    ['dl', 'ul'].forEach(function (k) {
      var g = S('linearGradient', { id: id + k, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
      S('stop', { offset: '0', 'class': 'stop-' + k, 'stop-opacity': k === 'dl' ? 0.24 : 0.2 }, g);
      S('stop', { offset: '1', 'class': 'stop-' + k, 'stop-opacity': 0.01 }, g);
    });
    var clip = S('clipPath', { id: id + 'c' }, defs);
    var clipRect = S('rect', { x: 0, y: 0, width: animate ? m.l : W, height: H }, clip);

    var gAxis = S('g', null, svg);
    if (compact) {
      var by = Math.round(Y(0)) + 0.5;
      S('line', { x1: m.l, x2: m.l + iw, y1: by, y2: by, 'class': 'c-base' }, gAxis);
      svgText(gAxis, m.l, H - 4, hm(cfg.from), 'c-tick');
      svgText(gAxis, m.l + iw, H - 4, 'bây giờ', 'c-tick', 'end');
      svgText(gAxis, m.l, 10, 'Cao nhất ' + fmtRate(max), 'c-tick');
    }
    sc.ticks.forEach(function (t, i) {
      if (compact) return;
      var y = Math.round(Y(t.v)) + 0.5;
      S('line', { x1: m.l, x2: m.l + iw, y1: y, y2: y, 'class': i === 0 ? 'c-base' : 'c-grid' }, gAxis);
      svgText(gAxis, m.l - 10, y + 4, t.label, 'c-tick', 'end');
    });

    var steps = compact ? [] : [300, 600, 900, 1800, 3600, 7200, 10800, 14400, 21600, 43200, 86400, 172800, 604800];
    var minPx = textW('00:00') + 26;
    var step = steps.length ? steps[steps.length - 1] : 0;
    for (var s = 0; s < steps.length; s++) {
      if (steps[s] / span * iw >= minPx) { step = steps[s]; break; }
    }
    var firstTick = step ? Math.ceil((cfg.from + tzOffset) / step) * step - tzOffset : cfg.to + 1;
    for (var t = firstTick; step && t <= cfg.to; t += step) {
      var x = X(t);
      if (x < m.l + 14 || x > m.l + iw - 14) continue;
      var d = lt(t);
      var label = (step >= 86400 || (d.getUTCHours() === 0 && d.getUTCMinutes() === 0)) ? dm(t) : hm(t);
      S('line', { x1: Math.round(x) + 0.5, x2: Math.round(x) + 0.5, y1: m.t + ih, y2: m.t + ih + 4, 'class': 'c-base' }, gAxis);
      svgText(gAxis, x, H - 9, label, 'c-tick', 'middle');
    }

    var segs = [];
    var curSeg = [];
    pts.forEach(function (p) {
      if (p[1] == null) { if (curSeg.length) segs.push(curSeg); curSeg = []; } else curSeg.push(p);
    });
    if (curSeg.length) segs.push(curSeg);

    var gData = S('g', { 'clip-path': 'url(#' + id + 'c)' }, svg);
    var base = m.t + ih;
    var lines = {};
    ['dl', 'ul'].forEach(function (k, si) {
      var idx = si + 1;
      var dA = '';
      var dL = '';
      segs.forEach(function (seg) {
        var path = '';
        seg.forEach(function (p, j) { path += (j ? 'L' : 'M') + f1(X(p[0])) + ',' + f1(Y(p[idx])); });
        if (seg.length === 1) path += 'h1.5';
        dL += path;
        dA += path + 'L' + f1(X(seg[seg.length - 1][0]) + (seg.length === 1 ? 1.5 : 0)) + ',' + base + 'L' + f1(X(seg[0][0])) + ',' + base + 'Z';
      });
      S('path', { d: dA, fill: 'url(#' + id + k + ')' }, gData);
      lines[k] = dL;
    });
    S('path', { d: lines.dl, 'class': 'c-line dl' }, gData);
    S('path', { d: lines.ul, 'class': 'c-line ul' }, gData);

    var lastP = real[real.length - 1];
    S('circle', { cx: X(lastP[0]), cy: Y(lastP[1]), r: 4, 'class': 'c-dot dl' }, gData);
    S('circle', { cx: X(lastP[0]), cy: Y(lastP[2]), r: 4, 'class': 'c-dot ul' }, gData);

    var gHover = S('g', { style: 'display:none' }, svg);
    var cross = S('line', { y1: m.t, y2: base, 'class': 'c-cross' }, gHover);
    var hd = S('circle', { r: 4.5, 'class': 'c-dot dl' }, gHover);
    var hu = S('circle', { r: 4.5, 'class': 'c-dot ul' }, gHover);
    var hit = S('rect', { x: m.l, y: m.t, width: iw, height: ih, 'class': 'c-hit', style: 'touch-action:pan-y' }, svg);

    var xs = real.map(function (p) { return X(p[0]); });
    el.appendChild(svg);

    bindNav(el, svg, hit, {
      width: W,
      count: real.length,
      indexAt: function (px) {
        var lo = 0, hi = xs.length - 1;
        while (lo < hi) {
          var mid = (lo + hi) >> 1;
          if (xs[mid] < px) lo = mid + 1; else hi = mid;
        }
        if (lo > 0 && Math.abs(xs[lo - 1] - px) < Math.abs(xs[lo] - px)) lo--;
        return lo;
      },
      anchor: function (i) { return [xs[i], Math.min(Y(real[i][1]), Y(real[i][2]))]; },
      highlight: function (i) {
        if (i < 0) { gHover.style.display = 'none'; return; }
        var p = real[i];
        var x = xs[i];
        gHover.style.display = '';
        cross.setAttribute('x1', Math.round(x) + 0.5);
        cross.setAttribute('x2', Math.round(x) + 0.5);
        hd.setAttribute('cx', x); hd.setAttribute('cy', Y(p[1]));
        hu.setAttribute('cx', x); hu.setAttribute('cy', Y(p[2]));
      },
      tooltip: function (i) {
        var p = real[i];
        var b = cfg.bucket || 0;
        var title = (b >= 86400 ? '' : hm(p[0]) + (b > 60 ? '–' + hm(p[0] + b) : '') + ' · ') + WDS[wday(p[0])] + ', ' + dmy(p[0]);
        return tipBuild(title, seriesRows(p[1], p[2], 'rate', false), b > 60 ? 'Trung bình mỗi ' + durText(b) : null);
      }
    });

    if (animate) {
      var t0 = null;
      var frame = function (now) {
        if (t0 === null) t0 = now;
        var k = Math.min(1, (now - t0) / 1100);
        var e = 1 - Math.pow(1 - k, 3);
        clipRect.setAttribute('width', m.l + (W - m.l) * e);
        if (k < 1) requestAnimationFrame(frame);
      };
      requestAnimationFrame(frame);
    }
  }

  function speedTable(cfg) {
    return {
      head: ['Thời gian', 'Download', 'Upload'],
      num: [0, 1, 1],
      rows: (cfg.points || []).filter(function (p) { return p[1] != null; }).slice().reverse().map(function (p) {
        return [hm(p[0]) + ' ' + dmy(p[0]), fmtRate(p[1]), fmtRate(p[2])];
      })
    };
  }

  /* ------------------------------------------------------------------
   * Biểu đồ cột chồng: Download (dưới) + Upload (trên)
   * cfg: { items: [{label, title, dl|null, ul|null, foot}], anchor: 'start'|'end' }
   * ------------------------------------------------------------------ */

  /** Chiều cao biểu đồ; data-fill = giãn theo thẻ bên cạnh (không nhỏ hơn data-height). */
  function chartHeight(el, def) {
    var H = +el.getAttribute('data-height') || def;
    if (el.hasAttribute('data-fill') && !el.classList.contains('is-table')) H = Math.max(H, Math.floor(el.clientHeight));
    return H;
  }

  function renderColumns(el, cfg, W, animate) {
    var H = chartHeight(el, 240);
    var items = cfg.items || [];
    var n = items.length;
    var totals = items.map(function (it) { return it.dl == null ? null : it.dl + it.ul; });
    if (!totals.some(function (v) { return v != null; })) { emptyState(el, 'Chưa có dữ liệu.'); return; }
    var max = 0, maxI = -1;
    totals.forEach(function (v, i) { if (v != null && v > max) { max = v; maxI = i; } });

    var sc = axisScale(max, 'bytes');
    var m = { t: 26, r: 10, b: 30, l: Math.ceil(maxTextW(sc.ticks.map(function (t) { return t.label; }))) + 14 };
    var iw = Math.max(20, W - m.l - m.r);
    var ih = H - m.t - m.b;
    var band = iw / n;
    var bw = Math.max(2, Math.min(24, band * 0.64));
    var base = m.t + ih;
    function Y(v) { return base - v / sc.max * ih; }

    var svg = S('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': animate ? 'anim' : null });
    var gAxis = S('g', null, svg);
    sc.ticks.forEach(function (t, i) {
      var y = Math.round(Y(t.v)) + 0.5;
      S('line', { x1: m.l, x2: m.l + iw, y1: y, y2: y, 'class': i === 0 ? 'c-base' : 'c-grid' }, gAxis);
      svgText(gAxis, m.l - 10, y + 4, t.label, 'c-tick', 'end');
    });

    var lw = maxTextW(items.map(function (it) { return it.label; }));
    var every = Math.max(1, Math.ceil((lw + 10) / band));
    if (every > 1 && cfg.niceEvery) {
      for (var ne = 0; ne < cfg.niceEvery.length; ne++) {
        if (cfg.niceEvery[ne] >= every) { every = cfg.niceEvery[ne]; break; }
      }
    }
    items.forEach(function (it, i) {
      var pos = cfg.anchor === 'end' ? (n - 1 - i) : i;
      if (pos % every !== 0) return;
      var cx = m.l + band * (i + 0.5);
      svgText(gAxis, Math.max(m.l + lw / 2, Math.min(W - lw / 2 - 2, cx)), H - 9, it.label, 'c-tick', 'middle');
    });

    var gCols = S('g', { 'class': 'c-cols' }, svg);
    var cols = [];
    items.forEach(function (it, i) {
      var cx = m.l + band * (i + 0.5);
      var x = cx - bw / 2;
      var g = S('g', { 'class': 'c-col', style: '--i:' + i }, gCols);
      S('rect', { x: f1(m.l + band * i), y: m.t, width: f1(band), height: ih, 'class': 'c-hit' }, g);
      cols.push(g);
      var tot = totals[i];
      if (tot == null || tot <= 0) return;
      var hT = Math.max(1, tot / sc.max * ih);
      var hD = it.dl / sc.max * ih;
      var hU = hT - hD;
      if (it.dl > 0 && hD < 1) hD = 1;
      var hasU = it.ul > 0 && hU >= 1.5;
      var gap = hasU && hD > 0 ? 2 : 0;
      if (hD > 0) {
        if (hasU) S('rect', { x: f1(x), y: f1(base - hD), width: f1(bw), height: f1(hD), 'class': 'c-bar dl' }, g);
        else S('path', { d: topRoundRect(x, base - hT, bw, hT, 4), 'class': 'c-bar dl' }, g);
      }
      if (hasU) {
        var uh = Math.max(1, hU - gap);
        S('path', { d: topRoundRect(x, base - hT, bw, uh, 4), 'class': 'c-bar ul' }, g);
      }
      if (i === maxI && cfg.labelMax !== false) {
        var lbl = fmtBytes(tot);
        var w2 = textW(lbl, 11.5, 600) / 2;
        svgText(g, Math.max(w2, Math.min(W - w2, cx)), f1(base - hT - 8), lbl, 'c-label', 'middle');
      }
    });

    var hit = S('rect', { x: m.l, y: 0, width: iw, height: base, fill: 'transparent', style: 'touch-action:pan-y;cursor:pointer' }, svg);
    el.appendChild(svg);

    bindNav(el, svg, hit, {
      width: W,
      count: n,
      indexAt: function (px) {
        var i = Math.floor((px - m.l) / band);
        return i < 0 || i >= n ? -1 : i;
      },
      anchor: function (i) {
        var tot = totals[i] || 0;
        return [m.l + band * (i + 0.5), base - tot / sc.max * ih];
      },
      highlight: function (i) {
        gCols.classList.toggle('has-hover', i >= 0);
        cols.forEach(function (g, j) { g.classList.toggle('is-hover', j === i); });
      },
      tooltip: function (i) {
        var it = items[i];
        if (it.dl == null) return tipBuild(it.title, [], it.emptyText || 'Chưa có dữ liệu');
        return tipBuild(it.title, seriesRows(it.dl, it.ul, 'bytes', true), it.foot || null);
      }
    });
  }

  function columnsTable(cfg) {
    var rows = (cfg.items || []).filter(function (it) { return it.dl != null; }).map(function (it) {
      return [it.title, fmtBytes(it.dl), fmtBytes(it.ul), fmtBytes(it.dl + it.ul)];
    });
    if (cfg.newestFirst) rows.reverse();
    return { head: ['Thời gian', 'Download', 'Upload', 'Tổng'], num: [0, 1, 1, 1], rows: rows };
  }

  /* ------------------------------------------------------------------
   * Bản đồ nhiệt: thứ x giờ (thói quen sử dụng)
   * cfg: { cells: [[wd(0=T2), hour, avgBytes, hoursCount], ...] }
   * ------------------------------------------------------------------ */

  var ROW_LABELS = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
  var ROW_NAMES = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật'];

  function scaleLegend(el, note) {
    var lg = doc.createElement('div');
    lg.className = 'scale-legend';
    var a = doc.createElement('span');
    a.textContent = 'Ít';
    lg.appendChild(a);
    for (var i = 1; i <= 5; i++) {
      var sw = doc.createElement('i');
      sw.className = 'l' + i;
      lg.appendChild(sw);
    }
    var b = doc.createElement('span');
    b.textContent = 'Nhiều';
    lg.appendChild(b);
    if (note) {
      var sep = doc.createElement('span');
      sep.className = 'sep';
      lg.appendChild(sep);
      var t = doc.createElement('span');
      t.textContent = note;
      lg.appendChild(t);
    }
    el.appendChild(lg);
  }

  function renderPattern(el, cfg, W, animate) {
    var cells = cfg.cells || [];
    if (!cells.length) { emptyState(el, 'Chưa đủ dữ liệu để thống kê thói quen.'); return; }
    var grid = [];
    var r, c;
    for (r = 0; r < 7; r++) { grid.push([]); for (c = 0; c < 24; c++) grid[r].push(null); }
    var max = 0;
    cells.forEach(function (x) { grid[x[0]][x[1]] = x; if (x[2] > max) max = x[2]; });

    var labelW = 28, gap = 3, topPad = 2;
    var cw = Math.max(6, (W - labelW) / 24 - gap);
    var ch = Math.max(14, Math.min(26, cw));
    var H = topPad + 7 * (ch + gap) + 20;
    var svg = S('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': animate ? 'anim' : null });
    for (r = 0; r < 7; r++) svgText(svg, 0, topPad + r * (ch + gap) + ch / 2 + 4, ROW_LABELS[r], 'c-tick');
    for (c = 0; c < 24; c += 3) svgText(svg, labelW + c * (cw + gap) + cw / 2, H - 4, c + 'h', 'c-tick', 'middle');

    var g = S('g', { 'class': 'c-cells' }, svg);
    var rects = [];
    for (r = 0; r < 7; r++) {
      for (c = 0; c < 24; c++) {
        var v = grid[r][c];
        var lvl = !v ? 'nd' : (v[2] <= 0 || max <= 0 ? 'l0' : 'l' + Math.min(5, Math.max(1, Math.ceil(v[2] / max * 5))));
        rects.push(S('rect', {
          x: f1(labelW + c * (cw + gap)), y: f1(topPad + r * (ch + gap)), rx: 3,
          width: f1(cw), height: f1(ch), 'class': 'c-cell ' + lvl, style: '--i:' + (c + r)
        }, g));
      }
    }
    el.appendChild(svg);
    scaleLegend(el, max > 0 ? 'Ô đậm nhất ≈ ' + fmtBytes(max) + '/giờ' : '');

    bindNav(el, svg, svg, {
      width: W,
      count: 168,
      indexAt: function (px, py) {
        var cc = Math.floor((px - labelW) / (cw + gap));
        var rr = Math.floor((py - topPad) / (ch + gap));
        if (cc < 0 || cc > 23 || rr < 0 || rr > 6) return -1;
        return rr * 24 + cc;
      },
      anchor: function (i) {
        return [labelW + (i % 24) * (cw + gap) + cw / 2, topPad + Math.floor(i / 24) * (ch + gap)];
      },
      highlight: function (i) { rects.forEach(function (rc, j) { rc.classList.toggle('is-hover', j === i); }); },
      tooltip: function (i) {
        var rr = Math.floor(i / 24), cc = i % 24;
        var v = grid[rr][cc];
        var title = ROW_NAMES[rr] + ' · ' + pad(cc) + ':00–' + pad((cc + 1) % 24) + ':00';
        if (!v) return tipBuild(title, [], 'Chưa có dữ liệu');
        return tipBuild(title, [{ value: fmtBytes(v[2]), name: 'trung bình mỗi giờ' }], 'Tính trên ' + v[3] + ' giờ có dữ liệu');
      }
    });
  }

  function patternTable(cfg) {
    var grid = {};
    (cfg.cells || []).forEach(function (x) { grid[x[0] + '-' + x[1]] = x[2]; });
    var head = ['Thứ'];
    for (var c = 0; c < 24; c++) head.push(c + 'h');
    var num = head.map(function (h, i) { return i > 0; });
    var rows = ROW_NAMES.map(function (name, r) {
      var row = [name];
      for (var cc = 0; cc < 24; cc++) {
        var v = grid[r + '-' + cc];
        row.push(v == null ? '—' : fmtBytes(v));
      }
      return row;
    });
    return { head: head, num: num, rows: rows };
  }

  /* ------------------------------------------------------------------
   * Lịch sử dụng (mỗi ô một ngày, ~12 tháng)
   * cfg: { days: {'YYYY-MM-DD': [rx, tx]}, today: 'YYYY-MM-DD', since: 'YYYY-MM-DD'|null }
   * ------------------------------------------------------------------ */

  function renderCalendar(el, cfg, W, animate) {
    var days = cfg.days || {};
    var labelW = 26, gap = 3, topH = 18;
    var cell = Math.max(10, Math.min(22, Math.floor((W - labelW) / 53) - gap));
    var weeks = Math.max(8, Math.min(53, Math.floor((W - labelW + gap) / (cell + gap))));
    var todayTs = ymdTs(cfg.today);
    var dow = (wday(todayTs) + 6) % 7;
    var startTs = todayTs - (dow + (weeks - 1) * 7) * 86400;

    var vals = [];
    var list = [];
    for (var i = 0; i < weeks * 7; i++) {
      var ts = startTs + i * 86400;
      if (ts > todayTs) break;
      var key = tsYmd(ts);
      var v = days[key];
      list.push({ ts: ts, key: key, v: v || null, i: i });
      if (v && v[0] + v[1] > 0) vals.push(v[0] + v[1]);
    }
    vals.sort(function (a, b) { return a - b; });
    var q = [0.2, 0.4, 0.6, 0.8].map(function (p) { return vals.length ? vals[Math.min(vals.length - 1, Math.floor(p * vals.length))] : 0; });
    function level(v) {
      if (!v) return 'nd';
      var t = v[0] + v[1];
      if (t <= 0) return 'l0';
      var l = 1;
      for (var k = 0; k < q.length; k++) if (t > q[k]) l = k + 2;
      return 'l' + Math.min(5, l);
    }

    var Wn = labelW + weeks * (cell + gap);
    var H = topH + 7 * (cell + gap);
    var svg = S('svg', { width: Wn, height: H, viewBox: '0 0 ' + Wn + ' ' + H, 'class': animate ? 'anim' : null, style: 'width:' + Wn + 'px;max-width:100%' });
    [0, 2, 4, 6].forEach(function (r) { svgText(svg, 0, topH + r * (cell + gap) + cell - 1, ROW_LABELS[r], 'c-tick'); });

    var lastLabelX = -100;
    for (var w = 0; w < weeks; w++) {
      var wts = startTs + w * 7 * 86400;
      var d = lt(wts);
      var dEnd = lt(wts + 6 * 86400);
      if (w === 0 || d.getUTCMonth() !== dEnd.getUTCMonth() || d.getUTCDate() === 1) {
        var mm = (w === 0 ? d : dEnd).getUTCMonth();
        var lx = labelW + w * (cell + gap);
        if (w === 0 && dEnd.getUTCDate() <= 7 && dEnd.getUTCMonth() !== d.getUTCMonth()) mm = dEnd.getUTCMonth();
        if (lx - lastLabelX > 34) {
          svgText(svg, lx, 11, 'Thg ' + (mm + 1), 'c-tick');
          lastLabelX = lx;
        }
      }
    }

    var g = S('g', { 'class': 'c-cells' }, svg);
    var rects = {};
    list.forEach(function (it) {
      var col = Math.floor(it.i / 7), row = it.i % 7;
      rects[it.i] = S('rect', {
        x: labelW + col * (cell + gap), y: topH + row * (cell + gap), width: cell, height: cell, rx: 2.5,
        'class': 'c-cell ' + level(it.v), style: '--i:' + col
      }, g);
    });

    el.appendChild(svg);
    var total = vals.reduce(function (a, b) { return a + b; }, 0);
    scaleLegend(el, 'Tổng ' + weeks + ' tuần: ' + fmtBytes(total));

    var byIndex = {};
    list.forEach(function (it) { byIndex[it.i] = it; });
    bindNav(el, svg, svg, {
      width: Wn,
      count: list.length,
      indexAt: function (px, py) {
        var col = Math.floor((px - labelW) / (cell + gap));
        var row = Math.floor((py - topH) / (cell + gap));
        if (col < 0 || col >= weeks || row < 0 || row > 6) return -1;
        var idx = col * 7 + row;
        return byIndex[idx] ? idx : -1;
      },
      anchor: function (idx) {
        return [labelW + Math.floor(idx / 7) * (cell + gap) + cell / 2, topH + (idx % 7) * (cell + gap)];
      },
      highlight: function (idx) {
        for (var k in rects) if (Object.prototype.hasOwnProperty.call(rects, k)) rects[k].classList.toggle('is-hover', +k === idx);
      },
      tooltip: function (idx) {
        var it = byIndex[idx];
        var title = WD[wday(it.ts)] + ', ' + dmy(it.ts);
        if (!it.v) return tipBuild(title, [], 'Không có dữ liệu');
        return tipBuild(title, seriesRows(it.v[0], it.v[1], 'bytes', true));
      }
    });
  }

  function calendarTable(cfg) {
    var keys = Object.keys(cfg.days || {}).sort().reverse();
    return {
      head: ['Ngày', 'Download', 'Upload', 'Tổng'],
      num: [0, 1, 1, 1],
      rows: keys.map(function (k) {
        var v = cfg.days[k];
        var ts = ymdTs(k);
        return [WDS[wday(ts)] + ', ' + dmy(ts), fmtBytes(v[0]), fmtBytes(v[1]), fmtBytes(v[0] + v[1])];
      })
    };
  }

  /* ------------------------------------------------------------------
   * Trang Tổng quan
   * ------------------------------------------------------------------ */

  function overviewCharts(d) {
    tzOffset = d.tzOffset;
    unitBase = d.unitBase;

    var sp = d.speed;
    mount('chart-speed', renderArea, { from: sp.from, to: sp.to, bucket: sp.bucket, points: sp.points }, speedTable, 'Biểu đồ tốc độ 24 giờ qua');
    if (d.live) {
      mount('chart-live', renderArea, { from: d.live.from, to: d.live.to, bucket: d.live.bucket, points: d.live.points, compact: true }, speedTable, 'Biểu đồ tốc độ 60 phút qua');
    }
    var sub = $('#speed-sub');
    if (sub) sub.textContent = 'Tốc độ trung bình mỗi ' + durText(sp.bucket) + ' · rê chuột hoặc chạm để xem chi tiết';

    mount('chart-hours', renderColumns, {
      anchor: 'start',
      niceEvery: [2, 3, 4, 6, 12],
      items: d.hours.map(function (h) {
        var hr = lt(h[0]).getUTCHours();
        return {
          label: hr + 'h',
          title: pad(hr) + ':00–' + pad((hr + 1) % 24) + ':00 · ' + dmy(h[0]),
          dl: h[1], ul: h[2],
          foot: h[3] != null ? 'Hôm qua cùng giờ: ' + fmtBytes(h[3]) : null,
          emptyText: h[0] > nowSec() ? 'Chưa tới giờ này' : 'Chưa có dữ liệu'
        };
      })
    }, columnsTable, 'Biểu đồ lưu lượng theo giờ hôm nay');

    mount('chart-daily', renderColumns, {
      anchor: 'end',
      newestFirst: true,
      niceEvery: [2, 3, 5, 7, 10, 15],
      items: d.daily.map(function (x) {
        var ts = ymdTs(x[0]);
        return { label: dm(ts), title: WD[wday(ts)] + ', ' + dmy(ts), dl: x[1], ul: x[2] };
      })
    }, columnsTable, 'Biểu đồ lưu lượng 30 ngày gần nhất');

    mount('chart-months', renderColumns, {
      anchor: 'end',
      newestFirst: true,
      niceEvery: [2, 3, 4, 6],
      items: d.months.map(function (x) {
        var p = x[0].split('-');
        return { label: 'Thg ' + (+p[1]), title: 'Tháng ' + (+p[1]) + '/' + p[0], dl: x[1], ul: x[2] };
      })
    }, columnsTable, 'Biểu đồ lưu lượng 12 tháng gần nhất');

    mount('chart-pattern', renderPattern, { cells: d.pattern }, patternTable, 'Bản đồ nhiệt thói quen sử dụng theo thứ và giờ');
    mount('chart-calendar', renderCalendar, { days: d.calendar, today: d.today }, calendarTable, 'Lịch sử dụng từng ngày trong 12 tháng');

    var pill = $('#status-pill');
    if (pill && d.status) {
      pill.setAttribute('data-last-ts', d.status.lastTs || 0);
      pill.setAttribute('data-typical', d.status.typical || 60);
    }
  }

  function swapSections(html) {
    Object.keys(html || {}).forEach(function (k) {
      var el = $('[data-section="' + k + '"]');
      if (!el) return;
      var oldWidths = $$('.meter span', el).map(function (s) { return s.style.width; });
      el.innerHTML = html[k];
      var spans = $$('.meter span', el);
      if (oldWidths.length === spans.length && !reduceMotion) {
        var target = spans.map(function (s) { return s.style.width; });
        spans.forEach(function (s, i) { s.style.width = oldWidths[i]; });
        void el.offsetWidth;
        spans.forEach(function (s, i) { s.style.width = target[i]; });
      }
    });
    fitSegLabels();
    bindRankTips();
  }

  function startPolling(conf) {
    if (!conf.refresh || !window.fetch) return;
    var rev = conf.rev;
    var busy = false;
    var last = Date.now();
    var every = conf.refresh * 1000;
    function poll() {
      if (busy || doc.hidden) return;
      busy = true;
      last = Date.now();
      fetch('api.php?a=overview', { cache: 'no-cache', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j || !j.data || j.rev === rev) return;
          rev = j.rev;
          swapSections(j.html);
          overviewCharts(j.data);
          tick();
        })
        .catch(function () { /* mất mạng tạm thời: thử lại ở lần sau */ })
        .then(function () { busy = false; });
    }
    setInterval(poll, every);
    on(doc, 'visibilitychange', function () {
      if (!doc.hidden && Date.now() - last > every) poll();
    });
  }

  /* ------------------------------------------------------------------
   * Trang Lịch sử
   * ------------------------------------------------------------------ */

  function historyCharts(h) {
    tzOffset = h.tzOffset;
    unitBase = h.unitBase;
    var multiYear = h.from.slice(0, 4) !== h.to.slice(0, 4);
    var items = h.items.map(function (it) {
      var k = it[0];
      var label, title;
      if (h.gran === 'hour') {
        var hr = lt(k).getUTCHours();
        label = hr === 0 && h.days > 1 ? dm(k) : hr + 'h';
        title = pad(hr) + ':00–' + pad((hr + 1) % 24) + ':00 · ' + WDS[wday(k)] + ', ' + dmy(k);
      } else if (h.gran === 'day') {
        var ts = ymdTs(k);
        label = dm(ts);
        title = WD[wday(ts)] + ', ' + dmy(ts);
      } else if (h.gran === 'month') {
        var p = String(k).split('-');
        label = multiYear ? (+p[1]) + '/' + p[0].slice(2) : 'Thg ' + (+p[1]);
        title = 'Tháng ' + (+p[1]) + '/' + p[0];
      } else {
        label = String(k);
        title = 'Năm ' + k;
      }
      return { label: label, title: title, dl: it[1], ul: it[2] };
    });
    var granName = { hour: 'giờ', day: 'ngày', month: 'tháng', year: 'năm' }[h.gran];
    mount('chart-history', renderColumns, {
      anchor: 'start',
      niceEvery: h.gran === 'hour' ? [2, 3, 4, 6, 12] : (h.gran === 'day' ? [2, 3, 5, 7, 10, 14] : [2, 3, 4, 6, 12]),
      items: items
    }, columnsTable, 'Biểu đồ lưu lượng theo ' + granName);
    if (h.speed) {
      mount('chart-speed', renderArea, { from: h.speed.from, to: h.speed.to, bucket: h.speed.bucket, points: h.speed.points }, speedTable, 'Biểu đồ tốc độ');
    }
  }

  /* ------------------------------------------------------------------
   * Hiệu ứng & cập nhật theo thời gian thực
   * ------------------------------------------------------------------ */

  function countUp(el) {
    var target = +el.getAttribute('data-count');
    var numEl = $('.num', el);
    if (!numEl || !(target > 0)) return;
    var p = bytesParts(target);
    var div = Math.pow(unitBase, p[2]);
    var finalV = target / div;
    var dec = p[2] === 0 ? 0 : autoDec(finalV);
    var t0 = null;
    numEl.textContent = fmtNum(0, dec);
    function frame(now) {
      if (t0 === null) t0 = now;
      var k = Math.min(1, (now - t0) / 1200);
      var e = 1 - Math.pow(1 - k, 3);
      numEl.textContent = k < 1 ? fmtNum(finalV * e, dec) : p[0];
      if (k < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }

  function fitSegLabels() {
    $$('.split-bar.tall .seg').forEach(function (seg) {
      var l = $('.seg-label', seg);
      if (!l) return;
      l.classList.remove('is-hidden');
      if (l.scrollWidth + 2 > seg.clientWidth) l.classList.add('is-hidden');
    });
  }

  function bindRankTips() {
    $$('[data-tip]').forEach(function (li) {
      if (li._tipBound) return;
      li._tipBound = true;
      var show = function (e) {
        var v;
        try { v = JSON.parse(li.getAttribute('data-tip')); } catch (err) { return; }
        var rows = [
          { key: 'dl', value: v[1], name: 'Download' },
          { key: 'ul', value: v[2], name: 'Upload' },
          { value: v[3], name: 'Tổng', total: true }
        ];
        var r = li.getBoundingClientRect();
        var x = e && e.clientX != null ? e.clientX : r.left + r.width * 0.6;
        var y = e && e.clientY != null ? e.clientY : r.top;
        tipShow(li, tipBuild(v[0], rows), x, y);
      };
      on(li, 'pointermove', show);
      on(li, 'pointerleave', function () { tipHide(li); });
      on(li, 'focus', function () { show(null); });
      on(li, 'blur', function () { tipHide(li); });
    });
  }

  function updateStatus() {
    var pill = $('#status-pill');
    if (!pill) return;
    var last = +pill.getAttribute('data-last-ts') || 0;
    var typical = +pill.getAttribute('data-typical') || 60;
    var txt = $('.status-text', pill);
    var state, label;
    if (!last) {
      state = 'none';
      label = 'Chưa có dữ liệu';
    } else if (nowSec() - last <= Math.max(3 * typical, typical + 120)) {
      state = 'online';
      label = 'Trực tuyến';
    } else {
      state = 'offline';
      label = 'Mất tín hiệu · ' + relTime(last);
    }
    pill.className = 'status-pill is-' + state;
    var title = last ? 'Mẫu gần nhất lúc ' + hms(last) + ' ' + dmy(last) + ' (' + relTime(last) + ')' : 'Router chưa gửi dữ liệu';
    pill.title = title;
    pill.setAttribute('aria-label', label + '. ' + title);
    if (txt && txt.textContent !== label) txt.textContent = label;
  }

  function tick() {
    var t = Math.floor(nowSec());
    var ct = $('.clock-time');
    var cd = $('.clock-date');
    if (ct) ct.textContent = hms(t);
    if (cd) cd.textContent = WD[wday(t)] + ', ' + dmy(t);
    $$('[data-ago]').forEach(function (el) {
      var s = relTime(+el.getAttribute('data-ago'));
      if (el.textContent !== s) el.textContent = s;
    });
    updateStatus();
  }

  /* ------------------------------------------------------------------
   * Giao diện sáng/tối & tiện ích trang quản trị
   * ------------------------------------------------------------------ */

  on($('#theme-toggle'), 'click', function () {
    var cur = root.getAttribute('data-theme');
    if (!cur) cur = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    var next = cur === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('nt-theme', next); } catch (e) { /* trình duyệt chặn lưu trữ */ }
  });

  function copyText(txt) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(txt);
    return new Promise(function (resolve, reject) {
      var ta = doc.createElement('textarea');
      ta.value = txt;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      body.appendChild(ta);
      ta.select();
      try { doc.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
      body.removeChild(ta);
    });
  }

  $$('[data-copy]').forEach(function (btn) {
    on(btn, 'click', function () {
      var src = $(btn.getAttribute('data-copy'));
      if (!src) return;
      var txt = src.value != null && src.tagName !== 'PRE' ? src.value : src.textContent;
      var label = btn.lastChild;
      var old = label && label.nodeType === 3 ? label.nodeValue : null;
      copyText(txt).then(function () {
        if (old !== null) {
          label.nodeValue = 'Đã chép!';
          setTimeout(function () { label.nodeValue = old; }, 1600);
        }
      }, function () { window.prompt('Sao chép thủ công:', txt); });
    });
  });

  $$('[data-gen-key]').forEach(function (btn) {
    on(btn, 'click', function () {
      var input = $(btn.getAttribute('data-gen-key'));
      if (!input) return;
      var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
      var out = '';
      var arr = new Uint32Array(24);
      (window.crypto || window.msCrypto).getRandomValues(arr);
      for (var i = 0; i < arr.length; i++) out += chars[arr[i] % chars.length];
      input.value = out;
      input.focus();
    });
  });

  $$('form[data-confirm]').forEach(function (f) {
    on(f, 'submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
  });

  /* ------------------------------------------------------------------
   * Khởi động
   * ------------------------------------------------------------------ */

  var confEl = doc.getElementById('app-config');
  var conf = null;
  if (confEl) {
    try { conf = JSON.parse(confEl.textContent); } catch (e) { conf = null; }
  }
  var chartsDrawn = false;
  var fontsDone = false;
  function startCharts() {
    if (chartsDrawn || !conf || !conf.data) return;
    chartsDrawn = true;
    if (conf.page === 'overview') {
      overviewCharts(conf.data);
      startPolling(conf);
    } else if (conf.page === 'history') {
      historyCharts(conf.data);
    }
  }
  if (conf && conf.data) {
    unitBase = conf.data.unitBase || 1024;
    tzOffset = conf.data.tzOffset || tzOffset;
  }
  // Chờ font tải xong (tối đa 0,7 giây) để đo chữ trên trục cho chuẩn.
  if (doc.fonts && doc.fonts.ready) {
    doc.fonts.ready.then(function () {
      fontsDone = true;
      fitSegLabels();
      if (chartsDrawn) {
        measureCtx = null;
        Object.keys(charts).forEach(function (k) { schedule(charts[k]); });
      } else {
        startCharts();
      }
    });
    setTimeout(function () { if (!fontsDone) startCharts(); }, 700);
  } else {
    startCharts();
  }

  if (!reduceMotion) $$('[data-count]').forEach(countUp);
  fitSegLabels();
  bindRankTips();
  tick();
  setInterval(tick, 1000);
  on(window, 'resize', function () { fitSegLabels(); });
  // Sau khi hiệu ứng mở trang chạy xong, các khối được làm mới sẽ không "nhảy" lại.
  setTimeout(function () { body.classList.add('is-ready'); }, 1400);
})();
