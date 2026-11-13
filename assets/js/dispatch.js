/* RAR Woo Smart Courier — Dispatch — admin UI (vanilla JS, no CDN, no build step). */
(function () {
	'use strict';
	if (typeof RWSC_D === 'undefined') { return; }
	var T = RWSC_D.i18n;

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { n.textContent = attrs[k]; }
			else if (k === 'class') { n.className = attrs[k]; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), attrs[k]); }
			else { n.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', 'rwsc_d_' + action);
		body.append('nonce', RWSC_D.nonce);
		(function add(obj, prefix) {
			Object.keys(obj || {}).forEach(function (k) {
				var key = prefix ? prefix + '[' + k + ']' : k, v = obj[k];
				if (v !== null && typeof v === 'object') { add(v, key); } else { body.append(key, v === null ? '' : v); }
			});
		})(data || {}, '');
		return fetch(RWSC_D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'HTTP ' + r.status } }; }); });
	}

	function updateCells(orderId, html) {
		document.querySelectorAll('.rwsc-d-cell[data-order-id="' + orderId + '"]').forEach(function (c) { c.innerHTML = html; });
	}

	/* ---------------- Refresh ---------------- */
	function refreshOne(id, btn) {
		if (btn) { btn.disabled = true; }
		return post('refresh', { order_id: id }).then(function (r) {
			if (btn) { btn.disabled = false; }
			if (r.success) { updateCells(id, r.data.cell); } else if (btn) { window.alert(r.data && r.data.message ? r.data.message : 'Error'); }
			return r;
		});
	}

	function refreshMany(ids) {
		var overlay = el('div', { 'class': 'rwsc-d-overlay' });
		var status = el('div', { 'class': 'rwsc-d-progress', text: T.refreshing });
		var box = el('div', { 'class': 'rwsc-d-modal rwsc-d-modal-sm' }, [el('h2', { text: T.refreshing }), status,
			el('p', {}, [el('button', { 'class': 'button', text: T.cancel, onclick: function () { overlay.remove(); location.reload(); } })])]);
		overlay.appendChild(box); document.body.appendChild(overlay);
		var i = 0, errs = 0;
		(function next() {
			if (i >= ids.length) { status.textContent = T.done + ' (' + ids.length + ', ' + errs + ' ✗)'; return; }
			status.textContent = T.refreshing + ' ' + (i + 1) + '/' + ids.length;
			refreshOne(ids[i++]).then(function (r) { if (!r.success) { errs++; } next(); });
		})();
	}

	/* ---------------- Dispatch modal ---------------- */
	var S = null; // session state

	function closeModal() { var o = document.querySelector('.rwsc-d-overlay'); if (o) { o.remove(); } }

	function openDispatch(ids) {
		closeModal();
		var overlay = el('div', { 'class': 'rwsc-d-overlay' });
		var body = el('div', { 'class': 'rwsc-d-modal-body' }, [el('p', { text: T.loading })]);
		var footer = el('div', { 'class': 'rwsc-d-modal-foot' });
		var modal = el('div', { 'class': 'rwsc-d-modal', role: 'dialog', 'aria-modal': 'true' }, [
			el('div', { 'class': 'rwsc-d-modal-head' }, [el('h2', { text: T.title }), el('button', { 'class': 'rwsc-d-x', type: 'button', 'aria-label': T.cancel, text: '×', onclick: function () { closeModal(); } })]),
			body, footer
		]);
		overlay.appendChild(modal); document.body.appendChild(overlay);

		post('prepare', { order_ids: ids }).then(function (r) {
			if (!r.success) { body.innerHTML = ''; body.appendChild(el('p', { 'class': 'rwsc-d-error', text: (r.data && r.data.message) || 'Error' })); return; }
			S = { data: r.data, cards: {}, cache: {}, body: body, footer: footer };
			render();
		}).catch(function () { body.innerHTML = ''; body.appendChild(el('p', { 'class': 'rwsc-d-error', text: T.netError })); });
	}

	function locations(type, id) {
		var key = type + id;
		if (!S.cache[key]) {
			S.cache[key] = post('locations', { type: type, id: id }).then(function (r) { return r.success ? r.data.items : []; });
		}
		return S.cache[key];
	}

	function fillSelect(sel, items, value, placeholder) {
		sel.innerHTML = '';
		sel.appendChild(el('option', { value: '', text: placeholder }));
		items.forEach(function (it) {
			var o = el('option', { value: it.id, text: it.name });
			if (String(it.id) === String(value)) { o.selected = true; }
			sel.appendChild(o);
		});
	}

	function field(label, input, name) {
		return el('label', { 'class': 'rwsc-d-f rwsc-d-f-' + name }, [el('span', { text: label }), input, el('em', { 'class': 'rwsc-d-ferr', 'data-err': name })]);
	}

	function card(o) {
		var f = o.fields, c = { order: o, inputs: {} };
		function inp(name, type, extra) {
			var i = el(type === 'textarea' ? 'textarea' : 'input', Object.assign(type === 'textarea' ? { rows: 2 } : { type: type }, extra || {}));
			i.value = f[name] === undefined || f[name] === null ? '' : f[name];
			c.inputs[name] = i; return i;
		}
		function sel(name, options) {
			var s = el('select');
			Object.keys(options).forEach(function (k) { var op = el('option', { value: k, text: options[k] }); if (String(f[name]) === String(k)) { op.selected = true; } s.appendChild(op); });
			c.inputs[name] = s; return s;
		}
		var store = el('select'); fillSelect(store, S.data.stores, f.store_id, '—'); c.inputs.store_id = store;
		var city = el('select'), zone = el('select'), area = el('select');
		c.inputs.recipient_city = city; c.inputs.recipient_zone = zone; c.inputs.recipient_area = area;
		fillSelect(city, S.data.cities, f.recipient_city, 'Auto (Pathao detects)');
		fillSelect(zone, [], '', 'Auto'); fillSelect(area, [], '', 'Auto');

		function loadZones(zoneVal, areaVal) {
			if (!city.value) { fillSelect(zone, [], '', 'Auto'); fillSelect(area, [], '', 'Auto'); return Promise.resolve(); }
			return locations('zones', city.value).then(function (z) { fillSelect(zone, z, zoneVal, 'Auto'); return loadAreas(areaVal); });
		}
		function loadAreas(areaVal) {
			if (!zone.value) { fillSelect(area, [], '', 'Auto'); return Promise.resolve(); }
			return locations('areas', zone.value).then(function (a) { fillSelect(area, a, areaVal, 'Auto'); });
		}
		city.addEventListener('change', function () { loadZones('', ''); estimate(c); });
		zone.addEventListener('change', function () { loadAreas(''); estimate(c); });
		['item_weight', 'delivery_type', 'item_type'].forEach(function (n) { /* bound after creation */ });
		c.loadZones = loadZones;

		var weight = inp('item_weight', 'number', { step: '0.1', min: '0.5', max: '10' });
		var dtype = sel('delivery_type', RWSC_D.deliveryTypes), itype = sel('item_type', RWSC_D.itemTypes);
		[weight, dtype, itype, store].forEach(function (i) { i.addEventListener('change', function () { estimate(c); }); });

		var warn = el('div', { 'class': 'rwsc-d-warns' });
		o.warnings.forEach(function (w) { warn.appendChild(el('div', { 'class': 'rwsc-d-warn rwsc-d-warn-' + w.level, text: w.text })); });
		var sg = o.suggest;
		if (sg && sg.city_id && S.data.location_mode === 'auto') {
			var names = [sg.city_name, sg.zone_name, sg.area_name].filter(Boolean).join(' › ');
			warn.appendChild(el('div', { 'class': 'rwsc-d-warn rwsc-d-warn-info' }, [T.suggested + ': ' + names + ' ', el('button', { type: 'button', 'class': 'button-link', text: T.apply, onclick: function () {
				city.value = sg.city_id;
				loadZones(sg.zone_id, sg.area_id).then(function () { estimate(c); });
			} })]));
		}

		var items = el('div', { 'class': 'rwsc-d-items rwsc-d-muted', text: o.items.map(function (i) { return i.name + ' ×' + i.qty; }).join(', ') });
		c.status = el('div', { 'class': 'rwsc-d-result' });
		c.fee = el('div', { 'class': 'rwsc-d-fee rwsc-d-muted' });
		c.skip = el('input', { type: 'checkbox' });
		c.skip.checked = !o.sent && !(S.data.guard && o.courier && !/pathao/i.test(o.courier));
		if (o.sent) { c.skip.disabled = true; }

		var head = el('div', { 'class': 'rwsc-d-card-head' }, [
			el('label', {}, [c.skip, ' ', el('a', { href: o.edit_url, target: '_blank', rel: 'noopener', text: '#' + o.order_number }), ' · ' + o.total + ' · ' + o.payment_method + (o.courier ? ' · ' + o.courier : '')]),
			c.status
		]);
		var grid = el('div', { 'class': 'rwsc-d-grid' }, [
			field('Name', inp('recipient_name', 'text'), 'recipient_name'),
			field('Phone', inp('recipient_phone', 'text'), 'recipient_phone'),
			field('Alt phone', inp('recipient_secondary_phone', 'text'), 'recipient_secondary_phone'),
			field('Collect (৳)', inp('amount_to_collect', 'number', { min: '0', step: '1' }), 'amount_to_collect'),
			field('Weight (kg)', weight, 'item_weight'),
			field('Qty', inp('item_quantity', 'number', { min: '1' }), 'item_quantity'),
			field('Address', inp('recipient_address', 'textarea'), 'recipient_address'),
			field('City', city, 'recipient_city'), field('Zone', zone, 'recipient_zone'), field('Area', area, 'recipient_area'),
			field('Store', store, 'store_id'), field('Delivery', dtype, 'delivery_type'), field('Item', itype, 'item_type'),
			field('Description', inp('item_description', 'textarea'), 'item_description'),
			field('Instruction', inp('special_instruction', 'textarea'), 'special_instruction')
		]);
		c.node = el('div', { 'class': 'rwsc-d-card' + (o.sent ? ' is-sent' : '') }, [head, items, warn, grid, c.fee]);
		if (f.recipient_city) { loadZones(f.recipient_zone, f.recipient_area).then(function () { estimate(c); }); } else if (sg && sg.city_id) { estimateWith(c, sg.city_id, sg.zone_id); }
		return c;
	}

	var estTimers = {};
	function estimate(c) { estimateWith(c, c.inputs.recipient_city.value, c.inputs.recipient_zone.value); }
	function estimateWith(c, cityId, zoneId) {
		var id = c.order.order_id;
		clearTimeout(estTimers[id]);
		if (!cityId || !zoneId) { c.fee.textContent = ''; return; }
		estTimers[id] = setTimeout(function () {
			post('price', { store_id: c.inputs.store_id.value, item_type: c.inputs.item_type.value, delivery_type: c.inputs.delivery_type.value, item_weight: c.inputs.item_weight.value, recipient_city: cityId, recipient_zone: zoneId })
				.then(function (r) {
					if (r.success && r.data && (r.data.final_price !== undefined || r.data.price !== undefined)) {
						var d = r.data; c.fee.textContent = T.estimate + ': ৳' + (d.final_price !== undefined ? d.final_price : d.price) + (d.cod_percentage ? ' + COD ' + (d.cod_percentage * 100).toFixed(1) + '%' : '');
					} else { c.fee.textContent = ''; }
				});
		}, 400);
	}

	function render() {
		var d = S.data; S.body.innerHTML = ''; S.cards = [];
		d.orders.forEach(function (o) { var c = card(o); S.cards.push(c); S.body.appendChild(c.node); });
		var btn = el('button', { 'class': 'button button-primary', type: 'button', onclick: sendAll });
		function label() { var n = S.cards.filter(function (c) { return c.skip.checked && !c.done; }).length; btn.textContent = T.sendAll.replace('%d', n); btn.disabled = n === 0; }
		S.cards.forEach(function (c) { c.skip.addEventListener('change', label); });
		S.label = label; label();
		S.footer.innerHTML = '';
		S.footer.appendChild(btn); S.footer.appendChild(el('button', { 'class': 'button', type: 'button', text: T.cancel, onclick: function () { closeModal(); } }));
		S.btn = btn;
	}

	function collect(c) {
		var out = {};
		Object.keys(c.inputs).forEach(function (k) { out[k] = c.inputs[k].value; });
		return out;
	}

	function showErrors(c, errors) {
		c.node.querySelectorAll('.rwsc-d-ferr').forEach(function (e) { e.textContent = ''; });
		Object.keys(errors || {}).forEach(function (k) {
			var e = c.node.querySelector('[data-err="' + k + '"]') || (k === 'recipient_city' || k === 'recipient_zone' ? c.node.querySelector('[data-err="recipient_address"]') : null);
			if (e) { e.textContent = errors[k]; }
		});
	}

	function sendAll() {
		var todo = S.cards.filter(function (c) { return c.skip.checked && !c.done; });
		if (!todo.length) { return; }
		S.btn.disabled = true;
		if (S.data.bulk_mode === 'native' && todo.length > 1) { return sendNative(todo); }
		var i = 0;
		(function next() {
			if (i >= todo.length) { S.btn.textContent = T.done; S.label(); return; }
			var c = todo[i++]; c.status.textContent = T.sending; c.status.className = 'rwsc-d-result';
			post('send', { order_id: c.order.order_id, fields: collect(c) }).then(function (r) {
				if (r.success) {
					c.done = true; c.skip.checked = false; c.skip.disabled = true; c.node.classList.add('is-sent');
					c.status.className = 'rwsc-d-result ok'; c.status.textContent = '✓ ' + T.sent + ' · ' + r.data.consignment_id + (r.data.delivery_fee ? ' · ৳' + r.data.delivery_fee : '');
					updateCells(c.order.order_id, r.data.cell); showErrors(c, {});
				} else {
					var d = r.data || {}; c.status.className = 'rwsc-d-result bad'; c.status.textContent = '✗ ' + T.failed + ': ' + (d.message || '');
					showErrors(c, d.errors);
					if (d.uncertain) { c.status.textContent += ' ⚠'; }
				}
				next();
			}).catch(function () { c.status.className = 'rwsc-d-result bad'; c.status.textContent = '✗ ' + T.netError; next(); });
		})();
	}

	function sendNative(todo) {
		var orders = {};
		todo.forEach(function (c) { orders[c.order.order_id] = collect(c); });
		post('send_native', { orders: orders }).then(function (r) {
			if (!r.success) { window.alert((r.data && r.data.message) || 'Error'); S.label(); return; }
			todo.forEach(function (c) {
				var id = c.order.order_id;
				if (r.data.cells && r.data.cells[id]) { c.done = true; c.skip.disabled = true; c.status.className = 'rwsc-d-result ok'; c.status.textContent = '✓ queued'; updateCells(id, r.data.cells[id]); }
				else { c.status.className = 'rwsc-d-result bad'; c.status.textContent = '✗ ' + ((r.data.rejected || {})[id] || T.failed); }
			});
			S.label();
		});
	}

	/* ---------------- Wiring ---------------- */
	function selectedIds() {
		return Array.prototype.map.call(document.querySelectorAll('.rwsc-d-check:checked'), function (c) { return parseInt(c.value, 10); });
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('.rwsc-d-open, .rwsc-d-refresh, .rwsc-d-cs, .rwsc-d-cr, .rwsc-d-ctest, #rwsc-d-bulk-send, #rwsc-d-bulk-refresh, #rwsc-d-test, #rwsc-d-reset-token, #rwsc-d-flush, .rwsc-d-copybtn') : null;
		if (!t) { return; }
		e.preventDefault();
		if (t.classList.contains('rwsc-d-cs')) { courierSend(t); }
		else if (t.classList.contains('rwsc-d-cr')) { courierRefresh(t); }
		else if (t.classList.contains('rwsc-d-ctest')) { courierTest(t); }
		else if (t.classList.contains('rwsc-d-copybtn')) { copyText(t); }
		else if (t.classList.contains('rwsc-d-open')) { openDispatch(String(t.getAttribute('data-order-ids')).split(',').map(Number)); }
		else if (t.classList.contains('rwsc-d-refresh')) { refreshOne(parseInt(t.getAttribute('data-order-id'), 10), t); }
		else if (t.id === 'rwsc-d-bulk-send') { bulkSend(); }
		else if (t.id === 'rwsc-d-bulk-refresh') { bulkRefresh(); }
		else if (t.id === 'rwsc-d-test') { settingsAction('test', t, 'rwsc-d-test-result', true); }
		else if (t.id === 'rwsc-d-reset-token') { settingsAction('reset_token', t, 'rwsc-d-test-result', false); }
		else if (t.id === 'rwsc-d-flush') { settingsAction('flush_cache', t, 'rwsc-d-flush-result', false); }
	});

	document.addEventListener('change', function (e) {
		if (e.target && e.target.classList && e.target.classList.contains('rwsc-d-cm')) {
			var sel = e.target, id = sel.getAttribute('data-order-id'); sel.disabled = true;
			post('courier_status', { courier: sel.getAttribute('data-courier'), order_id: id, status: sel.value }).then(function (r) {
				if (r.success) { setCcell(id, r.data.cell); } else { sel.disabled = false; window.alert((r.data && r.data.message) || T.failed); }
			});
			return;
		}
		if (e.target && e.target.id === 'rwsc-d-check-all') {
			document.querySelectorAll('.rwsc-d-check').forEach(function (c) { c.checked = e.target.checked; });
		}
	});

	function settingsAction(action, btn, outId, withForm) {
		var out = document.getElementById(outId), data = {};
		if (withForm) {
			var f = document.getElementById('rwsc-d-settings');
			['client_id', 'client_secret', 'username', 'password', 'environment', 'auth_mode'].forEach(function (k) {
				var i = f && f.querySelector('[name="rwsc_api[' + k + ']"]'); if (i) { data[k] = i.value; }
			});
		}
		btn.disabled = true; out.textContent = '…';
		post(action, data).then(function (r) {
			btn.disabled = false;
			out.textContent = (r.success ? '✓ ' : '✗ ') + ((r.data && r.data.message) || '');
			out.style.color = r.success ? '#008a20' : '#d63638';
		});
	}

	/* ---------------- RedX / SteadFast ---------------- */
	function setCcell(orderId, html) {
		if (html === undefined) { return; }
		document.querySelectorAll('.rwsc-d-ccell[data-order-id="' + orderId + '"]').forEach(function (n) { n.innerHTML = html; });
	}
	function courierSend(btn) {
		var id = btn.getAttribute('data-order-id'), c = btn.getAttribute('data-courier'), extra = {};
		if (btn.classList.contains('rwsc-d-manual')) {
			var cn = window.prompt((T.enterCn || 'Enter the CN (consignment note) number for order #%s') .replace('%s', id));
			if (!cn) { return; }
			extra.cn = cn;
		} else if (!window.confirm(String(T.confirmSend || 'Send order #%1$s with %2$s?').replace('%1$s', id).replace('%2$s', btn.textContent))) { return; }
		var all = btn.parentNode.querySelectorAll('button'); all.forEach(function (b) { b.disabled = true; });
		post('courier_send', Object.assign({ courier: c, order_id: id }, extra)).then(function (r) {
			if (r.success) { setCcell(id, r.data.cell); return; }
			all.forEach(function (b) { b.disabled = false; });
			var d = r.data || {}; window.alert((d.message || T.failed) + (d.uncertain ? ' ⚠' : ''));
			if (d.cell) { setCcell(id, d.cell); }
		}).catch(function () { all.forEach(function (b) { b.disabled = false; }); window.alert(T.netError); });
	}
	function courierRefresh(a) {
		var id = a.getAttribute('data-order-id'); a.textContent = '…';
		post('courier_refresh', { courier: a.getAttribute('data-courier'), order_id: id }).then(function (r) {
			if (r.success) { setCcell(id, r.data.cell); } else { a.textContent = '\u21BB'; window.alert((r.data && r.data.message) || T.failed); }
		});
	}
	function courierTest(btn) {
		var c = btn.getAttribute('data-courier'), f = document.getElementById('rwsc-d-settings'), data = { courier: c };
		(btn.getAttribute('data-fields') || '').split(',').forEach(function (k) { var i = f && f.querySelector('[name="rwsc_api[' + k + ']"]'); if (i) { data[k] = i.value; } });
		var out = document.querySelector('.rwsc-d-ctest-out[data-courier="' + c + '"]'); btn.disabled = true; out.textContent = '…';
		post('courier_test', data).then(function (r) {
			btn.disabled = false; out.textContent = (r.success ? '✓ ' : '✗ ') + ((r.data && r.data.message) || ''); out.style.color = r.success ? '#008a20' : '#d63638';
		});
	}


	/* ---------------- Parcels page: bulk across couriers ---------------- */
	function bulkOut(msg) { var o = document.getElementById('rwsc-d-bulk-out'); if (o) { o.textContent = msg; } }
	function runQueue(items, step, label) {
		var i = 0, ok = 0, bad = 0, firstErr = '';
		(function next() {
			if (i >= items.length) {
				bulkOut(label + ': ' + ok + ' ✓ / ' + bad + ' ✗' + (firstErr ? ' — ' + firstErr : ''));
				return;
			}
			var it = items[i++]; bulkOut(label + ' ' + i + '/' + items.length + '…');
			step(it).then(function (r) {
				if (r && r.success) { ok++; if (r.data && r.data.cell !== undefined) { setCcell(it.id, r.data.cell); } }
				else { bad++; if (!firstErr) { firstErr = '#' + it.id + ' ' + ((r && r.data && r.data.message) || T.failed); } }
				next();
			}).catch(function () { bad++; if (!firstErr) { firstErr = T.netError; } next(); });
		})();
	}
	function bulkSend() {
		var ids = selectedIds(), sel = document.getElementById('rwsc-d-bulk-courier');
		if (!ids.length || !sel) { return; }
		var c = sel.value, name = sel.options[sel.selectedIndex].text;
		if (c === 'pathao') { openDispatch(ids); return; }
		if (ids.length > 50) { window.alert('Select at most 50 orders at a time.'); return; }
		if (!window.confirm(String(T.confirmBulk || 'Send %1$s order(s) with %2$s?').replace('%1$s', ids.length).replace('%2$s', name))) { return; }
		runQueue(ids.map(function (id) { return { id: id }; }), function (it) { return post('courier_send', { courier: c, order_id: it.id }); }, name);
	}
	function bulkRefresh() {
		var checks = Array.prototype.filter.call(document.querySelectorAll('.rwsc-d-check:checked'), function (c) { return c.getAttribute('data-courier'); });
		if (!checks.length) { return; }
		var path = [], rest = [];
		checks.forEach(function (c) {
			var id = parseInt(c.value, 10), cr = c.getAttribute('data-courier');
			if (cr === 'pathao') { path.push(id); } else { rest.push({ id: id, courier: cr }); }
		});
		if (path.length) { refreshMany(path); }
		if (rest.length) { runQueue(rest, function (it) { return post('courier_refresh', { courier: it.courier, order_id: it.id }); }, 'Refresh'); }
	}

	/* ---------------- Settings: tabs + copy ---------------- */
	function copyText(btn) {
		var txt = btn.getAttribute('data-copy') || '', done = function () { var o = btn.textContent; btn.textContent = '✓'; setTimeout(function () { btn.textContent = o; }, 1200); };
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, function () {}); return; }
		var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select();
		try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
		document.body.removeChild(ta);
	}
	(function tabs() {
		var bar = document.querySelector('.rwsc-d-tabs');
		if (!bar) { return; }
		var field = document.getElementById('rwsc-d-tab-field');
		function show(name) {
			bar.querySelectorAll('button').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-tab') === name); });
			document.querySelectorAll('.rwsc-d-pane').forEach(function (p) { p.classList.toggle('is-on', p.getAttribute('data-pane') === name); });
			if (field) { field.value = name; }
			try { var u = new URL(window.location.href); u.searchParams.set('tab', name); u.searchParams.delete('saved'); window.history.replaceState({}, '', u.toString()); } catch (e) { /* ignore */ }
		}
		bar.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('button[data-tab]') : null; if (b) { e.preventDefault(); show(b.getAttribute('data-tab')); } });
	})();

	// Bulk action chosen on the WooCommerce orders list.
	if (RWSC_D.autoDo && RWSC_D.autoIds.length) {
		document.addEventListener('DOMContentLoaded', function () {
			if (RWSC_D.autoDo === 'send') { openDispatch(RWSC_D.autoIds); } else { refreshMany(RWSC_D.autoIds); }
			try {
				var u = new URL(window.location.href); u.searchParams.delete('rwsc_do'); u.searchParams.delete('rwsc_ids'); u.searchParams.delete('bulk_action'); u.searchParams.delete('changed');
				window.history.replaceState({}, '', u.toString());
			} catch (err) { /* ignore */ }
		});
	}

	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });
})();
