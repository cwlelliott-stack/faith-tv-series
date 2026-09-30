/* Faith TV Series: showcase, coverflow, rows, Sunday live and the sermon library, plus the on-page
   player. No dependencies; hls.js loads only when a video is about to play. */
(function () {
	'use strict';

	var CONFIG = window.FTVS_CONFIG || {};
	var STR = CONFIG.strings || {};
	var ID_RE = /^[A-Za-z0-9_-]{1,128}$/;
	// #faith-tv-<series>, #faith-tv-<series>/<video>, #faith-tv-<series>/<video>@<seconds> ("_" = no series)
	var HASH_RE = /^#faith-tv-([A-Za-z0-9_-]{1,128})(?:\/([A-Za-z0-9_-]{1,128}))?(?:@(\d{1,6}))?$/;
	var HAS_DIALOG = typeof window.HTMLDialogElement === 'function';

	var ICON_PLAY = '<svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
	var ICON_BACK = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';
	var ICON_OUT = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_SHARE = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_AGAIN = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M4 12a8 8 0 1 0 2.4-5.7M4 4v5h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

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

	function restHeaders(extra) {
		var h = extra || {};
		if (CONFIG.nonce) h['X-WP-Nonce'] = CONFIG.nonce;
		return h;
	}

	function getJSON(path) {
		return fetch((CONFIG.rest || '/wp-json/faith-tv/v1/') + path, { credentials: 'same-origin', headers: restHeaders() }).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	}

	function postJSON(path, data) {
		return fetch((CONFIG.rest || '/wp-json/faith-tv/v1/') + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: restHeaders({ 'Content-Type': 'application/json' }),
			body: JSON.stringify(data)
		}).then(function (r) {
			return r.json().then(function (body) {
				if (!r.ok) throw new Error(body && body.message ? body.message : 'HTTP ' + r.status);
				return body;
			});
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

	function saveData() {
		var c = navigator.connection;
		return !!(c && (c.saveData || /2g/.test(c.effectiveType || '')));
	}

	function isPhone() {
		return !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
	}

	/* ---------- Storage on the visitor's own device (never sent anywhere) ---------- */

	var Store = {
		get: function (key, fallback) {
			try {
				var v = window.localStorage.getItem('ftvs:' + key);
				return v === null ? fallback : JSON.parse(v);
			} catch (err) {
				return fallback;
			}
		},
		set: function (key, value) {
			try {
				window.localStorage.setItem('ftvs:' + key, JSON.stringify(value));
			} catch (err) {}
		}
	};

	/* Where each video was left off: { id: { t, d, at, s (series), done } }, newest 200. */
	var Progress = {
		all: function () {
			return Store.get('progress', {}) || {};
		},
		of: function (id) {
			return this.all()[id] || null;
		},
		save: function (id, t, d, series, done) {
			if (!CONFIG.resume || !id) return;
			var all = this.all();
			all[id] = { t: Math.round(t), d: Math.round(d || 0), at: Date.now(), s: series || '', done: !!done };
			var keys = Object.keys(all);
			if (keys.length > 200) {
				keys.sort(function (a, b) { return all[a].at - all[b].at; });
				keys.slice(0, keys.length - 200).forEach(function (k) { delete all[k]; });
			}
			Store.set('progress', all);
		}
	};

	/* ---------- Events other tools can hear (GoHighLevel pages, Tag Manager, the embed's host) ---------- */

	function emit(name, detail) {
		detail = detail || {};
		try {
			document.dispatchEvent(new CustomEvent('faithtv:' + name, { detail: detail }));
		} catch (err) {}
		if (window.dataLayer && window.dataLayer.push) {
			window.dataLayer.push({ event: 'faith_tv_' + name, faith_tv: detail });
		}
		if (CONFIG.embed && window.parent !== window) {
			window.parent.postMessage({ ftvs: 'event', name: name, detail: detail }, '*');
		}
	}

	/* ---------- Counting plays ---------- */

	function countLocal(e, item) {
		if (!CONFIG.count || !item || !item.id) return;
		var data = JSON.stringify({ e: e, id: item.id, t: item.title || '', p: CONFIG.embed ? document.referrer : window.location.pathname, embed: !!CONFIG.embed });
		var url = (CONFIG.rest || '/wp-json/faith-tv/v1/') + 'stats';
		if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([data], { type: 'application/json' }))) return;
		fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: data, keepalive: true }).catch(function () {});
	}

	/* Faith Stream's own analytics: start, heartbeats, finished (anonymous; watch time is credited by the server). */
	function Reporter(kind, id) {
		this.kind = kind;
		this.id = id;
		this.session = null;
		// A viewer signed in for check-in sends live heartbeats even when general reporting is off.
		this.url = CONFIG.report ? CONFIG.report.url : (kind === 'live' && Checkin.token() ? CONFIG.checkin.api + '/api/analytics/events?tenant=' + encodeURIComponent(CONFIG.checkin.tenant) : '');
		this.off = !this.url || !id || id.charAt(0) === '_';
		this.timer = 0;
	}
	Reporter.prototype.send = function (events, keepalive) {
		if (this.off) return Promise.resolve();
		var self = this;
		var body = {
			session_id: this.session,
			target_kind: this.kind,
			target_id: this.id,
			source: CONFIG.embed ? 'embed' : 'web',
			platform: 'wordpress',
			device: isPhone() ? 'phone' : 'desktop',
			referrer: String(CONFIG.embed ? document.referrer : window.location.href).slice(0, 300),
			events: events
		};
		var headers = { 'Content-Type': 'application/json' };
		if (Checkin.token()) headers['X-Viewer-Token'] = Checkin.token();
		return fetch(this.url, {
			method: 'POST',
			credentials: 'omit',
			keepalive: !!keepalive,
			headers: headers,
			body: JSON.stringify(body)
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		}).then(function (res) {
			if (res && res.session_id) self.session = res.session_id;
		}).catch(function () {
			// Faith Stream not reachable from this site (or not set up for it): stay quiet.
			if (!self.session) self.off = true;
		});
	};
	Reporter.prototype.start = function (pos) {
		var self = this;
		this.send([{ kind: 'start', position_s: pos || 0 }]);
		window.clearInterval(this.timer);
		this.timer = window.setInterval(function () { self.beat(); }, 10000);
	};
	Reporter.prototype.beat = function () {
		if (this.position && !this.paused) this.send([{ kind: 'heartbeat', position_s: this.position() }]);
	};
	Reporter.prototype.event = function (kind, pos) {
		this.send([{ kind: kind, position_s: pos || 0 }]);
	};
	Reporter.prototype.stop = function (pos) {
		window.clearInterval(this.timer);
		if (this.session) this.send([{ kind: 'end', position_s: pos || 0 }], true);
	};

	/* ---------- Sliding rows (row layout and the showcase strip) ---------- */

	function initScroller(root) {
		var track = root.querySelector('.ftvs__viewport [data-ftvs-track]');
		var prev = root.querySelector('.ftvs__viewport [data-ftvs-prev]');
		var next = root.querySelector('.ftvs__viewport [data-ftvs-next]');
		if (!track || !prev || !next) return;
		// Right-to-left pages scroll the other way (and browsers report scrollLeft as 0 or less).
		var dir = window.getComputedStyle(track).direction === 'rtl' ? -1 : 1;
		var queued = false;
		var update = function () {
			queued = false;
			var max = track.scrollWidth - track.clientWidth - 2;
			var at = Math.abs(track.scrollLeft);
			prev.hidden = at <= 2;
			next.hidden = at >= max;
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
			track.scrollBy({ left: -dir * track.clientWidth * 0.85, behavior: smooth() });
		});
		next.addEventListener('click', function () {
			track.scrollBy({ left: dir * track.clientWidth * 0.85, behavior: smooth() });
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
		var pauseBtn = root.querySelector('[data-ftvs-pause]');
		var autoplay = Number(root.getAttribute('data-autoplay')) || 0;
		var play = root.getAttribute('data-play');
		var active = 0;
		var swapTimer = 0;
		var backdropOn = 0;
		var pauses = {};
		var steps = 0;

		if (reducedMotion() || n < 2) autoplay = 0;
		if (!autoplay) {
			root.classList.add('ftvs--no-autoplay');
			if (pauseBtn) pauseBtn.hidden = true;
		}

		function artOf(e) {
			return e.item.poster || e.item.image || '';
		}

		function timer() {
			return layout === 'showcase'
				? entries[active].card.querySelector('[data-ftvs-timer]')
				: root.querySelector('[data-ftvs-timer]');
		}

		function rotating() {
			return autoplay && !Object.keys(pauses).length;
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

		function stopRotating() {
			autoplay = 0;
			root.classList.add('ftvs--no-autoplay');
			restartTimer();
			if (pauseBtn) pauseBtn.hidden = true;
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

		// byUser: someone chose this one (announce it to screen readers; automatic turns stay quiet).
		function go(i, focusCard, byUser) {
			i = ((i % n) + n) % n;
			var e = entries[i];
			var changed = i !== active;
			var fade = reducedMotion() ? 0 : 180;
			active = i;
			if (info) {
				if (byUser) info.setAttribute('aria-live', 'polite');
				else info.removeAttribute('aria-live');
			}

			if (changed) {
				// Quick fade so the words and picture change together.
				window.clearTimeout(swapTimer);
				if (info) info.classList.add('is-swapping');
				if (art) art.classList.add('is-swapping');
				swapTimer = window.setTimeout(function () {
					var now = entries[active];
					showInfo(now, active);
					if (art) {
						// A srcset would keep showing the first picture; the swapped one is set by src.
						art.removeAttribute('srcset');
						art.src = artOf(now);
					}
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
			// Warm up the next picture while it's rotating (not forever on a phone's data).
			if (rotating()) new Image().src = artOf(entries[(i + 1) % n]);
		}

		function openActive() {
			var e = entries[active];
			return Player.open(root, e.kind, e.item, e.href);
		}

		root.addEventListener('animationend', function (e) {
			if (e.animationName !== 'ftvs-fill' || !rotating()) return;
			// Three times around is enough; then it holds still.
			if (++steps >= n * 3) {
				stopRotating();
				return;
			}
			go(active + 1);
		});

		// Hold still while someone is pointing at it or using the keyboard in it, when it is off
		// screen or the tab is hidden, and when they pressed Pause.
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
		document.addEventListener('visibilitychange', function () {
			setPaused('hidden', document.hidden);
		});
		if (pauseBtn) {
			pauseBtn.addEventListener('click', function () {
				var on = pauseBtn.getAttribute('aria-pressed') !== 'true';
				pauseBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
				pauseBtn.setAttribute('aria-label', pauseBtn.getAttribute(on ? 'data-label-play' : 'data-label-pause') || '');
				root.classList.toggle('is-user-paused', on);
				setPaused('user', on);
			});
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
				if (play !== 'site' || !HAS_DIALOG) return;
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
				go(idx, false, true);
				return;
			}
			if (play !== 'site' || !HAS_DIALOG) return;
			e.preventDefault();
			openActive();
		});

		if (layout === 'coverflow') {
			var stage = root.querySelector('[data-ftvs-stage]');
			var prev = root.querySelector('.ftvs-cf [data-ftvs-prev]');
			var next = root.querySelector('.ftvs-cf [data-ftvs-next]');
			if (prev) prev.addEventListener('click', function () { go(active - 1, false, true); });
			if (next) next.addEventListener('click', function () { go(active + 1, false, true); });
			var startX = null;
			stage.addEventListener('pointerdown', function (e) { startX = e.clientX; });
			stage.addEventListener('pointerup', function (e) {
				if (startX === null) return;
				var dx = e.clientX - startX;
				startX = null;
				if (Math.abs(dx) > 40) {
					swallowClick = true;
					window.setTimeout(function () { swallowClick = false; }, 400);
					go(active + (dx < 0 ? 1 : -1), false, true);
				}
			});
			stage.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
					e.preventDefault();
					go(active + (e.key === 'ArrowRight' ? 1 : -1), true, true);
				}
			});
			positionSlides();
			root.classList.add('is-ready');
		}

		restartTimer();
		return { pause: setPaused, go: go };
	}

	/* ---------- Row, grid, list, one video, message pages: a click opens the player ---------- */

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
			if (!card || !root.contains(card) || !plainClick(e) || !HAS_DIALOG) return;
			var item = readItem(card);
			if (!item) return;
			e.preventDefault();
			Player.open(root, card.getAttribute('data-kind'), item, card.href);
		});
	}

	/* Start loading the player the moment someone shows interest in a card. */
	function initWarmup(root) {
		if (root.getAttribute('data-play') !== 'site') return;
		var warm = function (e) {
			if (e.pointerType && e.pointerType !== 'mouse' && e.type === 'pointerover') return;
			var card = e.target.closest && e.target.closest('.ftvs__card, [data-ftvs-watch], [data-ftvs-open]');
			if (!card || !root.contains(card)) return;
			Player.warm(card.getAttribute('data-kind'), readItem(card));
		};
		root.addEventListener('pointerover', warm, { passive: true });
		root.addEventListener('touchstart', warm, { passive: true });
		root.addEventListener('focusin', warm);
		// On a fast connection, fetch the video engine once the section is on screen.
		if ('IntersectionObserver' in window && !saveData()) {
			var io = new IntersectionObserver(function (list) {
				if (!list[0].isIntersecting) return;
				io.disconnect();
				var idle = window.requestIdleCallback || function (fn) { return window.setTimeout(fn, 1500); };
				var c = navigator.connection;
				if (!c || c.effectiveType === '4g') idle(function () { Player.warm('', null); });
			}, { rootMargin: '200px' });
			io.observe(root);
		}
	}

	/* Resume bars and "Watched" marks on video cards. */
	function markProgress(root) {
		if (!CONFIG.resume) return;
		var all = Progress.all();
		Array.prototype.forEach.call(root.querySelectorAll('.ftvs__card[data-kind="video"]'), function (card) {
			var item = readItem(card);
			var p = item ? all[item.id] : null;
			var thumb = card.querySelector('.ftvs__thumb');
			if (!p || !thumb || thumb.querySelector('.ftvs-prog')) return;
			if (p.done) {
				thumb.appendChild(el('span', { 'class': 'ftvs-watched', text: str('watched', 'Watched') }));
			} else if (p.d > 0 && p.t > 30) {
				var bar = el('span', { 'class': 'ftvs-prog', 'aria-hidden': 'true' }, [el('i')]);
				bar.firstChild.style.width = Math.min(100, Math.round((100 * p.t) / p.d)) + '%';
				thumb.appendChild(bar);
			}
		});
	}

	/* ---------- Sermon library: search, filters, load more ---------- */

	function initLibrary(root) {
		var form = root.querySelector('[data-ftvs-lib-form]');
		var filters = root.querySelector('[data-ftvs-lib-filters]');
		var list = root.querySelector('[data-ftvs-lib-list]');
		var count = root.querySelector('[data-ftvs-lib-count]');
		var moreBtn = root.querySelector('[data-ftvs-lib-more]');
		var per = Number(root.getAttribute('data-per')) || 24;
		var page = 1;
		var ask = 0;

		function params() {
			var p = { per: per, page: page, category: root.getAttribute('data-category') || '' };
			if (form) p.q = (form.q.value || '').trim();
			if (filters) {
				Array.prototype.forEach.call(filters.querySelectorAll('select'), function (s) {
					p[s.name] = s.value;
				});
			}
			return Object.keys(p).filter(function (k) { return p[k] !== '' && p[k] !== undefined; }).map(function (k) {
				return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]);
			}).join('&');
		}

		function row(v) {
			var meta = [v.speaker, shortDate(v.added), v.scripture].filter(Boolean).join('  ·  ');
			var a = el('a', {
				'class': 'ftvs__card ftvs-lib__row',
				href: v.watch || v.link || ('#faith-tv-' + (v.parent || '_') + '/' + v.id),
				'data-kind': 'video',
				'data-meta': duration(v.length),
				'data-item': JSON.stringify({ id: v.id, parent: v.parent, title: v.title, description: v.description, image: v.image, poster: v.poster, length: v.length, added: v.added, speaker: v.speaker, scripture: v.scripture, watch: v.watch, link: v.link, series: v.series })
			}, [
				el('span', { 'class': 'ftvs__thumb' }, [
					v.image ? el('img', { src: v.image, alt: '', loading: 'lazy', decoding: 'async' }) : null,
					v.length ? el('span', { 'class': 'ftvs-lib__len', text: duration(v.length) }) : null
				]),
				el('span', { 'class': 'ftvs-lib__text' }, [
					v.series ? el('span', { 'class': 'ftvs-lib__series', text: v.series }) : null,
					el('span', { 'class': 'ftvs__name', text: v.title }),
					meta ? el('span', { 'class': 'ftvs__meta', text: meta }) : null
				])
			]);
			return el('li', null, [a]);
		}

		function load(append) {
			var mine = ++ask;
			root.classList.add('is-loading');
			getJSON('library?' + params()).then(function (data) {
				if (mine !== ask) return;
				root.classList.remove('is-loading');
				if (!append) list.textContent = '';
				(data.videos || []).forEach(function (v) { list.appendChild(row(v)); });
				var total = data.total || 0;
				count.textContent = total === 1 ? str('oneMessage', '1 message') : str('nMessages', '%s messages').replace('%s', total.toLocaleString());
				if (!total) count.textContent = str('noResults', 'No messages match. Try other words.');
				if (moreBtn) moreBtn.hidden = page * per >= total;
				markProgress(root);
			}).catch(function () {
				if (mine !== ask) return;
				root.classList.remove('is-loading');
				count.textContent = str('failed', 'This video could not be loaded right now.');
			});
		}

		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				page = 1;
				load(false);
			});
			var typing = 0;
			form.q.addEventListener('input', function () {
				window.clearTimeout(typing);
				var q = form.q.value.trim();
				if (q.length === 1) return;
				typing = window.setTimeout(function () { page = 1; load(false); }, 400);
			});
		}
		if (filters) {
			filters.addEventListener('change', function () {
				page = 1;
				load(false);
			});
		}
		if (moreBtn) {
			moreBtn.addEventListener('click', function () {
				page++;
				load(true);
			});
		}
		initCards(root);
		initWarmup(root);
		markProgress(root);
	}

	/* ---------- Sunday live: countdown, live, replay ---------- */

	function countdown(ms) {
		var s = Math.max(0, Math.floor(ms / 1000));
		var d = Math.floor(s / 86400);
		var h = Math.floor((s % 86400) / 3600);
		var m = Math.floor((s % 3600) / 60);
		var parts = [];
		if (d) parts.push(d + str('d', 'd'));
		if (d || h) parts.push(h + str('h', 'h'));
		parts.push(m + str('m', 'm'));
		if (!d && !h) parts.push((s % 60) + str('s', 's'));
		return parts.join(' ');
	}

	function initLive(root) {
		var state = null;
		try { state = JSON.parse(root.getAttribute('data-state') || 'null'); } catch (err) {}
		var q = function (sel) { return root.querySelector(sel); };
		var img = q('[data-ftvs-live-img]');
		var badge = q('[data-ftvs-live-badge]');
		var kicker = q('[data-ftvs-live-kicker]');
		var title = q('[data-ftvs-live-title]');
		var when = q('[data-ftvs-live-when]');
		var count = q('[data-ftvs-countdown]');
		var cta = q('[data-ftvs-live-cta]');
		var chat = q('[data-ftvs-live-chat]');
		var remindBtn = q('[data-ftvs-remind-open]');
		var plays = Array.prototype.slice.call(root.querySelectorAll('[data-ftvs-live-play]'));
		var replayOn = root.getAttribute('data-replay') !== '0';
		var tick = 0;
		var poll = 0;

		function render(s) {
			state = s;
			var live = s.status === 'live' && s.play;
			var replay = replayOn ? s.replay : null;
			root.classList.toggle('is-live', !!live);
			root.classList.toggle('is-idle', !live);
			badge.textContent = live ? str('live', 'Live') : str('replay', 'Replay');
			badge.hidden = !live && !replay;
			kicker.textContent = live ? str('liveNow', 'Live now') : str('joinOnline', 'Join us online');
			title.textContent = live || !replay ? s.title : replay.title;
			var picture = live ? s.image : (replay ? (replay.poster || replay.image) : s.image);
			if (picture && img.getAttribute('src') !== picture) img.src = picture;
			img.hidden = !picture;
			if (live) {
				when.textContent = str('streaming', 'The service is streaming now.') + (s.viewers > 1 ? '  ·  ' + str('watching', '%d watching').replace('%d', s.viewers) : '');
			} else {
				when.textContent = s.next_label ? str('nextService', 'Next service: %s').replace('%s', s.next_label) : '';
			}
			cta.textContent = live ? str('watchLive', 'Watch live') : str('watchReplay', 'Watch the replay');
			plays.forEach(function (b) {
				b.hidden = b.tagName === 'BUTTON' && b.classList.contains('ftvs-btn') ? !(live || replay) : false;
				b.disabled = !(live || replay);
			});
			if (chat) {
				chat.hidden = !(live && s.chat);
				if (s.chat) chat.href = s.chat;
			}
			if (remindBtn) remindBtn.hidden = !!live;
			window.clearInterval(tick);
			count.hidden = true;
			if (!live && s.next) {
				var at = new Date(s.next).getTime();
				var draw = function () {
					var left = at - Date.now();
					if (left <= 0) {
						count.hidden = true;
						window.clearInterval(tick);
						schedule(5000);
						return;
					}
					count.hidden = left > 7 * 86400000;
					count.textContent = str('startsInT', 'Starts in %s').replace('%s', countdown(left));
				};
				draw();
				tick = window.setInterval(draw, 1000);
			}
		}

		function refresh() {
			if (document.hidden) {
				schedule(30000);
				return;
			}
			getJSON('live' + (root.getAttribute('data-channel') ? '?channel=' + encodeURIComponent(root.getAttribute('data-channel')) : '')).then(function (s) {
				var was = state && state.status;
				render(s);
				if (was && was !== s.status) emit('live', { status: s.status, title: s.title });
				// Ask often around service time, rarely otherwise.
				var soon = s.next && new Date(s.next).getTime() - Date.now() < 30 * 60000;
				schedule(s.status === 'live' || soon ? 30000 : 120000);
			}).catch(function () {
				schedule(120000);
			});
		}

		function schedule(ms) {
			window.clearTimeout(poll);
			poll = window.setTimeout(refresh, ms);
		}

		plays.forEach(function (b) {
			b.addEventListener('click', function () {
				if (!state || !HAS_DIALOG) return;
				if (state.status === 'live' && state.play) {
					Player.openLive(root, state);
				} else if (replayOn && state.replay) {
					Player.open(root, 'video', state.replay, state.replay.watch || '');
				}
			});
		});
		initRemind(root, function () { return state; });
		if (state) render(state);
		// The page may have come from a cache; check right away, then keep checking.
		schedule(state && state.status === 'live' ? 20000 : 1500);
	}

	/* "Remind me": email or mobile number, sent to the church's follow-up system. */
	function initRemind(root, getState) {
		var form = root.querySelector('[data-ftvs-remind]');
		var open = root.querySelector('[data-ftvs-remind-open]');
		if (!form) return;
		if (open) {
			open.addEventListener('click', function () {
				form.hidden = !form.hidden;
				if (!form.hidden) form.querySelector('input').focus();
				Embed.height();
			});
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var msg = form.querySelector('.ftvs-remind__msg');
			var s = getState ? getState() : null;
			var btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			postJSON('remind', {
				email: form.email.value,
				phone: form.phone.value,
				sms: form.sms.checked,
				hp: form.hp.value,
				kind: form.getAttribute('data-kind') || 'live',
				page: window.location.href,
				video: s && s.replay ? s.replay.id : '',
				title: s ? s.title : ''
			}).then(function () {
				msg.textContent = str('thanks', 'Thank you! We\'ll remind you.');
				Array.prototype.forEach.call(form.querySelectorAll('input, label'), function (x) { if (!x.classList.contains('ftvs-remind__hp')) x.hidden = true; });
				btn.hidden = true;
				emit('remind', { kind: form.getAttribute('data-kind') || 'live' });
			}).catch(function (err) {
				btn.disabled = false;
				msg.textContent = err && err.message && err.message.indexOf('HTTP') !== 0 ? err.message : str('tryAgain', 'That did not go through. Please try again.');
			});
		});
	}

	/* The "We're live" bar at the top of every page. */
	function initLiveBar(bar) {
		var hideKey = 'livebar-hidden';
		function show(s) {
			var live = s && s.status === 'live' && s.play;
			var hidden = false;
			try { hidden = window.sessionStorage.getItem('ftvs:' + hideKey) === (s && s.title); } catch (err) {}
			if (!live || hidden) {
				bar.hidden = true;
				return;
			}
			bar.textContent = '';
			var href = bar.getAttribute('data-href');
			var go = el(href ? 'a' : 'button', href ? { href: href, 'class': 'ftvs-livebar__go' } : { type: 'button', 'class': 'ftvs-livebar__go' }, [
				el('span', { 'class': 'ftvs-livebar__dot', 'aria-hidden': 'true' }),
				el('strong', { text: str('liveBar', 'We\'re live') }),
				el('span', { 'class': 'ftvs-livebar__title', text: s.title }),
				el('span', { 'class': 'ftvs-livebar__cta', text: str('watchNow', 'Watch now') })
			]);
			if (!href) {
				go.addEventListener('click', function () { if (HAS_DIALOG) Player.openLive(null, s); });
			}
			var x = el('button', { type: 'button', 'class': 'ftvs-livebar__x', 'aria-label': str('close', 'Close'), html: ICON_CLOSE });
			x.addEventListener('click', function () {
				bar.hidden = true;
				try { window.sessionStorage.setItem('ftvs:' + hideKey, s.title); } catch (err) {}
			});
			bar.appendChild(go);
			bar.appendChild(x);
			bar.hidden = false;
		}
		function refresh() {
			if (document.hidden) {
				window.setTimeout(refresh, 60000);
				return;
			}
			getJSON('live').then(function (s) {
				show(s);
				window.setTimeout(refresh, s.status === 'live' ? 60000 : 120000);
			}).catch(function () {
				window.setTimeout(refresh, 300000);
			});
		}
		window.setTimeout(refresh, 800);
	}

	var features = [];

	function initRoot(root) {
		if (root.getAttribute('data-ftvs-ready')) return;
		root.setAttribute('data-ftvs-ready', '1');
		var layout = root.getAttribute('data-layout') || 'row';
		if (layout === 'live') {
			initLive(root);
			return;
		}
		if (layout === 'library') {
			initLibrary(root);
			return;
		}
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
		initWarmup(root);
		markProgress(root);
	}

	function pauseAll(on) {
		features.forEach(function (f) {
			f.pause('dialog', on);
			// Closing the player hands focus back to the button that opened it.
			if (!on) f.pause('focus', false);
		});
	}

	/* ---------- Share ---------- */

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		return new Promise(function (resolve) {
			var ta = el('textarea', { style: 'position:fixed;opacity:0' });
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); } catch (err) {}
			ta.remove();
			resolve();
		});
	}

	function toast(text, near) {
		var t = el('div', { 'class': 'ftvs-toast', role: 'status', text: text });
		(near || document.body).appendChild(t);
		window.setTimeout(function () { t.classList.add('is-on'); }, 10);
		window.setTimeout(function () { t.remove(); }, 2400);
	}

	/* Phone: the share sheet (text, Facebook, WhatsApp). Computer: copy the link. */
	function share(url, title, near) {
		emit('share', { url: url, title: title });
		if (navigator.share && (isPhone() || 'ontouchstart' in window)) {
			return navigator.share({ title: title, url: url }).catch(function () {});
		}
		return copyText(url).then(function () { toast(str('copied', 'Link copied'), near); });
	}

	/* ---------- Online check-in: watching the live service signed in counts you present ---------- */

	var Checkin = (function () {
		var box = null;
		var timer = 0;
		var tick = 0;
		var active = false;
		var infoPromise = null;

		function enabled() {
			return !!(CONFIG.checkin && CONFIG.checkin.api && CONFIG.checkin.tenant);
		}

		function key() {
			return 'viewer:' + (CONFIG.checkin ? CONFIG.checkin.tenant : '');
		}

		function viewer() {
			return enabled() ? Store.get(key(), null) : null;
		}

		function token() {
			var v = viewer();
			return v && v.token ? v.token : '';
		}

		/* Faith Stream's own API, from the visitor's browser (the church's website is allowed; no cookies). */
		function api(path, method, body) {
			var headers = {};
			if (body) headers['Content-Type'] = 'application/json';
			if (token()) headers['X-Viewer-Token'] = token();
			var url = CONFIG.checkin.api + path + (path.indexOf('?') < 0 ? '?' : '&') + 'tenant=' + encodeURIComponent(CONFIG.checkin.tenant);
			return fetch(url, { method: method || 'GET', credentials: 'omit', headers: headers, body: body ? JSON.stringify(body) : undefined }).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					if (!r.ok) {
						var err = new Error(data && typeof data.detail === 'string' ? data.detail : 'HTTP ' + r.status);
						err.status = r.status;
						err.code = (data && data.code) || '';
						throw err;
					}
					return data;
				});
			});
		}

		/* The church's check-in settings (asked once per page). */
		function settings() {
			if (!infoPromise) {
				infoPromise = api('/api/tenant').then(function (t) {
					return (t && t.settings_public && t.settings_public.checkin) || null;
				}).catch(function () {
					infoPromise = null;
					return null;
				});
			}
			return infoPromise;
		}

		function start(container, playingFn) {
			if (!enabled() || !container) return;
			box = container;
			active = true;
			box.playing = playingFn;
			settings().then(function (s) {
				if (!active) return;
				if (!s || !s.enabled) {
					box.hidden = true;
					return;
				}
				render(s);
			});
		}

		function stop() {
			active = false;
			window.clearTimeout(timer);
			window.clearInterval(tick);
			if (box) {
				box.hidden = true;
				box.textContent = '';
			}
		}

		function render(s) {
			window.clearTimeout(timer);
			window.clearInterval(tick);
			box.hidden = false;
			box.textContent = '';
			if (viewer()) statusView(s);
			else signIn(s);
			Embed.height();
		}

		function field(label, attrs) {
			return el('label', { 'class': 'ftvs-ci__field' }, [el('span', { text: label }), el('input', attrs)]);
		}

		function saveViewer(res) {
			var v = res && res.viewer ? res.viewer : {};
			Store.set(key(), { token: res.viewer_token, name: v.name || v.email || '', kind: v.kind || '' });
		}

		function signIn(s) {
			var mode = s.mode || 'both';
			var msg = el('p', { 'class': 'ftvs-ci__msg', role: 'status' });
			var open = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--primary', text: str('countMe', 'Count me present') });
			var head = el('div', { 'class': 'ftvs-ci__head' }, [
				el('div', null, [
					el('p', { 'class': 'ftvs-ci__title', text: str('countTitle', 'Watching from home? Be counted in today\'s attendance.') }),
					el('p', { 'class': 'ftvs-ci__hint', text: str('countHint', 'Sign in once. After a few minutes of the service, you are checked in automatically.').replace('%d', s.threshold_minutes || 10) })
				]),
				open
			]);
			box.appendChild(head);
			var church = null;
			if (mode === 'fc' || mode === 'both') {
				var login = field(str('emailOrName', 'Email or first name'), { name: 'login', autocomplete: 'username', required: true });
				var pass = field(str('password', 'Password'), { name: 'password', type: 'password', autocomplete: 'current-password', required: true });
				var initial = field(str('lastInitial', 'First letter of your last name'), { name: 'initial', maxlength: '2' });
				initial.hidden = true;
				church = el('form', { 'class': 'ftvs-ci__form', hidden: true }, [
					el('p', { 'class': 'ftvs-ci__sub', text: str('churchAccount', 'Your church account (the one you use for the church app)') }),
					login, pass, initial,
					el('button', { type: 'submit', 'class': 'ftvs-btn ftvs-btn--primary', text: str('signIn', 'Sign in') })
				]);
				church.addEventListener('submit', function (e) {
					e.preventDefault();
					var who = church.login.value.trim();
					var body = who.indexOf('@') > 0 ? { email: who, password: church.password.value } : { first_name: who, password: church.password.value, last_initial: church.initial.value.trim() };
					msg.textContent = str('loading', 'Loading...');
					api('/api/viewer/fc/login', 'POST', body).then(function (res) {
						saveViewer(res);
						emit('checkin', { step: 'signed-in', kind: 'fc' });
						render(s);
					}).catch(function (err) {
						if (err.code === 'ambiguous' || err.status === 409) {
							initial.hidden = false;
							church.initial.focus();
						}
						msg.textContent = err.message && err.message.indexOf('HTTP') !== 0 ? err.message : str('tryAgain', 'That did not go through. Please try again.');
					});
				});
				box.appendChild(church);
			}
			var byEmail = null;
			if (mode === 'email' || mode === 'both') {
				byEmail = el('form', { 'class': 'ftvs-ci__form', hidden: true }, [
					el('p', { 'class': 'ftvs-ci__sub', text: str('justEmail', 'Or just your email and name') }),
					field(str('email', 'Email'), { name: 'email', type: 'email', autocomplete: 'email', required: true }),
					field(str('yourName', 'Your name'), { name: 'name', autocomplete: 'name' }),
					el('button', { type: 'submit', 'class': 'ftvs-btn ftvs-btn--light', text: str('useEmail', 'Use my email') })
				]);
				byEmail.addEventListener('submit', function (e) {
					e.preventDefault();
					msg.textContent = str('loading', 'Loading...');
					api('/api/viewer/email', 'POST', { email: byEmail.email.value.trim(), name: byEmail.name.value.trim() }).then(function (res) {
						saveViewer(res);
						emit('checkin', { step: 'signed-in', kind: 'email' });
						render(s);
					}).catch(function (err) {
						msg.textContent = err.message && err.message.indexOf('HTTP') !== 0 ? err.message : str('tryAgain', 'That did not go through. Please try again.');
					});
				});
				box.appendChild(byEmail);
			}
			box.appendChild(msg);
			open.addEventListener('click', function () {
				open.hidden = true;
				if (church) church.hidden = false;
				if (byEmail) byEmail.hidden = false;
				var first = box.querySelector('form:not([hidden]) input');
				if (first) first.focus();
				Embed.height();
			});
		}

		function statusView(s) {
			var v = viewer();
			var line = el('p', { 'class': 'ftvs-ci__who' }, [el('span', { text: str('signedInAs', 'Signed in as %s').replace('%s', v.name || '') + '  ' })]);
			var out = el('button', { type: 'button', 'class': 'ftvs-link-btn', text: str('notYou', 'Not you?') });
			out.addEventListener('click', function () {
				api('/api/viewer/logout', 'POST', {}).catch(function () {});
				Store.set(key(), null);
				render(s);
			});
			line.appendChild(out);
			var state = el('div', { 'class': 'ftvs-ci__state', 'aria-live': 'polite' });
			box.appendChild(state);
			box.appendChild(line);
			poll(s, state);
		}

		function poll(s, state) {
			api('/api/checkin/status').then(function (st) {
				if (!active) return;
				show(st, state);
				timer = window.setTimeout(function () { poll(s, state); }, st.status === 'checked_in' ? 300000 : 30000);
			}).catch(function (err) {
				if (!active) return;
				if (err.status === 401) {
					Store.set(key(), null);
					render(s);
					return;
				}
				timer = window.setTimeout(function () { poll(s, state); }, 120000);
			});
		}

		function show(st, state) {
			window.clearInterval(tick);
			state.textContent = '';
			if (st.status === 'checked_in') {
				state.className = 'ftvs-ci__state is-done';
				state.appendChild(el('p', { 'class': 'ftvs-ci__title', text: str('checkedIn', 'You\'re checked in. Thank you for joining us!') }));
				if (st.household_prompt) household(state);
				emit('checkin', { step: 'checked-in' });
				return;
			}
			state.className = 'ftvs-ci__state';
			if (st.status === 'failed') {
				state.appendChild(el('p', { 'class': 'ftvs-ci__title', text: str('checkinFailed', 'We couldn\'t check you in automatically. Let the church office know you joined online.') }));
				return;
			}
			if (!st.in_window && !st.checkin) {
				state.appendChild(el('p', { 'class': 'ftvs-ci__hint', text: str('checkinLater', 'Attendance counts while the service is live, during service times.') }));
				return;
			}
			var need = Math.max(60, st.threshold_s || 600);
			var watched = Math.min(need, st.watched_s || 0);
			var bar = el('span', { 'class': 'ftvs-ci__bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100' }, [el('i')]);
			var words = el('p', { 'class': 'ftvs-ci__title' });
			var draw = function () {
				var pct = Math.round((100 * watched) / need);
				bar.firstChild.style.width = pct + '%';
				bar.setAttribute('aria-valuenow', String(pct));
				words.textContent = str('watchedOf', 'Watched %1$d of %2$d minutes. Keep watching and you\'ll be checked in.').replace('%1$d', Math.floor(watched / 60)).replace('%2$d', Math.round(need / 60));
			};
			draw();
			state.appendChild(words);
			state.appendChild(bar);
			// Move between polls while the video plays (the server keeps the real count).
			tick = window.setInterval(function () {
				if (box.playing && box.playing()) {
					watched = Math.min(need, watched + 1);
					draw();
				}
			}, 1000);
		}

		/* "Watching with family?": check in the others in the household too. */
		function household(state) {
			var ask = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--light', text: str('withFamily', 'Watching with family?') });
			state.appendChild(ask);
			ask.addEventListener('click', function () {
				ask.hidden = true;
				var wrap = el('div', { 'class': 'ftvs-ci__family' }, [el('p', { 'class': 'ftvs-ci__sub', text: str('whoWatching', 'Who\'s watching with you?') })]);
				state.appendChild(wrap);
				api('/api/checkin/household').then(function (r) {
					var members = Array.isArray(r) ? r : (r.members || []);
					var kid = function (m) {
						var role = String(m.role || m.kind || '').toLowerCase();
						return role === 'child' || role === 'kid' || role === 'kids' || role === 'teen';
					};
					var boxes = [];
					members.forEach(function (m) {
						var already = !!(m.checkedIn || m.checked_in);
						var input = el('input', { type: 'checkbox', value: m.id, disabled: already, checked: already });
						boxes.push({ input: input, member: m });
						wrap.appendChild(el('label', { 'class': 'ftvs-ci__member' + (already ? ' is-done' : '') }, [input, el('span', { text: ' ' + m.name + (already ? '  (' + str('already', 'already checked in') + ')' : '') })]));
					});
					var send = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--primary', text: str('checkThemIn', 'Check them in') });
					send.addEventListener('click', function () {
						var picked = boxes.filter(function (b) { return b.input.checked && !b.input.disabled; }).map(function (b) { return b.member; });
						send.disabled = true;
						api('/api/checkin/household', 'POST', {
							adult_ids: picked.filter(function (m) { return !kid(m); }).map(function (m) { return m.id; }),
							kid_ids: picked.filter(kid).map(function (m) { return m.id; })
						}).then(function () {
							wrap.textContent = '';
							wrap.appendChild(el('p', { 'class': 'ftvs-ci__sub', text: str('familyDone', 'Thank you! They\'re checked in too.') }));
							emit('checkin', { step: 'household', count: picked.length });
						}).catch(function (err) {
							send.disabled = false;
							wrap.appendChild(el('p', { 'class': 'ftvs-ci__msg', text: err.message || str('tryAgain', 'That did not go through. Please try again.') }));
						});
					});
					if (!members.length) wrap.appendChild(el('p', { 'class': 'ftvs-ci__hint', text: str('noFamily', 'No one else is in your household on file.') }));
					else wrap.appendChild(send);
					Embed.height();
				}).catch(function (err) {
					wrap.appendChild(el('p', { 'class': 'ftvs-ci__msg', text: err.message || str('tryAgain', 'That did not go through. Please try again.') }));
				});
			});
		}

		return { start: start, stop: stop, token: token, enabled: enabled };
	})();

	/* ---------- The player dialog (one per page) ---------- */

	var Player = (function () {
		var dialog, grab, playerWrap, video, frame, posterBtn, errorBox, endBox, chipBox, backBtn, shareBtn, ambient, kickerEl, titleEl, liveEl, nowEl, metaEl, descEl, nextEl, statusEl, catsEl, epsLabel, epsEl, tvLink, poweredEl;
		var toolsEl, listenBtn, transcriptBtn, transcriptEl, checkinEl;
		var listening = false;
		var transcriptFor = '';
		var stack = [];
		var episodes = [];
		var current = -1;
		var token = 0;
		var hls = null;
		var hlsPromise = null;
		var scrollBeforeOpen = 0;
		var isOpen = false;
		var pushed = false;
		var label = '';
		var sectionRoot = null;
		var catCache = {};
		var videoInfo = {}; // REST answers for the playing video (related, series, watch)
		var reporter = null;
		var counted = {};
		var milestones = {};
		var upTimer = 0;
		var saveTimer = 0;
		var startAt = 0;
		var retries = 0;

		function build() {
			if (dialog) return;
			backBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__btn ftvs-dialog__back', hidden: true }, [
				el('span', { html: ICON_BACK }),
				el('span', { text: str('back', 'Back') })
			]);
			shareBtn = CONFIG.share ? el('button', { type: 'button', 'class': 'ftvs-dialog__btn ftvs-dialog__share', 'aria-label': str('share', 'Share') }, [
				el('span', { html: ICON_SHARE }),
				el('span', { 'class': 'ftvs-dialog__btn-text', text: str('share', 'Share') })
			]) : null;
			var closeBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__btn ftvs-dialog__close', 'aria-label': str('close', 'Close'), html: ICON_CLOSE });
			video = el('video', { controls: true, playsinline: true, preload: 'none' });
			posterBtn = el('button', { type: 'button', 'class': 'ftvs-dialog__poster' }, [el('span', { html: ICON_PLAY })]);
			errorBox = el('p', { 'class': 'ftvs-dialog__error', role: 'alert', hidden: true });
			endBox = el('div', { 'class': 'ftvs-end', hidden: true });
			chipBox = el('div', { 'class': 'ftvs-chip-note', role: 'status', hidden: true });
			ambient = el('img', { 'class': 'ftvs-dialog__ambient', alt: '', 'aria-hidden': 'true' });
			kickerEl = el('p', { 'class': 'ftvs-kicker' });
			titleEl = el('h2', { 'class': 'ftvs-dialog__title', id: 'ftvs-dialog-title' });
			liveEl = el('p', { 'class': 'ftvs-dialog__live', hidden: true });
			checkinEl = el('div', { 'class': 'ftvs-ci', hidden: true });
			nowEl = el('p', { 'class': 'ftvs-dialog__now', hidden: true });
			metaEl = el('p', { 'class': 'ftvs-dialog__meta', hidden: true });
			descEl = el('p', { 'class': 'ftvs-dialog__desc' });
			nextEl = el('div', { 'class': 'ftvs-next', hidden: true });
			listenBtn = el('button', { type: 'button', 'class': 'ftvs-tool', 'aria-pressed': 'false', hidden: true, text: str('listen', 'Listen') });
			transcriptBtn = el('button', { type: 'button', 'class': 'ftvs-tool', 'aria-expanded': 'false', hidden: true, text: str('transcript', 'Transcript') });
			toolsEl = el('div', { 'class': 'ftvs-dialog__tools', hidden: true }, [listenBtn, transcriptBtn]);
			transcriptEl = el('div', { 'class': 'ftvs-transcript', hidden: true });
			statusEl = el('p', { 'class': 'ftvs-dialog__status', role: 'status' });
			catsEl = el('ul', { 'class': 'ftvs-dialog__cats', role: 'list', hidden: true });
			epsLabel = el('h3', { 'class': 'ftvs-dialog__label', hidden: true });
			epsEl = el('ol', { 'class': 'ftvs-dialog__eps', hidden: true });
			tvLink = el('a', { 'class': 'ftvs-btn ftvs-btn--light ftvs-dialog__tv', target: '_blank', rel: 'noopener', hidden: true });
			tvLink.innerHTML = ICON_OUT;
			tvLink.appendChild(el('span', { 'data-ftvs-tv-text': true, text: str('watchOnTv', 'Watch on Faith TV') }));

			grab = el('div', { 'class': 'ftvs-dialog__grab', 'aria-hidden': 'true' });
			if (CONFIG.powered && CONFIG.brand && CONFIG.brand.name) {
				poweredEl = el('p', { 'class': 'ftvs-dialog__powered' }, [
					document.createTextNode(str('poweredBy', 'Powered by') + ' '),
					el('a', { href: CONFIG.brand.url, target: '_blank', rel: 'noopener' }, [el('b', { text: CONFIG.brand.name })])
				]);
			}
			dialog = el('dialog', { 'class': 'ftvs-dialog', 'aria-labelledby': 'ftvs-dialog-title' }, [
				grab,
				el('div', { 'class': 'ftvs-dialog__bar' }, [backBtn, el('span', { 'class': 'ftvs-grow' }), shareBtn, closeBtn]),
				(playerWrap = el('div', { 'class': 'ftvs-dialog__player' }, [video, posterBtn, errorBox, endBox, chipBox])),
				el('div', { 'class': 'ftvs-dialog__body' }, [
					ambient,
					el('div', { 'class': 'ftvs-dialog__inner' }, [kickerEl, titleEl, liveEl, checkinEl, nowEl, metaEl, toolsEl, descEl, transcriptEl, nextEl, statusEl, catsEl, epsLabel, epsEl, tvLink, poweredEl])
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
			if (shareBtn) shareBtn.addEventListener('click', shareCurrent);
			posterBtn.addEventListener('click', function () {
				play(current < 0 ? 0 : current, true);
			});
			// A click on the dark area around the box closes it.
			dialog.addEventListener('click', function (e) {
				if (e.target !== dialog) return;
				var r = dialog.getBoundingClientRect();
				if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close();
			});
			dialog.addEventListener('close', function () { cleanup(false); });
			// The viewer's volume (and mute) carry over between videos and visits.
			var vol = Store.get('vol', null);
			if (vol && typeof vol.v === 'number') {
				video.volume = Math.max(0, Math.min(1, vol.v));
				video.muted = !!vol.m;
			}
			video.addEventListener('volumechange', function () { Store.set('vol', { v: video.volume, m: video.muted }); });
			listenBtn.addEventListener('click', function () { useAudio(!listening); });
			transcriptBtn.addEventListener('click', toggleTranscript);
			video.addEventListener('ended', onEnded);
			video.addEventListener('playing', onPlaying);
			video.addEventListener('pause', function () {
				dialog.classList.remove('is-playing');
				if (reporter) {
					reporter.paused = true;
					reporter.event('pause', video.currentTime);
				}
				saveProgress();
			});
			video.addEventListener('play', function () { if (reporter) reporter.paused = false; });
			video.addEventListener('timeupdate', onTime);
			video.addEventListener('error', onNativeError);
			if (video.textTracks && video.textTracks.addEventListener) {
				video.textTracks.addEventListener('change', rememberCaptions);
			}
			window.addEventListener('popstate', function () {
				// Back button or back swipe on a phone: close the player, stay on the page.
				if (isOpen && pushed && !(window.history.state && window.history.state.ftvs)) {
					pushed = false;
					close();
				}
			});
			window.addEventListener('pagehide', function () {
				saveProgress();
				if (reporter) reporter.stop(video.currentTime);
			});
		}

		function close() {
			var hadPush = pushed;
			cleanup(true);
			if (dialog.open) dialog.close();
			if (hadPush && window.history.state && window.history.state.ftvs) window.history.back();
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

		function hashFor(entry, video, at) {
			if (entry.kind === 'live') return '';
			var series = entry.kind === 'category' ? entry.item.id : (entry.item.parent || '_');
			var vid = entry.kind === 'video' ? entry.item.id : (video ? video.id : '');
			return '#faith-tv-' + series + (vid ? '/' + vid : '') + (at ? '@' + at : '');
		}

		function setHash(entry) {
			if (CONFIG.embed || !window.history || !window.history.replaceState) return;
			var h = hashFor(entry);
			if (!h) return;
			var url = window.location.pathname + window.location.search + h;
			if (!pushed && window.history.pushState) {
				window.history.pushState({ ftvs: 1 }, '', url);
				pushed = true;
			} else {
				window.history.replaceState({ ftvs: 1 }, '', url);
			}
		}

		function begin(root) {
			build();
			sectionRoot = root;
			// The section's accent color (Elementor setting) carries into the player.
			var accent = root ? window.getComputedStyle(root).getPropertyValue('--ftvs-accent') : '';
			if (accent) dialog.style.setProperty('--ftvs-accent', accent.trim());
			var onAccent = root ? window.getComputedStyle(root).getPropertyValue('--ftvs-on-accent') : '';
			if (onAccent) dialog.style.setProperty('--ftvs-on-accent', onAccent.trim());
			// The section's style (Bold, Soft, Minimal) carries over too.
			['bold', 'soft', 'minimal'].forEach(function (name) {
				dialog.classList.toggle('ftvs--style-' + name, !!(root && root.classList.contains('ftvs--style-' + name)));
			});
			label = root ? root.getAttribute('data-label') || '' : '';
		}

		function reveal() {
			if (!isOpen) {
				isOpen = true;
				scrollBeforeOpen = window.scrollY;
				document.documentElement.classList.add('ftvs-lock');
				if (!dialog.open) dialog.showModal();
				pauseAll(true);
				Embed.show();
			}
		}

		/**
		 * @param opts { video: id to play first, t: seconds to start at }
		 * @return true when the player opened (false: the browser can't, so the link should work normally)
		 */
		function open(root, kind, item, href, opts) {
			if (!HAS_DIALOG || !item) return false;
			begin(root);
			stack = [{ kind: kind === 'video' ? 'video' : 'category', item: item, href: href }];
			show(stack[0], opts || {});
			reveal();
			setHash(stack[0]);
			emit('open', { kind: stack[0].kind, id: item.id, title: item.title || '' });
			return true;
		}

		function openLive(root, state) {
			if (!HAS_DIALOG || !state || !state.play) return false;
			begin(root);
			var item = { id: state.play.id || 'live', title: state.title, image: state.image, poster: state.image };
			stack = [{ kind: 'live', item: item, href: state.link || '', state: state }];
			show(stack[0], {});
			reveal();
			return true;
		}

		/* Warm-up: the video engine and a series' episode list, before the click. */
		function warm(kind, item) {
			if (needsHls() && !saveData()) loadHls().catch(function () {});
			if (kind === 'category' && item && !catCache[item.id]) {
				catCache[item.id] = getJSON('category/' + item.id).catch(function (err) {
					delete catCache[item.id];
					throw err;
				});
			}
		}

		function reset() {
			token++;
			stopVideo();
			episodes = [];
			current = -1;
			videoInfo = {};
			errorBox.hidden = true;
			endBox.hidden = true;
			endBox.textContent = '';
			chipBox.hidden = true;
			nowEl.hidden = true;
			liveEl.hidden = true;
			metaEl.hidden = true;
			nextEl.hidden = true;
			catsEl.hidden = true;
			catsEl.textContent = '';
			epsEl.hidden = true;
			epsLabel.hidden = true;
			epsEl.textContent = '';
			statusEl.textContent = '';
			statusEl.hidden = true;
			window.clearTimeout(upTimer);
			toolsEl.hidden = true;
			listenBtn.hidden = true;
			transcriptBtn.hidden = true;
			transcriptEl.hidden = true;
			transcriptEl.textContent = '';
			transcriptBtn.setAttribute('aria-expanded', 'false');
			transcriptFor = '';
			Checkin.stop();
		}

		function show(entry, opts) {
			opts = opts || {};
			reset();
			var item = entry.item;
			backBtn.hidden = stack.length < 2;
			kickerEl.textContent = entry.kind === 'live' ? str('liveNow', 'Live now') : (stack.length > 1 ? stack[stack.length - 2].item.title : label);
			kickerEl.hidden = !kickerEl.textContent;
			titleEl.textContent = item.title || '';
			descEl.textContent = item.description || '';
			descEl.hidden = !item.description;
			tvLink.href = entry.href || '#';
			tvLink.hidden = !entry.href;
			tvLink.querySelector('[data-ftvs-tv-text]').textContent = entry.kind === 'live' ? str('openChannel', 'Open the chat') : str('watchOnTv', 'Watch on Faith TV');
			var amb = item.image || item.poster || '';
			if (amb) ambient.src = amb;
			ambient.hidden = !amb;
			playerWrap.hidden = false;
			if (shareBtn) shareBtn.hidden = entry.kind === 'live';
			setPoster(item.poster || item.image || '');
			dialog.scrollTop = 0;
			dialog.classList.toggle('is-live', entry.kind === 'live');
			if (entry !== stack[0]) setHash(entry);

			if (entry.kind === 'live') {
				var s = entry.state;
				liveEl.hidden = false;
				liveEl.textContent = str('live', 'Live') + (s.viewers > 1 ? '  ·  ' + str('watching', '%d watching').replace('%d', s.viewers) : '');
				tvLink.hidden = !s.chat;
				if (s.chat) tvLink.href = s.chat;
				episodes = [item];
				current = 0;
				playLive(s);
				// Faith Stream live only: signed-in viewers are counted present.
				if (s.play && s.play.kind === 'hls' && s.play.id) {
					Checkin.start(checkinEl, function () { return !video.paused; });
				}
				return;
			}

			if (entry.kind === 'video') {
				episodes = [item];
				showMeta(item);
				renderNext(item);
				startAt = opts.t ? Number(opts.t) : 0;
				play(0, true);
				return;
			}

			var mine = token;
			statusEl.hidden = false;
			statusEl.textContent = str('loading', 'Loading...');
			var request = catCache[item.id] || getJSON('category/' + item.id);
			delete catCache[item.id];
			request.then(function (data) {
				if (mine !== token) return;
				statusEl.hidden = true;
				if (data.link) {
					tvLink.href = data.link;
					tvLink.hidden = false;
				}
				if (!item.title && data.self && data.self.title) {
					item.title = data.self.title;
					titleEl.textContent = item.title;
				}
				renderCategories(data.categories || []);
				renderEpisodes(data.videos || [], opts);
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

		function showMeta(ep) {
			var bits = [ep.speaker, shortDate(ep.added), ep.scripture].filter(Boolean);
			metaEl.textContent = bits.join('  ·  ');
			metaEl.hidden = !bits.length;
		}

		/* The church's next-step buttons: this section's own, else this series' button first, then the site's. */
		function steps(ep) {
			var own = sectionRoot ? sectionRoot.getAttribute('data-next') : '';
			if (own === 'off') return [];
			if (own) {
				try { return JSON.parse(own); } catch (err) {}
			}
			var list = ((CONFIG.next && CONFIG.next.steps) || []).slice();
			var bySeries = (CONFIG.next && CONFIG.next.series) || {};
			var entry = stack[0];
			var ids = [ep && ep.parent, entry && entry.kind === 'category' ? entry.item.id : '', sectionRoot ? sectionRoot.getAttribute('data-category') : ''];
			for (var i = 0; i < ids.length; i++) {
				if (ids[i] && bySeries[ids[i]]) {
					list.unshift(bySeries[ids[i]]);
					break;
				}
			}
			return list.slice(0, 3);
		}

		function tagged(url, ep) {
			try {
				var u = new URL(url, window.location.href);
				u.searchParams.set('utm_source', 'faith-tv');
				u.searchParams.set('utm_medium', CONFIG.embed ? 'embed' : 'website');
				u.searchParams.set('utm_campaign', (ep && ep.parent) || (stack[0] && stack[0].item.id) || 'video');
				if (ep) u.searchParams.set('utm_content', ep.id);
				return u.toString();
			} catch (err) {
				return url;
			}
		}

		function stepButtons(ep, into) {
			var list = steps(ep);
			if (!list.length) return false;
			into.appendChild(el('p', { 'class': 'ftvs-next__title', text: (CONFIG.next && CONFIG.next.title) || '' }));
			var row = el('div', { 'class': 'ftvs-next__btns' });
			list.forEach(function (s, i) {
				var a = el('a', { 'class': 'ftvs-btn ' + (i === 0 ? 'ftvs-btn--primary' : 'ftvs-btn--light'), href: tagged(s.url, ep), target: CONFIG.embed ? '_top' : null, text: s.label });
				a.addEventListener('click', function () { emit('nextstep', { label: s.label, url: s.url, video: ep ? ep.id : '' }); });
				row.appendChild(a);
			});
			into.appendChild(row);
			return true;
		}

		function renderNext(ep) {
			nextEl.textContent = '';
			nextEl.hidden = !stepButtons(ep, nextEl);
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

		function renderEpisodes(list, opts) {
			episodes = list;
			if (!list.length) return;
			var saved = Progress.all();
			epsLabel.hidden = false;
			epsLabel.textContent = str('episodes', 'Episodes') + ' (' + list.length + ')';
			epsEl.hidden = false;
			list.forEach(function (ep, i) {
				var when = shortDate(ep.added);
				var len = duration(ep.length);
				var p = saved[ep.id];
				var bar = null;
				if (p && !p.done && p.d > 0 && p.t > 30) {
					bar = el('span', { 'class': 'ftvs-prog', 'aria-hidden': 'true' }, [el('i')]);
					bar.firstChild.style.width = Math.min(100, Math.round((100 * p.t) / p.d)) + '%';
				}
				var btn = el('button', { type: 'button', 'class': 'ftvs-dialog__ep' + (p && p.done ? ' is-watched' : ''), 'data-index': i }, [
					el('span', { 'class': 'ftvs-dialog__ep-thumb' }, [
						ep.image ? el('img', { src: ep.image, alt: '', loading: 'lazy' }) : null,
						len ? el('span', { 'class': 'ftvs-dialog__ep-len', text: len }) : null,
						bar,
						el('span', { 'class': 'ftvs-eq', 'aria-hidden': 'true' }, [el('i'), el('i'), el('i')])
					]),
					el('span', { 'class': 'ftvs-dialog__ep-text' }, [
						list.length > 1 ? el('span', { 'class': 'ftvs-dialog__ep-num', text: str('episodeN', 'Episode %d').replace('%d', i + 1) }) : null,
						el('span', { 'class': 'ftvs-dialog__ep-title', text: ep.title }),
						when || ep.speaker ? el('span', { 'class': 'ftvs-dialog__ep-meta', text: [ep.speaker, when].filter(Boolean).join('  ·  ') }) : null,
						p && p.done ? el('span', { 'class': 'ftvs-dialog__ep-done', text: str('watched', 'Watched') }) : null,
						ep.description ? el('span', { 'class': 'ftvs-dialog__ep-desc', text: ep.description }) : null
					])
				]);
				btn.addEventListener('click', function () {
					play(i, true);
					dialog.scrollTo({ top: 0, behavior: smooth() });
				});
				epsEl.appendChild(el('li', null, [btn]));
			});
			// Ready to go: the asked-for episode, or the first one not finished yet.
			current = 0;
			if (opts && opts.video) {
				list.forEach(function (ep, i) { if (ep.id === opts.video) current = i; });
			}
			setPoster(list[current].poster || list[current].image || '');
			markCurrent();
			showMeta(list[current]);
			renderNext(list[current]);
			if (opts && opts.video) {
				startAt = opts.t ? Number(opts.t) : 0;
				play(current, true);
			}
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

		function entryOf() {
			return stack[stack.length - 1];
		}

		function play(index, autoplay) {
			var ep = episodes[index];
			if (!ep) return;
			current = index;
			markCurrent();
			errorBox.hidden = true;
			endBox.hidden = true;
			window.clearTimeout(upTimer);
			if (episodes.length > 1) {
				nowEl.hidden = false;
				nowEl.textContent = str('nowPlay', 'Now playing') + ': ' + ep.title;
			}
			showMeta(ep);
			renderNext(ep);
			stopVideo();
			video.poster = ep.poster || ep.image || '';
			video.setAttribute('aria-label', ep.title || '');
			posterBtn.hidden = true;
			retries = 0;
			milestones = {};
			var entry = entryOf();
			if (entry.kind === 'category') setHash({ kind: 'video', item: { id: ep.id, parent: entry.item.id } });
			var mine = ++token;
			getJSON('video/' + ep.id).then(function (data) {
				if (mine !== token) return;
				videoInfo = data || {};
				if (!ep.watch && data.watch) ep.watch = data.watch;
				if (data.item && !ep.title) {
					// Opened from a shared link: fill in what the link didn't carry.
					Object.keys(data.item).forEach(function (k) { if (!ep[k]) ep[k] = data.item[k]; });
					titleEl.textContent = ep.title || '';
					descEl.textContent = ep.description || '';
					descEl.hidden = !ep.description;
					showMeta(ep);
				}
				reporter = new Reporter('video', ep.id);
				reporter.position = function () { return video.currentTime; };
				if (data.embed) return attachEmbed(data.embed, ep);
				listenBtn.hidden = !data.audio;
				toolsEl.hidden = listenBtn.hidden && transcriptBtn.hidden;
				var wantsAudio = !!(data.audio && Store.get('listen', false));
				return (wantsAudio ? attachAudio(data.audio) : attach(data.hls)).then(function () {
					if (mine !== token) return;
					seekStart(ep);
					if (!autoplay) return;
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

		function playLive(s) {
			var mine = ++token;
			stopVideo();
			posterBtn.hidden = true;
			video.poster = s.image || '';
			video.setAttribute('aria-label', s.title || '');
			reporter = new Reporter('live', s.play.id);
			reporter.position = function () { return video.currentTime; };
			if (s.play.kind === 'embed') {
				attachEmbed(s.play.src, null);
				countLocal('live', { id: s.play.id || 'live', title: s.title });
				return;
			}
			attach(s.play.src).then(function () {
				if (mine !== token) return;
				var p = video.play();
				if (p && p.catch) p.catch(function () { if (mine === token) posterBtn.hidden = false; });
			}).catch(function () {
				if (mine === token) showError();
			});
		}

		/* Pick up where they left off, or start where a shared link says. */
		function seekStart(ep) {
			var want = startAt;
			startAt = 0;
			var saved = CONFIG.resume ? Progress.of(ep.id) : null;
			if (!want && saved && !saved.done && saved.t > 30 && (!saved.d || saved.t < saved.d - 30)) {
				want = saved.t;
				chip(str('resumed', 'Picking up where you left off (%s)').replace('%s', duration(want)), function () {
					video.currentTime = 0;
				});
			}
			if (!want) return;
			var go = function () {
				try { video.currentTime = want; } catch (err) {}
			};
			if (video.readyState >= 1) go();
			else video.addEventListener('loadedmetadata', go, { once: true });
		}

		function chip(text, onStartOver) {
			chipBox.textContent = '';
			chipBox.appendChild(el('span', { text: text }));
			var again = el('button', { type: 'button', text: str('startOver', 'Start over') });
			again.addEventListener('click', function () {
				chipBox.hidden = true;
				onStartOver();
			});
			chipBox.appendChild(again);
			chipBox.hidden = false;
			window.setTimeout(function () { chipBox.hidden = true; }, 7000);
		}

		function needsHls() {
			if (!video && !document.createElement('video').canPlayType) return false;
			var v = video || document.createElement('video');
			var native = !!v.canPlayType('application/vnd.apple.mpegurl');
			var safari = /^((?!chrome|chromium|android|crios|fxios|edg).)*safari/i.test(navigator.userAgent);
			return !(native && (safari || !(window.MediaSource || window.ManagedMediaSource)));
		}

		function attach(url) {
			if (!url || url.indexOf('https://') !== 0) return Promise.reject(new Error('no stream'));
			video.hidden = false;
			// Safari plays every stream itself. Elsewhere hls.js is used even where the browser has
			// its own HLS, because only hls.js handles streams with a separate audio track (Mux).
			var native = !!video.canPlayType('application/vnd.apple.mpegurl');
			if (!needsHls()) {
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
					var mediaFixes = 0;
					hls = new Hls({ capLevelToPlayerSize: true, renderTextTracksNatively: true });
					hls.on(Hls.Events.MANIFEST_PARSED, function () {
						ready = true;
						pickCaptions();
						showTools();
						resolve();
					});
					hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, function () {
						pickCaptions();
						showTools();
					});
					hls.on(Hls.Events.ERROR, function (evt, data) {
						if (!data || !data.fatal) return;
						if (!ready) {
							reject(new Error('stream failed'));
							return;
						}
						// Recover instead of giving up: a Wi-Fi blip, or an address that expired while paused.
						var code = data.response && data.response.code;
						if (data.type === Hls.ErrorTypes.MEDIA_ERROR && mediaFixes < 2) {
							if (mediaFixes++ === 1) hls.swapAudioCodec();
							hls.recoverMediaError();
							return;
						}
						if (data.type === Hls.ErrorTypes.NETWORK_ERROR && retries < 3) {
							retries++;
							softError();
							if (code === 401 || code === 403 || code === 410 || /manifest/i.test(data.details || '')) {
								refreshStream();
							} else {
								window.setTimeout(function () { if (hls) hls.startLoad(); }, 1000 * retries);
							}
							return;
						}
						showError();
					});
					hls.on(Hls.Events.FRAG_LOADED, function () {
						if (!errorBox.hidden && errorBox.classList.contains('is-soft')) errorBox.hidden = true;
					});
					hls.loadSource(url);
					hls.attachMedia(video);
				});
			}).catch(function (err) {
				if (!native) throw err;
				video.src = url;
			});
		}

		/* A fresh (newly signed) address for the same video, picking up at the same spot. */
		function refreshStream() {
			var ep = episodes[current];
			var entry = entryOf();
			var at = video.currentTime;
			var mine = token;
			var path = entry && entry.kind === 'live' ? 'live' : 'video/' + (ep ? ep.id : '') + '?fresh=' + Date.now();
			getJSON(path).then(function (data) {
				if (mine !== token) return;
				var url = entry && entry.kind === 'live' ? (data.play && data.play.src) : data.hls;
				if (!url) throw new Error('gone');
				if (hls) {
					hls.loadSource(url);
					hls.once && hls.once(window.Hls.Events.MANIFEST_PARSED, function () {
						if (entry.kind !== 'live') {
							try { video.currentTime = at; } catch (err) {}
						}
						video.play().catch(function () {});
					});
				} else {
					video.src = url;
					video.addEventListener('loadedmetadata', function () {
						if (entry.kind !== 'live') {
							try { video.currentTime = at; } catch (err) {}
						}
						video.play().catch(function () {});
					}, { once: true });
				}
			}).catch(function () {
				if (mine === token) showError();
			});
		}

		function onNativeError() {
			if (hls || !video.getAttribute('src') || !isOpen) return;
			if (retries++ < 2) {
				softError();
				refreshStream();
			} else {
				showError();
			}
		}

		/* YouTube, Vimeo and other players that come as an embed. */
		function attachEmbed(src, ep) {
			video.hidden = true;
			posterBtn.hidden = true;
			frame = el('iframe', { src: src, allow: 'autoplay; fullscreen; picture-in-picture; encrypted-media', allowfullscreen: true, title: ep ? ep.title : '', 'class': 'ftvs-dialog__frame' });
			playerWrap.insertBefore(frame, video);
			dialog.classList.add('is-playing');
			frame.addEventListener('load', function () {
				try {
					// Ask the player to tell us when the video ends (so the next episode can start).
					frame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 'ftvs' }), '*');
					frame.contentWindow.postMessage(JSON.stringify({ method: 'addEventListener', value: 'ended' }), '*');
				} catch (err) {}
			});
			if (ep) {
				count('play', ep);
				emit('play', { id: ep.id, title: ep.title });
			}
			return Promise.resolve();
		}

		window.addEventListener('message', function (e) {
			if (!frame || e.source !== frame.contentWindow) return;
			var d = e.data;
			if (typeof d === 'string') {
				try { d = JSON.parse(d); } catch (err) { return; }
			}
			if (!d) return;
			var ended = (d.event === 'onStateChange' && d.info === 0) || (d.event === 'infoDelivery' && d.info && d.info.playerState === 0) || d.event === 'ended';
			if (ended) onEnded();
		});

		function showTools() {
			transcriptBtn.hidden = !(hls && hls.subtitleTracks && hls.subtitleTracks.length);
			toolsEl.hidden = listenBtn.hidden && transcriptBtn.hidden;
		}

		/* Listen: just the sound (uses little data), for the car or the kitchen. */
		function attachAudio(url) {
			if (!url || url.indexOf('https://') !== 0) return Promise.reject(new Error('no audio'));
			listening = true;
			listenBtn.setAttribute('aria-pressed', 'true');
			listenBtn.textContent = str('watchVideo', 'Watch the video');
			dialog.classList.add('is-listening');
			video.src = url;
			return Promise.resolve();
		}

		function useAudio(on) {
			var at = video.currentTime;
			var playing = !video.paused;
			Store.set('listen', on);
			var mine = token;
			if (hls) {
				hls.destroy();
				hls = null;
			}
			video.removeAttribute('src');
			var done = function () {
				if (mine !== token) return;
				var go = function () {
					try { video.currentTime = at; } catch (err) {}
					if (playing) video.play().catch(function () {});
				};
				if (video.readyState >= 1) go();
				else video.addEventListener('loadedmetadata', go, { once: true });
			};
			if (on) {
				attachAudio(videoInfo.audio).then(done);
			} else {
				listening = false;
				listenBtn.setAttribute('aria-pressed', 'false');
				listenBtn.textContent = str('listen', 'Listen');
				dialog.classList.remove('is-listening');
				attach(videoInfo.hls).then(done).catch(function () { if (mine === token) showError(); });
			}
		}

		/* Transcript: the captions as text; a line takes the video to that moment. */
		function toggleTranscript() {
			var open = transcriptEl.hidden;
			transcriptEl.hidden = !open;
			transcriptBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
			var ep = episodes[current];
			if (!open || !ep || transcriptFor === ep.id || !hls || !hls.subtitleTracks.length) return;
			transcriptFor = ep.id;
			transcriptEl.textContent = str('loading', 'Loading...');
			var want = Store.get('cc', '');
			var track = hls.subtitleTracks.filter(function (t) { return (t.lang || t.name) === want; })[0] || hls.subtitleTracks[0];
			var base = track.url;
			fetch(base).then(function (r) { return r.text(); }).then(function (playlist) {
				var parts = playlist.split(/\r?\n/).filter(function (l) { return l && l.charAt(0) !== '#'; }).slice(0, 80);
				return Promise.all(parts.map(function (p) {
					return fetch(new URL(p, base).toString()).then(function (r) { return r.text(); }).catch(function () { return ''; });
				}));
			}).then(function (files) {
				if (transcriptFor !== ep.id) return;
				var cues = [];
				var seen = {};
				files.join('\n\n').split(/\r?\n\r?\n/).forEach(function (block) {
					var m = block.match(/(?:(\d+):)?(\d{2}):(\d{2})[.,](\d{3})\s+-->/);
					if (!m) return;
					var t = (Number(m[1] || 0) * 3600) + Number(m[2]) * 60 + Number(m[3]);
					var text = block.split(/\r?\n/).slice(block.split(/\r?\n/).findIndex(function (l) { return l.indexOf('-->') > -1; }) + 1).join(' ').replace(/<[^>]+>/g, '').trim();
					if (text && !seen[t + text]) {
						seen[t + text] = true;
						cues.push({ t: t, text: text });
					}
				});
				cues.sort(function (a, b) { return a.t - b.t; });
				transcriptEl.textContent = '';
				if (!cues.length) {
					transcriptEl.textContent = str('failed', 'This video could not be loaded right now.');
					return;
				}
				var list = el('ol', { 'class': 'ftvs-transcript__list' });
				cues.forEach(function (c) {
					var b = el('button', { type: 'button' }, [el('span', { 'class': 'ftvs-transcript__time', text: duration(c.t) || '0:00' }), el('span', { text: ' ' + c.text })]);
					b.addEventListener('click', function () {
						try { video.currentTime = c.t; } catch (err) {}
						video.play().catch(function () {});
					});
					list.appendChild(el('li', null, [b]));
				});
				transcriptEl.appendChild(list);
			}).catch(function () {
				transcriptFor = '';
				transcriptEl.textContent = str('failed', 'This video could not be loaded right now.');
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

		/* Captions: remember the viewer's choice (on this device). */
		function pickCaptions() {
			if (!hls || !hls.subtitleTracks || !hls.subtitleTracks.length) return;
			var want = Store.get('cc', '');
			if (!want || want === 'off') return;
			hls.subtitleTracks.forEach(function (t, i) {
				if ((t.lang || t.name) === want) {
					hls.subtitleTrack = i;
					hls.subtitleDisplay = true;
				}
			});
		}

		function rememberCaptions() {
			var on = 'off';
			Array.prototype.forEach.call(video.textTracks, function (t) {
				if (t.mode === 'showing') on = t.language || t.label || 'on';
			});
			Store.set('cc', on);
		}

		function softError() {
			errorBox.hidden = false;
			errorBox.classList.add('is-soft');
			errorBox.textContent = str('retrying', 'Reconnecting...');
		}

		function showError() {
			stopVideo();
			errorBox.hidden = false;
			errorBox.classList.remove('is-soft');
			errorBox.textContent = str('failed', 'This video could not be loaded right now.');
			posterBtn.hidden = false;
			var ep = episodes[current];
			if (ep) countLocal('error', ep);
		}

		function count(what, ep) {
			var key = what + ':' + ep.id;
			if (counted[key]) return;
			counted[key] = true;
			countLocal(what, ep);
		}

		function onPlaying() {
			dialog.classList.add('is-playing');
			errorBox.hidden = true;
			var entry = entryOf();
			var ep = episodes[current];
			if (!ep) return;
			if (entry && entry.kind === 'live') {
				count('live', { id: ep.id, title: ep.title });
				if (reporter && !reporter.session && !reporter.started) {
					reporter.started = true;
					reporter.start(0);
				}
				if (!counted['emitlive:' + ep.id]) {
					counted['emitlive:' + ep.id] = true;
					emit('play', { id: ep.id, title: ep.title, live: true });
				}
			} else {
				if (!counted['play:' + ep.id]) emit('play', { id: ep.id, title: ep.title, series: entry && entry.kind === 'category' ? entry.item.id : ep.parent || '' });
				count('play', ep);
				if (reporter && !reporter.started) {
					reporter.started = true;
					reporter.start(video.currentTime);
				} else if (reporter) {
					reporter.event('play', video.currentTime);
				}
			}
			mediaSession(ep, entry);
		}

		function onTime() {
			var ep = episodes[current];
			var entry = entryOf();
			if (!ep || !entry || entry.kind === 'live' || !video.duration || !isFinite(video.duration)) return;
			var pct = (100 * video.currentTime) / video.duration;
			[25, 50, 75, 90].forEach(function (m) {
				if (pct >= m && !milestones[m]) {
					milestones[m] = true;
					if (m === 90) {
						count('complete', ep);
						if (reporter) reporter.event('complete', video.currentTime);
						emit('complete', { id: ep.id, title: ep.title });
					} else {
						emit('progress', { id: ep.id, title: ep.title, percent: m });
					}
				}
			});
			if (!saveTimer) {
				saveTimer = window.setTimeout(function () {
					saveTimer = 0;
					saveProgress();
				}, 5000);
			}
		}

		function saveProgress(done) {
			var ep = episodes[current];
			var entry = entryOf();
			if (!ep || !entry || entry.kind === 'live' || !video || !(video.currentTime > 0)) return;
			var series = entry.kind === 'category' ? entry.item.id : ep.parent || '';
			Progress.save(ep.id, done ? 0 : video.currentTime, isFinite(video.duration) ? video.duration : ep.length, series, done || (video.duration && video.currentTime / video.duration > 0.95));
		}

		function onEnded() {
			var ep = episodes[current];
			var entry = entryOf();
			if (!ep || !entry || entry.kind === 'live') return;
			saveProgress(true);
			count('complete', ep);
			if (current >= 0 && current < episodes.length - 1) {
				if (!CONFIG.upnext) {
					play(current + 1, true);
					return;
				}
				upNext(episodes[current + 1], function () { play(current + 1, true); });
				return;
			}
			endPanel(ep);
		}

		/* "Up next" with a short countdown and Cancel. */
		function upNext(next, go) {
			var left = 8;
			endBox.textContent = '';
			endBox.hidden = false;
			var countEl = el('span', { 'class': 'ftvs-end__count', text: str('startsIn', 'Starts in %d').replace('%d', left) });
			var playNow = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--primary', html: ICON_PLAY.replace('34', '18').replace('34', '18') });
			playNow.appendChild(el('span', { text: str('playNow', 'Play now') }));
			var cancel = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--light', text: str('cancel', 'Cancel') });
			endBox.appendChild(el('div', { 'class': 'ftvs-end__up' }, [
				next.image ? el('img', { src: next.image, alt: '' }) : null,
				el('div', null, [
					el('p', { 'class': 'ftvs-kicker', text: str('upNext', 'Up next') }),
					el('p', { 'class': 'ftvs-end__title', text: next.title }),
					countEl,
					el('div', { 'class': 'ftvs-end__btns' }, [playNow, cancel])
				])
			]));
			var tickFn = function () {
				left--;
				if (left <= 0) {
					go();
					return;
				}
				countEl.textContent = str('startsIn', 'Starts in %d').replace('%d', left);
				upTimer = window.setTimeout(tickFn, 1000);
			};
			upTimer = window.setTimeout(tickFn, 1000);
			playNow.addEventListener('click', function () {
				window.clearTimeout(upTimer);
				go();
			});
			cancel.addEventListener('click', function () {
				window.clearTimeout(upTimer);
				endPanel(episodes[current], true);
			});
			playNow.focus({ preventScroll: true });
		}

		/* After the last episode: a next step, more like this, watch again. */
		function endPanel(ep, keepGoing) {
			window.clearTimeout(upTimer);
			endBox.textContent = '';
			endBox.hidden = false;
			var again = el('button', { type: 'button', 'class': 'ftvs-btn ftvs-btn--light', html: ICON_AGAIN });
			again.appendChild(el('span', { text: str('watchAgain', 'Watch again') }));
			again.addEventListener('click', function () {
				endBox.hidden = true;
				video.currentTime = 0;
				video.play().catch(function () {});
			});
			var box = el('div', { 'class': 'ftvs-end__panel' });
			stepButtons(ep, box);
			var related = (videoInfo.related || []).filter(function (r) { return r.id !== ep.id; }).slice(0, 4);
			if (!related.length && keepGoing) {
				related = episodes.slice(current + 1, current + 5);
			}
			if (related.length && CONFIG.upnext) {
				box.appendChild(el('p', { 'class': 'ftvs-next__title', text: str('moreLike', 'More like this') }));
				var list = el('ul', { 'class': 'ftvs-end__more', role: 'list' });
				related.forEach(function (r) {
					var b = el('button', { type: 'button', 'class': 'ftvs-end__item' }, [
						el('span', { 'class': 'ftvs-end__thumb' }, [r.image ? el('img', { src: r.image, alt: '', loading: 'lazy' }) : null]),
						el('span', { 'class': 'ftvs-end__name', text: r.title })
					]);
					b.addEventListener('click', function () {
						stack.push({ kind: 'video', item: r, href: r.watch || r.link || '' });
						show(stack[stack.length - 1]);
					});
					list.appendChild(el('li', null, [b]));
				});
				box.appendChild(list);
			}
			box.appendChild(el('div', { 'class': 'ftvs-end__btns' }, [again]));
			if (CONFIG.remind) box.appendChild(remindBox(ep));
			endBox.appendChild(box);
			Embed.height();
		}

		/* "Remind me when a new series starts" at the end of a message. */
		function remindBox(ep) {
			var wrap = el('div', { 'class': 'ftvs-end__remind' });
			var open = el('button', { type: 'button', 'class': 'ftvs-link-btn', text: str('remindSeries', 'Remind me when a new series starts') });
			var form = el('form', { 'class': 'ftvs-remind', 'data-ftvs-remind': true, 'data-kind': 'series', hidden: true }, [
				el('label', null, [el('span', { text: str('email', 'Email') }), el('input', { type: 'email', name: 'email', autocomplete: 'email' })]),
				el('label', null, [el('span', { text: str('orPhone', 'or mobile number') }), el('input', { type: 'tel', name: 'phone', autocomplete: 'tel' })]),
				el('label', { 'class': 'ftvs-remind__consent' }, [el('input', { type: 'checkbox', name: 'sms', value: '1' }), el('span', { text: CONFIG.consent || '' })]),
				el('input', { type: 'text', name: 'hp', value: '', tabindex: '-1', autocomplete: 'off', 'class': 'ftvs-remind__hp', 'aria-hidden': 'true' }),
				el('button', { type: 'submit', 'class': 'ftvs-btn ftvs-btn--primary', text: str('remindMe', 'Remind me') }),
				el('p', { 'class': 'ftvs-remind__msg', role: 'status' })
			]);
			open.addEventListener('click', function () {
				form.hidden = !form.hidden;
				if (!form.hidden) form.querySelector('input').focus();
			});
			wrap.appendChild(open);
			wrap.appendChild(form);
			initRemind(wrap, function () { return { replay: ep, title: ep.title }; });
			return wrap;
		}

		/* Lock screen and notification controls on phones. */
		function mediaSession(ep, entry) {
			if (!('mediaSession' in navigator) || !window.MediaMetadata) return;
			try {
				navigator.mediaSession.metadata = new window.MediaMetadata({
					title: ep.title || '',
					artist: CONFIG.church || '',
					album: entry && entry.kind === 'category' ? entry.item.title || label : label,
					artwork: ep.poster || ep.image ? [{ src: ep.poster || ep.image, sizes: '1280x720' }] : []
				});
				var set = function (action, fn) {
					try { navigator.mediaSession.setActionHandler(action, fn); } catch (err) {}
				};
				set('play', function () { video.play(); });
				set('pause', function () { video.pause(); });
				set('seekbackward', function () { video.currentTime = Math.max(0, video.currentTime - 10); });
				set('seekforward', function () { video.currentTime = video.currentTime + 10; });
				set('previoustrack', current > 0 ? function () { play(current - 1, true); } : null);
				set('nexttrack', current < episodes.length - 1 ? function () { play(current + 1, true); } : null);
			} catch (err) {}
		}

		/* Share this exact message (and, if they're partway in, the moment). */
		function shareCurrent() {
			var entry = entryOf();
			if (!entry) return;
			var ep = entry.kind === 'category' ? episodes[current] : entry.item;
			// Paused partway through: share that moment. Otherwise the message from the start.
			var at = video && video.paused && video.currentTime > 20 && !video.ended ? Math.floor(video.currentTime) : 0;
			var url;
			if (ep && ep.watch) {
				url = ep.watch + (at ? (ep.watch.indexOf('?') < 0 ? '?' : '&') + 't=' + at : '');
			} else if (ep) {
				url = window.location.origin + window.location.pathname + window.location.search + hashFor(entry.kind === 'category' ? { kind: 'category', item: entry.item } : entry, ep, at);
			} else {
				url = window.location.origin + window.location.pathname + window.location.search + hashFor(entry);
			}
			share(url, (ep || entry.item).title || document.title, dialog);
		}

		function stopVideo() {
			if (reporter) {
				reporter.stop(video ? video.currentTime : 0);
				reporter = null;
			}
			if (hls) {
				hls.destroy();
				hls = null;
			}
			if (frame) {
				frame.remove();
				frame = null;
			}
			listening = false;
			if (listenBtn) {
				listenBtn.setAttribute('aria-pressed', 'false');
				listenBtn.textContent = str('listen', 'Listen');
			}
			if (dialog) dialog.classList.remove('is-listening');
			if (video) {
				video.hidden = false;
				video.pause();
				video.removeAttribute('src');
				video.load();
			}
			if (dialog) dialog.classList.remove('is-playing');
		}

		function cleanup(fromButton) {
			if (!isOpen) return;
			saveProgress();
			isOpen = false;
			token++;
			stopVideo();
			window.clearTimeout(upTimer);
			pauseAll(false);
			document.documentElement.classList.remove('ftvs-lock');
			if (!pushed && HASH_RE.test(window.location.hash) && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', window.location.pathname + window.location.search);
			}
			if (!fromButton && pushed && window.history.state && window.history.state.ftvs) {
				// Closed with Escape: take our history entry off too.
				pushed = false;
				window.history.back();
			}
			pushed = false;
			if ('mediaSession' in navigator) {
				try { navigator.mediaSession.metadata = null; } catch (err) {}
			}
			if (Math.abs(window.scrollY - scrollBeforeOpen) > 2) window.scrollTo(0, scrollBeforeOpen);
			Embed.height();
			if (sectionRoot) markProgress(sectionRoot);
			emit('close', {});
		}

		return { open: open, openLive: openLive, warm: warm, dialog: function () { return dialog; } };
	})();

	/* ---------- Embed pages ---------- */

	var heightQueued = false;
	var Embed = {
		height: function () {
			if (!CONFIG.embed || window.parent === window || heightQueued) return;
			heightQueued = true;
			window.requestAnimationFrame(function () {
				heightQueued = false;
				// The body's own height, not the page's (which is never smaller than the frame).
				var h = Math.ceil(document.body.getBoundingClientRect().height);
				var d = Player.dialog();
				if (d && d.open) h = Math.max(h, d.scrollHeight + 48);
				window.parent.postMessage({ ftvs: 'height', h: h }, '*');
			});
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

	function openFromLink() {
		var m = window.location.hash.match(HASH_RE);
		var params = new URLSearchParams(window.location.search);
		var roots = document.querySelectorAll('[data-ftvs][data-play="site"]');
		// A message page opened with ?t=: play from that moment.
		if (!m && params.get('t')) {
			var watchCard = document.querySelector('[data-layout="watch"] .ftvs-watch__player');
			var item = watchCard ? readItem(watchCard) : null;
			if (item) Player.open(watchCard.closest('[data-ftvs]'), 'video', item, '', { t: Number(params.get('t')) || 0 });
			return;
		}
		if (!m) return;
		var series = m[1];
		var vid = m[2] || '';
		var at = m[3] ? Number(m[3]) : 0;
		var cards = document.querySelectorAll('[data-ftvs][data-play="site"] .ftvs__card');
		for (var i = 0; i < cards.length; i++) {
			var it = readItem(cards[i]);
			if (!it) continue;
			var kind = cards[i].getAttribute('data-kind');
			if (it.id.toLowerCase() === series.toLowerCase() || (vid && kind === 'video' && it.id === vid)) {
				if (kind === 'video') {
					Player.open(cards[i].closest('[data-ftvs]'), 'video', it, cards[i].href, { t: at });
				} else {
					Player.open(cards[i].closest('[data-ftvs]'), 'category', it, cards[i].href, { video: vid, t: at });
				}
				return;
			}
		}
		// Not on this page as a card: open it anyway (a link shared from another page or a deeper series).
		if (!roots.length) return;
		if (series === '_' && vid) {
			Player.open(roots[0], 'video', { id: vid, title: '' }, '', { t: at });
		} else if (series !== '_') {
			Player.open(roots[0], 'category', { id: series, title: '' }, '', { video: vid, t: at });
		}
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-ftvs]'), initRoot);
		Array.prototype.forEach.call(document.querySelectorAll('[data-ftvs-livebar]'), initLiveBar);
		// Share buttons outside the player (message pages).
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-ftvs-share]');
			if (!b) return;
			e.preventDefault();
			share(b.getAttribute('data-ftvs-share') || window.location.href, b.getAttribute('data-title') || document.title, b.parentNode);
		});
		// Next-step clicks on message pages.
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('[data-ftvs-next-step]');
			if (a) emit('nextstep', { label: a.textContent, url: a.href });
		});
		openFromLink();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();

	// The Elementor editor preview re-renders the widget each time a setting changes.
	var hooked = false;
	function hookElementor() {
		if (hooked || !window.elementorFrontend || !window.elementorFrontend.hooks) return;
		hooked = true;
		['faith_tv_series', 'faith_tv_live', 'faith_tv_library'].forEach(function (name) {
			window.elementorFrontend.hooks.addAction('frontend/element_ready/' + name + '.default', function ($scope) {
				var node = $scope && $scope[0] ? $scope[0].querySelector('[data-ftvs]') : null;
				if (node) initRoot(node);
			});
		});
	}
	hookElementor();
	if (window.jQuery) window.jQuery(window).on('elementor/frontend/init', hookElementor);
})();
