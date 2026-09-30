/* Faith TV Series embeds: loaded on the website that shows the embed. Lets each frame grow to fit. */
(function () {
	'use strict';
	if (window.__ftvsEmbedHost) return;
	window.__ftvsEmbedHost = true;

	function frameFor(source) {
		var frames = document.querySelectorAll('iframe[data-ftvs-embed]');
		for (var i = 0; i < frames.length; i++) {
			if (frames[i].contentWindow === source) return frames[i];
		}
		return null;
	}

	window.addEventListener('message', function (e) {
		var data = e.data;
		if (!data || typeof data !== 'object' || typeof data.ftvs !== 'string') return;
		var frame = frameFor(e.source);
		if (!frame) return;
		if (data.ftvs === 'height') {
			var h = Math.max(120, Math.min(6000, Math.round(Number(data.h) || 0)));
			frame.style.height = h + 'px';
		} else if (data.ftvs === 'show') {
			// The player opened: bring the frame into view.
			var r = frame.getBoundingClientRect();
			if (r.top < 0 || r.top > window.innerHeight * 0.4) {
				var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				frame.scrollIntoView({ block: 'start', behavior: still ? 'auto' : 'smooth' });
			}
		}
	});
})();
