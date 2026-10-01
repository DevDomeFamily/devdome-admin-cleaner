/**
 * Admin Cleaner screen behaviour (audit 3, batch 7). Enqueued file, no inline scripts.
 *
 * 1. WAI-ARIA tabs: aria-selected / tabindex roving / hidden panels, Left/Right/Home/End
 *    keyboard support, hash deep-links and Back/Forward history.
 * 2. Confirmations: any form carrying data-ac-confirm opens the in-page dialog
 *    (#ac-confirm, DESIGN.md section 20). Never the browser confirm().
 */
(function () {
	'use strict';

	var app = document.querySelector('.dd-app');
	// DESIGN.md 24: the "a database query failed" banner is printed after the page; it belongs at its top.
	var guardBanner = document.getElementById('devdadcl-guard-banner');
	if (app && guardBanner) { app.insertBefore(guardBanner, app.firstChild); }
	if (!app) { return; }

	/* ------------------------------ tabs ------------------------------ */

	var tabs = Array.prototype.slice.call(app.querySelectorAll('.dd-tab[data-dd-tab]'));
	var panels = Array.prototype.slice.call(app.querySelectorAll('.dd-tabpanel[data-dd-panel]'));

	/* --------------------------- flash banners (DESIGN.md 22.3) --------------------------- */
	// The result banner of the last action is gone on the next action or tab switch, and its URL flags are
	// stripped so a reload does not show it again (the tab flag stays, it picks the panel server-side).
	function clearFlash() {
		Array.prototype.slice.call(document.querySelectorAll('.dd-banner[data-ac-flash]')).forEach(function (b) { b.parentNode.removeChild(b); });
	}
	(function stripFlags() {
		if (!(window.history && history.replaceState) || !window.URLSearchParams) { return; }
		var url = new URL(location.href), changed = false;
		Array.from(url.searchParams.keys()).forEach(function (k) {
			if (k.indexOf('ac_') === 0 && k !== 'ac_tab') { url.searchParams.delete(k); changed = true; }
		});
		if (changed) { history.replaceState(null, '', url.pathname + url.search + url.hash); }
	})();
	document.addEventListener('submit', function () { clearFlash(); }, true);

	function show(name, moveFocus) {
		if ('dashboard' === name) { name = 'overview'; } // the first tab used to be #dashboard; old deep-links still land
		var known = panels.some(function (p) { return p.getAttribute('data-dd-panel') === name; });
		if (!known) { return; }
		var current = null;
		panels.forEach(function (p) { if (p.classList.contains('is-active')) { current = p.getAttribute('data-dd-panel'); } });
		if (current !== null && current !== name) { clearFlash(); }
		panels.forEach(function (p) {
			var on = p.getAttribute('data-dd-panel') === name;
			p.classList.toggle('is-active', on);
			if (on) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', 'hidden'); }
		});
		tabs.forEach(function (t) {
			var on = t.getAttribute('data-dd-tab') === name;
			t.classList.toggle('is-active', on);
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.setAttribute('tabindex', on ? '0' : '-1');
			if (on && moveFocus) { t.focus(); }
		});
	}

	tabs.forEach(function (t, idx) {
		t.addEventListener('click', function (e) {
			e.preventDefault();
			var name = t.getAttribute('data-dd-tab');
			show(name, false);
			t.blur();
			if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
		});
		t.addEventListener('keydown', function (e) {
			var target = null;
			if ('ArrowRight' === e.key || 'ArrowDown' === e.key) { target = tabs[(idx + 1) % tabs.length]; }
			else if ('ArrowLeft' === e.key || 'ArrowUp' === e.key) { target = tabs[(idx - 1 + tabs.length) % tabs.length]; }
			else if ('Home' === e.key) { target = tabs[0]; }
			else if ('End' === e.key) { target = tabs[tabs.length - 1]; }
			else if (' ' === e.key || 'Enter' === e.key) { target = t; }
			if (!target) { return; }
			e.preventDefault();
			var name = target.getAttribute('data-dd-tab');
			show(name, true);
			if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
		});
	});

	// In-panel cross-links (href="#inbox") and browser Back/Forward.
	window.addEventListener('hashchange', function () {
		var h = (location.hash || '').replace('#', '');
		if (h) { show(h, false); }
	});
	var hash = (location.hash || '').replace('#', '');
	if (hash) { show(hash, false); }

	/* --------------------------- confirmations ------------------------- */

	var modal = document.getElementById('ac-confirm');
	var modalText = document.getElementById('ac-confirm-text');
	var modalOk = document.getElementById('ac-confirm-ok');
	var pending = null; // { form, second }
	var lastFocus = null;

	function closeModal() {
		if (!modal) { return; }
		modal.setAttribute('hidden', 'hidden');
		pending = null;
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function openModal(text, onOk) {
		if (!modal) { onOk(); return; }
		lastFocus = document.activeElement;
		modalText.textContent = text;
		modal.removeAttribute('hidden');
		pending = onOk;
		modalOk.focus();
	}

	if (modal) {
		Array.prototype.slice.call(modal.querySelectorAll('[data-ac-modal-close]')).forEach(function (el) {
			el.addEventListener('click', closeModal);
		});
		modalOk.addEventListener('click', function () {
			var fn = pending;
			pending = null;
			modal.setAttribute('hidden', 'hidden');
			if (fn) { fn(); }
		});
		document.addEventListener('keydown', function (e) {
			if (modal.hasAttribute('hidden')) { return; }
			// Enter is left to the focused button (OK gets focus on open), so Enter on Cancel cancels.
			if ('Escape' === e.key) { e.preventDefault(); closeModal(); }
		});
	}

	Array.prototype.slice.call(document.querySelectorAll('form[data-ac-confirm]')).forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (form.getAttribute('data-ac-confirmed') === '1') { return; }
			e.preventDefault();
			var submit = function () {
				form.setAttribute('data-ac-confirmed', '1');
				if (!form.querySelector('input[name="ac_confirmed"]')) { // the server checks it too (DeepSeek round 6)
					var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ac_confirmed'; h.value = '1'; form.appendChild(h);
				}
				if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
			};
			var second = form.getAttribute('data-ac-confirm2');
			openModal(form.getAttribute('data-ac-confirm'), function () {
				// Protected-notice suppression asks twice (data-ac-confirm2).
				if (second) { openModal(second, submit); } else { submit(); }
			});
		});
	});

	/* ------------- typed-word confirmation (DESIGN.md 23, Analytics Disconnect / Reset 1:1) ------------- */
	// form[data-ac-typed="WORD"]: the [data-ac-typed-open] button hides and the .ac-typed-confirm panel shows; the submit
	// button stays disabled until the input equals WORD (case-insensitive, trimmed); Cancel or Esc puts the button back.
	Array.prototype.slice.call(app.querySelectorAll('form[data-ac-typed]')).forEach(function (form) {
		var word = String(form.getAttribute('data-ac-typed') || '').toUpperCase();
		var open = form.querySelector('[data-ac-typed-open]');
		var panel = form.querySelector('.ac-typed-confirm');
		var input = form.querySelector('.ac-typed-input');
		var submit = form.querySelector('button[type="submit"]');
		var cancel = form.querySelector('[data-ac-typed-cancel]');
		if (!word || !open || !panel || !input || !submit) { return; }
		function ok() { return input.value.trim().toUpperCase() === word; }
		var row = form.closest('.ac-actions');
		function reset() { panel.setAttribute('hidden', 'hidden'); form.classList.remove('ac-typed-open'); if (row) { row.classList.remove('ac-typed-open'); } open.style.display = ''; input.value = ''; submit.disabled = true; }
		open.addEventListener('click', function () {
			clearFlash();
			panel.removeAttribute('hidden'); form.classList.add('ac-typed-open'); if (row) { row.classList.add('ac-typed-open'); } open.style.display = 'none';
			input.value = ''; submit.disabled = true;
			setTimeout(function () { input.focus(); }, 0);
		});
		input.addEventListener('input', function () { submit.disabled = !ok(); });
		if (cancel) { cancel.addEventListener('click', function () { reset(); open.focus(); }); }
		form.addEventListener('submit', function (e) {
			if (!ok()) { e.preventDefault(); return; }
			var h = form.querySelector('input[name="ac_word"]');
			if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'ac_word'; form.appendChild(h); }
			h.value = input.value.trim(); // the server checks the word too (DeepSeek round 6)
			submit.disabled = true;
		});
		form.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hasAttribute('hidden')) { e.preventDefault(); reset(); open.focus(); } });
	});

	/* ------------------------ lists: search + pages (Malware Scanner controller, 1:1) ------------------------ */
	// One controller per paged table (table[data-ac-paged]): rows that pass the search box are shown a page at a
	// time with the "1-20 of 44" range, the per-page jump menu and the page buttons (DESIGN.md 19 + 21.8).
	Array.prototype.forEach.call(app.querySelectorAll('table[data-ac-paged]'), function (table) {
		var per = parseInt(table.getAttribute('data-ac-paged'), 10) || 20;
		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
		var wrap = table.closest('.dd-list-wrap');
		var pager = wrap && wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('ac-pagination') ? wrap.nextElementSibling : null;
		var state = { page: 1, q: '', per: per };
		// Sortable headers (th[data-ac-sort="text|num"]): click toggles ascending / descending on that column, the
		// tbody is re-ordered and the pager restarts at page 1. Numeric columns read td[data-sort]; text columns the cell text.
		var tbody = table.querySelector('tbody');
		var sortThs = Array.prototype.slice.call(table.querySelectorAll('th[data-ac-sort]'));
		function cellKey(tr, idx, kind) {
			var td = tr.children[idx];
			if (!td) { return kind === 'num' ? 0 : ''; }
			if (kind === 'num') { return parseFloat(td.getAttribute('data-sort') || td.textContent) || 0; }
			return (td.textContent || '').trim().toLowerCase();
		}
		function sortBy(th, dir) {
			var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
			var kind = th.getAttribute('data-ac-sort');
			var keyed = rows.map(function (tr, i) { return { tr: tr, k: cellKey(tr, idx, kind), i: i }; });
			keyed.sort(function (a, b) {
				var c = a.k < b.k ? -1 : (a.k > b.k ? 1 : 0);
				if (c === 0) { c = a.i - b.i; }
				return dir === 'desc' ? -c : c;
			});
			rows = keyed.map(function (x) { return x.tr; });
			if (tbody) { rows.forEach(function (tr) { tbody.appendChild(tr); }); }
			sortThs.forEach(function (t) {
				var ind = t.querySelector('.ac-sort-ind');
				if (t === th) { t.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending'); if (ind) { ind.textContent = dir === 'desc' ? '\u25BE' : '\u25B4'; } }
				else { t.removeAttribute('aria-sort'); if (ind) { ind.textContent = '\u21C5'; } }
			});
			state.page = 1;
			render();
		}
		sortThs.forEach(function (th) {
			var btn = th.querySelector('.ac-sort-btn') || th;
			btn.addEventListener('click', function () {
				sortBy(th, th.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc');
			});
		});
		function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
		function matches(tr) { return !state.q || (tr.textContent || '').toLowerCase().indexOf(state.q) !== -1; }
		function render() {
			var vis = rows.filter(matches);
			var per = state.per;
			var pages = Math.max(1, Math.ceil(vis.length / per));
			if (state.page > pages) { state.page = pages; }
			var onPage = vis.slice((state.page - 1) * per, state.page * per);
			rows.forEach(function (tr) { tr.hidden = onPage.indexOf(tr) === -1; });
			if (pager) {
				pager.textContent = '';
				if (!vis.length) {
					pager.appendChild(el('span', 'ac-pg-count', 'No matching rows'));
				} else {
					pager.appendChild(el('span', 'ac-pg-count', ((state.page - 1) * per + 1) + '-' + Math.min(vis.length, state.page * per) + ' of ' + vis.length));
					var right = el('div', 'ac-pg-right');
					var perWrap = el('div', 'ac-pg-per');
					perWrap.appendChild(el('span', '', 'Show per page:'));
					var jump = el('div', 'page-jump-wrap');
					var jb = el('button', 'page-btn page-jump-btn', per + ' ▾');
					jb.type = 'button';
					jb.setAttribute('aria-haspopup', 'true');
					jb.setAttribute('aria-expanded', 'false');
					var menu = el('div', 'page-jump-menu hidden');
					[10, 20, 50, 100, 200].forEach(function (n) {
						var it = el('button', 'pjm-item' + (n === per ? ' active' : ''), String(n));
						it.type = 'button';
						it.addEventListener('click', function () { menu.classList.add('hidden'); state.per = n; state.page = 1; render(); });
						menu.appendChild(it);
					});
					jb.addEventListener('click', function (e) {
						e.stopPropagation();
						var open = menu.classList.contains('hidden');
						menu.classList.toggle('hidden', !open);
						jb.setAttribute('aria-expanded', open ? 'true' : 'false');
					});
					if (!table.acJumpClose) { // one document listener per table, not one per render (DeepSeek round 8)
						table.acJumpClose = function () { var m = table.acJumpMenu; if (m) { m.menu.classList.add('hidden'); m.jb.setAttribute('aria-expanded', 'false'); } };
						document.addEventListener('click', table.acJumpClose);
					}
					table.acJumpMenu = { menu: menu, jb: jb };
					jump.appendChild(jb);
					jump.appendChild(menu);
					perWrap.appendChild(jump);
					right.appendChild(perWrap);
					var btns = el('div', 'ac-pg-btns');
					var mk = function (label, target, disabled, active) {
						var b = el('button', 'page-btn' + (active ? ' active' : ''), label);
						b.type = 'button';
						b.disabled = !!disabled;
						if (!disabled && !active) { b.addEventListener('click', function () { state.page = target; render(); }); }
						return b;
					};
					btns.appendChild(mk('‹', state.page - 1, state.page <= 1));
					var prev = 0;
					for (var p = 1; p <= pages; p++) {
						if (p !== 1 && p !== pages && Math.abs(p - state.page) > 1) { continue; }
						if (p - prev > 1) { btns.appendChild(el('span', 'page-gap', '…')); }
						btns.appendChild(mk(String(p), p, false, p === state.page));
						prev = p;
					}
					btns.appendChild(mk('›', state.page + 1, state.page >= pages));
					right.appendChild(btns);
					pager.appendChild(right);
				}
			}
			document.dispatchEvent(new Event('devdadcl:filtered'));
		}
		table.acList = {
			render: render,
			setQuery: function (q) { state.q = String(q || '').trim().toLowerCase(); state.page = 1; render(); }
		};
		render();
	});
	var inboxSearch = document.getElementById('ac-inbox-search');
	if (inboxSearch) {
		var searchTimer = null;
		inboxSearch.addEventListener('input', function () {
			if (searchTimer) { clearTimeout(searchTimer); }
			searchTimer = setTimeout(function () { var t = document.getElementById('ac-inbox-table'); if (t && t.acList) { t.acList.setQuery(inboxSearch.value); } }, 150);
		});
	}

	/* ------------------ custom dropdowns (.dd-dd, the shared component, Malware Scanner 1:1) ------------------ */
	var dds = Array.prototype.slice.call(app.querySelectorAll('.dd-dd'));
	function ddCloseAll(except) { dds.forEach(function (d) { if (d !== except) { d.classList.remove('is-open'); } }); }
	function ddApplyValue(dd, value, fire) {
		var input = dd.querySelector('input[type="hidden"]'), label = dd.querySelector('.dd-dd-label'), chosen = null;
		Array.prototype.forEach.call(dd.querySelectorAll('.dd-dd-opt'), function (o) {
			var sel = (o.getAttribute('data-value') === value);
			o.classList.toggle('is-selected', sel);
			o.setAttribute('aria-selected', sel ? 'true' : 'false');
			if (sel) { chosen = o; }
		});
		if (input) { input.value = value; }
		if (label) { label.textContent = chosen ? chosen.textContent : (dd.getAttribute('data-placeholder') || label.textContent); }
		if (fire && input) { input.dispatchEvent(new Event('change', { bubbles: true })); }
	}
	function ddReset(name) { var dd = app.querySelector('.dd-dd[data-name="' + name + '"]'); if (dd) { ddApplyValue(dd, '', true); } }
	dds.forEach(function (dd) {
		var trigger = dd.querySelector('.dd-dd-trigger');
		if (!trigger) { return; }
		function ddSetOpen(open) { ddCloseAll(dd); dd.classList.toggle('is-open', open); trigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		function ddOpts() { return Array.prototype.slice.call(dd.querySelectorAll('.dd-dd-opt')); }
		function ddFocusOpt(i) { var o = ddOpts(); if (!o.length) { return; } i = Math.max(0, Math.min(o.length - 1, i)); o[i].focus(); }
		function ddChoose(opt) { if (opt.classList.contains('is-disabled')) { return; } ddApplyValue(dd, opt.getAttribute('data-value'), true); ddSetOpen(false); trigger.focus(); }
		trigger.addEventListener('click', function (e) { e.stopPropagation(); ddSetOpen(!dd.classList.contains('is-open')); });
		trigger.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault(); ddSetOpen(true);
				var o = ddOpts(), sel = -1;
				o.forEach(function (x, i) { if (sel < 0 && x.classList.contains('is-selected')) { sel = i; } });
				ddFocusOpt(sel >= 0 ? sel : 0);
			} else if (e.key === 'Escape') { ddSetOpen(false); }
		});
		ddOpts().forEach(function (opt, idx) {
			opt.addEventListener('click', function (e) { e.stopPropagation(); ddChoose(opt); });
			opt.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowDown') { e.preventDefault(); ddFocusOpt(idx + 1); }
				else if (e.key === 'ArrowUp') { e.preventDefault(); ddFocusOpt(idx - 1); }
				else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ddChoose(opt); }
				else if (e.key === 'Escape' || e.key === 'Tab') { ddSetOpen(false); if (e.key === 'Escape') { e.preventDefault(); trigger.focus(); } }
			});
		});
	});
	document.addEventListener('click', function () { ddCloseAll(null); dds.forEach(function (d) { var t = d.querySelector('.dd-dd-trigger'); if (t) { t.setAttribute('aria-expanded', 'false'); } }); });

	/* ------- Notice Inbox bulk bar: check-all, Actions menu, Apply -> confirm -> one POST for the selection ------- */
	(function () {
		var applyBtn = document.getElementById('ac-inbox-apply');
		var form = document.getElementById('ac-inbox-bulk-form');
		if (!applyBtn || !form) { return; }
		var checkAll = document.getElementById('ac-inbox-checkall');
		var countEl = document.getElementById('ac-inbox-count');
		var input = document.querySelector('input[name="ac_inbox_action"]');
		var dd = input ? input.closest('.dd-dd') : null;
		function boxes() {
			return Array.prototype.filter.call(document.querySelectorAll('.ac-row-check'), function (cb) {
				var tr = cb.closest('tr');
				return tr && !tr.hidden;
			});
		}
		function selected() { return boxes().filter(function (cb) { return cb.checked; }); }
		// Which menu entries fit a row: Restore needs a hidden or snoozed row, snooze/hide need a visible one,
		// Remove fits every row (DESIGN.md 21.2: entries that do not fit EVERY selected row are greyed).
		function applies(act, row) {
			var muted = row.getAttribute('data-muted') === '1';
			if (act === 'restore') { return muted; }
			if (act === 'delete') { return true; }
			if (act === 'hide_admins') { return row.getAttribute('data-muted-admins') !== '1'; } // a personal hide can still be extended to every admin (Codex round 7)
			return !muted;
		}
		function hint(act) { return act === 'restore' ? 'Only hidden or snoozed notices can be restored' : 'Only visible notices can be snoozed or hidden'; }
		function sync() {
			var all = boxes(), sel = selected(), n = sel.length;
			var rows = sel.map(function (cb) { return cb.closest('tr'); });
			if (countEl) { countEl.textContent = 'Selected (' + n + ')'; }
			var chosenOk = true;
			if (dd) {
				Array.prototype.forEach.call(dd.querySelectorAll('.dd-dd-opt'), function (o) {
					var act = o.getAttribute('data-value'), ok = true;
					for (var i = 0; i < rows.length; i++) { if (!applies(act, rows[i])) { ok = false; break; } }
					o.classList.toggle('is-disabled', !ok);
					o.setAttribute('aria-disabled', ok ? 'false' : 'true');
					if (!ok) { o.setAttribute('title', hint(act)); } else { o.removeAttribute('title'); }
					if (!ok && input && input.value === act) { chosenOk = false; }
				});
			}
			applyBtn.disabled = !(input && input.value) || n === 0 || !chosenOk;
			if (checkAll) { checkAll.checked = all.length > 0 && n === all.length; checkAll.indeterminate = n > 0 && n < all.length; }
		}
		document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('ac-row-check')) { sync(); } });
		if (checkAll) { checkAll.addEventListener('change', function () { boxes().forEach(function (cb) { cb.checked = checkAll.checked; }); sync(); }); }
		if (input) { input.addEventListener('change', sync); }
		document.addEventListener('devdadcl:filtered', sync);
		// DESIGN.md 22.4: hide, snooze and restore are reversible from this inbox and run with no dialog; only
		// Remove (the record is dropped) asks once.
		function confirmText(act, n) {
			var things = n + (n === 1 ? ' notice' : ' notices');
			if (act === 'delete') { return 'Remove ' + things + ' from the inbox entirely? If a source plugin is still active its notice may reappear as a new entry.'; }
			return '';
		}
		applyBtn.addEventListener('click', function () {
			var act = input ? input.value : '', sel = selected();
			if (!act || !sel.length) { return; }
			var submit = function () {
				clearFlash();
				Array.prototype.slice.call(form.querySelectorAll('input[name="hashes[]"]')).forEach(function (h) { h.parentNode.removeChild(h); });
				sel.forEach(function (cb) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'hashes[]'; h.value = cb.value; form.appendChild(h); });
				form.querySelector('input[name="do"]').value = act;
				var c = form.querySelector('input[name="ac_confirmed"]');
				if (!c) { c = document.createElement('input'); c.type = 'hidden'; c.name = 'ac_confirmed'; form.appendChild(c); }
				c.value = confirmText(act, sel.length) ? '1' : ''; // the server checks the answer for Remove (Codex round 7)
				applyBtn.disabled = true;
				form.submit();
			};
			var text = confirmText(act, sel.length);
			if (!text) { submit(); return; }
			openModal(text, submit);
		});
		sync();
	})();
	// DESIGN.md 16.1: pending-changes counter. Every form with a Save footer compares its fields to their values at
	// load; "N changes not saved" shows next to the button while they differ, the "Saved." flash steps aside as soon
	// as something changes again, and leaving the page with pending changes asks first. Fields attached with the
	// form="" attribute (the Overview checkbox) are included through form.elements.
	(function () {
		var forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (f) { return f.querySelector('.dd-footer .dd-pending'); });
		forms.forEach(function (form) {
			var slot = form.querySelector('.dd-footer .dd-pending');
			var saved = form.querySelector('.dd-footer .dd-saved');
			var submitting = false;
			function fields() {
				return Array.prototype.slice.call(form.elements).filter(function (el) {
					return el.name && !/^(hidden|submit|button)$/.test(el.type) && el.name.indexOf('_acsn') !== 0 && el.name !== '_wp_http_referer';
				});
			}
			function value(el) {
				if (el.type === 'checkbox' || el.type === 'radio') { return el.checked ? '1' : '0'; }
				return String(el.value);
			}
			var initial = {};
			fields().forEach(function (el, i) { initial[el.name + '#' + i] = value(el); });
			function count() {
				var n = 0;
				fields().forEach(function (el, i) { var k = el.name + '#' + i; if (initial[k] !== undefined && initial[k] !== value(el)) { n++; } });
				return n;
			}
			var footer = form.querySelector('.dd-footer');
			var autoshow = footer && footer.hasAttribute('data-dd-autoshow');
			function paint() {
				var n = count();
				if (autoshow) { footer.hidden = (n === 0 && !(saved && !saved.hidden)); }
				if (n > 0) {
					slot.textContent = n === 1 ? slot.getAttribute('data-one') : slot.getAttribute('data-many').replace('%d', String(n));
					slot.hidden = false;
					if (saved) { saved.hidden = true; }
				} else {
					slot.hidden = true;
					slot.textContent = '';
				}
			}
			// A field attached with form="" sits outside the form element, so its events never bubble through it: listen on the document.
			document.addEventListener('change', function (e) { if (e.target && e.target.form === form) { paint(); } });
			document.addEventListener('input', function (e) { if (e.target && e.target.form === form) { paint(); } });
			form.addEventListener('submit', function () {
				submitting = true;
				var tabField = form.querySelector('input[name="ac_tab"]'), active = document.querySelector('.dd-tab.is-active[data-dd-tab]');
				if (tabField && active) { tabField.value = active.getAttribute('data-dd-tab'); } // come back to the tab the owner was on
			});
			window.addEventListener('beforeunload', function (e) {
				if (!submitting && count() > 0) { e.preventDefault(); e.returnValue = ''; }
			});
		});
	})();
})();
