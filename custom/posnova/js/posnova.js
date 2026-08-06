/* PosNova — terminal SPA logic (vanilla JS, no build step)
 * Copyright (C) 2026 PosNova module
 *
 * Contract with ajax/interface.php (JSON over POST, action-based):
 *   catalog | productinfo | customer_search | sale | getrate | catalog_changes | session_close | session_lock
 * Catalog item: {id, ref, label, price(TTC in POS currency), tva, stock, barcode, priceOld}
 */
(function () {
	'use strict';

	var C = window.PN_CONFIG || {};
	var T = window.PN_I18N || {};
	var CDF = C.cdfCode || 'CDF';
	var USD = 'USD';

	/* ---------------------------------------------------------------------
	 * Small DOM / utility helpers
	 * ------------------------------------------------------------------ */
	function $(sel, root) { return (root || document).querySelector(sel); }
	function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

	function decimals(cur) { return cur === CDF ? 0 : 2; }
	function roundCur(amount, cur) {
		if (cur === CDF) { return Math.floor(amount); } // mirror server DOWN_UNIT default
		return Math.round(amount * 100) / 100;
	}
	function fmt(amount, cur) {
		var d = decimals(cur);
		var n = (roundCur(amount, cur)).toFixed(d);
		var parts = n.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f');
		return (parts.length > 1 ? parts.join(',') : parts[0]) + ' ' + cur;
	}
	function convert(amount, from, to) {
		var rate = parseFloat(C.rate) || 0;
		if (from === to || rate <= 0) { return roundCur(amount, to); }
		if (from === USD && to !== USD) { return roundCur(amount * rate, to); }
		if (from !== USD && to === USD) { return roundCur(amount / rate, to); }
		return roundCur(amount, to);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */
	function api(action, params) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('token', C.csrf);
		body.set('pos_token', C.token);
		Object.keys(params || {}).forEach(function (k) {
			var v = params[k];
			body.set(k, (typeof v === 'object') ? JSON.stringify(v) : v);
		});
		return fetch(C.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: body.toString(),
			credentials: 'same-origin'
		}).then(function (r) { return r.json(); });
	}

	/* ---------------------------------------------------------------------
	 * Toasts
	 * ------------------------------------------------------------------ */
	function toast(msg, kind) {
		var box = $('#pn-toasts');
		var t = el('div', 'pn-toast pn-t-' + (kind || 'ok'));
		var ico = kind === 'err' ? '\u26d4' : (kind === 'warn' ? '\u26a0\ufe0f' : '\u2705');
		t.innerHTML = '<span>' + ico + '</span><span>' + esc(msg) + '</span>';
		box.appendChild(t);
		setTimeout(function () { t.classList.add('pn-out'); setTimeout(function () { t.remove(); }, 260); }, 3200);
	}

	/* ---------------------------------------------------------------------
	 * Modals
	 * ------------------------------------------------------------------ */
	function openModal(node) {
		var root = $('#pn-modal-root');
		var ov = el('div', 'pn-overlay');
		ov.appendChild(node);
		ov.addEventListener('mousedown', function (e) { if (e.target === ov) closeModal(); });
		root.appendChild(ov);
		document.addEventListener('keydown', escClose);
		return ov;
	}
	function closeModal() {
		var ov = $('#pn-modal-root .pn-overlay');
		if (ov) { ov.remove(); }
		document.removeEventListener('keydown', escClose);
	}
	function escClose(e) { if (e.key === 'Escape') closeModal(); }

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */
	var state = {
		cart: [],          // {uid, id, ref, label, price, tva, stock, qty, discount, editing}
		seq: 1,
		page: 1,
		search: '',
		category: 0,
		hasNext: false,
		lastSync: Math.floor(Date.now() / 1000),
		customer: { id: C.defaultCustomer ? C.defaultCustomer.id : 0, name: C.defaultCustomer ? C.defaultCustomer.name : '' },
		ticketsCount: 0,
		sessionStart: Date.now()
	};

	/* ---------------------------------------------------------------------
	 * Catalog
	 * ------------------------------------------------------------------ */
	function loadCatalog() {
		api('catalog', { q: state.search, category: state.category, page: state.page }).then(function (res) {
			if (!res || !res.ok) { return; }
			renderCatalog(res.items || []);
			state.hasNext = !!res.hasNext;
			$('#pn-pageinfo').textContent = state.page;
			$('#pn-prev').disabled = state.page <= 1;
			$('#pn-next').disabled = !state.hasNext;
			if (res.categories) { renderChips(res.categories); }
		}).catch(function () { setConn(false); });
	}

	var chipsRendered = false;
	function renderChips(cats) {
		if (chipsRendered) { return; }
		chipsRendered = true;
		var box = $('#pn-chips');
		cats.forEach(function (c) {
			var b = el('button', 'pn-chip', esc(c.label));
			b.dataset.cat = c.id;
			box.appendChild(b);
		});
		box.addEventListener('click', function (e) {
			var b = e.target.closest('.pn-chip');
			if (!b) { return; }
			box.querySelectorAll('.pn-chip').forEach(function (x) { x.classList.remove('pn-active'); });
			b.classList.add('pn-active');
			state.category = parseInt(b.dataset.cat, 10) || 0;
			state.page = 1;
			loadCatalog();
		});
	}

	function stockBadge(stock) {
		var low = parseInt(C.lowStockThreshold, 10) || 5;
		if (stock <= 0) { return '<span class="pn-stock pn-stock-out">\u25cf 0</span>'; }
		if (stock <= low) { return '<span class="pn-stock pn-stock-low">\u25cf ' + stock + '</span>'; }
		return '<span class="pn-stock pn-stock-ok">\u25cf ' + stock + '</span>';
	}

	function renderCatalog(items) {
		var box = $('#pn-catalog');
		box.innerHTML = '';
		if (!items.length) {
			box.appendChild(el('div', 'pn-empty', '<div class="pn-empty-ico">\ud83d\udd0d</div><div>' + esc(T.search || '') + '</div>'));
			return;
		}
		items.forEach(function (p) {
			var card = el('div', 'pn-prod');
			if (!C.allowSaleWithoutStock && p.stock <= 0) { card.classList.add('pn-disabled'); }
			var thumb = p.photo ? '<img src="' + esc(p.photo) + '" alt="">' : '\ud83d\udce6';
			var price = '<span class="pn-prod-price">' + fmt(p.price, C.currency) + '</span>';
			if (p.priceOld && p.priceOld > p.price) {
				price = '<span class="pn-pi-promo">' + fmt(p.priceOld, C.currency) + '</span>' + price;
			}
			card.innerHTML =
				'<div class="pn-prod-thumb">' + thumb + '</div>' +
				'<div class="pn-prod-name">' + esc(p.label) + '</div>' +
				'<div class="pn-prod-ref">' + esc(p.ref || '') + '</div>' +
				'<div class="pn-prod-foot">' + price + stockBadge(p.stock) + '</div>';
			card.dataset.id = p.id;
			card.addEventListener('click', function () { addToCart(p); flash(card); });
			card.addEventListener('dblclick', function (e) { e.preventDefault(); showProductInfo(p.id); });
			box.appendChild(card);
		});
	}
	function flash(card) { card.classList.add('pn-flash'); setTimeout(function () { card.classList.remove('pn-flash'); }, 1000); }

	/* ---------------------------------------------------------------------
	 * Product info dialog
	 * ------------------------------------------------------------------ */
	function showProductInfo(id) {
		api('productinfo', { id: id }).then(function (res) {
			if (!res || !res.ok) { toast(T.genericError, 'err'); return; }
			var p = res.product;
			var m = el('div', 'pn-modal pn-modal-wide');
			var rows = (res.stocks || []).map(function (s) {
				return '<tr class="' + (s.current ? 'pn-current' : '') + '"><td>' + (s.current ? '<span class="pn-star">\u2b50</span> ' : '') + esc(s.warehouse) + '</td>' +
					'<td class="pn-num">' + s.available + '</td><td class="pn-num">' + s.reserved + '</td><td class="pn-num">' + s.physical + '</td></tr>';
			}).join('');
			var promo = (p.priceOld && p.priceOld > p.price) ? '<span class="pn-pi-promo">' + fmt(p.priceOld, C.currency) + '</span>' : '';
			m.innerHTML =
				'<div class="pn-modal-head"><div class="pn-modal-title">' + esc(p.label) + '</div><button class="pn-modal-x" data-x>\u2715</button></div>' +
				'<div class="pn-modal-body">' +
					'<div class="pn-pi-head">' +
						'<div class="pn-pi-thumb">' + (p.photo ? '<img src="' + esc(p.photo) + '">' : '\ud83d\udce6') + '</div>' +
						'<div><div class="pn-pi-name">' + esc(p.label) + '</div>' +
						'<div class="pn-pi-meta">' + (p.ref ? esc(T.reference || 'Ref') + ': ' + esc(p.ref) + '<br>' : '') +
							(p.barcode ? '\ud83c\udff7\ufe0f ' + esc(p.barcode) + '<br>' : '') +
							(p.category ? esc(p.category) + '<br>' : '') +
							(p.description ? esc(p.description) : '') + '</div>' +
						'<div class="pn-pi-price">' + fmt(p.price, C.currency) + ' ' + promo + '</div></div>' +
					'</div>' +
					'<div class="pn-pay-group-title">' + esc(T.stockByWarehouse || '') + '</div>' +
					'<table class="pn-stocktable"><thead><tr><th>' + esc(T.warehouse || '') + '</th>' +
						'<th class="pn-num">' + esc(T.available || '') + '</th><th class="pn-num">' + esc(T.reserved || '') + '</th><th class="pn-num">' + esc(T.physical || '') + '</th></tr></thead>' +
						'<tbody>' + rows + '</tbody></table>' +
				'</div>' +
				'<div class="pn-modal-foot">' +
					'<button class="pn-btn pn-btn-ghost" data-x>' + esc(T.close || 'Close') + '</button>' +
					'<button class="pn-btn pn-btn-primary" data-add>\u2795 ' + esc(T.addToCart || '') + '</button>' +
				'</div>';
			openModal(m);
			m.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', closeModal); });
			m.querySelector('[data-add]').addEventListener('click', function () {
				addToCart({ id: p.id, ref: p.ref, label: p.label, price: p.price, tva: p.tva, stock: p.stockCurrent || 0 });
				closeModal();
			});
		});
	}

	/* ---------------------------------------------------------------------
	 * Ticket (inline editing)
	 * ------------------------------------------------------------------ */
	function addToCart(p) {
		// Merge into an existing validated line of the same product (not in edit mode).
		var existing = state.cart.find(function (l) { return l.id === p.id && !l.editing; });
		commitEditingRow();
		if (existing) {
			existing.qty += 1;
			existing.editing = true;
			state.cart.forEach(function (l) { if (l !== existing) l.editing = false; });
		} else {
			state.cart.forEach(function (l) { l.editing = false; });
			state.cart.unshift({
				uid: state.seq++, id: p.id, ref: p.ref, label: p.label,
				price: parseFloat(p.price) || 0, tva: parseFloat(p.tva) || 0,
				stock: p.stock != null ? p.stock : 9999, qty: 1, discount: 0, editing: true
			});
		}
		renderTicket();
		focusEditing();
	}

	function commitEditingRow() {
		var l = state.cart.find(function (x) { return x.editing; });
		if (l) { l.editing = false; }
	}

	function lineTotal(l) { return roundCur(l.price * l.qty * (1 - (l.discount || 0) / 100), C.currency); }

	function renderTicket() {
		var tb = $('#pn-lines');
		tb.innerHTML = '';
		$('#pn-empty').style.display = state.cart.length ? 'none' : 'block';

		state.cart.forEach(function (l) {
			var tr = el('tr', 'pn-trow' + (l.editing ? ' pn-editing' : ''));
			tr.dataset.uid = l.uid;
			if (l.editing) {
				tr.innerHTML = renderEditRow(l);
			} else {
				tr.innerHTML =
					'<td><div class="pn-line-name">' + esc(l.label) + '</div><div class="pn-line-ref">' + esc(l.ref || '') + '</div></td>' +
					'<td class="pn-num">' + fmt(l.price, C.currency) + '</td>' +
					'<td class="pn-num">' + l.qty + '</td>' +
					'<td class="pn-num">' + (l.discount ? l.discount + ' %' : '—') + '</td>' +
					'<td class="pn-num"><strong>' + fmt(lineTotal(l), C.currency) + '</strong></td>' +
					'<td><div class="pn-rowact"><button class="pn-rowbtn pn-del" data-del>\u2715</button></div></td>';
				tr.querySelector('[data-del]').addEventListener('click', function (e) { e.stopPropagation(); removeLine(l.uid); });
				tr.addEventListener('click', function () { editLine(l.uid); });
			}
			tb.appendChild(tr);
		});
		bindEditRow();
		updateTotals();
	}

	function renderEditRow(l) {
		var priceLocked = !parseInt(C.allowPriceEdit, 10);
		var overCap = (l.discount || 0) > (parseFloat(C.maxDiscount) || 0);
		return '<td><div class="pn-line-name">' + esc(l.label) + '</div><div class="pn-line-ref">' + esc(l.ref || '') + '</div></td>' +
			'<td class="pn-num"><input class="pn-cellinput' + (priceLocked ? ' pn-locked' : '') + '" data-f="price" type="number" step="0.01" min="0" value="' + l.price + '"' + (priceLocked ? ' readonly' : '') + '>' +
				(priceLocked ? '<div class="pn-lock-ico" style="font-size:11px">\ud83d\udd12</div>' : '') + '</td>' +
			'<td><div class="pn-qtybox"><button class="pn-qtybtn" data-q="-1">\u2212</button>' +
				'<input class="pn-cellinput" data-f="qty" type="number" step="1" min="1" value="' + l.qty + '">' +
				'<button class="pn-qtybtn" data-q="1">+</button></div></td>' +
			'<td class="pn-num"><input class="pn-cellinput' + (overCap ? ' pn-invalid' : '') + '" data-f="discount" type="number" step="0.5" min="0" max="100" value="' + (l.discount || 0) + '" title="' + (overCap ? esc(T.discountOverCap || '') : '') + '"></td>' +
			'<td class="pn-num"><strong data-total>' + fmt(lineTotal(l), C.currency) + '</strong></td>' +
			'<td><div class="pn-rowact"><button class="pn-rowbtn pn-ok" data-ok>\u2713</button><button class="pn-rowbtn pn-del" data-cancel>\u2715</button></div></td>';
	}

	function bindEditRow() {
		var l = state.cart.find(function (x) { return x.editing; });
		if (!l) { return; }
		var tr = $('#pn-lines tr[data-uid="' + l.uid + '"]');
		if (!tr) { return; }
		function read() {
			var pe = tr.querySelector('[data-f="price"]');
			var qe = tr.querySelector('[data-f="qty"]');
			var de = tr.querySelector('[data-f="discount"]');
			if (pe && !pe.readOnly) { l.price = parseFloat(pe.value) || 0; }
			l.qty = Math.max(1, parseInt(qe.value, 10) || 1);
			l.discount = Math.min(100, Math.max(0, parseFloat(de.value) || 0));
			var overCap = l.discount > (parseFloat(C.maxDiscount) || 0);
			de.classList.toggle('pn-invalid', overCap && !parseInt(C.canDiscount, 10));
			tr.querySelector('[data-total]').textContent = fmt(lineTotal(l), C.currency);
			updateTotals();
		}
		tr.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', read); });
		tr.querySelectorAll('[data-q]').forEach(function (b) {
			b.addEventListener('click', function () {
				var qe = tr.querySelector('[data-f="qty"]');
				qe.value = Math.max(1, (parseInt(qe.value, 10) || 1) + parseInt(b.dataset.q, 10));
				read();
			});
		});
		tr.querySelector('[data-ok]').addEventListener('click', function () { validateRow(l); });
		tr.querySelector('[data-cancel]').addEventListener('click', function () { removeLine(l.uid); });
		tr.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); validateRow(l); }
			else if (e.key === 'Escape') { e.preventDefault(); removeLine(l.uid); }
		});
	}

	function validateRow(l) {
		var overCap = (l.discount || 0) > (parseFloat(C.maxDiscount) || 0);
		if (overCap && !parseInt(C.canDiscount, 10)) { toast(T.discountOverCap, 'warn'); return; }
		if (!C.allowSaleWithoutStock && l.qty > l.stock) { toast(T.outOfStock, 'warn'); return; }
		l.editing = false;
		renderTicket();
	}

	function editLine(uid) {
		commitEditingRow();
		state.cart.forEach(function (l) { l.editing = (l.uid === uid); });
		renderTicket();
		focusEditing();
	}
	function removeLine(uid) {
		state.cart = state.cart.filter(function (l) { return l.uid !== uid; });
		renderTicket();
	}
	function focusEditing() {
		setTimeout(function () {
			var tr = $('#pn-lines tr.pn-editing');
			if (!tr) { return; }
			var inp = tr.querySelector('[data-f="qty"]');
			if (inp) { inp.focus(); inp.select(); }
		}, 30);
	}

	function updateTotals() {
		var sub = 0, disc = 0;
		state.cart.forEach(function (l) {
			var gross = l.price * l.qty;
			sub += gross;
			disc += gross * (l.discount || 0) / 100;
		});
		sub = roundCur(sub, C.currency);
		disc = roundCur(disc, C.currency);
		var grand = roundCur(sub - disc, C.currency);
		$('#pn-subtotal').textContent = fmt(sub, C.currency);
		$('#pn-discount').textContent = disc ? '− ' + fmt(disc, C.currency) : fmt(0, C.currency);
		$('#pn-grand').textContent = fmt(grand, C.currency);
		var other = C.currency === USD ? CDF : USD;
		$('#pn-equiv').textContent = grand > 0 ? '\u2248 ' + fmt(convert(grand, C.currency, other), other) + ' (' + (T.received ? '' : '') + 'taux ' + fmt(C.rate, CDF).replace(' ' + CDF, '') + ')' : '';
		$('#pn-checkout').disabled = !(grand > 0 && !state.cart.some(function (l) { return l.editing; }));
	}

	/* ---------------------------------------------------------------------
	 * Payment modal
	 * ------------------------------------------------------------------ */
	function grandTotalPos() {
		var sub = 0, disc = 0;
		state.cart.forEach(function (l) { sub += l.price * l.qty; disc += l.price * l.qty * (l.discount || 0) / 100; });
		return roundCur(sub - disc, C.currency);
	}

	function openPayment() {
		commitEditingRow(); renderTicket();
		var total = grandTotalPos();
		if (total <= 0) { return; }
		var other = C.currency === USD ? CDF : USD;

		var m = el('div', 'pn-modal');
		var accountsByCur = {};
		(C.accounts || []).forEach(function (a) { (accountsByCur[a.currency] = accountsByCur[a.currency] || []).push(a); });

		var groupsHtml = Object.keys(accountsByCur).map(function (cur) {
			var accs = accountsByCur[cur].map(function (a) {
				var modeOpts = (C.paymentModes || []).map(function (pm) {
					return '<option value="' + esc(pm.code) + '"' + (pm.code === a.mode ? ' selected' : '') + '>' + esc(pm.label) + '</option>';
				}).join('');
				return '<div class="pn-acct" data-acc="' + a.id + '" data-cur="' + esc(cur) + '">' +
					'<div class="pn-acct-top"><span class="pn-acct-ico">' + (cur === USD ? '\ud83d\udcb5' : '\ud83d\udcb4') + '</span>' +
						'<span class="pn-acct-name">' + esc(a.label) + '</span>' +
						'<span class="pn-curbadge pn-cur-' + esc(cur) + '">' + esc(cur) + '</span></div>' +
					'<div class="pn-acct-row"><label>' + esc(T.received || '') + '</label>' +
						'<input class="pn-amt-input" data-pay type="number" step="0.01" min="0" value="0"><span class="pn-amt-eq" data-eq></span></div>' +
					'<div class="pn-acct-row" style="margin-top:8px"><select class="pn-mode-sel" data-mode>' + modeOpts + '</select>' +
						'<input class="pn-ref-input" data-ref placeholder="' + esc(T.reference || '') + '" style="flex:1"></div>' +
					'<div class="pn-quickbtns" data-quick></div>' +
				'</div>';
			}).join('');
			return '<div class="pn-pay-group"><div class="pn-pay-group-title">' + esc(T.received || '') + ' — ' + esc(cur) + '</div>' + accs + '</div>';
		}).join('');

		m.innerHTML =
			'<div class="pn-modal-head"><div class="pn-modal-title">\ud83d\udcb3 ' + esc(T.paymentTitle || '') + '</div><button class="pn-modal-x" data-x>\u2715</button></div>' +
			'<div class="pn-modal-body">' +
				'<div class="pn-pay-total"><div class="pn-pt-k">' + esc(T.grandTotal || '') + '</div>' +
					'<div class="pn-pt-v">' + fmt(total, C.currency) + '</div>' +
					'<div class="pn-pt-eq">\u2248 ' + fmt(convert(total, C.currency, other), other) + '</div></div>' +
				groupsHtml +
				'<div class="pn-pay-state pn-missing" data-state></div>' +
			'</div>' +
			'<div class="pn-modal-foot">' +
				'<button class="pn-btn pn-btn-ghost" data-x>' + esc(T.close || '') + '</button>' +
				'<button class="pn-btn pn-btn-primary" data-confirm disabled>\u2705 ' + esc(T.confirmPay || '') + '</button>' +
			'</div>';
		openModal(m);
		m.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', closeModal); });

		// Quick-bill buttons per currency.
		var bills = { USD: [1, 5, 10, 20, 50, 100], };
		bills[CDF] = [1000, 5000, 10000, 20000];
		m.querySelectorAll('.pn-acct').forEach(function (acc) {
			var cur = acc.dataset.cur;
			var quick = acc.querySelector('[data-quick]');
			(bills[cur] || []).forEach(function (v) {
				var b = el('button', 'pn-quick', '+' + (cur === USD ? '$' : '') + v + (cur === CDF ? ' FC' : ''));
				b.addEventListener('click', function () {
					var inp = acc.querySelector('[data-pay]');
					inp.value = (parseFloat(inp.value) || 0) + v;
					recompute();
				});
				quick.appendChild(b);
			});
		});

		function recompute() {
			var paidPos = 0;
			m.querySelectorAll('.pn-acct').forEach(function (acc) {
				var cur = acc.dataset.cur;
				var amt = parseFloat(acc.querySelector('[data-pay]').value) || 0;
				acc.querySelector('[data-eq]').textContent = amt > 0 && cur !== C.currency ? '\u2248 ' + fmt(convert(amt, cur, C.currency), C.currency) : '';
				paidPos += convert(amt, cur, C.currency);
			});
			paidPos = roundCur(paidPos, C.currency);
			var st = m.querySelector('[data-state]');
			var diff = roundCur(paidPos - total, C.currency);
			st.classList.remove('pn-missing', 'pn-exact', 'pn-surplus');
			if (diff < 0) {
				st.classList.add('pn-missing');
				st.innerHTML = '<span>' + esc(T.remaining || '') + '</span><span>' + fmt(-diff, C.currency) + '</span>';
			} else if (diff === 0) {
				st.classList.add('pn-exact');
				st.innerHTML = '<span>' + esc(T.covered || '') + '</span><span>\u2714</span>';
			} else {
				st.classList.add('pn-surplus');
				st.innerHTML = '<span>' + esc(T.change || '') + '</span><span>' + fmt(diff, C.currency) + '</span>';
			}
			m.querySelector('[data-confirm]').disabled = diff < 0;
		}
		m.querySelectorAll('[data-pay]').forEach(function (i) { i.addEventListener('input', recompute); });

		// Auto-propose the remaining on the default account.
		var defAcc = m.querySelector('.pn-acct[data-acc="' + (defaultAccountId()) + '"]') || m.querySelector('.pn-acct');
		if (defAcc) {
			var di = defAcc.querySelector('[data-pay]');
			di.value = convert(total, C.currency, defAcc.dataset.cur);
		}
		recompute();

		m.querySelector('[data-confirm]').addEventListener('click', function () {
			var payments = [], changeAmt;
			var paidPos = 0;
			m.querySelectorAll('.pn-acct').forEach(function (acc) {
				var amt = parseFloat(acc.querySelector('[data-pay]').value) || 0;
				if (amt <= 0) { return; }
				var cur = acc.dataset.cur;
				paidPos += convert(amt, cur, C.currency);
				payments.push({ account_id: parseInt(acc.dataset.acc, 10), amount: amt, currency: cur, mode: acc.querySelector('[data-mode]').value, ref: acc.querySelector('[data-ref]').value });
			});
			var surplus = roundCur(paidPos - total, C.currency);
			var changes = [];
			if (surplus > 0 && defAcc) { changes.push({ account_id: parseInt(defAcc.dataset.acc, 10), amount: convert(surplus, C.currency, defAcc.dataset.cur), currency: defAcc.dataset.cur }); }
			submitSale(payments, changes, this);
		});
	}

	function defaultAccountId() {
		var d = (C.accounts || []).find(function (a) { return a.isDefault; });
		return d ? d.id : ((C.accounts || [])[0] || {}).id;
	}

	function submitSale(payments, changes, btn) {
		btn.disabled = true;
		var payload = {
			socid: state.customer.id || 0,
			lines: state.cart.map(function (l) { return { product_id: l.id, qty: l.qty, discount: l.discount || 0, price: l.price }; }),
			payments: payments,
			changes: changes
		};
		var invdate = $('#pn-invdate');
		if (invdate && invdate.value) { payload.invoice_date = invdate.value; }
		api('sale', { payload: payload }).then(function (res) {
			if (res && res.ok) {
				closeModal();
				toast((T.saleDone || '') + ' ' + res.ref, 'ok');
				state.cart = [];
				state.ticketsCount++;
				$('#pn-sess-count').textContent = state.ticketsCount;
				renderTicket();
				if (parseInt(C.autoprint, 10) && res.ref) { printTicket(res.ticket_id); }
			} else {
				btn.disabled = false;
				toast(translateErr(res && res.error), 'err');
			}
		}).catch(function () { btn.disabled = false; toast(T.genericError, 'err'); });
	}

	function translateErr(code) {
		var map = {
			PosNovaErrDiscountOverCap: T.discountOverCap,
			PosNovaErrInsufficientStock: T.outOfStock,
			PosNovaErrPaymentNotCovered: T.missing
		};
		return map[code] || code || T.genericError;
	}

	function printTicket(id) {
		var w = window.open(C.ajaxUrl.replace('/ajax/interface.php', '/print.php') + '?id=' + id + '&token=' + encodeURIComponent(C.csrf), 'pnprint', 'width=380,height=640');
		if (w) { setTimeout(function () { try { w.focus(); } catch (e) {} }, 300); }
	}

	/* ---------------------------------------------------------------------
	 * Connection + polling + clock
	 * ------------------------------------------------------------------ */
	function setConn(online) {
		var c = $('#pn-conn');
		c.classList.toggle('pn-online', online);
		c.classList.toggle('pn-offline', !online);
		$('#pn-conn-label').textContent = online ? (T.online || 'Online') : (T.offline || 'Offline');
	}
	window.addEventListener('online', function () { setConn(true); });
	window.addEventListener('offline', function () { setConn(false); });

	function pollChanges() {
		api('catalog_changes', { since: state.lastSync }).then(function (res) {
			setConn(true);
			if (res && res.ok) {
				state.lastSync = res.now || state.lastSync;
				if (res.rate && (res.rate.rate !== C.rate || res.rate.source !== C.rateSource)) {
					C.rate = res.rate.rate; C.rateSource = res.rate.source;
					$('#pn-rate-val').textContent = fmt(C.rate, CDF).replace(' ' + CDF, '');
					var box = $('#pn-rate-box');
					box.classList.toggle('pn-rate-manual', res.rate.source === 'MANUAL');
					box.classList.toggle('pn-rate-system', res.rate.source !== 'MANUAL');
					$('#pn-rate-src').textContent = res.rate.source === 'MANUAL' ? 'MANUEL' : 'SYSTÈME';
					updateTotals();
				}
				if (res.changed > 0) { loadCatalog(); if (res.changed > 3) { toast(T.catalogUpdated, 'ok'); } }
			}
		}).catch(function () { setConn(false); });
	}

	function tickClock() {
		var s = Math.floor((Date.now() - state.sessionStart) / 1000);
		var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
		$('#pn-sess-clock').textContent = (h ? h + 'h' : '') + ('0' + m).slice(-2) + 'm';
	}

	/* ---------------------------------------------------------------------
	 * Customer search
	 * ------------------------------------------------------------------ */
	function bindCustomer() {
		var inp = $('#pn-customer');
		var dd;
		var run = debounce(function () {
			var q = inp.value.trim();
			if (q.length < 2) { if (dd) { dd.remove(); dd = null; } return; }
			api('customer_search', { q: q }).then(function (res) {
				if (dd) { dd.remove(); }
				if (!res || !res.ok || !res.items.length) { return; }
				dd = el('div', 'pn-card');
				dd.style.cssText = 'position:absolute;z-index:30;top:46px;left:0;right:0;max-height:240px;overflow:auto;padding:6px;';
				res.items.forEach(function (c) {
					var o = el('div', 'pn-postile', '<div class="pn-postile-name" style="font-size:14px">' + esc(c.name) + '</div><div class="pn-postile-wh">' + esc(c.detail || '') + '</div>');
					o.style.cssText = 'padding:10px;cursor:pointer';
					o.addEventListener('click', function () { state.customer = { id: c.id, name: c.name }; inp.value = c.name; dd.remove(); dd = null; });
					dd.appendChild(o);
				});
				inp.parentNode.appendChild(dd);
			});
		}, 250);
		inp.addEventListener('input', run);
		inp.addEventListener('focus', function () { if (inp.value === (C.defaultCustomer ? C.defaultCustomer.name : '')) { inp.select(); } });
	}

	/* ---------------------------------------------------------------------
	 * Init
	 * ------------------------------------------------------------------ */
	function init() {
		$('#pn-search').addEventListener('input', debounce(function (e) { state.search = e.target.value.trim(); state.page = 1; loadCatalog(); }, 260));
		$('#pn-prev').addEventListener('click', function () { if (state.page > 1) { state.page--; loadCatalog(); } });
		$('#pn-next').addEventListener('click', function () { if (state.hasNext) { state.page++; loadCatalog(); } });
		$('#pn-checkout').addEventListener('click', openPayment);
		$('#pn-clear').addEventListener('click', function () { if (state.cart.length) { state.cart = []; renderTicket(); } });
		bindCustomer();

		var menuBtn = $('#pn-btn-menu');
		if (menuBtn) { menuBtn.addEventListener('click', openMenu); }
		var transferBtn = $('#pn-btn-transfer');
		if (transferBtn) { transferBtn.addEventListener('click', function () { toast(T.transfer + ' — ' + (T.catalogUpdated || ''), 'ok'); }); }

		// Global keyboard: focus search on "/" ; checkout on F2.
		document.addEventListener('keydown', function (e) {
			if (e.key === '/' && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); $('#pn-search').focus(); }
			if (e.key === 'F2' && !$('#pn-checkout').disabled) { e.preventDefault(); openPayment(); }
		});

		renderTicket();
		loadCatalog();
		setConn(navigator.onLine);
		setInterval(pollChanges, (parseInt(C.pollInterval, 10) || 30) * 1000);
		setInterval(tickClock, 30000); tickClock();
	}

	function openMenu() {
		var m = el('div', 'pn-modal');
		m.innerHTML =
			'<div class="pn-modal-head"><div class="pn-modal-title">\u2630 ' + esc(C.posLabel) + '</div><button class="pn-modal-x" data-x>\u2715</button></div>' +
			'<div class="pn-modal-body">' +
				'<div class="pn-field"><label>' + esc(T.session || '') + '</label><div>' + esc(C.sessionRef) + '</div></div>' +
				'<button class="pn-btn pn-btn-ghost" style="width:100%;margin-bottom:10px" data-lock>\ud83d\udd12 ' + esc(T.lock || '') + '</button>' +
				'<button class="pn-btn pn-btn-primary" style="width:100%;background:linear-gradient(135deg,#dc2626,#b91c1c)" data-close>\ud83d\udeaa ' + esc(T.closeSession || '') + '</button>' +
			'</div>';
		openModal(m);
		m.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', closeModal); });
		m.querySelector('[data-close]').addEventListener('click', function () { window.location.href = C.ajaxUrl.replace('/ajax/interface.php', '/pos.php') + '?action=close'; });
		m.querySelector('[data-lock]').addEventListener('click', function () { window.location.href = C.ajaxUrl.replace('/ajax/interface.php', '/pos.php') + '?action=lock'; });
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
