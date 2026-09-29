# Faith TV Series

WordPress plugin that puts a Faith TV (Gideo) category, like the Mini Series, live on
faithtabernacle.com. New series added on Faith TV show up on the site by themselves, and
visitors can watch episodes right on the page.

- Elementor widget **Faith TV Series** (search "Faith TV" in the widget panel)
- Shortcode anywhere else: `[faith_tv_series category="Faith TV Mini Series"]`
- **Settings > Faith TV Series** lists every Faith TV category with a shortcode to copy

Layouts: showcase (default, a big featured series that rotates), 3D carousel, sliding row,
grid. Phones get their own layout (by default the swipe carousel). All options are in
[`faith-tv-series/readme.txt`](faith-tv-series/readme.txt).

## Install on the website

1. Build the zip: `python build-zip.py` (makes `faith-tv-series.zip`)
2. WordPress admin > Plugins > Add New Plugin > Upload Plugin > choose the zip > Install Now > Activate
3. Edit the page in Elementor, drag in the "Faith TV Series" widget, pick the category

To update later: build a new zip and upload it the same way. WordPress asks to replace the
installed version.

## How it works

- `includes/class-gideo-client.php` reads Gideo's public catalog
  (`ott.gideo.video/api/legacy`, account `Faith-Tabernacle-1`) and caches it (15 minutes by
  default). The last good copy is kept, so the site still shows the list if Gideo is down.
- `includes/class-renderer.php` renders the shortcode and the Elementor widget.
- `includes/class-rest.php` serves `/wp-json/faith-tv/v1/category/<id>` and `/video/<id>`
  to the page script when someone opens a series or presses play.
- `assets/faith-tv-series.js` runs the rotating showcase, the carousel and the player.
  Videos are HLS; browsers without built-in HLS use the bundled hls.js 1.7.3 (Apache-2.0),
  loaded only when someone presses play.

## Test locally

A throwaway WordPress in Docker, with this folder mounted as the plugin:

```bash
docker network create ftvs-net
docker run -d --name ftvs-db --network ftvs-net -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp -e MYSQL_USER=wp -e MYSQL_PASSWORD=wpdev mysql:8.4
docker run -d --name ftvs-wp --network ftvs-net -p 127.0.0.1:8095:80 -e WORDPRESS_DB_HOST=ftvs-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wpdev -e WORDPRESS_DB_NAME=wp -v "$PWD/faith-tv-series:/var/www/html/wp-content/plugins/faith-tv-series" wordpress:php8.3-apache
```

Then finish the WordPress setup at http://127.0.0.1:8095, activate the plugin and add the
shortcode to a page.
