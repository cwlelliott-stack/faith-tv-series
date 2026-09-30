<?php
/**
 * A page on the church's own site for every message: /watch/<video>/ under the Watch page the
 * church picks (Faith Stream > Church, or "Create my Watch page"). It shows the video, its
 * details and more from the series, and carries what Google, Facebook and text messages read
 * (title, picture, description, video structured data). Messages are also listed in the site's
 * sitemap. Shared links point here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Watch {

	const VAR = 'ftvs_video';

	/** @var array|null The video being shown on this request. */
	private static $video = null;

	/** @var bool Did the theme print the page title (now the message's)? Then the message view doesn't repeat it. */
	private static $titled = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 1 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 99999 );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ), 99 );
		add_filter( 'the_title', array( __CLASS__, 'page_title' ), 99, 2 );
		add_filter( 'request', array( __CLASS__, 'real_child_pages' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'post_updated', array( __CLASS__, 'maybe_flush' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'sitemap' ), 30 );
		add_shortcode( 'faith_tv_watch', array( __CLASS__, 'shortcode' ) );
	}

	public static function page_id() {
		$id   = (int) FTVS_Settings::get( 'watch_page_id' );
		$page = $id ? get_post( $id ) : null;
		return $page && 'page' === $page->post_type && 'publish' === $page->post_status ? $id : 0;
	}

	/** This video's page on the church's site, or '' when there is no Watch page. */
	public static function url( $video_id ) {
		$page = self::page_id();
		if ( ! $page || ! is_string( $video_id ) || '' === $video_id ) {
			return '';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( array( 'page_id' => $page, self::VAR => $video_id ), home_url( '/' ) );
		}
		return home_url( user_trailingslashit( get_page_uri( $page ) . '/' . rawurlencode( $video_id ) ) );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::VAR;
		return $vars;
	}

	public static function rewrite() {
		$page = self::page_id();
		if ( ! $page ) {
			return;
		}
		$uri = get_page_uri( $page );
		add_rewrite_rule( '^' . preg_quote( $uri, '#' ) . '/([A-Za-z0-9_-]{1,128})/?$', 'index.php?page_id=' . $page . '&' . self::VAR . '=$matches[1]', 'top' );
		// New Watch page, or its address changed: rebuild the rules once.
		$ver = md5( $page . '|' . $uri );
		if ( get_option( 'ftvs_rewrite_ver' ) !== $ver ) {
			update_option( 'ftvs_rewrite_ver', $ver, true );
			delete_option( 'rewrite_rules' ); // rebuilt when this request is parsed, with every plugin's rules
		}
	}

	public static function maybe_flush( $post_id ) {
		if ( (int) $post_id === (int) FTVS_Settings::get( 'watch_page_id' ) ) {
			delete_option( 'ftvs_rewrite_ver' );
		}
	}

	/** A real page under the Watch page (watch/live/, say) is that page, not a message called "live". */
	public static function real_child_pages( $vars ) {
		if ( empty( $vars[ self::VAR ] ) || empty( $vars['page_id'] ) ) {
			return $vars;
		}
		$path = get_page_uri( (int) $vars['page_id'] ) . '/' . $vars[ self::VAR ];
		return get_page_by_path( $path ) ? array( 'pagename' => $path ) : $vars;
	}

	/** The requested video; unknown ones are a plain 404. */
	public static function template_redirect() {
		$id = get_query_var( self::VAR );
		if ( '' === $id || ! self::page_id() || ! is_page( self::page_id() ) ) {
			return;
		}
		$id    = sanitize_text_field( $id );
		$video = FTVS_Catalog::is_known( $id ) ? FTVS_Catalog::find_video( $id ) : null;
		if ( ! $video || ( FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		self::$video = $video;
		// This page speaks for itself in search results and link previews.
		remove_action( 'wp_head', 'rel_canonical' );
		self::quiet_seo_plugins();
	}

	/** On a message's page the page's own content is replaced by the message. */
	public static function content( $content ) {
		if ( null === self::$video || ! in_the_loop() || ! is_main_query() || (int) get_the_ID() !== self::page_id() ) {
			return $content;
		}
		return self::render( self::$video );
	}

	public static function shortcode() {
		return null !== self::$video ? self::render( self::$video ) : '';
	}

	/** The theme's heading for the Watch page shows the message's title instead. */
	public static function page_title( $title, $post_id = 0 ) {
		if ( null === self::$video || (int) $post_id !== self::page_id() || is_admin() || ! in_the_loop() ) {
			return $title;
		}
		self::$titled = true;
		return esc_html( self::$video['title'] ); // themes print the_title() as HTML
	}

	public static function document_title( $parts ) {
		if ( null !== self::$video ) {
			$parts['title'] = self::$video['title'];
		}
		return $parts;
	}

	/** One message: player, details, share, next steps, and more from the series. */
	public static function render( $video ) {
		wp_enqueue_style( 'faith-tv-series' );
		wp_enqueue_script( 'faith-tv-series' );
		$theme  = FTVS_Settings::get( 'theme' );
		$series = isset( $video['series'] ) ? $video['series'] : '';
		$meta   = array_filter(
			array(
				$video['speaker'],
				self::date( $video['added'] ),
				$video['scripture'],
				FTVS_Renderer::duration( $video['length'] ),
			)
		);
		$item   = FTVS_Renderer::item_json( $video );
		ob_start();
		?>
		<div class="<?php echo esc_attr( 'ftvs ftvs--' . $theme . ' ' . FTVS_Renderer::style_class() . ' ftvs-watch' ); ?>" data-ftvs data-layout="watch" data-play="site" data-category="<?php echo esc_attr( $video['parent'] ); ?>" data-label="<?php echo esc_attr( $series ); ?>">
			<a class="ftvs__card ftvs-watch__player" href="#faith-tv-<?php echo esc_attr( $video['parent'] . '/' . $video['id'] ); ?>" data-kind="video" data-meta="<?php echo esc_attr( FTVS_Renderer::duration( $video['length'] ) ); ?>" data-item="<?php echo esc_attr( $item ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: video title */ __( 'Watch %s', 'faith-tv-series' ), $video['title'] ) ); ?>">
				<span class="ftvs__thumb"><img src="<?php echo esc_url( '' !== $video['poster'] ? $video['poster'] : $video['image'] ); ?>" alt="" fetchpriority="high" decoding="async"><span class="ftvs__play ftvs__play--big" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span></span>
			</a>
			<div class="ftvs-watch__body">
				<?php if ( '' !== $series ) : ?>
					<p class="ftvs-kicker"><span class="ftvs-kicker__label"><?php echo esc_html( $series ); ?></span></p>
				<?php endif; ?>
				<?php if ( ! self::$titled ) : ?>
					<h1 class="ftvs-feature__title ftvs-watch__title"><?php echo esc_html( $video['title'] ); ?></h1>
				<?php endif; ?>
				<?php if ( $meta ) : ?>
					<p class="ftvs-feature__meta"><?php echo esc_html( implode( '  ·  ', $meta ) ); ?></p>
				<?php endif; ?>
				<div class="ftvs-show__actions">
					<a class="ftvs-btn ftvs-btn--primary" href="#faith-tv-<?php echo esc_attr( $video['parent'] . '/' . $video['id'] ); ?>" data-ftvs-open data-kind="video" data-item="<?php echo esc_attr( $item ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg><span><?php esc_html_e( 'Watch now', 'faith-tv-series' ); ?></span></a>
					<?php if ( FTVS_Settings::get( 'share' ) ) : ?>
						<button type="button" class="ftvs-btn ftvs-btn--ghost" data-ftvs-share="<?php echo esc_attr( self::url( $video['id'] ) ); ?>" data-title="<?php echo esc_attr( $video['title'] ); ?>"><?php esc_html_e( 'Share', 'faith-tv-series' ); ?></button>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $video['description'] ) : ?>
					<div class="ftvs-feature__desc ftvs-watch__desc"><?php echo wp_kses_post( wpautop( esc_html( $video['description'] ) ) ); ?></div>
				<?php endif; ?>
				<?php echo FTVS_Renderer::next_steps_html( $video ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			</div>
		</div>
		<?php
		if ( '' !== $video['parent'] ) {
			echo FTVS_Renderer::render( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the renderer.
				array(
					'category'      => $video['parent'],
					'layout'        => 'row',
					'mobile_layout' => 'row',
					/* translators: %s: series name */
					'title'         => '' !== $series ? sprintf( __( 'More from %s', 'faith-tv-series' ), $series ) : __( 'More messages', 'faith-tv-series' ),
					'badge'         => '',
				)
			);
		}
		return trim( ob_get_clean() );
	}

	/** Title, description, picture and video details for search engines and link previews. */
	public static function head() {
		if ( null === self::$video ) {
			return;
		}
		$v     = self::$video;
		$url   = self::url( $v['id'] );
		$desc  = '' !== $v['description'] ? wp_trim_words( $v['description'], 40, '…' ) : sprintf( /* translators: 1: video title, 2: church name */ __( 'Watch %1$s from %2$s.', 'faith-tv-series' ), $v['title'], FTVS_Settings::get( 'church_name' ) );
		$image = '' !== $v['poster'] ? $v['poster'] : $v['image'];
		echo "\n<!-- Faith TV Series: message page -->\n";
		if ( ! self::seo_plugin() ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
			$og = array(
				'og:type'        => 'video.other',
				'og:title'       => $v['title'],
				'og:description' => $desc,
				'og:url'         => $url,
				'og:image'       => $image,
				'og:site_name'   => get_bloginfo( 'name' ),
				'twitter:card'   => 'summary_large_image',
				'twitter:title'  => $v['title'],
				'twitter:image'  => $image,
			);
			foreach ( $og as $prop => $value ) {
				if ( '' !== (string) $value ) {
					printf( '<meta %s="%s" content="%s">' . "\n", 0 === strpos( $prop, 'twitter:' ) ? 'name' : 'property', esc_attr( $prop ), esc_attr( $value ) );
				}
			}
		}
		$ld = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $v['title'],
			'description'  => $desc,
			'thumbnailUrl' => array_values( array_filter( array( $image ) ) ),
			'uploadDate'   => self::iso( $v['added'] ),
			'url'          => $url,
			'publisher'    => array(
				'@type' => 'Organization',
				'name'  => (string) FTVS_Settings::get( 'church_name' ) ? FTVS_Settings::get( 'church_name' ) : get_bloginfo( 'name' ),
			),
		);
		if ( $v['length'] > 0 ) {
			$ld['duration'] = 'PT' . intdiv( $v['length'], 60 ) . 'M' . ( $v['length'] % 60 ) . 'S';
		}
		$ld = array_filter( $ld );
		echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}

	private static function seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	/** The message's own address wherever WordPress (or a plugin that asks it) builds the canonical link. */
	public static function canonical( $url, $post = null ) {
		return null !== self::$video ? self::url( self::$video['id'] ) : $url;
	}

	/** SEO plugins describe the Watch page itself; point them at the message instead. */
	private static function quiet_seo_plugins() {
		$v     = self::$video;
		$title = function () use ( $v ) {
			return $v['title'] . ' - ' . get_bloginfo( 'name' );
		};
		$desc  = function () use ( $v ) {
			return wp_trim_words( $v['description'], 40, '…' );
		};
		$url   = function () use ( $v ) {
			return FTVS_Watch::url( $v['id'] );
		};
		$image = function () use ( $v ) {
			return '' !== $v['poster'] ? $v['poster'] : $v['image'];
		};
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 99, 2 );
		foreach ( array( 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title', 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title', 'aioseo_title', 'seopress_titles_title' ) as $hook ) {
			add_filter( $hook, $title, 99 );
		}
		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'aioseo_description', 'seopress_titles_desc' ) as $hook ) {
			add_filter( $hook, $desc, 99 );
		}
		foreach ( array( 'wpseo_canonical', 'wpseo_opengraph_url', 'rank_math/frontend/canonical', 'rank_math/opengraph/facebook/og_url', 'aioseo_canonical_url' ) as $hook ) {
			add_filter( $hook, $url, 99 );
		}
		// SEOPress hands over the whole <link> tag.
		add_filter(
			'seopress_titles_canonical',
			function () use ( $url ) {
				return '<link rel="canonical" href="' . esc_url( $url() ) . '" />';
			},
			99
		);
		// All in One SEO's social tags come as one list.
		foreach ( array( 'aioseo_facebook_tags', 'aioseo_twitter_tags' ) as $hook ) {
			add_filter(
				$hook,
				function ( $tags ) use ( $title, $desc, $url, $image ) {
					if ( ! is_array( $tags ) ) {
						return $tags;
					}
					$set = array(
						'og:title'            => $title(),
						'og:description'      => $desc(),
						'og:url'              => $url(),
						'og:image'            => $image(),
						'twitter:title'       => $title(),
						'twitter:description' => $desc(),
						'twitter:image'       => $image(),
					);
					foreach ( $set as $k => $v ) {
						if ( array_key_exists( $k, $tags ) && '' !== (string) $v ) {
							$tags[ $k ] = $v;
						}
					}
					return $tags;
				},
				99
			);
		}
		foreach ( array( 'wpseo_opengraph_image', 'wpseo_twitter_image', 'rank_math/opengraph/facebook/image', 'rank_math/opengraph/twitter/image' ) as $hook ) {
			add_filter( $hook, $image, 99 );
		}
	}

	/** Every message in the site's sitemap (WordPress 5.5+). */
	public static function sitemap() {
		if ( self::page_id() && function_exists( 'wp_register_sitemap_provider' ) && ! FTVS_Catalog::is_demo() ) {
			require_once FTVS_DIR . 'includes/class-sitemap.php';
			wp_register_sitemap_provider( 'faithtv', new FTVS_Sitemap() );
		}
	}

	private static function date( $raw ) {
		$t = $raw ? strtotime( $raw ) : false;
		return $t ? wp_date( get_option( 'date_format' ), $t ) : '';
	}

	private static function iso( $raw ) {
		$t = $raw ? strtotime( $raw ) : false;
		return $t ? gmdate( 'c', $t ) : '';
	}
}
