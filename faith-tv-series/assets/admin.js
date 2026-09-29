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
		var frame = builder.querySelector('[data-ftvs-embed-preview]');
		var codeBox = builder.querySelector('[data-ftvs-embed-code]');
		var openLink = builder.querySelector('[data-ftvs-embed-open]');
		var heights = JSON.parse(builder.getAttribute('data-heights') || '{}');
		var timer = 0;
		var ask = 0;
		var build = function () {
			var data = new FormData(form);
			data.append('action', 'ftvs_embed_code');
			data.append('_ajax_nonce', builder.getAttribute('data-nonce'));
			var mine = ++ask;
			fetch(builder.getAttribute('data-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (mine !== ask || !res || !res.success) return;
					codeBox.value = res.data.code;
					openLink.href = res.data.url;
					if (frame.getAttribute('src') !== res.data.url) {
						var layout = String(new FormData(form).get('layout') || 'row');
						frame.style.height = (heights[layout] || 700) + 'px';
						frame.src = res.data.url;
					}
				})
				.catch(function () {});
		};
		var schedule = function () {
			clearTimeout(timer);
			timer = setTimeout(build, 350);
		};
		form.addEventListener('input', schedule);
		form.addEventListener('change', schedule);
	}

	/* Look & feel preview: word colors. */
	if (look && mini) {
		var paint = function () {
			var get = function (part) {
				var box = look.querySelector('[data-ftvs-text="' + part + '-color"]');
				return box ? box.value.trim() : '';
			};
			mini.querySelector('.ftvs-mini__k').style.color = get('heading');
			mini.querySelector('h4').style.color = get('series');
			mini.querySelector('.ftvs-mini__eps').style.color = get('meta');
			mini.querySelector('[data-ftvs-out="label"]').style.color = get('meta');
		};
		look.addEventListener('input', paint);
		paint();
	}
})();
