/* Faith Stream admin pages: copy buttons, search, the Look & feel preview and the embed builder. */
(function () {
	'use strict';

	function $$(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function copy(text, btn) {
		var done = function () {
			var was = btn.getAttribute('data-label') || btn.textContent;
			btn.setAttribute('data-label', was);
			btn.textContent = 'Copied';
			setTimeout(function () { btn.textContent = was; }, 1400);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
		} else {
			fallback(text);
			done();
		}
	}

	function fallback(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		ta.remove();
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-copy]');
		if (btn) {
			copy(btn.getAttribute('data-copy'), btn);
			return;
		}
		btn = e.target.closest('[data-copy-from]');
		if (btn) {
			var src = document.querySelector(btn.getAttribute('data-copy-from'));
			if (src) copy(src.value, btn);
		}
	});

	/* Videos: search */
	var filter = document.querySelector('[data-ftvs-filter]');
	if (filter) {
		filter.addEventListener('input', function () {
			var q = filter.value.trim().toLowerCase();
			$$('[data-ftvs-row]').forEach(function (row) {
				row.hidden = q !== '' && row.getAttribute('data-ftvs-row').indexOf(q) === -1;
			});
		});
	}

	/* Look & feel: live preview */
	var look = document.querySelector('[data-ftvs-look]');
	var mini = document.querySelector('[data-ftvs-mini]');
	if (look && mini) {
		var custom = look.querySelector('[data-ftvs-custom]');
		var customRadio = look.querySelector('[data-ftvs-custom-radio]');
		var update = function () {
			var checked = look.querySelector('input[name$="[accent]"]:checked');
			if (checked) mini.style.setProperty('--acc', checked.value);
			var label = look.querySelector('[data-ftvs-in="label"]').value.trim();
			mini.querySelector('[data-ftvs-out="label"]').textContent = label || mini.getAttribute('data-category');
			mini.querySelector('[data-ftvs-out="badge"]').textContent = look.querySelector('[data-ftvs-in="badge"]').value.trim();
			mini.querySelector('[data-ftvs-out="powered"]').classList.toggle('is-hidden', !look.querySelector('[data-ftvs-in="powered"]').checked);
		};
		if (custom && customRadio) {
			custom.addEventListener('input', function () {
				customRadio.value = custom.value.toUpperCase();
				customRadio.checked = true;
				customRadio.parentNode.style.setProperty('--c', custom.value);
				update();
			});
		}
		look.addEventListener('input', update);
		look.addEventListener('change', update);
		$$('[data-ftvs-pv]').forEach(function (b) {
			b.addEventListener('click', function () {
				$$('[data-ftvs-pv]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				mini.classList.toggle('is-phone', b.getAttribute('data-ftvs-pv') === 'phone');
			});
		});
		update();
	}

	/* Embed builder */
	var builder = document.querySelector('[data-ftvs-embed]');
	if (builder && builder.classList.contains('ftvs-embed-builder')) {
		var form = builder.querySelector('[data-ftvs-embed-form]');
		var frame = builder.querySelector('[data-ftvs-embed-preview]');
		var codeBox = builder.querySelector('[data-ftvs-embed-code]');
		var openLink = builder.querySelector('[data-ftvs-embed-open]');
		var heights = JSON.parse(builder.getAttribute('data-heights') || '{}');
		var timer = 0;
		var esc = function (s) {
			return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		};
		var build = function () {
			var data = new FormData(form);
			var parts = ['ftvs_embed=' + encodeURIComponent(data.get('category') || '')];
			['layout', 'theme', 'bg', 'title', 'eyebrow', 'limit', 'open'].forEach(function (k) {
				var v = String(data.get(k) || '').trim();
				if (v && !(k === 'limit' && v === '0')) parts.push('ftvs_' + k + '=' + encodeURIComponent(v));
			});
			var url = builder.getAttribute('data-base') + '?' + parts.join('&');
			var layout = String(data.get('layout') || 'row');
			var title = String(data.get('title') || '').trim() || 'Videos';
			codeBox.value = '<iframe src="' + esc(url) + '" title="' + esc(title) + '" data-ftvs-embed loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="width:100%;height:' + (heights[layout] || 700) + 'px;border:0;display:block"></iframe>\n' +
				'<script src="' + esc(builder.getAttribute('data-script')) + '" async></scr' + 'ipt>';
			openLink.href = url;
			clearTimeout(timer);
			timer = setTimeout(function () {
				if (frame.getAttribute('src') !== url) {
					frame.style.height = (heights[layout] || 700) + 'px';
					frame.src = url;
				}
			}, 350);
		};
		form.addEventListener('input', build);
		form.addEventListener('change', build);
		build();
	}
})();
