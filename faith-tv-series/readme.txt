=== Faith TV Series ===
Contributors: faithtabernacle
Tags: video, gideo, church, series, hls
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Puts a Faith TV (Gideo) category, like the Mini Series, live on the church website.

== Description ==

The plugin reads the public Faith TV catalog on Gideo (the same one tv.faithtabernacle.com
and the TV apps use) and shows any category on the website. When the video team adds a new
series on Faith TV, it appears on the website by itself. Nobody has to upload pictures to
WordPress or edit the page.

Styled like faithtabernacle.com: square corners, heavy uppercase Roboto, crimson buttons.

Layouts:

* Showcase (default): the newest series big, with its artwork framed on the right, a
  "Watch the series" button, and a strip of the others underneath. It rotates through the
  series on its own and pauses while someone points at it.
* 3D carousel (coverflow): like the slider on the home page, with the details under the
  middle card. Swipe on phones.
* Sliding row and Grid.

Phones get their own layout. By default the Showcase turns into the swipe carousel on
phones (shorter, made for thumbs) and a Grid turns into a sliding row. Pick any layout for
phones with the "Phone layout" setting.

Visitors click a series to open a player right on the page, with the episode list under it.
Episodes play one after another. A "Watch on Faith TV" link is always there too.

* Elementor widget: "Faith TV Series" (search "Faith TV" in the widget panel).
* Shortcode for anywhere else: [faith_tv_series category="781898cc9c88dc2250d54cbe7bb22866"]
* Settings > Faith TV Series lists every category with a shortcode ready to copy.

Shortcode options:

* category: the category ID, or its exact name, e.g. category="Faith TV Mini Series"
* layout: showcase (default), coverflow, row or grid
* mobile_layout: auto (default), same, showcase, coverflow, row or grid (what phones get)
* title / eyebrow: a big heading, and a small red line above it
* label: the small red line over each featured series (default: the category name, or "Faith TV")
* badge: marks the newest one (default "New"; badge="" for none)
* autoplay: seconds between rotations for showcase and coverflow (default 7, 0 = off)
* limit: show only the first N (default 0 = all)
* play: site (default, watch on the page) or faithtv (open tv.faithtabernacle.com)
* theme: dark (default, for dark sections) or light
* descriptions: yes to show a short description under each card (row and grid)

Faith TV is checked for changes every 15 minutes (changeable in settings), and
"Refresh from Faith TV now" on the settings page pulls changes right away. If Gideo
is ever unreachable, the site keeps showing the last list it got.

Share a series: faithtabernacle.com/#faith-tv-<series id> opens that series straight
away on any page that shows it.

== Installation ==

1. WordPress admin > Plugins > Add New Plugin > Upload Plugin, choose faith-tv-series.zip, Install Now, Activate.
2. Edit the home page with Elementor, drag the "Faith TV Series" widget where the mini series slider is, and pick "Faith TV Mini Series".

== Third-party code ==

assets/vendor/hls.light.min.js is hls.js 1.7.3 (Apache License 2.0, see hls.js-LICENSE.txt).
It plays the Faith TV video streams in browsers without built-in HLS support, and loads
only when someone presses play.

== Updates ==

New versions come from GitHub releases (github.com/cwlelliott-stack/faith-tv-series) and
show up on the Plugins page like any other plugin update. "Check for updates now" is on
Settings > Faith TV Series. While that repository is private, save a read-only GitHub
token on the same page.

== Changelog ==

= 1.1.0 =
* Updates itself from GitHub releases (Plugins page shows "Update available").
* Settings page: Updates section, Check for updates now, optional GitHub token.

= 1.0.0 =
* First version.
