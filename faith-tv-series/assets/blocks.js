/* Faith TV Series blocks for the block editor. No build step: plain wp.* globals. */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks) return;

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var be = wp.blockEditor || wp.editor;
	var c = wp.components;
	var SSR = wp.serverSideRender;

	var ICON = el('svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
		el('rect', { x: 2.5, y: 4.5, width: 19, height: 13, rx: 1.5, fill: 'none', stroke: 'currentColor', strokeWidth: 2 }),
		el('path', { d: 'M10 8.5v5l4.5-2.5z', fill: 'currentColor' }));

	var cache = null;
	function useCategories() {
		var s = useState(cache || []);
		useEffect(function () {
			if (cache) return;
			wp.apiFetch({ path: '/faith-tv/v1/admin/categories' }).then(function (list) {
				cache = list;
				s[1](list);
			}).catch(function () {});
		}, []);
		return s[0];
	}

	function layouts(withDefault) {
		return [
			{ value: '', label: withDefault },
			{ value: 'showcase', label: __('Showcase', 'faith-tv-series') },
			{ value: 'coverflow', label: __('3D carousel', 'faith-tv-series') },
			{ value: 'list', label: __('Featured + list', 'faith-tv-series') },
			{ value: 'row', label: __('Sliding row', 'faith-tv-series') },
			{ value: 'grid', label: __('Grid', 'faith-tv-series') }
		];
	}

	var THEMES = [
		{ value: '', label: __('Site default', 'faith-tv-series') },
		{ value: 'dark', label: __('Dark section (light text)', 'faith-tv-series') },
		{ value: 'light', label: __('Light section (dark text)', 'faith-tv-series') }
	];

	/* Controls with the editor's current spacing and size (the old defaults are going away). */
	function ctl(type, props) {
		props.__nextHasNoMarginBottom = true;
		if (type !== c.ToggleControl) props.__next40pxDefaultSize = true;
		return el(type, props);
	}

	function set(props, key) {
		return function (v) {
			var o = {};
			o[key] = v;
			props.setAttributes(o);
		};
	}

	function preview(name, props) {
		var bp = be.useBlockProps ? be.useBlockProps() : {};
		return el('div', bp, el(SSR, { block: name, attributes: props.attributes }));
	}

	wp.blocks.registerBlockType('faith-tv/series', {
		apiVersion: 3,
		title: __('Faith TV Series', 'faith-tv-series'),
		description: __('A row of series or messages from your channel.', 'faith-tv-series'),
		icon: ICON,
		category: 'media',
		keywords: ['faith', 'stream', 'sermon', 'video', 'series'],
		edit: function (props) {
			var a = props.attributes;
			var cats = useCategories();
			var options = [{ value: '', label: __('- Pick what to show -', 'faith-tv-series') }].concat(cats);
			return el(wp.element.Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: __('What to show', 'faith-tv-series') },
						ctl(c.SelectControl, { label: __('Category', 'faith-tv-series'), value: a.category, options: options, onChange: set(props, 'category') }),
						ctl(c.TextControl, { label: __('Or one video (its ID)', 'faith-tv-series'), value: a.video, onChange: set(props, 'video') }),
						ctl(c.RangeControl, { label: __('How many to show (0 = all)', 'faith-tv-series'), value: a.limit, min: 0, max: 50, onChange: set(props, 'limit') })
					),
					el(c.PanelBody, { title: __('Look', 'faith-tv-series') },
						ctl(c.SelectControl, { label: __('Layout', 'faith-tv-series'), value: a.layout, options: layouts(__('Site default', 'faith-tv-series')), onChange: set(props, 'layout') }),
						ctl(c.SelectControl, { label: __('Phone layout', 'faith-tv-series'), value: a.mobileLayout, options: [{ value: '', label: __('Site default', 'faith-tv-series') }, { value: 'auto', label: __('Automatic', 'faith-tv-series') }, { value: 'same', label: __('Same as computers', 'faith-tv-series') }, { value: 'list', label: __('Featured + list', 'faith-tv-series') }, { value: 'row', label: __('Sliding row', 'faith-tv-series') }], onChange: set(props, 'mobileLayout') }),
						ctl(c.SelectControl, { label: __('Background', 'faith-tv-series'), value: a.theme, options: THEMES, onChange: set(props, 'theme') }),
						ctl(c.TextControl, { label: __('Heading (optional)', 'faith-tv-series'), value: a.title, onChange: set(props, 'title') }),
						ctl(c.TextControl, { label: __('Small line above it (optional)', 'faith-tv-series'), value: a.eyebrow, onChange: set(props, 'eyebrow') }),
						ctl(c.RangeControl, { label: __('Rotate every (seconds, 0 = off)', 'faith-tv-series'), value: a.autoplay, min: 0, max: 60, onChange: set(props, 'autoplay') }),
						ctl(c.ToggleControl, { label: __('Open videos on your channel instead of here', 'faith-tv-series'), checked: !!a.openChannel, onChange: set(props, 'openChannel') })
					),
					el(c.PanelBody, { title: __('Schedule (optional)', 'faith-tv-series'), initialOpen: false },
						el('p', null, __('Show this section only between these dates, like a series until Easter. Dates like 2026-04-05, in your site\'s time zone.', 'faith-tv-series')),
						ctl(c.TextControl, { label: __('Show from', 'faith-tv-series'), value: a.from, placeholder: '2026-03-01', onChange: set(props, 'from') }),
						ctl(c.TextControl, { label: __('Show until', 'faith-tv-series'), value: a.until, placeholder: '2026-04-06', onChange: set(props, 'until') }),
						ctl(c.SelectControl, { label: __('Other times, show', 'faith-tv-series'), value: a.otherwise, options: [{ value: '', label: __('Nothing', 'faith-tv-series') }].concat(cats), onChange: set(props, 'otherwise') })
					)
				),
				preview('faith-tv/series', props)
			);
		},
		save: function () { return null; }
	});

	wp.blocks.registerBlockType('faith-tv/live', {
		apiVersion: 3,
		title: __('Sunday Live', 'faith-tv-series'),
		description: __('Countdown to the next service, the live stream while it is on, then the replay.', 'faith-tv-series'),
		icon: ICON,
		category: 'media',
		keywords: ['live', 'stream', 'service', 'sunday', 'faith'],
		edit: function (props) {
			var a = props.attributes;
			return el(wp.element.Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: __('Sunday Live', 'faith-tv-series') },
						ctl(c.TextControl, { label: __('Heading (optional)', 'faith-tv-series'), value: a.title, onChange: set(props, 'title') }),
						ctl(c.TextControl, { label: __('Small line above it (optional)', 'faith-tv-series'), value: a.eyebrow, onChange: set(props, 'eyebrow') }),
						ctl(c.TextControl, { label: __('Channel (optional, for a campus)', 'faith-tv-series'), help: __('Leave empty for whichever channel is live.', 'faith-tv-series'), value: a.channel, onChange: set(props, 'channel') }),
						ctl(c.SelectControl, { label: __('Background', 'faith-tv-series'), value: a.theme, options: THEMES, onChange: set(props, 'theme') })
					)
				),
				preview('faith-tv/live', props)
			);
		},
		save: function () { return null; }
	});

	wp.blocks.registerBlockType('faith-tv/library', {
		apiVersion: 3,
		title: __('Sermon Library', 'faith-tv-series'),
		description: __('Every message, newest first, with search and filters by speaker and year.', 'faith-tv-series'),
		icon: ICON,
		category: 'media',
		keywords: ['sermon', 'library', 'search', 'archive', 'faith'],
		edit: function (props) {
			var a = props.attributes;
			var cats = useCategories();
			return el(wp.element.Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: __('Sermon Library', 'faith-tv-series') },
						ctl(c.SelectControl, { label: __('Only messages in', 'faith-tv-series'), value: a.category, options: [{ value: '', label: __('Everything', 'faith-tv-series') }].concat(cats.filter(function (x) { return x.value.charAt(0) !== '@'; })), onChange: set(props, 'category') }),
						ctl(c.TextControl, { label: __('Heading (optional)', 'faith-tv-series'), value: a.title, onChange: set(props, 'title') }),
						ctl(c.TextControl, { label: __('Small line above it (optional)', 'faith-tv-series'), value: a.eyebrow, onChange: set(props, 'eyebrow') }),
						ctl(c.SelectControl, { label: __('Background', 'faith-tv-series'), value: a.theme, options: THEMES, onChange: set(props, 'theme') })
					)
				),
				preview('faith-tv/library', props)
			);
		},
		save: function () { return null; }
	});
})(window.wp);
