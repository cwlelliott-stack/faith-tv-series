=== Faith TV Series ===
Contributors: faithstream
Tags: church, sermons, video, live stream, podcast
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later

By FaithStream. Your church's videos live on your website: series, Sunday live, a sermon library and a page for every message.

== Description ==

Connect your church once. New series and sermons show up on your website by themselves, and
visitors watch right on the page. Nobody uploads pictures to WordPress or edits the page on
Sunday morning.

Videos can come from:

* Faith Stream (type your Faith Stream address)
* A Gideo TV channel (type your TV website, for example tv.yourchurch.com)
* YouTube (paste playlist links or your channel link; each playlist becomes a series)
* Series you build by hand from any video links (YouTube, Vimeo, anything WordPress can embed,
  or a direct .m3u8 stream), under Faith Stream > Build a series
* Not ready yet? "Try it with sample videos" shows every layout on your own site (only people
  who can edit the site see them).

What you can put on a page (Elementor widget, block, or shortcode):

* Videos: any row or series, in five layouts (Showcase, 3D carousel, Featured + list, Row, Grid),
  or two automatic picks: the newest messages, and what you feature on your channel.
* One video, with a big play button, for a blog post or landing page.
* Sunday Live: "Next service: Sunday 10:30" with a countdown, the live stream as soon as it
  starts, then the replay. Faith Stream churches get this automatically; others paste their live
  link (YouTube, Vimeo, Boxcast, Resi, Church Online...) and enter their service times. An
  optional "We're live" bar can sit at the top of every page.
* Sermon Library: every message, newest first, with search (topics, speakers, verses) and
  filters by speaker and year.

The player:

* Opens on the page; on phones it slides up from the bottom, and the Back button closes it.
* Episodes play one after another with an "Up next" countdown, then "More like this".
* Your next-step buttons (Plan a visit, Prayer, Give...) under the player and at the end, tagged
  with which message led there.
* Share a message (or the exact moment), pick up where you left off, captions and a clickable
  transcript when your videos have captions, and Listen (audio only) when an audio file exists.
* Recovers by itself from Wi-Fi blips and expired links; lock-screen controls on phones.

A page for every message: pick your Watch page (or press "Create my Watch page") and every
message also gets its own address, like yourchurch.com/watch/message-name, with the title,
picture and description that Google, Facebook and text messages show, and it's added to your
sitemap. A podcast feed of the messages is one switch away (Faith Stream makes the audio).

For your team:

* Faith Stream > Health lists every page with a video section and whether each one works, and
  emails the admin if videos stop showing. It also adds checks to WordPress's Site Health and a
  "Copy diagnostics" button for support. WP-CLI: wp faith-tv status | refresh | where-used.
* Plays are counted on the dashboard ("Faith Stream: videos this week") without collecting
  anything about the people watching; optional Monday email.
* Sections can be dated ("this series until Easter, then back to normal").
* Your channel is checked in the background, so visitors never wait on it, and page caches are
  cleared when something changes. Faith Stream can also tell the site the moment you publish.
* Look & feel previews the real section with your real videos before you save: church color,
  Bold, Soft or Minimal style, light or dark, your website's font, text sizes and colors.
* Embed any section, one video, Sunday live or the library on other websites.
* "Remind me" sign-ups and "new video" notices go to your follow-up system (a GoHighLevel
  webhook, Zapier, Make...).

Shortcodes (leave any option out to use the Look & feel defaults):

* [faith_tv_series category="<id or name>"] with layout, mobile_layout, title, eyebrow, label,
  badge, autoplay, limit, play="faithtv", theme="light|dark", descriptions="yes", video="<id>"
  (one video), from / until / otherwise (show between dates, e.g. from="2026-03-01"
  until="2026-04-06" otherwise="@newest"), next="off" or next_label / next_url, and text size and
  color per part (heading_size, heading_color, eyebrow_, series_, card_, text_, meta_).
  category="@newest" and category="@featured" pick themselves.
* [faith_tv_live] with title, eyebrow, theme, channel="<campus channel id>".
* [faith_tv_library] with title, eyebrow, theme, category (only messages in one row), per.
* [faithstream ...]: the shortcode Faith Stream's Embeds page hands out works too.

Share links: yourchurch.com/<page>#faith-tv-<series id> opens a series,
#faith-tv-<series id>/<video id> a message, and @<seconds> at the end starts at that moment.

For agencies: define FTVS_BRAND_NAME, FTVS_BRAND_URL and FTVS_SUPPORT_URL in wp-config.php to use
your own name and links (FTVS_BRAND_NAME '' hides "Powered by"), or use the ftvs_brand filter.
Player events for Tag Manager, GoHighLevel pages or your own code: faithtv:open, faithtv:play,
faithtv:progress (25/50/75), faithtv:complete, faithtv:nextstep, faithtv:share, faithtv:live
(DOM events on document, also pushed to dataLayer, and posted to the parent page from embeds).

== External services ==

The plugin talks to the video platform the church connects, to show that church's videos. Nothing is
sent anywhere until a church is connected (or "Try it with sample videos" is chosen).

* Faith Stream (the church's own Faith Stream address, for example stream.yourchurch.com)
  - From the site: the public catalog (series, videos, search), the live status, and the church's
    name, logo and colors. Faith Stream can also send the site a signed "something changed" ping.
  - From visitors' browsers, when "Also count them in Faith Stream's reports" is on (off until you
    turn it on in the wordpress.org edition): play reports (start, watch time, finished, the page's
    address without its query). Faith Stream keeps only a daily-scrambled form of the visitor's
    internet address.
  - From visitors' browsers, only when a visitor chooses "Count me present" during a live service:
    their church-account email or first name and password, or the email and name they type, go to
    Faith Stream, which checks them in on the church's Faith Connections. The browser keeps a sign-in
    token until they press "Not you?".
  - Terms: https://faithstream.video/terms  Privacy: https://faithstream.video/privacy
* Gideo (ott.gideo.video and cdn.gideo.video): the church's public TV catalog and streams.
  Privacy: https://gideo.video/privacy
* Mux (image.mux.com, stream.mux.com) delivers Faith Stream pictures and video; the sample videos
  come from test-streams.mux.dev. Terms: https://www.mux.com/terms  Privacy: https://www.mux.com/privacy
* YouTube (www.youtube.com feeds, www.googleapis.com with the church's own optional API key,
  i.ytimg.com pictures, and www.youtube-nocookie.com for playing).
  Terms: https://www.youtube.com/t/terms  Privacy: https://policies.google.com/privacy
* Vimeo, or any site a hand-built series or the Sunday live link points to (Boxcast, Resi, Church
  Online...): the video plays in that site's own player, and the site is asked (through WordPress's
  oEmbed) about a hand-built series' links when it is saved. Vimeo terms: https://vimeo.com/terms
  Privacy: https://vimeo.com/privacy
* "Remind me" sign-ups (email or mobile number, and the texting consent that was shown) and "new
  video" notices go to the webhook addresses the church enters (its own follow-up system).
* Updates (the direct edition only): the plugin reads a signed latest.json from
  github.com/cwlelliott-stack/faith-tv-series releases. The wordpress.org edition leaves this out.
  Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service
  Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

Requests from the site include the site's address in the user agent. Suggested text for the
site's privacy policy is under Settings > Privacy.

== Frequently Asked Questions ==

= New videos don't show up on the site =

They appear within the time set under Faith Stream > Church > Advanced (15 minutes by default),
or right away with "Refresh from your channel". Faith Stream churches can paste the refresh
address and secret into Faith Stream so it happens within seconds. If you use a page cache
plugin, the plugin clears it when your channel changes; Faith Stream > Health shows when that
last happened.

= A section disappeared =

Visitors never see error messages; people who can edit the page see a note in its place.
Faith Stream > Health lists every section and what is wrong (a renamed or removed category,
for example).

= Do I need Elementor? =

No. There are blocks for the block editor ("Faith TV Series", "Sunday Live", "Sermon Library")
and shortcodes for everything else.

= Does it collect anything about visitors? =

The site's own play counts are totals per day, per video and per page, with no names or addresses.
"Continue watching" and the volume are remembered in the visitor's own browser only. Faith Stream
churches can also send anonymous play reports to Faith Stream (a switch), and visitors who choose
"Count me present" during a live service sign in with Faith Stream; see External services.

= Is there a credit link? =

Only if you turn it on: "Powered by" under Faith Stream > Look & feel (off by default in the
wordpress.org edition).

== Screenshots ==

1. Showcase: the newest series big, the others in a strip. Visitors watch right on the page.
2. The player: episodes, the church's next-step buttons, share, and a transcript when there are captions.
3. Sunday Live: the countdown to the next service, the live stream, then the replay.
4. Sermon Library: every message, newest first, with search and filters.
5. On phones: the newest series big and the rest as a list; the player slides up from the bottom.
6. Look & feel: church color, style and layouts, with a live preview of the real section.
7. Health: every page with a video section, and whether each one works.
8. Sunday live settings: service times, the live link for other platforms, and the "We're live" bar.
9. Connect a church: Faith Stream, a Gideo TV channel, YouTube, series built by hand, or sample videos.

== Installation ==

1. WordPress admin > Plugins > Add New Plugin > Upload Plugin, choose faith-tv-series.zip, Install Now, Activate.
2. Faith Stream > Church: connect your church (or try it with sample videos).
3. Press "Create my Watch page", or add the widget, block or shortcode to any page.

== Updates ==

New versions show up on the Plugins page like any other plugin update. Each one is checked
against FaithStream's signature before it installs, and a release can reach some sites first.
Faith Stream > Updates has "Check for updates now" and "Install updates automatically".

== Third-party code ==

assets/vendor/hls.min.js is hls.js 1.7.3 (Apache License 2.0, see hls.js-LICENSE.txt; source:
https://github.com/video-dev/hls.js/tree/v1.7.3). It plays video streams in browsers without full
built-in HLS support, and loads only when someone is about to press play.

== Changelog ==

= 1.2.1 =
* Fixes the not-allowed message on the old Settings > Faith TV Series link.

= 1.2.0 =
* Connect any church (Faith Stream or Gideo), a new Faith Stream menu, embeds for other websites, text sizes and colors per section, and a cleaner phone layout with a slide-up player.

= 1.1.0 =
* Updates itself from GitHub releases (Plugins page shows "Update available").
* Settings page: Updates section, Check for updates now, optional GitHub token.

= 1.0.0 =
* First version.
