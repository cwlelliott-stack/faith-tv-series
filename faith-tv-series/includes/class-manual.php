<?php
/**
 * Series built by hand in WordPress: a title, a picture, a description, and a list of video
 * links (YouTube, Vimeo, anything WordPress can embed, or a direct .m3u8 stream). They work
 * with every layout, next to whatever platform the church connected (or with none at all).
 *
 * Ids: a series is "_ms<post id>", its episodes "_mv<post id>x<n>".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Manual {

	const TYPE = 'ftvs_series';
	const META = '_ftvs_episodes';
	const DONE = '_ftvs_resolved';
	const ROW  = '_msall';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		// The list is read once per request; a series saved, trashed or deleted since is read again.
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'forget_posts' ), 1 );
		add_action( 'trashed_post', array( __CLASS__, 'forget_posts' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget_posts' ) );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		// Keep the Faith Stream menu open while building a series.
		add_filter(
			'parent_file',
			function ( $parent ) {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				return $screen && self::TYPE === $screen->post_type ? FTVS_Admin::PAGE : $parent;
			}
		);
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Series you build', 'faith-tv-series' ),
					'singular_name' => __( 'Series', 'faith-tv-series' ),
					'add_new_item'  => __( 'Build a series', 'faith-tv-series' ),
					'edit_item'     => __( 'Edit series', 'faith-tv-series' ),
					'menu_name'     => __( 'Build a series', 'faith-tv-series' ),
					'all_items'     => __( 'Build a series', 'faith-tv-series' ),
				),
				'public'       => false,
				'show_ui'      => true,
				// Listed last under the Faith Stream menu (FTVS_Admin::menu), after the tabs.
				'show_in_menu' => false,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'show_in_rest' => false,
				'capability_type' => 'page',
			)
		);
	}

	public static function owns( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^_m(s\d+|v\d+x\d+|sall)$/D', $id );
	}

	public static function exists( $id ) {
		if ( self::ROW === $id ) {
			return (bool) self::posts();
		}
		$post = self::post_of( $id );
		if ( ! $post ) {
			return false;
		}
		if ( preg_match( '/^_mv\d+x(\d+)$/D', $id, $m ) ) {
			return isset( self::episodes( $post )[ (int) $m[1] ] );
		}
		return true;
	}

	public static function title( $id ) {
		if ( self::ROW === $id ) {
			return __( 'Series', 'faith-tv-series' );
		}
		$post = self::post_of( $id );
		return $post ? get_the_title( $post ) : '';
	}

	/** Published series, in the order set on their edit screens. */
	/** @var WP_Post[]|null The published series, read once per request. */
	private static $posts = null;

	public static function forget_posts() {
		self::$posts = null;
	}

	private static function posts() {
		if ( null === self::$posts ) {
			self::$posts = get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
					'no_found_rows'  => true,
				)
			);
		}
		return self::$posts;
	}

	private static function post_of( $id ) {
		if ( ! preg_match( '/^_m[sv](\d+)/', (string) $id, $m ) ) {
			return null;
		}
		$post = get_post( (int) $m[1] );
		return $post && self::TYPE === $post->post_type && 'publish' === $post->post_status ? $post : null;
	}

	/** A row for the category tree (admin lists, Elementor picker), or null when there are none. */
	public static function tree_row() {
		$posts = self::posts();
		if ( ! $posts ) {
			return null;
		}
		$children = array_map( array( __CLASS__, 'category' ), $posts );
		return array(
			'id'            => self::ROW,
			'title'         => __( 'Series you built', 'faith-tv-series' ),
			'description'   => '',
			'image'         => $children[0]['image'],
			'videos'        => 0,
			'subcategories' => count( $children ),
			'children'      => $children,
			'style'         => '',
		);
	}

	public static function get_children( $id ) {
		if ( self::ROW === $id ) {
			return array(
				'categories' => array_map( array( __CLASS__, 'category' ), self::posts() ),
				'videos'     => array(),
			);
		}
		$post = self::post_of( $id );
		if ( ! $post ) {
			return new WP_Error( 'ftvs_gone', __( 'That series was deleted.', 'faith-tv-series' ) );
		}
		return array(
			'categories' => array(),
			'videos'     => self::videos( $post ),
		);
	}

	/** Every episode of every series (joins the channel's library). */
	public static function library() {
		$all = array();
		foreach ( self::posts() as $post ) {
			foreach ( self::videos( $post ) as $video ) {
				$video['series'] = get_the_title( $post );
				$all[]           = $video;
			}
		}
		return $all;
	}

	public static function get_video( $id ) {
		if ( ! preg_match( '/^_mv(\d+)x(\d+)$/D', (string) $id, $m ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a video on your channel.', 'faith-tv-series' ) );
		}
		$post = self::post_of( $id );
		$eps  = $post ? self::episodes( $post ) : array();
		if ( ! isset( $eps[ (int) $m[2] ] ) ) {
			return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
		}
		$ep    = $eps[ (int) $m[2] ];
		$video = self::video( $post, (int) $m[2], $ep );
		$video['hls']      = 'hls' === $ep['kind'] ? $ep['url'] : '';
		$video['embed']    = 'embed' === $ep['kind'] ? $ep['embed'] : '';
		$video['audio']    = '';
		$video['captions'] = false;
		$video['series']   = array(
			array(
				'id'    => '_ms' . $post->ID,
				'title' => get_the_title( $post ),
			),
		);
		$video['related']  = array();
		return $video;
	}

	private static function category( $post ) {
		$eps = self::episodes( $post );
		return array(
			'id'            => '_ms' . $post->ID,
			'title'         => get_the_title( $post ),
			'description'   => trim( wp_strip_all_tags( $post->post_content ) ),
			'image'         => self::image( $post, $eps ),
			'videos'        => count( $eps ),
			'subcategories' => 0,
		);
	}

	private static function videos( $post ) {
		$out = array();
		foreach ( self::episodes( $post ) as $n => $ep ) {
			$out[] = self::video( $post, $n, $ep );
		}
		return $out;
	}

	private static function video( $post, $n, $ep ) {
		$image = '' !== $ep['image'] ? $ep['image'] : self::image( $post, array() );
		return array(
			'id'          => '_mv' . $post->ID . 'x' . $n,
			'parent'      => '_ms' . $post->ID,
			/* translators: %d: episode number */
			'title'       => '' !== $ep['title'] ? $ep['title'] : sprintf( __( 'Episode %d', 'faith-tv-series' ), $n + 1 ),
			'description' => '',
			'image'       => $image,
			'poster'      => $image,
			'length'      => 0,
			'added'       => get_post_time( 'c', true, $post ),
			'live'        => false,
			'speaker'     => '',
			'scripture'   => '',
			'tags'        => array(),
		);
	}

	private static function image( $post, $eps ) {
		$thumb = get_the_post_thumbnail_url( $post, 'large' );
		if ( $thumb ) {
			return $thumb;
		}
		foreach ( $eps as $ep ) {
			if ( '' !== $ep['image'] ) {
				return $ep['image'];
			}
		}
		return '';
	}

	/** @return array List of { url, kind: hls|embed, embed, title, image }. */
	private static function episodes( $post ) {
		$done = get_post_meta( $post->ID, self::DONE, true );
		return is_array( $done ) ? $done : array();
	}

	/* ---------- Edit screen ---------- */

	public static function meta_box() {
		add_meta_box( 'ftvs-episodes', __( 'Episodes', 'faith-tv-series' ), array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'ftvs_series_save', 'ftvs_series_nonce' );
		$raw = (string) get_post_meta( $post->ID, self::META, true );
		$eps = self::episodes( $post );
		?>
		<p><?php esc_html_e( 'One video per line, in the order they should play. Paste a YouTube or Vimeo link, a link from any site WordPress can embed, or a direct .m3u8 stream address. To name an episode yourself, add " | " and the name after the link.', 'faith-tv-series' ); ?></p>
		<textarea name="ftvs_episodes" rows="8" class="large-text code" placeholder="https://www.youtube.com/watch?v=...&#10;https://vimeo.com/123456 | Part 2: Going deeper"><?php echo esc_textarea( $raw ); ?></textarea>
		<?php if ( $eps ) : ?>
			<ol>
				<?php foreach ( $eps as $ep ) : ?>
					<li><?php echo esc_html( '' !== $ep['title'] ? $ep['title'] : $ep['url'] ); ?> <span class="description">(<?php echo 'hls' === $ep['kind'] ? esc_html__( 'stream', 'faith-tv-series' ) : esc_html( wp_parse_url( $ep['url'], PHP_URL_HOST ) ); ?>)</span></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The picture: set a Featured image, or the first episode\'s picture is used. The description comes from the text above.', 'faith-tv-series' ); ?></p>
		<?php
	}

	/** Looks each link up once, when the series is saved, so visitors never wait on other sites. */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['ftvs_series_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ftvs_series_nonce'] ) ), 'ftvs_series_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['ftvs_episodes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ftvs_episodes'] ) ) : '';
		update_post_meta( $post_id, self::META, $raw );
		$old = array();
		foreach ( self::episodes( $post ) as $ep ) {
			$old[ $ep['url'] ] = $ep;
		}
		$eps = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = esc_url_raw( $parts[0] );
			if ( 0 !== strpos( $url, 'https://' ) || count( $eps ) >= 100 ) {
				continue;
			}
			$title = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
			$ep    = isset( $old[ $url ] ) ? $old[ $url ] : self::resolve( $url );
			if ( ! $ep ) {
				continue;
			}
			if ( '' !== $title ) {
				$ep['title'] = $title;
			}
			$eps[] = $ep;
		}
		update_post_meta( $post_id, self::DONE, $eps );
		FTVS_Purge::soon();
	}

	/** @return array|null One episode from a link. */
	public static function resolve( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/\.m3u8$/i', $path ) ) {
			return array(
				'url'   => $url,
				'kind'  => 'hls',
				'embed' => '',
				'title' => '',
				'image' => '',
			);
		}
		$oembed = _wp_oembed_get_object();
		$data   = $oembed->get_data( $url, array( 'width' => 1280 ) );
		if ( ! $data || empty( $data->html ) || ! preg_match( '/<iframe[^>]+src=["\']([^"\']+)["\']/i', $data->html, $m ) ) {
			return null;
		}
		return array(
			'url'   => $url,
			'kind'  => 'embed',
			'embed' => self::autoplay( html_entity_decode( $m[1] ) ),
			'title' => isset( $data->title ) ? sanitize_text_field( $data->title ) : '',
			'image' => isset( $data->thumbnail_url ) ? esc_url_raw( $data->thumbnail_url ) : '',
		);
	}

	/** Player address that starts playing and reports when it ends (YouTube and Vimeo). */
	private static function autoplay( $src ) {
		$src = esc_url_raw( $src );
		if ( preg_match( '#^https://(www\.)?youtube\.com/embed/([A-Za-z0-9_-]{11})#', $src, $m ) ) {
			return FTVS_YouTube_Client::embed_url( $m[2] );
		}
		if ( false !== strpos( $src, 'player.vimeo.com/video/' ) ) {
			return add_query_arg( array( 'autoplay' => 1, 'api' => 1, 'dnt' => 1 ), $src );
		}
		return add_query_arg( 'autoplay', 1, $src );
	}

	public static function columns( $cols ) {
		$cols['ftvs_eps']  = __( 'Episodes', 'faith-tv-series' );
		$cols['ftvs_code'] = __( 'Shortcode', 'faith-tv-series' );
		return $cols;
	}

	public static function column( $col, $post_id ) {
		if ( 'ftvs_eps' === $col ) {
			echo (int) count( self::episodes( get_post( $post_id ) ) );
		} elseif ( 'ftvs_code' === $col ) {
			echo '<code>' . esc_html( '[faith_tv_series category="_ms' . (int) $post_id . '"]' ) . '</code>';
		}
	}
}
