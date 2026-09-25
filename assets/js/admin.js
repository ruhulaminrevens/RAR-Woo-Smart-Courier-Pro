/*! RAR Woo Smart Courier – admin app (dashboard, shipments board, settings helpers). No dependencies. */
(function () {
    'use strict';

    var C = window.RWSC || {};
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var cur = C.currency || { symbol: '৳', pos: 'left', decimals: 0 };
    function num(v, d) {
        var n = Number(v) || 0;
        return n.toLocaleString('en-IN', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
    }
    function money(v) {
        var s = num(v, cur.decimals), sym = cur.symbol;
        switch (cur.pos) {
            case 'right': return s + sym;
            case 'left_space': return sym + ' ' + s;
            case 'right_space': return s + ' ' + sym;
            default: return sym + s;
        }
    }
    function compactMoney(v) {
        var n = Number(v) || 0, a = Math.abs(n);
        if (a >= 10000000) { return cur.symbol + (n / 10000000).toFixed(a >= 100000000 ? 0 : 1) + ' Cr'; }
        if (a >= 100000) { return cur.symbol + (n / 100000).toFixed(a >= 1000000 ? 0 : 1) + ' L'; }
        if (a >= 1000) { return cur.symbol + (n / 1000).toFixed(a >= 10000 ? 0 : 1) + 'k'; }
        return money(n);
    }
    var COURIER = {};
    (C.couriers || []).forEach(function (c) { COURIER[c.key] = c; });
    var STATUS = {};
    (C.statuses || []).forEach(function (s) { STATUS[s.key] = s; });
    var cname = function (k) { return COURIER[k] ? COURIER[k].name : (k ? k.charAt(0).toUpperCase() + k.slice(1) : 'Unassigned'); };
    var ccolor = function (k) { return COURIER[k] ? COURIER[k].color : '#94a3b8'; };

    /* ------------------------------------------------------------------ */
    /* Infrastructure                                                     */
    /* ------------------------------------------------------------------ */

    function api(action, data) {
        var fd = new FormData();
        fd.append('action', 'rwsc_' + action);
        fd.append('nonce', C.nonce);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); } else if (v !== undefined && v !== null) { fd.append(k, v); }
        });
        return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            return r.text().then(function (t) {
                var j;
                try { j = JSON.parse(t); } catch (e) { throw new Error(r.status === 403 || t === '-1' ? 'Your session expired – please reload the page.' : 'Unexpected server response (' + r.status + ').'); }
                if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Request failed.'); }
                return j.data;
            });
        });
    }

    var toastBox;
    function toast(msg, tone) {
        if (!toastBox) {
            toastBox = document.createElement('div');
            toastBox.className = 'rwsc-toasts';
            toastBox.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastBox);
        }
        var t = document.createElement('div');
        t.className = 'rwsc-toast ' + (tone || 'ok');
        t.textContent = msg;
        toastBox.appendChild(t);
        setTimeout(function () { t.classList.add('out'); }, 3200);
        setTimeout(function () { t.remove(); }, 3700);
    }
    function copy(text) {
        var done = function () { toast('Copied to clipboard'); };
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text).then(done); }
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { toast('Copy failed', 'bad'); }
        ta.remove();
        return Promise.resolve();
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }

    /* Store-time clock: "Friday । Sep 25, 2026 । 01:49:02 pm" */
    function storeNow() { return new Date(Date.now() + (C.tzOffset || 0) * 1000); }
    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var zp = function (n) { return (n < 10 ? '0' : '') + n; };
    function tickClock() {
        var el = $('#rwsc-clock');
        if (!el) { return; }
        var d = storeNow(), h = d.getUTCHours(), h12 = h % 12 || 12;
        el.innerHTML = '<b>' + DAYS[d.getUTCDay()] + '</b> <i>।</i> ' + MON[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear() +
            ' <i>।</i> <span class="t">' + zp(h12) + ':' + zp(d.getUTCMinutes()) + ':' + zp(d.getUTCSeconds()) + ' ' + (h < 12 ? 'am' : 'pm') + '</span>';
    }
    function ymdLabel(ymd) {
        var p = String(ymd).split('-');
        return p.length === 3 ? Number(p[2]) + ' ' + MON[Number(p[1]) - 1] : ymd;
    }
    function ago(ts) {
        var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
        if (s < 60) { return 'just now'; }
        if (s < 3600) { return Math.round(s / 60) + 'm ago'; }
        if (s < 86400) { return Math.round(s / 3600) + 'h ago'; }
        return Math.round(s / 86400) + 'd ago';
    }
    function dateShort(ts) {
        var d = new Date(ts * 1000 + (C.tzOffset || 0) * 1000), h = d.getUTCHours();
        return d.getUTCDate() + ' ' + MON[d.getUTCMonth()] + ', ' + (h % 12 || 12) + ':' + zp(d.getUTCMinutes()) + (h < 12 ? 'am' : 'pm');
    }

    var ICON = {
        box: '<svg viewBox="0 0 24 24"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
        cash: '<svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>',
        clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        truck: '<svg viewBox="0 0 24 24"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>',
        check: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>',
        undo: '<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 010 12h-3"/></svg>',
        bolt: '<svg viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/></svg>',
        gift: '<svg viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="13" rx="1"/><path d="M12 8v13M3 12h18"/><path d="M12 8S10 3 7.5 4 9 8 12 8zm0 0s2-5 4.5-4S15 8 12 8z"/></svg>',
        refresh: '<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>',
        print: '<svg viewBox="0 0 24 24"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>',
        csv: '<svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>',
        list: '<svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
        copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>',
        ext: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>',
        search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
        x: '<svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>',
        pin: '<svg viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 10-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        phone: '<svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/></svg>',
        warn: '<svg viewBox="0 0 24 24"><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
        info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'
    };

    /* ------------------------------------------------------------------ */
    /* Charts (SVG)                                                       */
    /* ------------------------------------------------------------------ */

    function niceMax(v) {
        if (v <= 0) { return 4; }
        var p = Math.pow(10, Math.floor(Math.log10(v))), n = v / p;
        var m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
        var top = m * p;
        return top < 4 && Math.round(v) === v ? 4 : top;
    }
    function pickLabels(n, xOf, gap) {
        var out = [];
        for (var i = 0; i < n; i++) {
            var x = xOf(i);
            if (i === n - 1) {
                while (out.length && x - xOf(out[out.length - 1]) < gap) { out.pop(); }
                out.push(i);
            } else if (!out.length || x - xOf(out[out.length - 1]) >= gap) { out.push(i); }
        }
        return out;
    }
    function widthOf(el, fallback) { var w = el ? el.clientWidth - 8 : 0; return w > 220 ? Math.round(w) : fallback; }

    function stackedBars(days, keys, W) {
        var H = W < 520 ? 200 : 230, pl = 34, pr = 8, pt = 12, pb = 28, n = days.length;
        var maxV = Math.max.apply(null, [0].concat(days.map(function (d) { return d.total; })));
        var top = niceMax(maxV), cw = (W - pl - pr) / Math.max(n, 1), bw = Math.max(3, Math.min(26, cw * 0.64));
        var y = function (v) { return pt + (H - pt - pb) * (1 - v / top); };
        var g = '';
        [0, top / 2, top].forEach(function (v) {
            g += '<line class="g" x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y(v) + '" y2="' + y(v) + '"/><text class="yl" x="' + (pl - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + num(v, v % 1 ? 1 : 0) + '</text>';
        });
        var bx = function (i) { return pl + i * cw + cw / 2; };
        var labels = pickLabels(n, bx, 58);
        days.forEach(function (d, i) {
            var acc = 0, cx = bx(i), tip = ymdLabel(d.date) + ': ' + d.total + ' parcels';
            keys.forEach(function (k) {
                var v = d.by[k] || 0;
                if (!v) { return; }
                var y1 = y(acc + v), h = y(acc) - y1;
                g += '<rect x="' + (cx - bw / 2).toFixed(1) + '" y="' + y1.toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + Math.max(1, h).toFixed(1) + '" fill="' + ccolor(k) + '" rx="2"><title>' + esc(ymdLabel(d.date) + ' · ' + cname(k) + ': ' + v) + '</title></rect>';
                acc += v;
                tip += ' · ' + cname(k) + ' ' + v;
            });
            if (!d.total) { g += '<rect class="zero" x="' + (cx - bw / 2).toFixed(1) + '" y="' + (y(0) - 1) + '" width="' + bw.toFixed(1) + '" height="1"/>'; }
            g += '<rect class="hit" x="' + (cx - cw / 2).toFixed(1) + '" y="' + pt + '" width="' + cw.toFixed(1) + '" height="' + (H - pt - pb) + '"><title>' + esc(tip) + '</title></rect>';
            if (labels.indexOf(i) > -1) { g += '<text class="xl" x="' + Math.min(Math.max(cx, pl + 16), W - pr - 16) + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(ymdLabel(d.date)) + '</text>'; }
        });
        return '<svg class="rwsc-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Parcels per day by courier">' + g + '</svg>';
    }

    function donut(parts, center, sub) {
        var total = parts.reduce(function (a, p) { return a + p.v; }, 0) || 1, R = 58, r = 40, cx = 70, cy = 70, a0 = -Math.PI / 2, g = '';
        var P = function (rad, a) { return (cx + rad * Math.cos(a)).toFixed(2) + ',' + (cy + rad * Math.sin(a)).toFixed(2); };
        if (!parts.some(function (p) { return p.v; })) {
            g += '<circle cx="70" cy="70" r="49" fill="none" stroke="var(--rw-line)" stroke-width="18"/>';
        }
        parts.forEach(function (p) {
            var f = p.v / total;
            if (!f) { return; }
            var a1 = a0 + f * Math.PI * 2 - (f >= 1 ? 0.0001 : 0), large = a1 - a0 > Math.PI ? 1 : 0;
            g += '<path d="M' + P(R, a0) + 'A' + R + ',' + R + ' 0 ' + large + ' 1 ' + P(R, a1) + 'L' + P(r, a1) + 'A' + r + ',' + r + ' 0 ' + large + ' 0 ' + P(r, a0) + 'Z" fill="' + p.color + '"><title>' + esc(p.name + ': ' + p.v + ' (' + Math.round(f * 100) + '%)') + '</title></path>';
            a0 = a1;
        });
        g += '<text x="70" y="' + (sub ? 70 : 76) + '" text-anchor="middle" class="dc">' + esc(center) + '</text>';
        if (sub) { g += '<text x="70" y="88" text-anchor="middle" class="ds">' + esc(sub) + '</text>'; }
        return '<svg class="rwsc-donut" viewBox="0 0 140 140" role="img" aria-label="Courier share">' + g + '</svg>';
    }

    function hbars(rows, fmt) {
        var max = Math.max.apply(null, [1].concat(rows.map(function (r) { return r.v; })));
        return '<div class="rwsc-hbars">' + rows.map(function (r) {
            return '<div class="hb' + (r.href ? ' is-link' : '') + '"' + (r.href ? ' data-href="' + esc(r.href) + '" tabindex="0" role="link"' : '') + '><span class="hb-l">' + (r.dot ? '<i style="background:' + r.dot + '"></i>' : '') + esc(r.name) + '</span><span class="hb-t"><span style="width:' + Math.max(r.v ? 3 : 0, r.v * 100 / max).toFixed(1) + '%;background:' + (r.color || 'var(--rw-pri)') + '"></span></span><b>' + esc(fmt ? fmt(r.v) : r.v) + '</b></div>';
        }).join('') + '</div>';
    }

    /* ------------------------------------------------------------------ */
    /* Rate calculator (dashboard + zone tester)                          */
    /* ------------------------------------------------------------------ */

    function districtOptions(sel) {
        var byDiv = {};
        (C.districts || []).forEach(function (d) { (byDiv[d[3]] = byDiv[d[3]] || []).push(d); });
        return Object.keys(byDiv).map(function (div) {
            return '<optgroup label="' + esc(div) + '">' + byDiv[div].map(function (d) {
                return '<option value="' + d[0] + '"' + (d[0] === sel ? ' selected' : '') + '>' + esc(d[1] + ' — ' + d[2]) + '</option>';
            }).join('') + '</optgroup>';
        }).join('');
    }

    function quoteCard(q, free) {
        var badges = [];
        var T = (C.display && C.display.badgeText) || {};
        if (q.recommended && T.recommended) { badges.push('<span class="rwsc-badge b-rec">' + esc(T.recommended) + '</span>'); }
        if (q.fastest && T.fastest) { badges.push('<span class="rwsc-badge b-fast">' + esc(T.fastest) + '</span>'); }
        if (q.cheapest && T.cheapest) { badges.push('<span class="rwsc-badge b-cheap">' + esc(T.cheapest) + '</span>'); }
        var price = q.free_applied ? '<del>' + money(q.normal_cost) + '</del><b class="free">Free</b>' : '<b>' + money(q.cost) + '</b>';
        var why = q.reason === 'zone' ? 'Not offered in this zone' : q.reason === 'weight' ? 'Over max weight' : '';
        return '<div class="rwsc-q' + (q.available ? '' : ' is-na') + (q.recommended ? ' is-rec' : '') + '" style="--c:' + esc(q.color) + '">' +
            '<div class="q-top"><i class="q-dot"></i><span class="q-name">' + esc(q.name) + '</span>' + badges.join('') + '<span class="q-price">' + (q.available ? price : '<em>' + esc(why) + '</em>') + '</span></div>' +
            '<div class="q-sub">' + (q.eta ? '<span>' + ICON.clock + esc(q.eta) + '</span>' : '<span class="muted">No ETA set</span>') + (q.edd ? '<span>' + ICON.truck + 'Arrives ' + esc(q.edd.label) + '</span>' : '') + '</div></div>';
    }

    function mountCalc(el, opts) {
        opts = opts || {};
        el.innerHTML =
            '<div class="rwsc-calc-form">' +
            '<label>District<select data-f="state">' + districtOptions(opts.state || 'BD-13') + '</select></label>' +
            '<label>Area / Town<input data-f="city" type="text" placeholder="e.g. Mirpur, Savar, সাভার" value="' + esc(opts.city || '') + '"></label>' +
            '<label>Address (optional)<input data-f="address" type="text" placeholder="House, road, area…"></label>' +
            '<label>Weight (kg)<span class="rwsc-step"><button type="button" data-step="-0.5" aria-label="Less">−</button><input data-f="weight" type="number" min="0.1" step="0.1" value="' + (opts.weight || 1) + '"><button type="button" data-step="0.5" aria-label="More">+</button></span></label>' +
            '<label class="rwsc-inline"><input data-f="free" type="checkbox"> Free shipping applies</label>' +
            '</div><div class="rwsc-calc-out" aria-live="polite"><div class="rwsc-skel"></div></div>';
        var out = $('.rwsc-calc-out', el), seq = 0;
        var run = debounce(function () {
            var f = {};
            $$('[data-f]', el).forEach(function (i) { f[i.getAttribute('data-f')] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
            var my = ++seq;
            out.classList.add('is-busy');
            api('quote', f).then(function (d) {
                if (my !== seq) { return; }
                out.classList.remove('is-busy');
                if (!d.zone) { out.innerHTML = '<div class="rwsc-empty">This address is outside the courier engine (not Bangladesh).</div>'; return; }
                var reason = { dhaka_area: 'matched area “' + d.area + '”', nearby_area: 'matched area “' + d.area + '”', nearby_district: 'nearby district', district: 'district', city: 'city name', unknown: 'district not recognised' }[d.reason] || '';
                out.innerHTML = '<div class="rwsc-calc-sum"><span class="rwsc-zone z-' + esc(d.zone) + '">' + esc(d.zone_label) + '</span><span>' + ICON.pin + esc(d.district_name || '—') + (reason ? ' <small>(' + esc(reason) + ')</small>' : '') + '</span><span>' + ICON.box + num(d.weight, d.weight % 1 ? 2 : 0) + ' kg</span><span>' + ICON.truck + 'Hand-over ' + esc(d.dispatch) + '</span></div>' +
                    (d.quotes.length ? d.quotes.map(function (q) { return quoteCard(q, d.free); }).join('') : '<div class="rwsc-empty">No courier is enabled.</div>');
                if (opts.onQuote) { opts.onQuote(d); }
            }).catch(function (e) { out.classList.remove('is-busy'); out.innerHTML = '<div class="rwsc-empty bad">' + esc(e.message) + '</div>'; });
        }, 280);
        el.addEventListener('input', run);
        el.addEventListener('change', run);
        el.addEventListener('click', function (e) {
            var b = e.target.closest('[data-step]');
            if (!b) { return; }
            var w = $('[data-f=weight]', el), v = Math.max(0.1, Math.round(((Number(w.value) || 0) + Number(b.getAttribute('data-step'))) * 10) / 10);
            w.value = v; run();
        });
        run();
        return { run: run };
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard                                                          */
    /* ------------------------------------------------------------------ */

    var D = { days: 30, data: null, loading: false };
    try { D.days = Number(localStorage.getItem('rwsc_days')) || 30; } catch (e) { /* ignore */ }

    function healthHtml(list, compact) {
        if (!list || !list.length) { return compact ? '' : '<div class="rwsc-health ok"><div class="h-row good">' + ICON.check + '<div><b>Setup looks good</b><span>Couriers are configured and a Bangladesh shipping zone exists.</span></div></div></div>'; }
        return '<div class="rwsc-health">' + list.filter(function (h) { return !compact || h.level !== 'info'; }).map(function (h) {
            var link = h.url ? '<a href="' + esc(h.url) + '">Fix →</a>' : (h.link && C.canManage ? '<a href="' + esc(C.urls.settings + '#' + h.link) + '">Open settings →</a>' : '');
            return '<div class="h-row ' + h.level + '">' + (h.level === 'info' ? ICON.info : ICON.warn) + '<div><b>' + esc(h.title) + '</b><span>' + esc(h.text) + '</span></div>' + link + '</div>';
        }).join('') + '</div>';
    }

    function delta(nowV, prevV) {
        if (!prevV) { return nowV ? '<span class="d up">new</span>' : ''; }
        var p = Math.round((nowV - prevV) * 100 / prevV);
        return '<span class="d ' + (p > 0 ? 'up' : p < 0 ? 'down' : 'flat') + '">' + (p > 0 ? '▲' : p < 0 ? '▼' : '•') + ' ' + Math.abs(p) + '%</span>';
    }

    function shipUrl(tab, extra) {
        return C.urls.shipments + '#tab=' + tab + (extra || '');
    }

    function renderDashboard(root) {
        var d = D.data, k = d.kpi, periodLabel = D.days === 1 ? 'today' : 'last ' + D.days + ' days';
        var fin = k.delivered + k.returned;
        var cards = [
            { tone: 'indigo', icon: ICON.box, label: 'Courier orders', value: num(k.orders), sub: delta(k.orders, d.prev.orders) + ' vs previous period', href: shipUrl('all') },
            { tone: 'teal', icon: ICON.cash, label: 'Shipping collected', value: money(k.shipping), sub: delta(k.shipping, d.prev.shipping) + ' · COD ' + compactMoney(k.cod), href: '' },
            { tone: 'amber', icon: ICON.clock, label: 'Awaiting booking', value: num(k.pending), sub: k.stale ? '<span class="d down">' + k.stale + ' waiting ' + C.staleDays + 'd+</span>' : 'All fresh', href: shipUrl('ready'), alert: k.stale > 0 },
            { tone: 'sky', icon: ICON.truck, label: 'With courier', value: num(k.active), sub: compactMoney(k.cod_field) + ' COD in the field', href: shipUrl('active') },
            { tone: 'green', icon: ICON.check, label: 'Delivered', value: num(k.delivered), sub: k.success === null ? 'No finished parcels yet' : '<span class="d up">' + k.success + '%</span> success rate', href: shipUrl('delivered') },
            { tone: 'rose', icon: ICON.undo, label: 'Returned', value: num(k.returned), sub: fin ? Math.round(k.returned * 1000 / fin) / 10 + '% return rate' : 'No returns', href: shipUrl('returned') },
            { tone: 'violet', icon: ICON.bolt, label: 'Avg. delivery time', value: k.avg_days === null ? '—' : k.avg_days + ' d', sub: 'Booked → delivered', href: '' },
            { tone: 'pink', icon: ICON.gift, label: 'Free-shipping cost', value: money(k.subsidy), sub: k.free_orders + ' free-shipping orders', href: '' }
        ];
        var keys = Object.keys(d.courier_meta || {});
        var leaders = d.couriers || [];
        var totalOrders = k.orders;

        var html = '';
        html += healthHtml(C.health, true);
        html += '<div class="rwsc-toolbar"><div class="rwsc-seg" role="tablist">' + [[1, 'Today'], [7, '7 days'], [30, '30 days'], [90, '90 days']].map(function (p) {
            return '<button type="button" role="tab" aria-selected="' + (p[0] === D.days) + '" data-days="' + p[0] + '">' + p[1] + '</button>';
        }).join('') + '</div><span class="rwsc-upd">Updated ' + ago(d.generated) + '</span><button type="button" class="rwsc-btn rwsc-btn-ghost rwsc-icon-text" data-act="refresh">' + ICON.refresh + 'Refresh</button>' +
            '<a class="rwsc-btn rwsc-btn-primary rwsc-icon-text" href="' + esc(C.urls.shipments) + '">' + ICON.list + 'Open shipments' + (d.attention.ready ? ' <span class="rwsc-count-pill">' + d.attention.ready + '</span>' : '') + '</a></div>';

        html += '<div class="rwsc-kpis">' + cards.map(function (c) {
            var tag = c.href ? 'a' : 'div';
            return '<' + tag + ' class="rwsc-kpi t-' + c.tone + (c.alert ? ' is-alert' : '') + '"' + (c.href ? ' href="' + esc(c.href) + '"' : '') + '><span class="k-ic">' + c.icon + '</span><span class="k-l">' + esc(c.label) + '</span><b class="k-v">' + c.value + '</b><span class="k-s">' + c.sub + '</span></' + tag + '>';
        }).join('') + '</div>';

        html += '<div class="rwsc-grid g-21">';
        html += '<section class="rwsc-card"><div class="c-h"><h3>Parcels per day</h3><span>' + esc(periodLabel) + '</span></div><div class="rwsc-legend">' + keys.filter(function (x) { return leaders.some(function (l) { return l.key === x; }); }).map(function (x) { return '<span><i style="background:' + ccolor(x) + '"></i>' + esc(cname(x)) + '</span>'; }).join('') + '</div><div class="c-chart" id="rwsc-daily"></div></section>';
        html += '<section class="rwsc-card"><div class="c-h"><h3>Courier share</h3><span>' + num(totalOrders) + ' parcels</span></div><div class="rwsc-donut-wrap">' +
            donut(leaders.map(function (l) { return { name: l.name, v: l.orders, color: l.color }; }), num(totalOrders), 'parcels') +
            '<ul class="rwsc-dl">' + (leaders.length ? leaders.map(function (l) { return '<li><i style="background:' + l.color + '"></i><span>' + esc(l.name) + '</span><b>' + l.share + '%</b></li>'; }).join('') : '<li class="muted">No courier orders in this period.</li>') + '</ul></div></section>';
        html += '</div>';

        html += '<section class="rwsc-card"><div class="c-h"><h3>Courier performance</h3><span>' + esc(periodLabel) + '</span></div>' + (leaders.length ? '<div class="rwsc-table-wrap"><table class="rwsc-table"><thead><tr><th>Courier</th><th class="n">Orders</th><th>Share</th><th class="n">Shipping</th><th class="n">Pending</th><th class="n">With courier</th><th class="n">Delivered</th><th class="n">Returned</th><th class="n">Success</th><th class="n">Avg days</th></tr></thead><tbody>' +
            leaders.map(function (l) {
                var sc = l.success === null ? '—' : '<span class="rwsc-sc ' + (l.success >= 90 ? 'good' : l.success >= 75 ? 'mid' : 'bad') + '">' + l.success + '%</span>';
                return '<tr><td><a class="rwsc-cn" href="' + esc(shipUrl('all', '&courier=' + l.key)) + '"><i style="background:' + l.color + '"></i>' + esc(l.name) + '</a></td><td class="n">' + num(l.orders) + '</td><td><span class="rwsc-mini-bar"><span style="width:' + l.share + '%;background:' + l.color + '"></span></span></td><td class="n">' + money(l.shipping) + '</td><td class="n">' + num(l.pending) + '</td><td class="n">' + num(l.active) + '</td><td class="n">' + num(l.delivered) + '</td><td class="n">' + num(l.returned) + '</td><td class="n">' + sc + '</td><td class="n">' + (l.avg_days === null ? '—' : l.avg_days) + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<div class="rwsc-empty">Courier orders will appear here once customers choose couriers at checkout (or you assign them on the Shipments board).</div>') + '</section>';
        var st = d.statuses || {};
        html += '<div class="rwsc-grid g-11">';
        html += '<section class="rwsc-card"><div class="c-h"><h3>Shipment pipeline</h3><span>' + esc(periodLabel) + '</span></div>' +
            hbars((C.statuses || []).map(function (s) { return { name: s.label, v: st[s.key] || 0, color: s.color, dot: s.color, href: shipUrl(s.key === 'pending' ? 'ready' : s.key) }; })) + '</section>';

        var z = d.zones || {};
        html += '<section class="rwsc-card"><div class="c-h"><h3>Where parcels go</h3></div>' +
            hbars([{ name: C.zones.dhaka, v: z.dhaka || 0, color: '#6366f1', dot: '#6366f1' }, { name: C.zones.nearby, v: z.nearby || 0, color: '#06b6d4', dot: '#06b6d4' }, { name: C.zones.outside, v: z.outside || 0, color: '#f59e0b', dot: '#f59e0b' }]) +
            '<h4 class="rwsc-subh">Top districts</h4>' + (d.districts.length ? hbars(d.districts.map(function (x) { return { name: x.name, v: x.orders, color: 'linear-gradient(90deg,#818cf8,#22d3ee)' }; })) : '<div class="rwsc-empty">No data yet.</div>') + '</section>';
        html += '</div>';
        html += '<section class="rwsc-card rwsc-calc-card"><div class="c-h"><h3>Rate calculator</h3><span>Live quotes from your saved rates</span></div><div class="rwsc-calc" id="rwsc-calc"></div></section>';

        var att = d.attention.rows || [];
        html += '<section class="rwsc-card"><div class="c-h"><h3>Needs attention</h3><span>' + (att.length ? att.length + ' orders' : '') + '</span></div>' + (att.length ? '<div class="rwsc-att">' + att.map(function (r) {
            var s = r.ship;
            return '<div class="att-row" data-id="' + r.id + '"><a href="' + esc(r.edit_url) + '" class="att-no">#' + esc(r.number) + '</a><span class="att-who"><b>' + esc(r.name) + '</b><small>' + esc([r.district_name || r.city, dateShort(r.created)].filter(Boolean).join(' · ')) + '</small></span>' +
                (s.courier ? '<span class="rwsc-pill" style="--c:' + s.color + '"><i></i>' + esc(s.courier_name) + '</span><span class="att-why warn">Waiting ' + ago(r.created).replace(' ago', '') + '</span>' : '<span class="att-why bad">No courier</span>') +
                '<span class="att-act">' + (s.courier ? '' : '<button type="button" class="rwsc-btn rwsc-btn-ghost sm" data-act="assign" data-id="' + r.id + '">Assign recommended</button>') + '<a class="rwsc-btn rwsc-btn-ghost sm" href="' + esc(shipUrl('ready')) + '">Book</a></span></div>';
        }).join('') + '</div>' : '<div class="rwsc-allgood">' + ICON.check + '<span>Nothing is stuck – every ready order has a courier and nothing is waiting longer than ' + C.staleDays + ' days.</span></div>') + '</section>';

        root.innerHTML = html;
        drawDaily();
        mountCalc($('#rwsc-calc', root), {});
    }

    function drawDaily() {
        var box = $('#rwsc-daily');
        if (!box || !D.data) { return; }
        var keys = Object.keys(D.data.courier_meta || {});
        var days = D.data.daily || [];
        if (D.days === 1) {
            box.innerHTML = hbars((D.data.couriers || []).map(function (l) { return { name: l.name, v: l.orders, color: l.color, dot: l.color }; })) || '';
            if (!(D.data.couriers || []).length) { box.innerHTML = '<div class="rwsc-empty">No courier orders today yet.</div>'; }
            return;
        }
        box.innerHTML = stackedBars(days, keys, widthOf(box, 640));
    }

    function loadDashboard(root, quiet) {
        if (D.loading) { return; }
        D.loading = true;
        if (!quiet && D.data) { root.classList.add('is-busy'); }
        api('dashboard', { days: D.days }).then(function (data) {
            D.data = data;
            renderDashboard(root);
        }).catch(function (e) {
            root.innerHTML = '<div class="rwsc-empty bad">' + esc(e.message) + '</div>';
        }).then(function () { D.loading = false; root.classList.remove('is-busy'); });
    }

    function initDashboard(root) {
        loadDashboard(root);
        root.addEventListener('click', function (e) {
            var p = e.target.closest('[data-days]');
            if (p) {
                D.days = Number(p.getAttribute('data-days'));
                try { localStorage.setItem('rwsc_days', D.days); } catch (err) { /* ignore */ }
                loadDashboard(root);
                return;
            }
            if (e.target.closest('[data-act=refresh]')) { loadDashboard(root); return; }
            var hb = e.target.closest('.hb[data-href]');
            if (hb) { window.location.href = hb.getAttribute('data-href'); return; }
            var as = e.target.closest('[data-act=assign]');
            if (as) {
                as.disabled = true;
                api('update_shipment', { order_id: as.getAttribute('data-id'), courier: 'auto' }).then(function (r) {
                    toast('#' + r.number + ' → ' + r.ship.courier_name);
                    loadDashboard(root, true);
                }).catch(function (err) { as.disabled = false; toast(err.message, 'bad'); });
            }
        });
        root.addEventListener('keydown', function (e) {
            var hb = e.target.closest('.hb[data-href]');
            if (hb && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); window.location.href = hb.getAttribute('data-href'); }
        });
        window.addEventListener('resize', debounce(drawDaily, 200));
        setInterval(function () { if (!document.hidden) { loadDashboard(root, true); } }, 120000);
    }

    /* ------------------------------------------------------------------ */
    /* Shipments board                                                    */
    /* ------------------------------------------------------------------ */

    var TABS = [
        ['ready', 'Ready to book'], ['active', 'With courier'], ['booked', 'Booked'], ['picked', 'Picked up'], ['in_transit', 'In transit'],
        ['hold', 'On hold'], ['delivered', 'Delivered'], ['returned', 'Returned'], ['unassigned', 'No courier'], ['all', 'All']
    ];
    var S = { tab: 'ready', courier: '', zone: '', q: '', days: 30, page: 1, per_page: 25, data: null, sel: {}, busy: false };

    function readHash() {
        var h = window.location.hash.replace(/^#/, '');
        h.split('&').forEach(function (p) {
            var kv = p.split('=');
            if (kv.length !== 2) { return; }
            var k = decodeURIComponent(kv[0]), v = decodeURIComponent(kv[1]);
            if (k === 'tab' && TABS.some(function (t) { return t[0] === v; })) { S.tab = v; }
            if (k === 'courier') { S.courier = v; }
            if (k === 'zone') { S.zone = v; }
            if (k === 'q') { S.q = v; }
            if (k === 'days') { S.days = Number(v) || 30; }
        });
    }
    function writeHash() {
        var parts = ['tab=' + S.tab];
        if (S.courier) { parts.push('courier=' + encodeURIComponent(S.courier)); }
        if (S.zone) { parts.push('zone=' + S.zone); }
        if (S.q) { parts.push('q=' + encodeURIComponent(S.q)); }
        if (S.days !== 30) { parts.push('days=' + S.days); }
        history.replaceState(null, '', '#' + parts.join('&'));
    }
    function filterQuery() {
        return '&tab=' + S.tab + '&courier=' + encodeURIComponent(S.courier) + '&zone=' + S.zone + '&q=' + encodeURIComponent(S.q) + '&days=' + S.days;
    }
    function selectedIds() { return Object.keys(S.sel).filter(function (k) { return S.sel[k]; }); }

    function courierSelect(cur) {
        var opts = '<option value="">— choose —</option><option value="auto">★ Recommended</option>' + (C.couriers || []).filter(function (c) { return c.enabled || c.key === cur; }).map(function (c) {
            return '<option value="' + esc(c.key) + '"' + (c.key === cur ? ' selected' : '') + '>' + esc(c.name) + '</option>';
        }).join('');
        return '<select class="rwsc-sel-courier" data-f="courier" aria-label="Courier" style="--c:' + ccolor(cur) + '">' + opts + '</select>';
    }
    function statusSelect(cur) {
        return '<select class="rwsc-sel-status" data-f="status" aria-label="Shipment status" style="--s:' + (STATUS[cur] ? STATUS[cur].color : '#94a3b8') + '">' + (C.statuses || []).map(function (s) {
            return '<option value="' + s.key + '"' + (s.key === (cur || 'pending') ? ' selected' : '') + '>' + esc(s.label) + '</option>';
        }).join('') + '</select>';
    }

    function rowHtml(r) {
        var s = r.ship, sel = !!S.sel[r.id];
        var codHtml = r.cod > 0 ? '<b class="cod">' + money(r.cod) + '</b><small>Collect (COD)</small>' : '<b class="paid">Paid</b><small>' + esc(r.payment || '') + '</small>';
        var weight = s.weight || 0;
        return '<div class="rwsc-row' + (sel ? ' is-sel' : '') + (r.stale ? ' is-stale' : '') + '" data-id="' + r.id + '" style="--c:' + esc(s.courier ? s.color : '#cbd5e1') + '">' +
            '<label class="r-chk"><input type="checkbox" data-sel="' + r.id + '"' + (sel ? ' checked' : '') + ' aria-label="Select order ' + esc(r.number) + '"></label>' +
            '<div class="r-order"><a href="' + esc(r.edit_url) + '" class="r-no">#' + esc(r.number) + '</a><small>' + esc(dateShort(r.created)) + '</small><span class="rwsc-ost ost-' + esc(r.status) + '">' + esc(r.status_name) + '</span>' + (r.stale ? '<span class="r-stale">' + ICON.clock + ago(r.created).replace(' ago', '') + '</span>' : '') + '</div>' +
            '<div class="r-cust"><b>' + esc(r.name) + '</b><a href="tel:' + esc(r.phone) + '" class="r-ph">' + ICON.phone + esc(r.phone) + '</a><span class="r-addr">' + esc([r.address, r.city].filter(Boolean).join(', ')) + '</span><span class="r-dist">' + (s.zone ? '<span class="rwsc-zone z-' + esc(s.zone) + '">' + esc(s.zone_label) + '</span>' : '') + esc(r.district_name || '') + '</span></div>' +
            '<div class="r-amt">' + codHtml + '<small>' + r.item_count + ' item' + (r.item_count === 1 ? '' : 's') + (weight ? ' · ' + num(weight, weight % 1 ? 2 : 0) + ' kg' : '') + '</small></div>' +
            '<div class="r-courier">' + courierSelect(s.courier) + (s.eta ? '<small>' + esc(s.eta) + '</small>' : '') + '</div>' +
            '<div class="r-track"><div class="r-tin"><input type="text" data-f="tracking" value="' + esc(s.tracking) + '" placeholder="Tracking / CN no." autocomplete="off" spellcheck="false" aria-label="Tracking number">' + (s.tracking_url ? '<a href="' + esc(s.tracking_url) + '" target="_blank" rel="noopener noreferrer" title="Open tracking" class="r-tl">' + ICON.ext + '</a>' : '') + '</div>' + (s.edd_label && ['delivered', 'returned'].indexOf(s.status) < 0 ? '<small>ETA ' + esc(s.edd_label) + '</small>' : '') + '</div>' +
            '<div class="r-status">' + statusSelect(s.status) + (s.updated_at ? '<small>' + esc(ago(s.updated_at)) + '</small>' : '') + '</div>' +
            '<div class="r-act"><button type="button" class="rwsc-icon-btn" data-act="detail" title="Details & timeline" aria-label="Details">' + ICON.list + '</button><button type="button" class="rwsc-icon-btn" data-act="copy" title="Copy parcel info for the courier panel" aria-label="Copy parcel info">' + ICON.copy + '</button><a class="rwsc-icon-btn" href="' + esc(C.urls.print + '&ids=' + r.id) + '" target="_blank" rel="noopener" title="Print label" aria-label="Print label">' + ICON.print + '</a></div>' +
            '</div>';
    }

    function parcelText(r) {
        var s = r.ship;
        return [
            'Invoice: #' + r.number,
            'Name: ' + r.name,
            'Phone: ' + r.phone,
            'Address: ' + [r.address, r.city, r.district_name].filter(Boolean).join(', '),
            'COD: ' + (r.cod > 0 ? money(r.cod) : '0 (paid)'),
            'Items: ' + (r.items || []).join(', '),
            (s.weight ? 'Weight: ' + s.weight + ' kg' : ''),
            (s.note || r.customer_note ? 'Note: ' + [s.note, r.customer_note].filter(Boolean).join(' · ') : '')
        ].filter(Boolean).join('\n');
    }

    function renderShipmentsShell(root) {
        var courierOpts = '<option value="">All couriers</option>' + (C.couriers || []).map(function (c) { return '<option value="' + esc(c.key) + '"' + (S.courier === c.key ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('');
        var zoneOpts = '<option value="">All zones</option>' + Object.keys(C.zones).map(function (z) { return '<option value="' + z + '"' + (S.zone === z ? ' selected' : '') + '>' + esc(C.zones[z]) + '</option>'; }).join('');
        var dayOpts = [[7, 'Last 7 days'], [30, 'Last 30 days'], [90, 'Last 90 days'], [0, 'All time']].map(function (d) { return '<option value="' + d[0] + '"' + (S.days === d[0] ? ' selected' : '') + '>' + d[1] + '</option>'; }).join('');
        root.innerHTML =
            '<div class="rwsc-tabstrip" role="tablist" id="rwsc-tabs"></div>' +
            '<div class="rwsc-toolbar rwsc-filters">' +
            '<label class="rwsc-search">' + ICON.search + '<input type="search" id="rwsc-q" placeholder="Search order, name, phone, tracking, area…" value="' + esc(S.q) + '"></label>' +
            '<select id="rwsc-f-courier" aria-label="Courier">' + courierOpts + '</select>' +
            '<select id="rwsc-f-zone" aria-label="Zone">' + zoneOpts + '</select>' +
            '<select id="rwsc-f-days" aria-label="Period">' + dayOpts + '</select>' +
            '<span class="rwsc-grow"></span>' +
            '<a class="rwsc-btn rwsc-btn-ghost rwsc-icon-text" data-out="labels" target="_blank" rel="noopener">' + ICON.print + 'Labels</a>' +
            '<a class="rwsc-btn rwsc-btn-ghost rwsc-icon-text" data-out="manifest" target="_blank" rel="noopener">' + ICON.list + 'Manifest</a>' +
            '<a class="rwsc-btn rwsc-btn-ghost rwsc-icon-text" data-out="csv">' + ICON.csv + 'CSV</a>' +
            '<button type="button" class="rwsc-icon-btn" data-act="reload" title="Refresh" aria-label="Refresh">' + ICON.refresh + '</button>' +
            '</div>' +
            '<div class="rwsc-bulk" id="rwsc-bulk" hidden><b id="rwsc-bulk-n"></b>' +
            '<select id="rwsc-bulk-courier" aria-label="Set courier"><option value="">Set courier…</option><option value="auto">★ Recommended</option>' + (C.couriers || []).filter(function (c) { return c.enabled; }).map(function (c) { return '<option value="' + esc(c.key) + '">' + esc(c.name) + '</option>'; }).join('') + '</select>' +
            '<select id="rwsc-bulk-status" aria-label="Set status"><option value="">Set status…</option>' + (C.statuses || []).map(function (s) { return '<option value="' + s.key + '">' + esc(s.label) + '</option>'; }).join('') + '</select>' +
            '<a class="rwsc-btn rwsc-btn-light" data-bout="labels" target="_blank" rel="noopener">' + ICON.print + 'Labels</a><a class="rwsc-btn rwsc-btn-light" data-bout="manifest" target="_blank" rel="noopener">' + ICON.list + 'Manifest</a><a class="rwsc-btn rwsc-btn-light" data-bout="csv">' + ICON.csv + 'CSV</a>' +
            '<button type="button" class="rwsc-btn rwsc-btn-light" data-act="clear-sel">Clear</button></div>' +
            '<div class="rwsc-listhead"><label class="r-chk"><input type="checkbox" id="rwsc-sel-all" aria-label="Select all on this page"></label><span>Order</span><span>Customer & address</span><span>Amount</span><span>Courier</span><span>Tracking</span><span>Status</span><span></span></div>' +
            '<div class="rwsc-list" id="rwsc-list"><div class="rwsc-loading"><span></span>Loading…</div></div>' +
            '<div class="rwsc-pager" id="rwsc-pager"></div>' +
            '<div class="rwsc-hint">Tip: type a tracking number and press <kbd>Enter</kbd> – it saves, marks the parcel <b>Booked</b> and jumps to the next row.</div>';
        updateOutLinks(root);
    }

    function updateOutLinks(root) {
        $$('[data-out]', root).forEach(function (a) { a.href = C.urls[a.getAttribute('data-out') === 'labels' ? 'print' : a.getAttribute('data-out')] + filterQuery(); });
        var ids = selectedIds().join(',');
        $$('[data-bout]', root).forEach(function (a) { a.href = C.urls[a.getAttribute('data-bout') === 'labels' ? 'print' : a.getAttribute('data-bout')] + '&ids=' + ids; });
    }

    function renderTabs() {
        var t = (S.data && S.data.tabs) || {};
        $('#rwsc-tabs').innerHTML = TABS.map(function (x) {
            var n = t[x[0]] || 0;
            return '<button type="button" role="tab" data-tab="' + x[0] + '" aria-selected="' + (S.tab === x[0]) + '" class="tab-' + x[0] + '">' + esc(x[1]) + '<span>' + n + '</span></button>';
        }).join('');
    }

    function renderList() {
        var list = $('#rwsc-list'), d = S.data;
        renderTabs();
        if (!d.rows.length) {
            var msg = S.q ? 'No shipments match “' + esc(S.q) + '”.' : S.tab === 'ready' ? 'Nothing waiting to be booked. 🎉' : 'No shipments here yet.';
            list.innerHTML = '<div class="rwsc-empty big">' + ICON.box + '<span>' + msg + '</span></div>';
        } else {
            list.innerHTML = d.rows.map(rowHtml).join('');
        }
        var pager = $('#rwsc-pager');
        pager.innerHTML = d.pages > 1 ? '<button type="button" class="rwsc-btn rwsc-btn-ghost" data-goto="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>← Prev</button><span>Page ' + d.page + ' of ' + d.pages + ' · ' + d.total + ' orders</span><button type="button" class="rwsc-btn rwsc-btn-ghost" data-goto="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>Next →</button>' : (d.total ? '<span>' + d.total + ' order' + (d.total === 1 ? '' : 's') + '</span>' : '');
        syncBulk();
    }

    function loadShipments(quiet) {
        var list = $('#rwsc-list');
        if (!quiet && list) { list.classList.add('is-busy'); }
        writeHash();
        updateOutLinks(document);
        return api('shipments', { tab: S.tab, courier: S.courier, zone: S.zone, q: S.q, days: S.days, page: S.page, per_page: S.per_page }).then(function (d) {
            S.data = d;
            renderList();
        }).catch(function (e) {
            if (list) { list.innerHTML = '<div class="rwsc-empty bad">' + esc(e.message) + '</div>'; }
        }).then(function () { if (list) { list.classList.remove('is-busy'); } });
    }

    function syncBulk() {
        var ids = selectedIds(), bar = $('#rwsc-bulk');
        if (!bar) { return; }
        bar.hidden = !ids.length;
        $('#rwsc-bulk-n').textContent = ids.length + ' selected';
        var all = $('#rwsc-sel-all'), rows = (S.data && S.data.rows) || [];
        if (all) {
            var onPage = rows.filter(function (r) { return S.sel[r.id]; }).length;
            all.checked = rows.length > 0 && onPage === rows.length;
            all.indeterminate = onPage > 0 && onPage < rows.length;
        }
        updateOutLinks(document);
    }

    function rowById(id) { return ((S.data && S.data.rows) || []).filter(function (r) { return String(r.id) === String(id); })[0]; }

    function inTab(r, tab) {
        var st = r.ship.status, ready = (C.ready || []).indexOf(r.status) > -1, active = ['booked', 'picked', 'in_transit', 'hold'].indexOf(st) > -1;
        switch (tab) {
            case 'ready': return ready && (!r.ship.courier || st === 'pending');
            case 'active': return !!r.ship.courier && active;
            case 'unassigned': return !r.ship.courier && ready;
            case 'all': return true;
            default: return !!r.ship.courier && st === tab;
        }
    }

    function saveRow(id, changes, el) {
        var rowEl = $('.rwsc-row[data-id="' + id + '"]');
        if (rowEl) { rowEl.classList.add('is-saving'); }
        var data = Object.assign({ order_id: id }, changes);
        return api('update_shipment', data).then(function (r) {
            var old = rowById(id);
            var idx = S.data.rows.indexOf(old);
            if (idx > -1) { S.data.rows[idx] = r; }
            // Keep tab counters roughly in step without a reload.
            if (old) {
                TABS.forEach(function (t) {
                    var was = inTab(old, t[0]), now = inTab(r, t[0]);
                    if (was !== now && S.data.tabs[t[0]] !== undefined) { S.data.tabs[t[0]] += now ? 1 : -1; }
                });
            }
            renderTabs();
            if (rowEl) {
                var tmp = document.createElement('div');
                tmp.innerHTML = rowHtml(r);
                var fresh = tmp.firstChild;
                if (!inTab(r, S.tab)) { fresh.classList.add('is-moved'); }
                rowEl.replaceWith(fresh);
                fresh.classList.add('is-saved');
                setTimeout(function () { fresh.classList.remove('is-saved'); }, 900);
            }
            var what = changes.tracking !== undefined ? 'Tracking saved' : changes.courier !== undefined ? 'Courier: ' + r.ship.courier_name : 'Status: ' + r.ship.status_label;
            toast('#' + r.number + ' · ' + what);
            return r;
        }).catch(function (e) {
            if (rowEl) { rowEl.classList.remove('is-saving'); }
            toast(e.message, 'bad');
            if (el && el.tagName === 'SELECT') { loadShipments(true); }
            throw e;
        });
    }

    /* Detail drawer */
    function openDrawer(id) {
        var r = rowById(id);
        if (!r) { return; }
        var s = r.ship;
        var wrap = document.createElement('div');
        wrap.className = 'rwsc-drawer-wrap';
        wrap.innerHTML = '<div class="rwsc-drawer-bg" data-close></div><aside class="rwsc-drawer" role="dialog" aria-modal="true" aria-label="Order #' + esc(r.number) + '" style="--c:' + esc(s.color) + '">' +
            '<header><div><small>Order</small><h2>#' + esc(r.number) + '</h2></div><button type="button" class="rwsc-icon-btn" data-close aria-label="Close">' + ICON.x + '</button></header>' +
            '<div class="dr-body">' +
            '<div class="dr-sec"><div class="dr-pills">' + (s.courier ? '<span class="rwsc-pill" style="--c:' + s.color + '"><i></i>' + esc(s.courier_name) + '</span>' : '<span class="rwsc-pill">No courier</span>') + '<span class="rwsc-st" style="--s:' + s.status_color + '">' + esc(s.status_label || 'Awaiting booking') + '</span><span class="rwsc-ost ost-' + esc(r.status) + '">' + esc(r.status_name) + '</span></div>' +
            '<dl class="dr-kv"><dt>Customer</dt><dd><b>' + esc(r.name) + '</b><br><a href="tel:' + esc(r.phone) + '">' + esc(r.phone) + '</a></dd><dt>Address</dt><dd>' + esc([r.address, r.city, r.district_name].filter(Boolean).join(', ')) + (s.zone_label ? '<br><span class="rwsc-zone z-' + esc(s.zone) + '">' + esc(s.zone_label) + '</span>' : '') + '</dd>' +
            '<dt>Amount</dt><dd>' + (r.cod > 0 ? '<b>' + money(r.cod) + '</b> to collect (COD)' : 'Paid · ' + esc(r.payment)) + '<br><small>Shipping charged ' + money(r.shipping) + (s.free_applied ? ' (free shipping – normal ' + money(s.normal_cost) + ')' : '') + '</small></dd>' +
            (s.eta ? '<dt>Delivery</dt><dd>' + esc(s.eta) + (s.edd_label ? '<br><small>Estimated ' + esc(s.edd_label) + '</small>' : '') + '</dd>' : '') +
            (s.tracking ? '<dt>Tracking</dt><dd><code>' + esc(s.tracking) + '</code>' + (s.tracking_url ? ' <a href="' + esc(s.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : '') + '</dd>' : '') +
            '</dl></div>' +
            '<div class="dr-sec"><h3>Items</h3><ul class="dr-items">' + (r.items || []).map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>' + (r.customer_note ? '<p class="dr-cnote"><b>Customer note:</b> ' + esc(r.customer_note) + '</p>' : '') + '</div>' +
            '<div class="dr-sec"><h3>Note for courier / label</h3><textarea class="rwsc-input" rows="2" id="rwsc-dr-note">' + esc(s.note) + '</textarea><div class="rwsc-btns"><button type="button" class="rwsc-btn rwsc-btn-ghost sm" data-dr="note">Save note</button><button type="button" class="rwsc-btn rwsc-btn-ghost sm" data-dr="copy">' + ICON.copy + ' Copy parcel info</button><a class="rwsc-btn rwsc-btn-ghost sm" href="' + esc(r.edit_url) + '">Edit order ↗</a></div></div>' +
            '<div class="dr-sec"><h3>Timeline</h3>' + (s.log && s.log.length ? '<ol class="dr-tl">' + s.log.slice().reverse().map(function (e) {
                var c = STATUS[e.s] ? STATUS[e.s].color : '#94a3b8';
                return '<li style="--s:' + c + '"><b>' + esc(e.m) + '</b><span>' + esc(dateShort(e.t)) + ' · ' + esc(e.u) + '</span></li>';
            }).join('') + '</ol>' : '<p class="muted">No shipment events yet.</p>') + '</div>' +
            '</div></aside>';
        document.body.appendChild(wrap);
        var prev = document.activeElement;
        requestAnimationFrame(function () { wrap.classList.add('open'); $('.rwsc-drawer [data-close]', wrap).focus(); });
        var close = function () {
            wrap.classList.remove('open');
            document.removeEventListener('keydown', onKey);
            setTimeout(function () { wrap.remove(); if (prev) { prev.focus(); } }, 220);
        };
        var onKey = function (e) { if (e.key === 'Escape') { close(); } };
        document.addEventListener('keydown', onKey);
        wrap.addEventListener('click', function (e) {
            if (e.target.closest('[data-close]')) { close(); return; }
            var b = e.target.closest('[data-dr]');
            if (!b) { return; }
            if (b.getAttribute('data-dr') === 'copy') { copy(parcelText(r)); }
            if (b.getAttribute('data-dr') === 'note') {
                b.disabled = true;
                saveRow(r.id, { note: $('#rwsc-dr-note', wrap).value }).then(function () { b.disabled = false; toast('Note saved'); }).catch(function () { b.disabled = false; });
            }
        });
    }

    function initShipments(root) {
        readHash();
        renderShipmentsShell(root);
        loadShipments();

        root.addEventListener('click', function (e) {
            var t = e.target.closest('[data-tab]');
            if (t) { S.tab = t.getAttribute('data-tab'); S.page = 1; loadShipments(); return; }
            var p = e.target.closest('[data-goto]');
            if (p && !p.disabled) { S.page = Number(p.getAttribute('data-goto')) || 1; loadShipments(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
            if (e.target.closest('[data-act=reload]')) { loadShipments(); return; }
            if (e.target.closest('[data-act=clear-sel]')) { S.sel = {}; $$('[data-sel]').forEach(function (c) { c.checked = false; c.closest('.rwsc-row').classList.remove('is-sel'); }); syncBulk(); return; }
            var a = e.target.closest('[data-act]');
            if (a) {
                var id = a.closest('.rwsc-row') && a.closest('.rwsc-row').getAttribute('data-id');
                if (a.getAttribute('data-act') === 'detail') { openDrawer(id); }
                if (a.getAttribute('data-act') === 'copy') { copy(parcelText(rowById(id))); }
            }
        });

        root.addEventListener('change', function (e) {
            var el = e.target;
            if (el.matches('[data-sel]')) {
                S.sel[el.getAttribute('data-sel')] = el.checked;
                el.closest('.rwsc-row').classList.toggle('is-sel', el.checked);
                syncBulk();
                return;
            }
            if (el.id === 'rwsc-sel-all') {
                (S.data.rows || []).forEach(function (r) { S.sel[r.id] = el.checked; });
                $$('[data-sel]').forEach(function (c) { c.checked = el.checked; c.closest('.rwsc-row').classList.toggle('is-sel', el.checked); });
                syncBulk();
                return;
            }
            if (el.id === 'rwsc-f-courier') { S.courier = el.value; S.page = 1; loadShipments(); return; }
            if (el.id === 'rwsc-f-zone') { S.zone = el.value; S.page = 1; loadShipments(); return; }
            if (el.id === 'rwsc-f-days') { S.days = Number(el.value); S.page = 1; loadShipments(); return; }
            if (el.id === 'rwsc-bulk-courier' || el.id === 'rwsc-bulk-status') {
                var ids = selectedIds(), v = el.value;
                if (!v || !ids.length) { return; }
                var field = el.id === 'rwsc-bulk-courier' ? 'courier' : 'status';
                var label = field === 'courier' ? (v === 'auto' ? 'the recommended courier' : cname(v)) : (STATUS[v] ? STATUS[v].label : v);
                if (!window.confirm('Set ' + field + ' to ' + label + ' for ' + ids.length + ' order(s)?')) { el.value = ''; return; }
                var payload = { ids: ids };
                payload[field] = v;
                el.disabled = true;
                api('bulk_shipments', payload).then(function (res) {
                    toast(res.updated + ' updated' + (res.errors.length ? ', ' + res.errors.length + ' failed' : ''), res.errors.length ? 'warn' : 'ok');
                    if (res.errors.length) { window.alert(res.errors.join('\n')); }
                    loadShipments(true);
                }).catch(function (err) { toast(err.message, 'bad'); }).then(function () { el.disabled = false; el.value = ''; });
                return;
            }
            var row = el.closest('.rwsc-row');
            if (row && el.matches('select[data-f]')) {
                var ch = {};
                ch[el.getAttribute('data-f')] = el.value;
                if (!el.value) { return; }
                saveRow(row.getAttribute('data-id'), ch, el).catch(function () {});
            }
        });

        // Tracking: save on Enter (then jump to next row) or on blur when changed.
        root.addEventListener('keydown', function (e) {
            var el = e.target;
            if (el.matches('input[data-f=tracking]') && e.key === 'Enter') {
                e.preventDefault();
                var row = el.closest('.rwsc-row'), id = row.getAttribute('data-id'), next = row.nextElementSibling;
                var r = rowById(id);
                var nextId = next && next.getAttribute('data-id');
                if (r && el.value.trim() === (r.ship.tracking || '')) { if (next) { $('input[data-f=tracking]', next).focus(); } return; }
                el.setAttribute('data-saving', '1');
                saveRow(id, { tracking: el.value.trim() }).then(function () {
                    var n = nextId && $('.rwsc-row[data-id="' + nextId + '"] input[data-f=tracking]');
                    if (n) { n.focus(); }
                }).catch(function () {});
            }
            if (el.id === 'rwsc-q' && e.key === 'Enter') { e.preventDefault(); S.q = el.value.trim(); S.page = 1; loadShipments(); }
        });
        root.addEventListener('focusout', function (e) {
            var el = e.target;
            if (!el.matches('input[data-f=tracking]') || el.getAttribute('data-saving')) { return; }
            var row = el.closest('.rwsc-row'), r = row && rowById(row.getAttribute('data-id'));
            if (r && el.value.trim() !== (r.ship.tracking || '')) { saveRow(r.id, { tracking: el.value.trim() }).catch(function () {}); }
        });
        root.addEventListener('input', debounce(function (e) {
            if (e.target.id === 'rwsc-q') { S.q = e.target.value.trim(); S.page = 1; loadShipments(); }
        }, 450));
        window.addEventListener('hashchange', function () { var before = S.tab; readHash(); if (before !== S.tab) { loadShipments(); } });
    }

    /* ------------------------------------------------------------------ */
    /* Settings page                                                      */
    /* ------------------------------------------------------------------ */

    function tagInput(ta) {
        var wrap = document.createElement('div');
        wrap.className = 'rwsc-taginput';
        var list = function () { return ta.value.split(/[,\n]+/).map(function (s) { return s.trim(); }).filter(Boolean); };
        var rendering = false;
        var render = function () {
            rendering = true;
            wrap.innerHTML = list().map(function (t, i) { return '<span class="rwsc-tag">' + esc(t) + '<button type="button" data-i="' + i + '" aria-label="Remove ' + esc(t) + '">×</button></span>'; }).join('') +
                '<input type="text" placeholder="' + esc(ta.getAttribute('data-placeholder') || 'Add…') + '" aria-label="' + esc(ta.getAttribute('data-placeholder') || 'Add') + '">';
            rendering = false;
        };
        var set = function (arr) { ta.value = arr.join(', '); ta.dispatchEvent(new Event('change', { bubbles: true })); render(); $('input', wrap).focus(); };
        ta.hidden = true;
        ta.parentNode.insertBefore(wrap, ta);
        render();
        wrap.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-i]');
            if (b) { var arr = list(); arr.splice(Number(b.getAttribute('data-i')), 1); set(arr); return; }
            $('input', wrap).focus();
        });
        wrap.addEventListener('keydown', function (e) {
            var inp = e.target;
            if (inp.tagName !== 'INPUT') { return; }
            if ((e.key === 'Enter' || e.key === ',') && inp.value.trim()) {
                e.preventDefault();
                var arr = list();
                inp.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (v) { if (arr.indexOf(v) < 0) { arr.push(v); } });
                set(arr);
            } else if (e.key === 'Backspace' && !inp.value) {
                var a2 = list(); a2.pop(); set(a2);
            } else if (e.key === 'Enter') { e.preventDefault(); }
        });
        wrap.addEventListener('focusout', function (e) {
            var inp = e.target;
            if (rendering) { return; }
            if (inp.tagName === 'INPUT' && inp.value.trim()) { var arr = list(); if (arr.indexOf(inp.value.trim()) < 0) { arr.push(inp.value.trim()); } set(arr); }
        });
    }

    function previewLabel(q, form) {
        var val = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
        var on = function (n) { var el = form.querySelector('input[type=checkbox][name="' + n + '"]'); return el ? el.checked : false; };
        var style = (form.querySelector('[name=label_style]:checked') || {}).value || 'detailed';
        var T = { recommended: val('badge_rec'), fastest: val('badge_fast'), cheapest: val('badge_cheap') };
        var price = q.free_applied ? '<del class="rwsc-old-price">' + money(q.normal_cost) + '</del>' : '<span class="rwsc-price">' + money(q.cost) + '</span>';
        var edd = on('show_edd') && q.edd ? 'Arrives ' + q.edd.label : '';
        if (style === 'compact') {
            return '<span class="rwsc-line" style="--rwsc-c:' + q.color + '"><span class="rwsc-name">' + esc(q.name) + '</span>' + (on('show_eta') && q.eta ? ' <span class="rwsc-sep">·</span> <span class="rwsc-eta">' + esc(q.eta) + '</span>' : '') + '<span class="rwsc-colon">:</span> ' + price + (on('show_badges') && q.recommended && T.recommended ? ' <span class="rwsc-rec">· ' + esc(T.recommended) + '</span>' : '') + '</span>';
        }
        var b = '';
        if (on('show_badges')) {
            ['recommended', 'fastest', 'cheapest'].forEach(function (k) { if (q[k] && T[k]) { b += '<span class="rwsc-badge rwsc-b-' + k + '">' + esc(T[k]) + '</span>'; } });
        }
        var sub = [];
        if (on('show_eta') && q.eta) { sub.push('<span class="rwsc-eta">' + esc(q.eta) + '</span>'); }
        if (edd) { sub.push('<span class="rwsc-edd">' + esc(edd) + '</span>'); }
        return '<span class="rwsc-opt" style="--rwsc-c:' + q.color + '"><span class="rwsc-top"><span class="rwsc-dot"></span><span class="rwsc-name">' + esc(q.name) + '</span>' + b + '<span class="rwsc-amt">' + price + '</span></span>' + (sub.length ? '<span class="rwsc-sub">' + sub.join('<span class="rwsc-sep"> · </span>') + '</span>' : '') + '</span>';
    }

    function initSettings() {
        var form = $('#rwsc-settings-form');
        if (!form) { return; }
        var tabs = $$('.rwsc-tabs [data-tab]'), panels = $$('.rwsc-panel-tab'), tabField = $('#rwsc-tab-field');
        var show = function (id) {
            if (!panels.some(function (p) { return p.getAttribute('data-panel') === id; })) { id = 'couriers'; }
            tabs.forEach(function (t) { t.setAttribute('aria-selected', String(t.getAttribute('data-tab') === id)); t.classList.toggle('is-active', t.getAttribute('data-tab') === id); });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== id; });
            tabField.value = id;
            $('#rwsc-savebar').hidden = id === 'tools';
        };
        show(window.location.hash.replace('#', '') || 'couriers');
        tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + t.getAttribute('data-tab')); show(t.getAttribute('data-tab')); }); });
        window.addEventListener('hashchange', function () { show(window.location.hash.replace('#', '')); });

        var dirty = false;
        var markDirty = function () { dirty = true; document.body.classList.add('rwsc-is-dirty'); };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

        // Courier cards.
        var grid = $('#rwsc-cc-grid');
        grid.addEventListener('change', function (e) {
            var card = e.target.closest('.rwsc-cc');
            if (!card) { return; }
            if (e.target.classList.contains('rwsc-cc-on')) { card.classList.toggle('is-off', !e.target.checked); }
        });
        grid.addEventListener('input', function (e) {
            if (e.target.classList.contains('rwsc-cc-color')) { e.target.closest('.rwsc-cc').style.setProperty('--c', e.target.value); }
        });
        grid.addEventListener('click', function (e) {
            var rm = e.target.closest('.rwsc-cc-remove');
            if (!rm) { return; }
            var card = rm.closest('.rwsc-cc');
            var name = $('.rwsc-cc-name', card).value || 'this courier';
            if (!window.confirm('Remove ' + name + '? It is deleted when you save.')) { return; }
            var del = $('.rwsc-cc-delete', card);
            if (del) { del.value = '1'; card.hidden = true; } else { card.remove(); }
            markDirty();
        });
        $('#rwsc-add-courier').addEventListener('click', function () {
            var key = 'c' + Date.now().toString(36);
            var html = $('#rwsc-cc-tpl').innerHTML.replace(/__KEY__/g, key);
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var card = tmp.firstElementChild;
            grid.appendChild(card);
            card.classList.add('is-new');
            $('.rwsc-cc-name', card).focus();
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            markDirty();
        });

        // District chips.
        var ndCount = function () { var n = $$('#rwsc-districts input:checked').length; $('#rwsc-nd-count').textContent = n + ' selected'; };
        ndCount();
        $('#rwsc-districts').addEventListener('change', ndCount);
        $$('.rwsc-filter').forEach(function (f) {
            f.addEventListener('input', function () {
                var q = f.value.trim().toLowerCase(), box = $(f.getAttribute('data-filter'));
                $$('.rwsc-chip', box).forEach(function (c) { c.hidden = q && c.getAttribute('data-s').indexOf(q) < 0; });
                $$('.rwsc-div', box).forEach(function (d) { d.hidden = !$$('.rwsc-chip', d).some(function (c) { return !c.hidden; }); });
            });
        });
        $$('textarea.rwsc-tags').forEach(tagInput);

        // Holidays helper.
        var hb = $('#rwsc-add-holidays');
        if (hb) {
            hb.addEventListener('click', function () {
                var ta = $('#rwsc-holidays'), now = storeNow(), lines = ta.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                var fixed = [[2, 21, 'Shaheed Day & International Mother Language Day'], [3, 26, 'Independence Day'], [4, 14, 'Pohela Boishakh'], [5, 1, 'May Day'], [12, 16, 'Victory Day'], [12, 25, 'Christmas Day']];
                var added = 0;
                fixed.forEach(function (f) {
                    var y = now.getUTCFullYear(), dt = Date.UTC(y, f[0] - 1, f[1]);
                    if (dt < Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate())) { y += 1; }
                    var ymd = y + '-' + zp(f[0]) + '-' + zp(f[1]);
                    if (!lines.some(function (l) { return l.indexOf(ymd) === 0; })) { lines.push(ymd + ' ' + f[2]); added++; }
                });
                lines.sort();
                ta.value = lines.join('\n');
                markDirty();
                toast(added ? added + ' holidays added – remember to save' : 'Already in the list');
            });
        }

        // Label preview.
        var pv = $('#rwsc-label-preview'), pq = null;
        var drawPreview = function () {
            if (!pv) { return; }
            if (!pq) { pv.innerHTML = '<div class="rwsc-skel"></div>'; return; }
            pv.innerHTML = '<ul class="rwsc-pv-list">' + pq.quotes.slice(0, 4).map(function (q, i) {
                return '<li><label><input type="radio" name="rwsc_pv" ' + (i === 0 ? 'checked' : '') + ' tabindex="-1"> ' + previewLabel(q, form) + '</label></li>';
            }).join('') + '</ul>';
        };
        if (pv) {
            api('quote', { state: 'BD-13', city: 'Mirpur', weight: 1 }).then(function (d) { pq = d; drawPreview(); }).catch(function (e) { pv.innerHTML = '<div class="rwsc-empty bad">' + esc(e.message) + '</div>'; });
            form.addEventListener('input', debounce(drawPreview, 120));
            form.addEventListener('change', drawPreview);
        }

        // Zone tester.
        var zt = $('#rwsc-zone-tester [data-calc]');
        if (zt) { mountCalc(zt, { state: 'BD-13', city: 'Savar' }); }

        // Tools.
        var health = $('#rwsc-health');
        if (health) { health.innerHTML = healthHtml(C.health, false); }
        $$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy($(b.getAttribute('data-copy')).value); }); });
        var dl = $('#rwsc-export-dl');
        if (dl) {
            dl.addEventListener('click', function () {
                var blob = new Blob([$('#rwsc-export').value], { type: 'application/json' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'smart-courier-settings-' + new Date().toISOString().slice(0, 10) + '.json';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
            });
        }
        var flash = $('.rwsc-flash');
        if (flash) { setTimeout(function () { flash.classList.add('out'); }, 4000); }
    }

    /* ------------------------------------------------------------------ */
    /* Boot                                                               */
    /* ------------------------------------------------------------------ */

    function boot() {
        tickClock();
        setInterval(tickClock, 1000);
        var root = $('#rwsc-root');
        if (root && root.getAttribute('data-page') === 'dashboard') { initDashboard(root); }
        if (root && root.getAttribute('data-page') === 'shipments') { initShipments(root); }
        initSettings();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
