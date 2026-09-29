=== Faith TV Series ===
Contributors: faithtabernacle
Tags: video, church, series, faith stream, gideo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

By FaithStream. Puts your church's video series live on your website, from Faith Stream or a Gideo TV channel.

== Description ==

Connect your church once and any category of your videos (like the Mini Series) can go on
any page. When your team adds a new series, it appears on the website by itself. Nobody
uploads pictures to WordPress or edits the page.

Works with:

* Faith Stream (enter your Faith Stream address and church ID)
* A Gideo TV channel (just type your TV website, for example tv.yourchurch.com; the plugin
  looks up the rest)

Layouts, styled after faithtabernacle.com (square corners, heavy uppercase type, your color):

* Showcase: the newest series big with its artwork, rotating through the others.
* 3D carousel: like a classic cover-flow slider.
* Featured + list: the newest one big, the rest as a clean list. What phones get by default.
* Sliding row and Grid.

Visitors click a series to open a player right on the page with the episode list. On phones
it slides up from the bottom and swipes down to close. Episodes play one after another.

Everything lives under the Faith Stream menu in WordPress:

* Church: connect (or switch) your church in three steps.
* Videos: every category on your channel, with a shortcode to copy.
* Look & feel: your church color, layouts for computers and phones, label, badge, and
  text sizes and colors for every section.
* Embed: put a video section on any other website (Faith Central, a landing page, a partner
  church) with a copy-and-paste code. The frame grows to fit, even while the player is open.
* Updates: new versions install like any WordPress plugin update.

Elementor: drag in the "Faith TV Series" widget (search "Faith TV"). Its Style tab sets the
size (per device), font and color of the heading, the small line, the big series title, the
series names, descriptions, and episode counts.
Anywhere else: [faith_tv_series category="<id>"] (copy it from Faith Stream > Videos).

Shortcode options (leave any out to use the Look & feel defaults):

* category: the category ID from Faith Stream > Videos, or its exact name
* layout: showcase, coverflow, list, row or grid
* mobile_layout: auto, same, list, coverflow, row, showcase or grid
* title / eyebrow: a big heading, and a small colored line above it
* label: the small line over each featured series
* badge: marks the newest one (badge="" for none)
* autoplay: seconds between rotations for showcase and coverflow (0 = off)
* limit: show only the first N
* play: site (watch on the page) or faithtv (open your channel in a new tab)
* theme: dark (for dark sections) or light
* descriptions: yes to show a short description under each card (row and grid)
* Text size and color, per part: heading_size / heading_color (the heading),
  eyebrow_size / eyebrow_color (the small line above it), series_size / series_color
  (the big series title), card_size / card_color (series names on cards), text_size /
  text_color (descriptions), meta_size / meta_color (episode counts and labels).
  Sizes like 48, 2.5rem or clamp(28px, 6vw, 56px); colors like #FFFFFF.
  Example: [faith_tv_series category="<id>" heading_size="56" heading_color="#FFFFFF"]

Your channel is checked for changes every 15 minutes (changeable), and "Refresh from your
channel" pulls changes right away. If the channel is ever unreachable, the site keeps showing
the last list it got.

Share a series: yourchurch.com/<page>#faith-tv-<series id> opens it straight away.

== Installation ==

1. WordPress admin > Plugins > Add New Plugin > Upload Plugin, choose faith-tv-series.zip, Install Now, Activate.
2. Faith Stream > Church: connect your church.
3. Edit a page with Elementor and drag in the "Faith TV Series" widget, or paste a shortcode.

== Updates ==

New versions come from GitHub releases (github.com/cwlelliott-stack/faith-tv-series) and show
up on the Plugins page like any other plugin update. Faith Stream > Updates has "Check for
updates now" and "Install updates automatically".

== Third-party code ==

assets/vendor/hls.min.js is hls.js 1.7.3 (Apache License 2.0, see hls.js-LICENSE.txt).
assets/vendor/hls.light.min.js is the same file under the name version 1.1 used, so pages
a cache saved before the update keep playing.
It plays video streams in browsers without full built-in HLS support, and loads only when
someone presses play.

== Changelog ==

= 1.2.0 =
* Connect any church (Faith Stream or Gideo), a new Faith Stream menu, embeds for other websites, text sizes and colors per section, and a cleaner phone layout with a slide-up player.

= 1.1.0 =
* Updates itself from GitHub releases (Plugins page shows "Update available").
* Settings page: Updates section, Check for updates now, optional GitHub token.

= 1.0.0 =
* First version.
