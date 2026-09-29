# Faith TV Series (Faith Stream for WordPress)

WordPress plugin by FaithStream that puts a church's video series live on its website, from
**Faith Stream** or a **Gideo** TV channel. New series show up on the site by themselves, and
visitors watch right on the page. Built first for faithtabernacle.com; any church can connect.

- **Faith Stream** menu in WordPress: Church (connect in 3 steps), Videos, Look & feel, Embed, Updates
- Elementor widget **Faith TV Series** (search "Faith TV" in the widget panel)
- Shortcode anywhere else: `[faith_tv_series category="<id>"]` (copy from Faith Stream > Videos)
- **Embed** page: copy-and-paste code to show a category on any other website
- Updates itself from this repository's GitHub releases

Layouts: showcase (default), 3D carousel, featured + list (phones), sliding row, grid. All
options are in [`faith-tv-series/readme.txt`](faith-tv-series/readme.txt).

## Install on the website

1. Build the zip: `python build-zip.py` (makes `faith-tv-series.zip`)
2. WordPress admin > Plugins > Add New Plugin > Upload Plugin > choose the zip > Install Now > Activate
3. Edit the page in Elementor, drag in the "Faith TV Series" widget, pick the category

To update later: build a new zip and upload it the same way. WordPress asks to replace the
installed version.

## Releasing an update

```bash
python release.py 1.2.0 "What changed, in one line"
```

It sets the version, adds the note to the changelog, builds `faith-tv-series.zip`, commits,
tags `v1.2.0`, pushes and creates the GitHub release. Sites see "Update available" within
12 hours, or right away with Faith Stream > Updates > Check for updates now. Add
`--dry-run` to see what it would do first.

## How it works

- `includes/class-catalog.php` is the switchboard: every request goes to the connected
  church's platform, `class-gideo-client.php` (Gideo's public API) or
  `class-faithstream-client.php` (Faith Stream's public API). Both return the same shapes.
- `includes/class-cache.php` caches answers (15 minutes by default) and keeps the last good
  copy, so the site still shows the list if the platform is down.
- `includes/class-renderer.php` renders the shortcode and the Elementor widget.
- `includes/class-embed.php` serves `/?ftvs_embed=<category>` for iframes on other sites;
  `assets/embed.js` on the host page lets the frame grow to fit.
- `includes/class-rest.php` serves `/wp-json/faith-tv/v1/category/<id>` and `/video/<id>`
  to the page script when someone opens a series or presses play.
- `includes/class-updater.php` feeds GitHub releases into WordPress's update system.
- `assets/faith-tv-series.js` runs the layouts and the player (hls.js 1.7.3, Apache-2.0,
  loaded only on play).

## Test locally

A throwaway WordPress in Docker, with this folder mounted as the plugin:

```bash
docker network create ftvs-net
docker run -d --name ftvs-db --network ftvs-net -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp -e MYSQL_USER=wp -e MYSQL_PASSWORD=wpdev mysql:8.4
docker run -d --name ftvs-wp --network ftvs-net -p 127.0.0.1:8095:80 -e WORDPRESS_DB_HOST=ftvs-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wpdev -e WORDPRESS_DB_NAME=wp -v "$PWD/faith-tv-series:/var/www/html/wp-content/plugins/faith-tv-series" wordpress:php8.3-apache
```

Then finish the WordPress setup at http://127.0.0.1:8095, activate the plugin and add the
shortcode to a page.
