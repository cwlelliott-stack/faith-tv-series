# Faith TV Series (Faith Stream for WordPress)

WordPress plugin by FaithStream that puts a church's videos live on its website: series, Sunday
live, a searchable sermon library, a page for every message and a podcast feed. Videos come from
**Faith Stream** or a **Gideo** TV channel. New series show up by themselves, and visitors watch
right on the page. Built first for faithtabernacle.com; any church can connect (or try it with
sample videos first).

YouTube as a source and series built by hand are built and tested but not offered for now:
`define( 'FTVS_EXTRA_SOURCES', true );` in wp-config.php (or the `ftvs_extra_sources` filter)
turns them back on. The tests run with them on.

- **Faith Stream** menu in WordPress: Church (connect, Watch page, podcast, instant updates),
  Videos, Look & feel (with a live preview), Player (next steps, counting, follow-up), Sunday
  live, Embed, Health, Updates, Build a series
- Elementor widgets **Faith TV Series**, **Sunday Live**, **Sermon Library**; the same three as
  blocks for the block editor; shortcodes `[faith_tv_series]`, `[faith_tv_live]`,
  `[faith_tv_library]`, and Faith Stream's own `[faithstream ...]`
- Every option and what it does is in [`faith-tv-series/readme.txt`](faith-tv-series/readme.txt)

## Install on the website

1. Build the zip: `python build-zip.py` (makes `faith-tv-series.zip`)
2. WordPress admin > Plugins > Add New Plugin > Upload Plugin > choose the zip > Install Now > Activate
3. Faith Stream > Church: connect the church, then press "Create my Watch page" (or add a widget,
   block or shortcode anywhere)

Sites update themselves after that (see below). `python build-zip.py --wporg` makes the
wordpress.org edition (`faith-tv-series-wporg.zip`). It leaves out `includes/class-updater.php`
(the self-updater, the Updates tab and its auto-update switch), the `Update URI` header and the
Google Fonts line, and rewrites the readme's Installation and Updates text. The code tells the
editions apart with `FTVS_Settings::direct_edition()`: in the wordpress.org edition the "Powered
by" link and play reports to Faith Stream start off (wordpress.org wants both opt-in).

Before submitting to wordpress.org, run Plugin Check on the wordpress.org zip in a test site
(`wp plugin install plugin-check --activate`, then
`wp plugin check faith-tv-series --include-experimental`). On 9/30/2026 it reported no errors and
one warning (`load_plugin_textdomain`, kept so the bundled Spanish loads). faithstream.video also
needs the `/terms` and `/privacy` pages the readme links to.

`.wordpress-org/` holds the directory listing's banner, icon and screenshots (made from the
sample videos, not a real church). They go in the SVN `assets/` folder when the plugin is listed;
the screenshot captions are in readme.txt.

## Releasing an update

```bash
python release.py 1.3.0 "What changed, in one line"
python release.py 1.3.0 "What changed" --rollout 25    # a quarter of sites first
python release.py --rollout 1.3.0 100                   # then everyone (0 holds it back)
```

It sets the version, adds the note to the changelog, builds `faith-tv-series.zip`, writes a
signed `latest.json` (the version, the zip's address and SHA-256, and the rollout), commits,
tags `v1.3.0`, pushes and creates the GitHub release with both files. Run it from `main`.

**Signing key.** Sites install an update only if `latest.json` is signed with FaithStream's
release key and the zip matches its SHA-256 (`includes/class-updater.php` holds the public key).
The private key is `~/.faith-tv-series/release-signing.key` on the release computer and is never
in git. **Back it up** somewhere safe: without it, installed sites refuse new versions until
someone uploads a zip by hand. Needs Python's `cryptography` package and the GitHub CLI (`gh`).

Sites read `https://github.com/cwlelliott-stack/faith-tv-series/releases/latest/download/latest.json`
(not GitHub's rate-limited API). Sites still on 1.2.x update to the first signed release through
their old updater once, then use the signed path.

## How it works

- `includes/class-catalog.php` is the switchboard. Every source returns the same shapes
  (category, video), and two automatic picks ride on top (`@newest`, `@featured`):
  `class-faithstream-client.php` (Faith Stream's public API), `class-gideo-client.php` (Gideo's
  public API), `class-youtube-client.php` (YouTube feeds, or the Data API with a key),
  `class-demo-client.php` (sample videos, editors only) and `class-manual.php` (series built by
  hand, the `ftvs_series` post type, alongside any source).
- `includes/class-cache.php`: every answer is cached and backed up. Stale answers are served
  right away while WP-Cron fetches fresh ones (one fetch at a time, with a short cool-off when
  the platform is down); a 404 means "removed" and the backup goes too. A list that suddenly
  comes back empty keeps the backup for 30 minutes, and a list that failed partway is never saved
  (a short list would make the missing videos look "new" later). `expire()` (a Faith Stream ping,
  a plugin update) keeps serving backups while fresh answers load; `clear()` ("Refresh from your
  channel") fetches before showing. Copies saved by 1.2 or earlier are never shown.
  `class-purge.php` clears common page caches when the catalog changes (the `ftvs_pages_purged`
  action tells hosts); Faith Stream can also ping `/wp-json/faith-tv/v1/refresh` (HMAC-signed).
- After an update (WordPress doesn't run the activation hook then), `FTVS_Settings::maybe_upgrade()`
  starts a new cache generation and clears page caches once per version (`ftvs_version`).
- `includes/class-renderer.php` renders every section (the shortcodes, widgets and blocks all go
  through it); `class-live.php` works out Sunday live; `class-watch.php` serves the message pages
  (`/watch/<video>/` under the chosen Watch page) with link previews and structured data, and
  `class-sitemap.php` lists them in WordPress's sitemap. `class-channel.php` is the whole channel on one
  page (`[faith_tv_channel]`, the "Faith TV Channel" block and widget, Faith Stream > Channel page): every
  view (home, series, group of series, video, live, search) is rendered on the server and has its own
  address (`/watch/series/<id>/`, `/watch/<video>/?ftvs_series=<id>`, `/watch/live/<id>/` on the Watch
  page; `?ftvs_series=`, `?ftvs_video=`, `?ftvs_live=` elsewhere; `?ftvs_q=` for search). The page
  script swaps views through `GET /wp-json/faith-tv/v1/channel` and the browser's history (its entries
  keep WordPress's interactivity id, or 7.x reloads the page on Back), and plays videos inline.
  Gideo does not say how its rows are laid out, so the first row of videos is the banner, a row named
  Featured the slider and rows of series tiles; any row can be changed or hidden (`channel_rows`).
  What each listing said about its series (title, description, picture, parent) is kept in
  `ftvs_chcats_<md5(identity)>` for titles and breadcrumbs. Styles are in `assets/faith-tv-channel.css`
  (logical properties, so it needs no RTL copy). With Yoast, Rank Math or All in One SEO (which
  turn WordPress's off) the pages are at `/faith-tv-messages.xml`, added to that plugin's sitemap index
  (no "sitemap" in the name: those plugins claim every `*-sitemap.xml`). `class-podcast.php` serves
  `/feed/faith-tv/`.
- `includes/class-rest.php` serves the page script under `/wp-json/faith-tv/v1/` (category,
  video, live, library, search, stats, remind, refresh, ping).
- `includes/class-health.php`: the hourly background check, "where it's used", Site Health,
  diagnostics, admin alerts and `wp faith-tv status|refresh|where-used`.
- `includes/class-stats.php` counts plays (no personal data) for the dashboard widget;
  `class-followup.php` sends "Remind me" sign-ups and new-video notices to the church's webhooks.
- `includes/class-embed.php` serves signed embeds (`/?ftvs_embed=...`) and the Look & feel preview;
  `assets/embed.js` on the host page lets the frame grow to fit.
- `includes/class-updater.php` feeds signed releases into WordPress's update system.
- `assets/faith-tv-series.js` runs the layouts, the live block, the library and the player
  (hls.js 1.7.3, Apache-2.0, loaded only when needed); `assets/blocks.js` is the block editor UI.
- `assets/*-rtl.css` are mirrored copies for right-to-left languages, generated with rtlcss
  (`npx rtlcss faith-tv-series/assets/faith-tv-series.css faith-tv-series/assets/faith-tv-series-rtl.css`,
  same for admin.css); `build-zip.py` warns when one is older than its source.
- `languages/`: the template and Spanish (es_MX, es_ES). After changing strings:
  `wp i18n make-pot`, `wp i18n update-po`, translate, `wp i18n make-mo`, `wp i18n make-json`.

## Test locally

A throwaway WordPress in Docker, with this folder mounted as the plugin:

```bash
docker network create ftvs-net
docker run -d --name ftvs-db --network ftvs-net -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp -e MYSQL_USER=wp -e MYSQL_PASSWORD=wpdev mysql:8.4
docker run -d --name ftvs-wp --network ftvs-net -p 127.0.0.1:8095:80 -e WORDPRESS_DB_HOST=ftvs-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wpdev -e WORDPRESS_DB_NAME=wp -v "$PWD/faith-tv-series:/var/www/html/wp-content/plugins/faith-tv-series" wordpress:php8.3-apache
```

Then finish the WordPress setup at http://127.0.0.1:8095, activate the plugin, and connect a
church (or press "Try it with sample videos").

## Tests

`tests/` holds an automated suite. It runs inside a real WordPress with WP-CLI (no Composer or
PHPUnit), makes no network calls (every HTTP request is a stub), never saves settings, and puts
back anything it touched in the database. `.github/workflows/ci.yml` runs it on every push and
pull request, along with PHP 7.4 to 8.4 syntax checks, a JavaScript syntax check and a check
that the plugin header, `FTVS_VERSION` and the readme `Stable tag` agree.

```bash
bash tests/run-docker.sh            # the whole suite, against the containers from "Test locally"
bash tests/run-docker.sh cache      # only tests whose name or file contains "cache"
bash tests/lint.sh                  # php -l on every PHP file, node --check on every script
PHP="docker exec -i ftvs3-wp php" bash tests/lint.sh php   # no PHP installed: use a container's
bash tests/check-version.sh v1.3.0  # the three version numbers agree, and match this tag
```

`run-docker.sh` runs WP-CLI in a throwaway `wordpress:cli` container that shares the site's
volumes. Run the suite on a test site, not a live website (a few tests briefly create and delete
a draft or published "series" post). If your containers are named differently:
`FTVS_WP=ftvs3-wp FTVS_DB=ftvs3-db FTVS_NET=ftvs3-net bash tests/run-docker.sh`. On any other
site with the plugin active: `wp eval-file tests/run.php [filter]`.

Add tests as functions named `test_*` in `tests/test-<area>.php` (helpers and assertions are in
`tests/helpers.php`). A test that fails because of a known, not-yet-fixed plugin bug can be marked with
`ftvs_known_bug()`; it shows as XFAIL, does not fail the run, and turns into XPASS once the bug
is fixed (`FTVS_STRICT=1` makes known bugs fail the run).
