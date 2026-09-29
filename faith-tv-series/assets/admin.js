/* Faith Stream admin pages: copy buttons, search, the Look & feel preview and the embed builder. */
(function () {
	'use strict';

	var T = window.FTVS_ADMIN || {};

	function $$(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function copy(text, btn) {
		var done = function () {
			var was = btn.getAttribute('data-label') || btn.textContent;
			btn.setAttribute('data-label', was);
			btn.textContent = T.copied || 'Copied';
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

	/* WCAG contrast of a color against white. */
	function luminance(hex) {
		hex = String(hex || '').replace('#', '');
		if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		if (!/^[0-9a-f]{6}$/i.test(hex)) return 0;
		return [0, 2, 4].map(function (i) {
			var c = parseInt(hex.substr(i, 2), 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		}).reduce(function (sum, c, i) { return sum + c * [0.2126, 0.7152, 0.0722][i]; }, 0);
	}

	function contrastWithWhite(hex) {
		return 1.05 / (luminance(hex) + 0.05);
	}

	/* Look & feel: the real section, with the settings being tried, before saving. */
	var look = document.querySelector('[data-ftvs-look]');
	var frame = document.querySelector('[data-ftvs-pv-frame]');
	if (look && frame) {
		var custom = look.querySelector('[data-ftvs-custom]');
		var customRadio = look.querySelector('[data-ftvs-custom-radio]');
		var hint = look.querySelector('[data-ftvs-contrast]');
		var hintText = hint ? hint.textContent : '';
		var cat = document.querySelector('[data-ftvs-pv-cat]');
		var stage = document.querySelector('[data-ftvs-pv-stage]');
		var timer = 0;
		var ask = 0;
		var preview = function () {
			var data = new FormData(look);
			data.delete('option_page');
			data.delete('_wpnonce');
			data.delete('_wp_http_referer');
			data.delete('action');
			data.append('action', 'ftvs_preview_url');
			data.append('_ajax_nonce', look.getAttribute('data-nonce'));
			data.append('preview_category', cat ? cat.value : '');
			var mine = ++ask;
			fetch(look.getAttribute('data-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (mine !== ask || !res || !res.success) return;
					if (frame.getAttribute('src') !== res.data.url) frame.src = res.data.url;
				})
				.catch(function () {});
		};
		var schedule = function () {
			clearTimeout(timer);
			timer = setTimeout(preview, 400);
		};
		var checkContrast = function () {
			var checked = look.querySelector('input[name$="[accent]"]:checked');
			if (!hint || !checked) return;
			var low = contrastWithWhite(checked.value) < 4.5;
			hint.textContent = low && T.lowText ? T.lowText : hintText;
			hint.classList.toggle('is-note', low);
		};
		if (custom && customRadio) {
			custom.addEventListener('input', function () {
				customRadio.value = custom.value.toUpperCase();
				customRadio.checked = true;
				customRadio.parentNode.style.setProperty('--c', custom.value);
				checkContrast();
				schedule();
			});
		}
		look.addEventListener('input', schedule);
		look.addEventListener('change', function () {
			checkContrast();
			schedule();
		});
		if (cat) cat.addEventListener('change', preview);
		$$('[data-ftvs-pv]').forEach(function (b) {
			b.addEventListener('click', function () {
				$$('[data-ftvs-pv]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				stage.classList.toggle('is-phone', b.getAttribute('data-ftvs-pv') === 'phone');
			});
		});
		checkContrast();
		preview();
	}

	/* Size + color boxes: the color picker fills the text box next to it. */
	$$('[data-ftvs-pick]').forEach(function (pick) {
		var box = pick.parentNode.querySelector('[data-ftvs-text$="-color"]');
		if (!box) return;
		pick.addEventListener('input', function () {
			box.value = pick.value.toUpperCase();
			box.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});

	/* Embed builder: the site signs every embed address, so the code comes from the server. */
	var builder = document.querySelector('.ftvs-embed-builder[data-ftvs-embed]');
	if (builder) {
		var form = builder.querySelector('[data-ftvs-embed-form]');
		var eframe = builder.querySelector('[data-ftvs-embed-preview]');
		var codeBox = builder.querySelector('[data-ftvs-embed-code]');
		var openLink = builder.querySelector('[data-ftvs-embed-open]');
		var heights = JSON.parse(builder.getAttribute('data-heights') || '{}');
		var etimer = 0;
		var eask = 0;
		var kinds = function () {
			var kind = String(new FormData(form).get('kind') || 'category');
			$$('[data-ftvs-kind]', form).forEach(function (f) {
				f.hidden = (' ' + f.getAttribute('data-ftvs-kind') + ' ').indexOf(' ' + kind + ' ') === -1;
			});
			return kind;
		};
		var build = function () {
			var kind = kinds();
			var data = new FormData(form);
			data.append('action', 'ftvs_embed_code');
			data.append('_ajax_nonce', builder.getAttribute('data-nonce'));
			var mine = ++eask;
			fetch(builder.getAttribute('data-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (mine !== eask || !res || !res.success) return;
					codeBox.value = res.data.code;
					openLink.href = res.data.url;
					if (eframe.getAttribute('src') !== res.data.url) {
						var layout = kind === 'category' ? String(new FormData(form).get('layout') || 'row') : kind;
						eframe.style.height = (heights[layout] || 700) + 'px';
						eframe.src = res.data.url;
					}
				})
				.catch(function () {});
		};
		var eschedule = function () {
			clearTimeout(etimer);
			etimer = setTimeout(build, 350);
		};
		form.addEventListener('input', eschedule);
		form.addEventListener('change', eschedule);
		kinds();
	}
})();
