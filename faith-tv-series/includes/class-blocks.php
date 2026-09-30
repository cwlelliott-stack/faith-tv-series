<?php
/**
 * Blocks for the WordPress block editor (for churches without Elementor): Faith TV Series,
 * Sunday Live and Sermon Library. They render on the server through FTVS_Renderer, exactly like
 * the shortcodes, and the editor shows a live preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Blocks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'faith-tv-blocks',
			FTVS_URL . 'assets/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ),
			FTVS_VERSION,
			true
		);
		wp_set_script_translations( 'faith-tv-blocks', 'faith-tv-series', FTVS_DIR . 'languages' );
		$text = array( 'type' => 'string', 'default' => '' );
		register_block_type(
			'faith-tv/series',
			array(
				'api_version'     => 3,
				'editor_script'   => 'faith-tv-blocks',
				'editor_style'    => 'faith-tv-series',
				'render_callback' => array( __CLASS__, 'series' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ) ),
				'attributes'      => array(
					'category'     => $text,
					'video'        => $text,
					'layout'       => $text,
					'mobileLayout' => $text,
					'theme'        => $text,
					'title'        => $text,
					'eyebrow'      => $text,
					'from'         => $text,
					'until'        => $text,
					'otherwise'    => $text,
					'limit'        => array( 'type' => 'number', 'default' => 0 ),
					'autoplay'     => array( 'type' => 'number', 'default' => 7 ),
					'openChannel'  => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_block_type(
			'faith-tv/live',
			array(
				'api_version'     => 3,
				'editor_script'   => 'faith-tv-blocks',
				'editor_style'    => 'faith-tv-series',
				'render_callback' => array( __CLASS__, 'live' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ) ),
				'attributes'      => array(
					'channel' => $text,
					'title'   => $text,
					'eyebrow' => $text,
					'theme'   => $text,
				),
			)
		);
		register_block_type(
			'faith-tv/channel',
			array(
				'api_version'     => 3,
				'editor_script'   => 'faith-tv-blocks',
				'editor_style'    => 'faith-tv-channel',
				'render_callback' => array( __CLASS__, 'channel' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ) ),
				'attributes'      => array(
					'align'    => array( 'type' => 'string', 'default' => 'full' ),
					'name'     => $text,
					'backdrop' => $text,
				),
			)
		);
		register_block_type(
			'faith-tv/library',
			array(
				'api_version'     => 3,
				'editor_script'   => 'faith-tv-blocks',
				'editor_style'    => 'faith-tv-series',
				'render_callback' => array( __CLASS__, 'library' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ) ),
				'attributes'      => array(
					'category' => $text,
					'title'    => $text,
					'eyebrow'  => $text,
					'theme'    => $text,
				),
			)
		);
	}

	private static function blank( $v ) {
		return '' === $v ? null : $v;
	}

	/** Wide and full width (the block's align setting) come through the wrapper. */
	private static function wrap( $html ) {
		if ( '' === $html || 0 === strpos( $html, '<!--' ) ) {
			return $html;
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	public static function series( $a ) {
		return self::wrap( FTVS_Renderer::render(
			array(
				'category'      => (string) $a['category'],
				'video'         => (string) $a['video'],
				'layout'        => self::blank( (string) $a['layout'] ),
				'mobile_layout' => self::blank( (string) $a['mobileLayout'] ),
				'theme'         => self::blank( (string) $a['theme'] ),
				'title'         => (string) $a['title'],
				'eyebrow'       => (string) $a['eyebrow'],
				'from'          => (string) $a['from'],
				'until'         => (string) $a['until'],
				'otherwise'     => (string) $a['otherwise'],
				'limit'         => (int) $a['limit'],
				'autoplay'      => (int) $a['autoplay'],
				'play'          => ! empty( $a['openChannel'] ) ? 'faithtv' : 'site',
			)
		) );
	}

	public static function live( $a ) {
		return self::wrap( FTVS_Renderer::live(
			array(
				'channel' => (string) $a['channel'],
				'title'   => (string) $a['title'],
				'eyebrow' => (string) $a['eyebrow'],
				'theme'   => self::blank( (string) $a['theme'] ),
			)
		) );
	}

	public static function channel( $a ) {
		return self::wrap( FTVS_Channel::render(
			array(
				'name'     => (string) $a['name'],
				'backdrop' => (string) $a['backdrop'],
			)
		) );
	}

	public static function library( $a ) {
		return self::wrap( FTVS_Renderer::library(
			array(
				'category' => (string) $a['category'],
				'title'    => (string) $a['title'],
				'eyebrow'  => (string) $a['eyebrow'],
				'theme'    => self::blank( (string) $a['theme'] ),
			)
		) );
	}

	/**
	 * A ready-made Watch page: Sunday live, the newest messages, the church's series and the
	 * sermon library. Used by "Create my Watch page".
	 *
	 * @return int|WP_Error The new page's id (a draft).
	 */
	public static function create_watch_page() {
		$tree   = FTVS_Catalog::get_tree();
		$series = '';
		foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
			if ( '' === $series && $row['children'] ) {
				$series = $row['id'];
			}
		}
		// serialize_block() writes the attributes the way the editor reads them back (quotes and apostrophes in
		// translated headings included).
		$block    = function ( $name, $attrs ) {
			return serialize_block(
				array(
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		};
		$blocks   = '';
		$has_live = FTVS_Catalog::has_live() || '' !== (string) FTVS_Settings::get( 'live_url' ) || FTVS_Settings::get( 'services' );
		if ( $has_live ) {
			$blocks .= $block( 'faith-tv/live', array( 'align' => 'wide', 'eyebrow' => __( 'Sunday', 'faith-tv-series' ), 'title' => __( 'Watch live', 'faith-tv-series' ) ) ) . "\n\n";
		}
		$blocks .= $block( 'faith-tv/series', array( 'align' => 'wide', 'category' => '@newest', 'layout' => 'row', 'mobileLayout' => 'row', 'title' => __( 'Latest messages', 'faith-tv-series' ) ) ) . "\n\n";
		if ( '' !== $series ) {
			$blocks .= $block( 'faith-tv/series', array( 'align' => 'wide', 'category' => (string) $series, 'title' => __( 'Series', 'faith-tv-series' ) ) ) . "\n\n";
		}
		$blocks .= $block( 'faith-tv/library', array( 'align' => 'wide', 'title' => __( 'Find a message', 'faith-tv-series' ) ) );
		$slug    = get_page_by_path( 'watch' ) ? 'watch-online' : 'watch';
		$id      = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => __( 'Watch', 'faith-tv-series' ),
				'post_name'    => $slug,
				'post_content' => $blocks,
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			FTVS_Settings::update( array( 'watch_page_id' => $id ) );
		}
		return $id;
	}
}
