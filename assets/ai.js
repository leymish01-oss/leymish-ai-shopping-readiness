/* LeyMish AI Readiness: the AI fixes review panel, Now vs Proposed side by side. Nothing is saved until the owner approves. */
(function () {
	'use strict';
	var cfg = window.lasrAI;
	if (!cfg) {
		return;
	}
	var t = cfg.i18n;

	function el(tag, attrs, text) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	// One field: what's there now and what's proposed, side by side, with a box to keep it (ticked).
	function row(panel, name, now, suggested, key) {
		var wrap = el('div', { 'class': 'lasr-nvp' });
		var head = el('label', { 'class': 'lasr-nvp-head' });
		var box = el('input', { type: 'checkbox', 'data-key': key, checked: 'checked' });
		head.appendChild(box);
		head.appendChild(document.createTextNode(' ' + name));
		wrap.appendChild(head);
		var cols = el('div', { 'class': 'lasr-nvp-cols' });
		var a = el('div', { 'class': 'lasr-nvp-now' });
		a.appendChild(el('span', { 'class': 'lasr-nvp-k' }, t.current));
		a.appendChild(el('p', {}, now || '—'));
		var b = el('div', { 'class': 'lasr-nvp-new' });
		b.appendChild(el('span', { 'class': 'lasr-nvp-k' }, t.suggest));
		b.appendChild(el('p', {}, suggested));
		cols.appendChild(a);
		cols.appendChild(b);
		wrap.appendChild(cols);
		panel.appendChild(wrap);
	}

	function show(panel, product, res) {
		panel.textContent = '';
		var s = res.suggestion || {};
		var c = res.current || {};
		var kind = res.kind;
		var items = [];
		if (kind === 'attributes') {
			(s.attributes || []).forEach(function (a, i) {
				row(panel, a.name, (c.attributes || {})[a.name], a.value + (a.evidence ? ' ("' + a.evidence + '")' : ''), 'a' + i);
				items.push(a);
			});
		} else if (kind === 'description') {
			if (s.description) { row(panel, 'Description', (c.description || '').slice(0, 160) + '…', s.description, 'd'); }
			if (s.short_description) { row(panel, 'Short description', c.short_description, s.short_description, 's'); }
		} else if (kind === 'category') {
			(s.categories || []).forEach(function (cat, i) { row(panel, 'Google category', c.google, cat.path + ' — ' + cat.reason, 'c' + i); });
		} else if (kind === 'alt_text' && s.alt_text) {
			row(panel, 'Alt text', c.alt_text, s.alt_text, 'x');
		}
		if (!panel.querySelector('input')) {
			panel.appendChild(el('p', {}, t.none));
			return;
		}
		var apply = el('button', { type: 'button', 'class': 'button button-primary' }, t.apply);
		var cancel = el('button', { type: 'button', 'class': 'button' }, t.cancel);
		apply.addEventListener('click', function () {
			var keep = function (key) { var b = panel.querySelector('input[data-key="' + key + '"]'); return b && b.checked; };
			var accepted = {};
			if (kind === 'attributes') { accepted.attributes = items.filter(function (a, i) { return keep('a' + i); }); }
			if (kind === 'description') {
				accepted.description = keep('d') ? s.description : '';
				accepted.short_description = keep('s') ? s.short_description : '';
			}
			if (kind === 'category') {
				accepted.categories = (s.categories || []).filter(function (cat, i) { return keep('c' + i); }).slice(0, 1);
			}
			if (kind === 'alt_text') { accepted.alt_text = keep('x') ? s.alt_text : ''; }
			apply.disabled = true;
			post('lasr_ai_apply', { product: product, kind: kind, accepted: JSON.stringify(accepted) }).then(function (r) {
				panel.textContent = r && r.success ? t.saved : ((r && r.data && r.data.message) || 'Error');
			});
		});
		cancel.addEventListener('click', function () { panel.textContent = ''; });
		panel.appendChild(apply);
		panel.appendChild(document.createTextNode(' '));
		panel.appendChild(cancel);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.lasr-ai-suggest') : null;
		if (!btn) {
			return;
		}
		var product = btn.getAttribute('data-product');
		var panel = document.getElementById('lasr-ai-' + product);
		panel.textContent = t.working;
		post('lasr_ai_suggest', { product: product, kind: btn.getAttribute('data-kind') }).then(function (r) {
			if (r && r.success) {
				show(panel, product, r.data);
			} else {
				panel.textContent = (r && r.data && r.data.message) || 'Error';
			}
		}).catch(function () { panel.textContent = 'Error'; });
	});
}());
