/**
 * DevDome Admin Cleaner: late notices (owner 2026-09-15). Loaded on every admin screen while Auto-hide is on.
 * A notice that a plugin inserts with JavaScript AFTER the page was built never passed the server-side capture, so it
 * would stay on screen. This script hides such a node at once, then sends its markup to the plugin, which records it in
 * the Notice Inbox (classified, redacted) and mutes it for every administrator, exactly like a captured notice. If the
 * inbox says the owner restored that notice, the node is shown again. Nothing is sent for nodes that were already in
 * the page at load (those went through the capture), nor for our own banners.
 */
(function () {
	var cfg = window.devdadcl_late || {};
	if (!cfg.ajax || !cfg.nonce || !document.body) { return; }
	var SEL = 'div.notice, div.updated, div.error, div.update-nag'; // notice containers only: an <input class="error"> is a form control (Codex round 3)
	var seen = new WeakSet();
	function mark(root) {
		if (!root || !root.querySelectorAll) { return; }
		if (root.matches && root.matches(SEL)) { seen.add(root); }
		root.querySelectorAll(SEL).forEach(function (n) { seen.add(n); });
	}
	mark(document.body); // everything present now went through the capture (or is filler)
	function isOurs(n) {
		return !!(n.closest && (n.closest('.dd-app') || n.closest('.dd-ac-protected-banner') || n.closest('#ac-inbox-table')));
	}
	function handle(n) {
		if (seen.has(n) || isOurs(n)) { return; }
		seen.add(n);
		var html = n.outerHTML || '';
		if (!html || html.length > 16384) { return; }
		n.setAttribute('data-devdadcl-late', '1');
		n.style.display = 'none';
		var body = new FormData();
		body.append('action', 'devdadcl_late_notice');
		body.append('nonce', cfg.nonce);
		body.append('html', html);
		body.append('screen', (cfg.screen || '') + ' ' + location.pathname);
		var ctl = ('AbortController' in window) ? new AbortController() : null; // a hung request must not keep the notice hidden (DeepSeek round 8)
		var timer = ctl ? setTimeout(function () { ctl.abort(); }, 8000) : null;
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: ctl ? ctl.signal : undefined }).then(function (r) { return r.json(); }).then(function (j) {
			if (timer) { clearTimeout(timer); }
			// Hidden ONLY on a valid success that says show:false. An expired nonce (-1), a logged-out answer (0), an error
			// object or a failed write all fail visible (Codex + DeepSeek round 2).
			if (!(j && j.success === true && j.data && j.data.show === false)) { n.style.display = ''; }
		}).catch(function () { if (timer) { clearTimeout(timer); } n.style.display = ''; }); // unreachable or timed out: fail visible
	}
	var obs = new MutationObserver(function (records) {
		records.forEach(function (rec) {
			Array.prototype.forEach.call(rec.addedNodes || [], function (node) {
				if (node.nodeType !== 1) { return; }
				if (node.matches && node.matches(SEL)) { handle(node); }
				if (node.querySelectorAll) { node.querySelectorAll(SEL).forEach(handle); }
			});
		});
	});
	obs.observe(document.body, { childList: true, subtree: true });

	/* ---- bars outside the notice system (1.0.12, owner: hide all means ALL): ripped out of the DOM, not just hidden ---- */
	var BARS = Array.isArray(cfg.bars) ? cfg.bars.join(', ') : '';
	var CORE_TOP = /^(wpadminbar|wpwrap|wpbody|wpcontent|wpfooter|screen-meta|screen-meta-links|adminmenumain|wp-auth-check-wrap|wp-link-wrap|wp-link-backdrop|TB_window|TB_overlay|query-monitor-main|wp-admin-bar-root-default)$/;
	var CORE_CLS = /(^|\s)(media-modal|media-modal-backdrop|ui-dialog|ui-widget-overlay|components-|interface-|edit-post-|edit-site-|wp-core-ui|notice|updated|error|update-nag|dd-app|dd-ac-protected-banner|wp-block-|editor-|block-editor-|popover|wp-a11y)/;
	function isCore(el) {
		return CORE_TOP.test(el.id || '') || CORE_CLS.test(el.className && typeof el.className === 'string' ? el.className : '') || /^(SCRIPT|STYLE|LINK|NOSCRIPT|TEMPLATE|IFRAME)$/.test(el.tagName);
	}
	function rip(el) {
		if (!el || !el.parentNode || isOurs(el)) { return; }
		el.setAttribute('data-devdadcl-bar', '1');
		el.parentNode.removeChild(el);
	}
	function sweep() {
		try {
			if (BARS) { document.querySelectorAll(BARS).forEach(function (el) { if (!isCore(el) || el.classList.contains('wp-pointer')) { rip(el); } }); }
		} catch (e) { /* an old browser without :has(): the CSS still hides what it can */ }
		// Markup printed before the page's own .wrap on #wpbody-content (notices are handled above, screen meta is core's).
		var bc = document.getElementById('wpbody-content');
		var wrap = bc ? bc.querySelector(':scope > .wrap') : null;
		if (bc && wrap) {
			Array.prototype.slice.call(bc.children).some(function (el) {
				if (el === wrap) { return true; }
				if (!isCore(el) && !(el.matches && el.matches(SEL))) { rip(el); }
				return false;
			});
		}
		// Bars glued to the top of the window by a plugin's own CSS, wherever they sit in the DOM.
		Array.prototype.slice.call(document.body.children).forEach(function (el) {
			if (isCore(el) || el.id === 'wpwrap') { return; }
			var cs = window.getComputedStyle(el);
			if ((cs.position === 'fixed' || cs.position === 'sticky') && cs.display !== 'none' && parseFloat(cs.top || '9999') < 64 && el.getBoundingClientRect().width >= window.innerWidth * 0.5) { rip(el); }
		});
	}
	sweep();
	var pending = null;
	new MutationObserver(function () { if (pending) { return; } pending = setTimeout(function () { pending = null; sweep(); }, 50); }).observe(document.documentElement, { childList: true, subtree: true });
})();
