/* Faith TV Series: showcase, coverflow and rows, plus the on-page player. No dependencies; hls.js loads only when a video plays. */
(function () {
	'use strict';

	var CONFIG = window.FTVS_CONFIG || {};
	var STR = CONFIG.strings || {};
	var ID_RE = /^[A-Za-z0-9_-]{1,128}$/;
	var HASH_RE = /^#faith-tv-([A-Za-z0-9_-]{1,128})$/;

	var ICON_PLAY = '<svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
	var ICON_BACK = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';
	var ICON_OUT = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	function str(key, fallback) {
		return STR[key] || fallback;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) return;
				if (k === 'text') node.textContent = v;
				else if (k === 'html') node.innerHTML = v; // only ever our own static icons
				else node.setAttribute(k, v === true ? '' : v);
			});
		}
		(children || []).forEach(function (c) {
			if (c) node.appendChild(c);
		});
		return node;
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function duration(seconds) {
		seconds = Math.round(Number(seconds) || 0);
		if (seconds <= 0) return '';
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
	}

	function shortDate(iso) {
		var d = iso ? new Date(iso) : null;
		if (!d || isNaN(d.getTime())) return '';
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function getJSON(path) {
		return fetch((CONFIG.rest || '/wp-json/faith-tv/v1/') + path, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	}

	function reducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function smooth() {
		return reducedMotion() ? 'auto' : 'smooth';
	}

	function readItem(card) {
		try {
			var item = JSON.parse(card.getAttribute('data-item') || '{}');
			return item && ID_RE.test(item.id || '') ? item : null;
		} catch (err) {
			return null;
		}
	}

	function plainClick(e) {
		return !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0);
	}

	/* ---------- Sliding rows (row layout and the showcase strip) ---------- */

	function initScroller(root) {
		var track = root.querySelector('.ftvs__viewport [data-ftvs-track]');
		var prev = root.querySelector('.ftvs__viewport [data-ftvs-prev]');
		var next = root.querySelector('.ftvs__viewport [data-ftvs-next]');
		if (!track || !prev || !next) return;
		var queued = false;
		var update = function () {
			queued = false;
			var max = track.scrollWidth - track.clientWidth - 2;
			prev.hidden = track.scrollLeft <= 2;
			next.hidden = track.scrollLeft >= max;
		};
		var schedule = function () {
			if (!queued) {
				queued = true;
				window.requestAnimationFrame(update);
			}
		};
		track.addEventListener('scroll', schedule, { passive: true });
		window.addEventListener('resize', schedule);
		window.addEventListener('load', schedule);
		prev.addEventListener('click', function () {
			track.scrollBy({ left: -track.clientWidth * 0.85, behavior: smooth() });
		});
		next.addEventListener('click', function () {
			track.scrollBy({ left: track.clientWidth * 0.85, behavior: smooth() });
		});
		update();
	}

	/* ---------- Showcase + coverflow: one featured item at a time, rotating on its own ---------- */

	function initFeature(root, layout) {
		var cards = Array.prototype.slice.call(root.querySelectorAll('[data-ftvs-track] .ftvs__card'));
		if (!cards.length) {
			var solo = root.querySelector('[data-ftvs-watch][data-item]');
			if (solo) cards = [solo];
		}
		var entries = cards.map(function (card) {
			return { card: card, kind: card.getAttribute('data-kind'), meta: card.getAttribute('data-meta') || '', item: readItem(card), href: card.href };
		});
		var n = entries.length;
		if (!n || entries.some(function (e) { return !e.item; })) return null;

		var info = root.querySelector('[data-ftvs-info]');
		var badge = root.querySelector('[data-ftvs-info] [data-ftvs-badge]');
		var titleEl = root.querySelector('[data-ftvs-title]');
		var metaEl = root.querySelector('[data-ftvs-meta]');
		var descEl = root.querySelector('[data-ftvs-desc]');
		var counter = root.querySelector('[data-ftvs-counter]');
		var art = root.querySelector('[data-ftvs-art]');
		var backdrops = root.querySelectorAll('[data-ftvs-backdrop]');
		var watches = Array.prototype.slice.call(root.querySelectorAll('[data-ftvs-watch]'));
		var slides = layout === 'coverflow' ? Array.prototype.slice.call(root.querySelectorAll('.ftvs-cf__slide')) : [];
		var track = root.querySelector('.ftvs__viewport [data-ftvs-track]');
		var autoplay = Number(root.getAttribute('data-autoplay')) || 0;
		var play = root.getAttribute('data-play');
		var active = 0;
		var swapTimer = 0;
		var backdropOn = 0;
		var pauses = {};

		if (reducedMotion() || n < 2) autoplay = 0;
		if (!autoplay) root.classList.add('ftvs--no-autoplay');

		function artOf(e) {
			return e.item.poster || e.item.image || '';
		}

		function timer() {
			return layout === 'showcase'
				? entries[active].card.querySelector('[data-ftvs-timer]')
				: root.querySelector('[data-ftvs-timer]');
		}

		function restartTimer() {
			Array.prototype.forEach.call(root.querySelectorAll('[data-ftvs-timer].is-running'), function (t) {
				t.classList.remove('is-running');
			});
			if (!autoplay) return;
			var t = timer();
			if (!t) return;
			void t.offsetWidth; // lets the CSS animation start over
			t.classList.add('is-running');
		}

		function setPaused(reason, on) {
			if (on) pauses[reason] = true;
			else delete pauses[reason];
			root.classList.toggle('is-paused', Object.keys(pauses).length > 0);
		}

		function positionSlides() {
			slides.forEach(function (slide, k) {
				var off = k - active;
				if (off > n / 2) off -= n;
				if (off < -n / 2) off += n;
				slide.setAttribute('data-pos', Math.abs(off) <= 2 ? String(off) : (off < 0 ? 'far-left' : 'far-right'));
			});
		}

		function showInfo(e, i) {
			titleEl.textContent = e.item.title || '';
			metaEl.textContent = e.meta;
			descEl.textContent = e.item.description || '';
			if (badge) badge.hidden = i !== 0;
		}

		function go(i, focusCard) {
			i = ((i % n) + n) % n;
			var e = entries[i];
			var changed = i !== active;
			var fade = reducedMotion() ? 0 : 180;
			active = i;

			if (changed) {
				// Quick fade so the words and picture change together.
				window.clearTimeout(swapTimer);
				if (info) info.classList.add('is-swapping');
				if (art) art.classList.add('is-swapping');
				swapTimer = window.setTimeout(function () {
					var now = entries[active];
					showInfo(now, active);
					if (art) art.src = artOf(now);
					if (info) info.classList.remove('is-swapping');
					if (art) art.classList.remove('is-swapping');
				}, fade);
				if (backdrops.length === 2) {
					var incoming = backdrops[1 - backdropOn];
					incoming.src = artOf(e);
					incoming.classList.add('is-on');
					backdrops[backdropOn].classList.remove('is-on');
					backdropOn = 1 - backdropOn;
				}
			}
			watches.forEach(function (w) {
				w.href = e.href;
				w.setAttribute('aria-label', str('watchX', 'Watch %s').replace('%s', e.item.title || ''));
			});
			entries.forEach(function (x, k) {
				if (k === i) {
					x.card.setAttribute('aria-current', 'true');
					if (layout === 'coverflow') x.card.removeAttribute('tabindex');
				} else {
					x.card.removeAttribute('aria-current');
					if (layout === 'coverflow') x.card.setAttribute('tabindex', '-1');
				}
			});
			if (layout === 'coverflow') positionSlides();
			if (track && changed) {
				var li = e.card.parentNode;
				if (li.offsetLeft < track.scrollLeft || li.offsetLeft + li.offsetWidth > track.scrollLeft + track.clientWidth) {
					track.scrollTo({ left: Math.max(0, li.offsetLeft - 8), behavior: smooth() });
				}
			}
			if (counter) counter.textContent = pad(i + 1) + ' / ' + pad(n);
			if (focusCard) e.card.focus({ preventScroll: true });
			restartTimer();
			// Warm up the next picture so it appears instantly.
			new Image().src = artOf(entries[(i + 1) % n]);
		}

		function openActive() {
			var e = entries[active];
			Player.open(root, e.kind, e.item, e.href);
		}

		root.addEventListener('animationend', function (e) {
			if (e.animationName === 'ftvs-fill' && !Object.keys(pauses).length) go(active + 1);
		});

		// Hold still while someone is pointing at it or using the keyboard in it, or when it is off screen.
		root.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') setPaused('hover', true); });
		root.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') setPaused('hover', false); });
		root.addEventListener('focusin', function (e) {
			var keyboard = false;
			try { keyboard = e.target.matches(':focus-visible'); } catch (err) {}
			if (keyboard) setPaused('focus', true);
		});
		root.addEventListener('focusout', function (e) {
			if (!e.relatedTarget || !root.contains(e.relatedTarget)) setPaused('focus', false);
		});
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (list) {
				setPaused('offscreen', !list[0].isIntersecting);
			}, { threshold: 0.25 }).observe(root);
		}

		var swallowClick = false;

		root.addEventListener('click', function (e) {
			if (swallowClick) {
				swallowClick = false;
				e.preventDefault();
				return;
			}
			if (!plainClick(e)) return;
			var watch = e.target.closest('[data-ftvs-watch]');
			if (watch && root.contains(watch)) {
				if (play !== 'site') return;
				e.preventDefault();
				openActive();
				return;
			}
			var card = e.target.closest('.ftvs__card');
			var idx = card ? cards.indexOf(card) : -1;
			if (idx < 0) return;
			if (idx !== active) {
				// First click brings it to the front; the next one plays it.
				e.preventDefault();
				go(idx);
				return;
			}
			if (play !== 'site') return;
			e.preventDefault();
			openActive();
		});

		if (layout === 'coverflow') {
			var stage = root.querySelector('[data-ftvs-stage]');
			var prev = root.querySelector('.ftvs-cf [data-ftvs-prev]');
			var next = root.querySelector('.ftvs-cf [data-ftvs-next]');
			if (prev) prev.addEventListener('click', function () { go(active - 1); });
			if (next) next.addEventListener('click', function () { go(active + 1); });
			var startX = null;
			stage.addEventListener('pointerdown', function (e) { startX = e.clientX; });
			stage.addEventListener('pointerup', function (e) {
				if (startX === null) return;
				var dx = e.clientX - startX;
				startX = null;
				if (Math.abs(dx) > 40) {
					swallowClick = true;
					window.setTimeout(function () { swallowClick = false; }, 400);
					go(active + (dx < 0 ? 1 : -1));
				}
			});
			stage.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
					e.preventDefault();
					go(active + (e.key === 'ArrowRight' ? 1 : -1), true);
				}
			});
			positionSlides();
			root.classList.add('is-ready');
		}

		restartTimer();
		return { pause: setPaused, go: go };
	}

	/* ---------- Row and grid: a click opens the player straight away ---------- */

	function initCards(root) {
		var more = root.querySelector('[data-ftvs-more]');
		if (more) {
			more.addEventListener('click', function () {
				root.querySelector('.ftvs-list').classList.add('is-open');
				more.hidden = true;
			});
		}
		if (root.getAttribute('data-play') !== 'site') return;
		root.addEventListener('click', function (e) {
			var card = e.target.closest('.ftvs__card, [data-ftvs-open]');
			if (!card || !root.contains(card) || !plainClick(e)) return;
			var item = readItem(card);
			if (!item) return;
			e.preventDefault();
			Player.open(root, card.getAttribute('data-kind'), item, card.href);
		});
	}

	var features = [];

	function initRoot(root) {
		if (root.getAttribute('data-ftvs-ready')) return;
		root.setAttribute('data-ftvs-ready', '1');
		var layout = root.getAttribute('data-layout') || 'row';
		initScroller(root);
		if (layout === 'showcase' || layout === 'coverflow') {
			var f = initFeature(root, layout);
			if (f) {
				root.ftvs = f;
				features.push(f);
			}
		} else {
			initCards(root);
		}
	}

	function pauseAll(on) {
		features.forEach(function (f) {
			f.pause('dialog', on);
			// Closing the player hands focus back to the button that opened it.
			if (!on) f.pause('focus', false);
		});
	}

	/* ---------- The player dialog (one per page) ---------- */

	var Player = (function () {
		var dialog, grab, playerWrap, video, posterBtn, errorBox, backBtn, ambient, kickerEl, titleEl, nowEl, descEl, statusEl, catsEl, epsLabel, epsEl, tvLink;
		var stack = [];
		var episodes = [];
		var current = -1;
		var token = 0;
		var hls = null;
		var hlsPromise = null;
		var scrollBeforeOpen = 0;
		var isOpen = false;
		var label = '';

		function build() {
			if (dialog) return;
			backBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__btn ftvs-dialog__back', hidden: true }, [
				el('span', { html: ICON_BACK }),
				el('span', { text: str('back', 'Back') })
			]);
			var closeBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__btn ftvs-dialog__close', 'aria-label': str('close', 'Close'), html: ICON_CLOSE });
			video = el('video', { controls: true, playsinline: true, preload: 'none' });
			posterBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__poster' }, [el('span', { html: ICON_PLAY })]);
			errorBox = el('p', { 'class': 'ftvs-dialog__error', role: 'alert', hidden: true });
			ambient = el('img', { 'class': 'ftvs-dialog__ambient', alt: '', 'aria-hidden': 'true' });
			kickerEl = el('p', { 'class': 'ftvs-kicker' });
			titleEl = el('h2', { 'class': 'ftvs-dialog__title', id: 'ftvs-dialog-title' });
			nowEl = el('p', { 'class': 'ftvs-dialog__now', hidden: true });
			descEl = el('p', { 'class': 'ftvs-dialog__desc' });
			statusEl = el('p', { 'class': 'ftvs-dialog__status', role: 'status' });
			catsEl = el('ul', { 'class': 'ftvs-dialog__cats', role: 'list', hidden: true });
			epsLabel = el('h3', { 'class': 'ftvs-dialog__label', hidden: true });
			epsEl = el('ol', { 'class': 'ftvs-dialog__eps', hidden: true });
			tvLink = el('a', { 'class': 'ftvs-btn ftvs-btn--light ftvs-dialog__tv', target: '_blank', rel: 'noopener', hidden: true });
			tvLink.innerHTML = ICON_OUT;
			tvLink.appendChild(el('span', { text: str('watchOnTv', 'Watch on Faith TV') }));

			grab = el('div', { 'class': 'ftvs-dialog__grab', 'aria-hidden': 'true' });
			var powered = CONFIG.powered ? el('p', { 'class': 'ftvs-dialog__powered', html: 'Powered by <b>FAITHSTREAM</b>' }) : null;
			dialog = el('dialog', { 'class': 'ftvs-dialog', 'aria-labelledby': 'ftvs-dialog-title' }, [
				grab,
				el('div', { 'class': 'ftvs-dialog__bar' }, [backBtn, closeBtn]),
				(playerWrap = el('div', { 'class': 'ftvs-dialog__player' }, [video, posterBtn, errorBox])),
				el('div', { 'class': 'ftvs-dialog__body' }, [
					ambient,
					el('div', { 'class': 'ftvs-dialog__inner' }, [kickerEl, titleEl, nowEl, descEl, statusEl, catsEl, epsLabel, epsEl, tvLink, powered])
				])
			]);
			document.body.appendChild(dialog);
			dragToClose();
			if (CONFIG.embed && window.ResizeObserver) new ResizeObserver(Embed.height).observe(dialog);

			closeBtn.addEventListener('click', close);
			backBtn.addEventListener('click', function () {
				stack.pop();
				show(stack[stack.length - 1]);
			});
			posterBtn.addEventListener('click', function () {
				play(current < 0 ? 0 : current, true);
			});
			// A click on the dark area around the box closes it.
			dialog.addEventListener('click', function (e) {
				if (e.target !== dialog) return;
				var r = dialog.getBoundingClientRect();
				if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close();
			});
			dialog.addEventListener('close', cleanup);
			video.addEventListener('ended', function () {
				if (current >= 0 && current < episodes.length - 1) play(current + 1, true);
			});
			video.addEventListener('playing', function () { dialog.classList.add('is-playing'); });
			video.addEventListener('pause', function () { dialog.classList.remove('is-playing'); });
		}

		function close() {
			cleanup();
			if (dialog.open) dialog.close();
			Embed.height();
		}

		// Phones: pull the sheet down by its handle to close it.
		function dragToClose() {
			var startY = null;
			grab.addEventListener('pointerdown', function (e) {
				if (!window.matchMedia('(max-width: 600px)').matches) return;
				startY = e.clientY;
				grab.setPointerCapture(e.pointerId);
				dialog.classList.add('is-dragging');
			});
			grab.addEventListener('pointermove', function (e) {
				if (startY === null) return;
				dialog.style.transform = 'translateY(' + Math.max(0, e.clientY - startY) + 'px)';
			});
			function end(e) {
				if (startY === null) return;
				var dy = e.clientY - startY;
				startY = null;
				dialog.classList.remove('is-dragging');
				dialog.style.transform = '';
				if (dy > 110) close();
			}
			grab.addEventListener('pointerup', end);
			grab.addEventListener('pointercancel', end);
		}

		function open(root, kind, item, href) {
			build();
			// The section's accent color (Elementor setting) carries into the player.
			var accent = window.getComputedStyle(root).getPropertyValue('--ftvs-accent');
			if (accent) dialog.style.setProperty('--ftvs-accent', accent.trim());
			label = root.getAttribute('data-label') || '';
			stack = [{ kind: kind === 'video' ? 'video' : 'category', item: item, href: href }];
			show(stack[0]);
			if (!isOpen) {
				isOpen = true;
				scrollBeforeOpen = window.scrollY;
				document.documentElement.classList.add('ftvs-lock');
				if (!dialog.open) dialog.showModal();
				pauseAll(true);
				Embed.show();
			}
			if (kind !== 'video' && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#faith-tv-' + item.id);
			}
		}

		function reset() {
			token++;
			stopVideo();
			episodes = [];
			current = -1;
			errorBox.hidden = true;
			nowEl.hidden = true;
			catsEl.hidden = true;
			catsEl.textContent = '';
			epsEl.hidden = true;
			epsLabel.hidden = true;
			epsEl.textContent = '';
			statusEl.textContent = '';
			statusEl.hidden = true;
		}

		function show(entry) {
			reset();
			var item = entry.item;
			backBtn.hidden = stack.length < 2;
			kickerEl.textContent = stack.length > 1 ? stack[stack.length - 2].item.title : label;
			kickerEl.hidden = !kickerEl.textContent;
			titleEl.textContent = item.title || '';
			descEl.textContent = item.description || '';
			descEl.hidden = !item.description;
			tvLink.href = entry.href || '#';
			tvLink.hidden = !entry.href;
			var amb = item.image || item.poster || '';
			if (amb) ambient.src = amb;
			ambient.hidden = !amb;
			playerWrap.hidden = false;
			setPoster(item.poster || item.image || '');
			dialog.scrollTop = 0;

			if (entry.kind === 'video') {
				episodes = [item];
				play(0, true);
				return;
			}

			var mine = token;
			statusEl.hidden = false;
			statusEl.textContent = str('loading', 'Loading...');
			getJSON('category/' + item.id).then(function (data) {
				if (mine !== token) return;
				statusEl.hidden = true;
				if (data.link) {
					tvLink.href = data.link;
					tvLink.hidden = false;
				}
				renderCategories(data.categories || []);
				renderEpisodes(data.videos || []);
				playerWrap.hidden = !episodes.length;
				if (!episodes.length && !(data.categories || []).length) {
					statusEl.hidden = false;
					statusEl.textContent = str('failed', 'This video could not be loaded right now.');
				}
			}).catch(function () {
				if (mine !== token) return;
				statusEl.hidden = false;
				statusEl.textContent = str('failed', 'This video could not be loaded right now.');
			});
		}

		function renderCategories(list) {
			if (!list.length) return;
			catsEl.hidden = false;
			list.forEach(function (cat) {
				var btn = el('button', { type: 'button', 'class': 'ftvs-dialog__cat' }, [
					el('span', { 'class': 'ftvs-dialog__cat-thumb' }, [cat.image ? el('img', { src: cat.image, alt: '', loading: 'lazy' }) : null]),
					el('span', { 'class': 'ftvs-dialog__cat-name', text: cat.title })
				]);
				btn.addEventListener('click', function () {
					stack.push({ kind: 'category', item: cat, href: cat.link || '' });
					show(stack[stack.length - 1]);
				});
				catsEl.appendChild(el('li', null, [btn]));
			});
		}

		function renderEpisodes(list) {
			episodes = list;
			if (!list.length) return;
			epsLabel.hidden = false;
			epsLabel.textContent = str('episodes', 'Episodes') + ' (' + list.length + ')';
			epsEl.hidden = false;
			list.forEach(function (ep, i) {
				var when = shortDate(ep.added);
				var len = duration(ep.length);
				var btn = el('button', { type: 'button', 'class': 'ftvs-dialog__ep', 'data-index': i }, [
					el('span', { 'class': 'ftvs-dialog__ep-thumb' }, [
						ep.image ? el('img', { src: ep.image, alt: '', loading: 'lazy' }) : null,
						len ? el('span', { 'class': 'ftvs-dialog__ep-len', text: len }) : null,
						el('span', { 'class': 'ftvs-eq', 'aria-hidden': 'true' }, [el('i'), el('i'), el('i')])
					]),
					el('span', { 'class': 'ftvs-dialog__ep-text' }, [
						list.length > 1 ? el('span', { 'class': 'ftvs-dialog__ep-num', text: str('episodeN', 'Episode %d').replace('%d', i + 1) }) : null,
						el('span', { 'class': 'ftvs-dialog__ep-title', text: ep.title }),
						when ? el('span', { 'class': 'ftvs-dialog__ep-meta', text: when }) : null,
						ep.description ? el('span', { 'class': 'ftvs-dialog__ep-desc', text: ep.description }) : null
					])
				]);
				btn.addEventListener('click', function () {
					play(i, true);
					dialog.scrollTo({ top: 0, behavior: smooth() });
				});
				epsEl.appendChild(el('li', null, [btn]));
			});
			// Ready to go: first episode's picture with a big play button.
			current = 0;
			setPoster(list[0].poster || list[0].image || '');
			markCurrent();
		}

		function setPoster(url) {
			posterBtn.hidden = false;
			posterBtn.style.backgroundImage = url ? 'url("' + url.replace(/"/g, '%22') + '")' : '';
			var ep = episodes[current] || episodes[0];
			posterBtn.setAttribute('aria-label', str('play', 'Play') + (ep ? ': ' + ep.title : ''));
		}

		function markCurrent() {
			Array.prototype.forEach.call(epsEl.querySelectorAll('.ftvs-dialog__ep'), function (b) {
				if (Number(b.getAttribute('data-index')) === current) b.setAttribute('aria-current', 'true');
				else b.removeAttribute('aria-current');
			});
		}

		function play(index, autoplay) {
			var ep = episodes[index];
			if (!ep) return;
			current = index;
			markCurrent();
			errorBox.hidden = true;
			if (episodes.length > 1) {
				nowEl.hidden = false;
				nowEl.textContent = str('nowPlay', 'Now playing') + ': ' + ep.title;
			}
			stopVideo();
			video.poster = ep.poster || ep.image || '';
			posterBtn.hidden = true;
			var mine = ++token;
			getJSON('video/' + ep.id).then(function (data) {
				if (mine !== token) return;
				return attach(data.hls).then(function () {
					if (mine !== token || !autoplay) return;
					var p = video.play();
					if (p && p.catch) {
						p.catch(function () {
							// Browser wanted a fresh tap; show the play button again.
							if (mine === token) posterBtn.hidden = false;
						});
					}
				});
			}).catch(function () {
				if (mine !== token) return;
				showError();
			});
		}

		function attach(url) {
			if (!url || url.indexOf('https://') !== 0) return Promise.reject(new Error('no stream'));
			// Safari plays every stream itself. Elsewhere hls.js is used even where the browser has
			// its own HLS, because only hls.js handles streams with a separate audio track (Mux).
			var native = !!video.canPlayType('application/vnd.apple.mpegurl');
			var safari = /^((?!chrome|chromium|android|crios|fxios|edg).)*safari/i.test(navigator.userAgent);
			if (native && (safari || !(window.MediaSource || window.ManagedMediaSource))) {
				video.src = url;
				return Promise.resolve();
			}
			return loadHls().then(function (Hls) {
				if (!Hls || !Hls.isSupported()) {
					if (!native) throw new Error('HLS not supported');
					video.src = url;
					return;
				}
				// Resolve once the stream is ready; calling play() earlier gets rejected.
				return new Promise(function (resolve, reject) {
					var ready = false;
					hls = new Hls({ capLevelToPlayerSize: true });
					hls.on(Hls.Events.MANIFEST_PARSED, function () {
						ready = true;
						resolve();
					});
					hls.on(Hls.Events.ERROR, function (evt, data) {
						if (!data || !data.fatal) return;
						if (ready) showError();
						else reject(new Error('stream failed'));
					});
					hls.loadSource(url);
					hls.attachMedia(video);
				});
			}).catch(function (err) {
				if (!native) throw err;
				video.src = url;
			});
		}

		function loadHls() {
			if (window.Hls) return Promise.resolve(window.Hls);
			if (!hlsPromise) {
				hlsPromise = new Promise(function (resolve, reject) {
					var s = document.createElement('script');
					s.src = CONFIG.hls;
					s.async = true;
					s.onload = function () { resolve(window.Hls); };
					s.onerror = function () {
						hlsPromise = null;
						reject(new Error('hls.js failed to load'));
					};
					document.head.appendChild(s);
				});
			}
			return hlsPromise;
		}

		function showError() {
			stopVideo();
			errorBox.hidden = false;
			errorBox.textContent = str('failed', 'This video could not be loaded right now.');
			posterBtn.hidden = false;
		}

		function stopVideo() {
			if (hls) {
				hls.destroy();
				hls = null;
			}
			if (video) {
				video.pause();
				video.removeAttribute('src');
				video.load();
			}
			if (dialog) dialog.classList.remove('is-playing');
		}

		function cleanup() {
			if (!isOpen) return;
			isOpen = false;
			token++;
			stopVideo();
			pauseAll(false);
			document.documentElement.classList.remove('ftvs-lock');
			if (HASH_RE.test(window.location.hash) && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', window.location.pathname + window.location.search);
			}
			if (Math.abs(window.scrollY - scrollBeforeOpen) > 2) window.scrollTo(0, scrollBeforeOpen);
			Embed.height();
		}

		return { open: open, dialog: function () { return dialog; } };
	})();

	/* ---------- Embed pages ---------- */

	var Embed = {
		height: function () {
			if (!CONFIG.embed || window.parent === window) return;
			// The body's own height, not the page's (which is never smaller than the frame).
			var h = Math.ceil(document.body.getBoundingClientRect().height);
			var d = Player.dialog();
			if (d && d.open) h = Math.max(h, d.scrollHeight + 48);
			window.parent.postMessage({ ftvs: 'height', h: h }, '*');
		},
		show: function () {
			if (!CONFIG.embed || window.parent === window) return;
			window.parent.postMessage({ ftvs: 'show' }, '*');
			setTimeout(Embed.height, 50);
		}
	};

	if (CONFIG.embed) {
		window.addEventListener('load', Embed.height);
		window.addEventListener('resize', Embed.height);
		if (window.ResizeObserver) new ResizeObserver(Embed.height).observe(document.body);
	}

	/* ---------- Start ---------- */

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-ftvs]'), initRoot);

		// faithtabernacle.com/#faith-tv-<series id> opens that series straight away (shareable).
		var m = window.location.hash.match(HASH_RE);
		if (!m) return;
		var cards = document.querySelectorAll('[data-ftvs][data-play="site"] .ftvs__card');
		for (var i = 0; i < cards.length; i++) {
			var item = readItem(cards[i]);
			if (item && item.id.toLowerCase() === m[1].toLowerCase()) {
				Player.open(cards[i].closest('[data-ftvs]'), cards[i].getAttribute('data-kind'), item, cards[i].href);
				break;
			}
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();

	// The Elementor editor preview re-renders the widget each time a setting changes.
	var hooked = false;
	function hookElementor() {
		if (hooked || !window.elementorFrontend || !window.elementorFrontend.hooks) return;
		hooked = true;
		window.elementorFrontend.hooks.addAction('frontend/element_ready/faith_tv_series.default', function ($scope) {
			var node = $scope && $scope[0] ? $scope[0].querySelector('[data-ftvs]') : null;
			if (node) initRoot(node);
		});
	}
	hookElementor();
	if (window.jQuery) window.jQuery(window).on('elementor/frontend/init', hookElementor);
})();
