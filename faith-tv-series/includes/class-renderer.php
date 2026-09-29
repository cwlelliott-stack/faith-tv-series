<?php
/**
 * The [faith_tv_series] shortcode. The Elementor widget renders through here too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Renderer {

	public static function register() {
		wp_register_style( 'faith-tv-series', FTVS_URL . 'assets/faith-tv-series.css', array(), FTVS_VERSION );
		wp_register_script( 'faith-tv-series', FTVS_URL . 'assets/faith-tv-series.js', array(), FTVS_VERSION, true );
		// Attached at registration so it prints whenever the script does, even when
		// a page builder serves the widget HTML without calling render().
		wp_add_inline_script( 'faith-tv-series', 'window.FTVS_CONFIG = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
		add_shortcode( 'faith_tv_series', array( __CLASS__, 'shortcode' ) );
	}

	const LAYOUTS = array( 'showcase', 'coverflow', 'row', 'grid' );

	public static function defaults() {
		return array(
			'category'     => '',
			'layout'       => 'showcase',
			'mobile_layout' => 'auto',
			'limit'        => 0,
			'play'         => 'site',
			'eyebrow'      => '',
			'title'        => '',
			'label'        => '',
			'badge'        => __( 'New', 'faith-tv-series' ),
			'autoplay'     => 7,
			'descriptions' => 'no',
			'theme'        => 'dark',
		);
	}

	public static function shortcode( $atts ) {
		return self::render( shortcode_atts( self::defaults(), $atts, 'faith_tv_series' ) );
	}

	/**
	 * @param array $atts See defaults().
	 * @return string HTML.
	 */
	public static function render( $atts ) {
		$atts     = wp_parse_args( $atts, self::defaults() );
		$layout   = in_array( $atts['layout'], self::LAYOUTS, true ) ? $atts['layout'] : 'showcase';
		$play     = 'faithtv' === $atts['play'] ? 'faithtv' : 'site';
		$theme    = 'light' === $atts['theme'] ? 'light' : 'dark';
		$autoplay = min( 60, absint( $atts['autoplay'] ) );
		$descs    = in_array( strtolower( (string) $atts['descriptions'] ), array( 'yes', '1', 'true', 'on' ), true );

		$found = FTVS_Gideo_Client::find_category( $atts['category'] );
		if ( is_wp_error( $found ) ) {
			return self::problem( $found );
		}
		$data = FTVS_Gideo_Client::get_children( $found['id'] );
		if ( is_wp_error( $data ) ) {
			return self::problem( $data );
		}
		$items = self::items( $data, $found['id'], absint( $atts['limit'] ) );
		if ( ! $items ) {
			return self::problem( new WP_Error( 'ftvs_empty', __( 'This Faith TV category has nothing published yet.', 'faith-tv-series' ) ) );
		}

		self::enqueue();

		$label = trim( (string) $atts['label'] );
		if ( '' === $label ) {
			$label = '' !== $found['title'] ? $found['title'] : __( 'Faith TV', 'faith-tv-series' );
		}
		$ctx = array(
			'play'  => $play,
			'label' => $label,
			'badge' => trim( (string) $atts['badge'] ),
			'descs' => $descs,
			'more'  => FTVS_Settings::get( 'tv_url' ) . '/program-group/' . $found['id'],
		);

		$mobile = self::mobile_layout( $atts['mobile_layout'], $layout );
		$split  = $mobile !== $layout;
		// Both versions are in the page when they differ; lazy images keep the hidden one from downloading.
		$ctx['lazy'] = $split;
		$root        = array(
			'theme'    => $theme,
			'play'     => $play,
			'category' => $found['id'],
			'label'    => $label,
			'autoplay' => $autoplay,
		);

		ob_start();
		if ( $split ) {
			echo '<div class="' . esc_attr( 'ftvs ftvs--' . $theme . ' ftvs-switch' ) . '">';
			self::header( $atts );
			self::root( $layout, 'ftvs-only-desktop', $items, $ctx, $root );
			self::root( $mobile, 'ftvs-only-mobile', $items, $ctx, $root );
			echo '</div>';
		} else {
			self::root( $layout, '', $items, $ctx, $root, $atts );
		}
		return trim( ob_get_clean() );
	}

	/**
	 * Which layout phones get. "auto" picks the one that works best on a small screen.
	 */
	private static function mobile_layout( $choice, $desktop ) {
		if ( in_array( $choice, self::LAYOUTS, true ) ) {
			return $choice;
		}
		if ( 'same' === $choice ) {
			return $desktop;
		}
		$auto = array(
			'showcase'  => 'coverflow',
			'coverflow' => 'coverflow',
			'row'       => 'row',
			'grid'      => 'row',
		);
		return $auto[ $desktop ];
	}

	/** One section root. $atts is passed only when the heading belongs inside it. */
	private static function root( $layout, $extra_class, $items, $ctx, $root, $atts = null ) {
		$classes = 'ftvs ftvs--' . $root['theme'] . ' ftvs--' . $layout . ( $root['autoplay'] ? '' : ' ftvs--no-autoplay' ) . ( $extra_class ? ' ' . $extra_class : '' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-ftvs data-layout="<?php echo esc_attr( $layout ); ?>" data-play="<?php echo esc_attr( $root['play'] ); ?>" data-category="<?php echo esc_attr( $root['category'] ); ?>" data-label="<?php echo esc_attr( $root['label'] ); ?>" data-autoplay="<?php echo esc_attr( $root['autoplay'] ); ?>" style="--ftvs-autoplay: <?php echo esc_attr( max( 1, $root['autoplay'] ) ); ?>s">
			<?php
			if ( $atts ) {
				self::header( $atts );
			}
			if ( 'showcase' === $layout ) {
				self::showcase( $items, $ctx );
			} elseif ( 'coverflow' === $layout ) {
				self::coverflow( $items, $ctx );
			} else {
				self::cards( $items, $ctx, $layout );
			}
			?>
		</div>
		<?php
	}

	/** Categories first, then videos, each with its Faith TV link and a short "3 episodes" / "6:35" line. */
	private static function items( $data, $category_id, $limit ) {
		$tv    = FTVS_Settings::get( 'tv_url' );
		$items = array();
		foreach ( $data['categories'] as $cat ) {
			$items[] = array(
				'kind' => 'category',
				'item' => $cat,
				'href' => $tv . '/program-group/' . $cat['id'],
				'meta' => $cat['videos'] > 0
					/* translators: %d: number of episodes */
					? sprintf( _n( '%d episode', '%d episodes', $cat['videos'], 'faith-tv-series' ), $cat['videos'] )
					/* translators: %d: number of series inside this category */
					: sprintf( _n( '%d series', '%d series', $cat['subcategories'], 'faith-tv-series' ), $cat['subcategories'] ),
			);
		}
		foreach ( $data['videos'] as $video ) {
			$items[] = array(
				'kind' => 'video',
				'item' => $video,
				'href' => $tv . '/program-group/' . ( $video['parent'] ? $video['parent'] : $category_id ) . '/program/' . $video['id'],
				'meta' => self::duration( $video['length'] ),
			);
		}
		return $limit > 0 ? array_slice( $items, 0, $limit ) : $items;
	}

	private static function header( $atts ) {
		$eyebrow = trim( (string) $atts['eyebrow'] );
		$title   = trim( (string) $atts['title'] );
		if ( '' === $eyebrow && '' === $title ) {
			return;
		}
		?>
		<div class="ftvs__head">
			<?php if ( '' !== $eyebrow ) : ?>
				<p class="ftvs-kicker"><?php echo esc_html( $eyebrow ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $title ) : ?>
				<h2 class="ftvs__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Big featured series with the artwork framed on the right, and a strip of the others below. */
	private static function showcase( $items, $ctx ) {
		$first = $items[0];
		?>
		<div class="ftvs-show">
			<div class="ftvs-show__backdrop" aria-hidden="true">
				<img class="is-on" src="<?php echo esc_url( self::art( $first ) ); ?>" alt="" data-ftvs-backdrop<?php echo $ctx['lazy'] ? ' loading="lazy"' : ''; ?>>
				<img alt="" data-ftvs-backdrop>
			</div>
			<div class="ftvs-show__stage">
				<div class="ftvs-show__info" data-ftvs-info>
					<?php self::info( $first, $ctx, true ); ?>
					<div class="ftvs-show__actions">
						<?php self::watch_button( $first, $ctx ); ?>
						<a class="ftvs-btn ftvs-btn--light" href="<?php echo esc_url( $ctx['more'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More on Faith TV', 'faith-tv-series' ); ?></a>
					</div>
				</div>
				<a class="ftvs-show__art" href="<?php echo esc_url( $first['href'] ); ?>"<?php echo self::target( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-ftvs-watch aria-label="<?php echo esc_attr( self::watch_label( $first ) ); ?>">
					<img src="<?php echo esc_url( self::art( $first ) ); ?>" alt="" data-ftvs-art<?php echo $ctx['lazy'] ? ' loading="lazy"' : ''; ?>>
					<span class="ftvs__play ftvs__play--big" aria-hidden="true"><?php echo self::play_icon( 34 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</a>
			</div>
			<?php if ( count( $items ) > 1 ) : ?>
				<div class="ftvs__viewport ftvs-show__strip">
					<?php self::arrow( 'prev' ); ?>
					<ul class="ftvs__track" role="list" data-ftvs-track>
						<?php
						foreach ( $items as $i => $entry ) {
							self::card( $entry, $ctx, $i, true );
						}
						?>
					</ul>
					<?php self::arrow( 'next' ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** 3D carousel like the one on the church home page, with the details under the middle card. */
	private static function coverflow( $items, $ctx ) {
		$first = $items[0];
		$count = count( $items );
		?>
		<div class="ftvs-cf">
			<div class="ftvs-cf__stage" data-ftvs-stage>
				<ul class="ftvs-cf__track" role="list" data-ftvs-track>
					<?php foreach ( $items as $i => $entry ) : ?>
						<li class="ftvs-cf__slide" data-pos="<?php echo esc_attr( self::cf_pos( $i, $count ) ); ?>">
							<a class="ftvs__card" <?php echo self::card_attrs( $entry, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo 0 === $i ? ' aria-current="true"' : ' tabindex="-1"'; ?>>
								<span class="ftvs__thumb">
									<img src="<?php echo esc_url( self::art( $entry ) ); ?>" alt="<?php echo esc_attr( $entry['item']['title'] ); ?>" loading="<?php echo ( ! $ctx['lazy'] && ( $i < 3 || $i > $count - 3 ) ) ? 'eager' : 'lazy'; ?>" decoding="async">
									<span class="ftvs__play ftvs__play--big" aria-hidden="true"><?php echo self::play_icon( 34 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				if ( $count > 1 ) {
					self::arrow( 'prev', false );
					self::arrow( 'next', false );
				}
				?>
			</div>
			<div class="ftvs-cf__caption" data-ftvs-info>
				<?php self::info( $first, $ctx, true ); ?>
				<div class="ftvs-show__actions">
					<?php self::watch_button( $first, $ctx ); ?>
					<?php if ( $count > 1 ) : ?>
						<span class="ftvs-cf__count" data-ftvs-counter><?php echo esc_html( sprintf( '%02d / %02d', 1, $count ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $count > 1 ) : ?>
					<span class="ftvs-cf__bar" aria-hidden="true"><span data-ftvs-timer></span></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Plain sliding row or grid. */
	private static function cards( $items, $ctx, $layout ) {
		?>
		<div class="ftvs__viewport">
			<?php
			if ( 'row' === $layout ) {
				self::arrow( 'prev' );
			}
			?>
			<ul class="ftvs__track" role="list" data-ftvs-track>
				<?php
				foreach ( $items as $i => $entry ) {
					self::card( $entry, $ctx, $i, false );
				}
				?>
			</ul>
			<?php
			if ( 'row' === $layout ) {
				self::arrow( 'next' );
			}
			?>
		</div>
		<?php
	}

	private static function card( $entry, $ctx, $index, $in_strip ) {
		$item = $entry['item'];
		?>
		<li class="ftvs__item">
			<a class="ftvs__card" <?php echo self::card_attrs( $entry, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo ( $in_strip && 0 === $index ) ? ' aria-current="true"' : ''; ?>>
				<span class="ftvs__thumb">
					<?php if ( $item['image'] ) : ?>
						<img src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy" decoding="async">
					<?php endif; ?>
					<?php if ( 0 === $index && '' !== $ctx['badge'] ) : ?>
						<span class="ftvs-badge ftvs-badge--corner"><?php echo esc_html( $ctx['badge'] ); ?></span>
					<?php endif; ?>
					<span class="ftvs__play" aria-hidden="true"><?php echo self::play_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<?php if ( $in_strip ) : ?>
						<span class="ftvs__bar" aria-hidden="true"><span data-ftvs-timer></span></span>
					<?php endif; ?>
				</span>
				<span class="ftvs__name"><?php echo esc_html( $item['title'] ); ?></span>
				<?php if ( '' !== $entry['meta'] ) : ?>
					<span class="ftvs__meta"><?php echo esc_html( $entry['meta'] ); ?></span>
				<?php endif; ?>
				<?php if ( $ctx['descs'] && '' !== $item['description'] ) : ?>
					<span class="ftvs__desc"><?php echo esc_html( wp_trim_words( $item['description'], 24 ) ); ?></span>
				<?php endif; ?>
			</a>
		</li>
		<?php
	}

	/** Label, title, count and description of the featured item. The script swaps these as it rotates. */
	private static function info( $entry, $ctx, $is_first ) {
		$item = $entry['item'];
		?>
		<p class="ftvs-kicker">
			<?php if ( '' !== $ctx['badge'] ) : ?>
				<span class="ftvs-badge" data-ftvs-badge<?php echo $is_first ? '' : ' hidden'; ?>><?php echo esc_html( $ctx['badge'] ); ?></span>
			<?php endif; ?>
			<span><?php echo esc_html( $ctx['label'] ); ?></span>
		</p>
		<h3 class="ftvs-feature__title" data-ftvs-title><?php echo esc_html( $item['title'] ); ?></h3>
		<p class="ftvs-feature__meta" data-ftvs-meta><?php echo esc_html( $entry['meta'] ); ?></p>
		<p class="ftvs-feature__desc" data-ftvs-desc><?php echo esc_html( $item['description'] ); ?></p>
		<?php
	}

	private static function watch_button( $entry, $ctx ) {
		?>
		<a class="ftvs-btn ftvs-btn--primary" href="<?php echo esc_url( $entry['href'] ); ?>"<?php echo self::target( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-ftvs-watch aria-label="<?php echo esc_attr( self::watch_label( $entry ) ); ?>">
			<?php echo self::play_icon( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php echo 'video' === $entry['kind'] ? esc_html__( 'Watch now', 'faith-tv-series' ) : esc_html__( 'Watch the series', 'faith-tv-series' ); ?></span>
		</a>
		<?php
	}

	private static function card_attrs( $entry, $ctx ) {
		return 'href="' . esc_url( $entry['href'] ) . '"' . self::target( $ctx )
			. ' data-kind="' . esc_attr( $entry['kind'] ) . '"'
			. ' data-meta="' . esc_attr( $entry['meta'] ) . '"'
			. ' data-item="' . esc_attr( wp_json_encode( $entry['item'] ) ) . '"';
	}

	private static function target( $ctx ) {
		return 'faithtv' === $ctx['play'] ? ' target="_blank" rel="noopener"' : '';
	}

	private static function watch_label( $entry ) {
		/* translators: %s: series or video title */
		return sprintf( __( 'Watch %s', 'faith-tv-series' ), $entry['item']['title'] );
	}

	/** The big picture: a video's full-size frame, or the series artwork. */
	private static function art( $entry ) {
		$item = $entry['item'];
		return ! empty( $item['poster'] ) ? $item['poster'] : $item['image'];
	}

	/** Starting position of each coverflow card (the script takes over after load). */
	private static function cf_pos( $i, $count ) {
		$offset = $i;
		if ( $offset > $count / 2 ) {
			$offset -= $count;
		}
		return abs( $offset ) <= 2 ? (string) $offset : ( $offset < 0 ? 'far-left' : 'far-right' );
	}

	private static function arrow( $direction, $hidden = true ) {
		$prev = 'prev' === $direction;
		?>
		<button type="button" class="ftvs__arrow ftvs__arrow--<?php echo esc_attr( $direction ); ?>" data-ftvs-<?php echo esc_attr( $direction ); ?> aria-label="<?php echo $prev ? esc_attr__( 'Previous', 'faith-tv-series' ) : esc_attr__( 'Next', 'faith-tv-series' ); ?>"<?php echo $hidden ? ' hidden' : ''; ?>><?php echo self::chevron( $prev ? 'left' : 'right' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		<?php
	}

	private static function enqueue() {
		wp_enqueue_style( 'faith-tv-series' );
		wp_enqueue_script( 'faith-tv-series' );
	}

	private static function script_config() {
		return array(
			'rest'    => esc_url_raw( rest_url( 'faith-tv/v1/' ) ),
			'hls'     => FTVS_URL . 'assets/vendor/hls.light.min.js?ver=1.7.3',
			'tv'      => FTVS_Settings::get( 'tv_url' ),
			'strings' => array(
				'close'     => __( 'Close', 'faith-tv-series' ),
				'back'      => __( 'Back', 'faith-tv-series' ),
				'episodes'  => __( 'Episodes', 'faith-tv-series' ),
				'loading'   => __( 'Loading...', 'faith-tv-series' ),
				'failed'    => __( 'This video could not be loaded right now.', 'faith-tv-series' ),
				'watchOnTv' => __( 'Watch on Faith TV', 'faith-tv-series' ),
				'nowPlay'   => __( 'Now playing', 'faith-tv-series' ),
				'play'      => __( 'Play', 'faith-tv-series' ),
				/* translators: %d: episode number */
				'episodeN'  => __( 'Episode %d', 'faith-tv-series' ),
				/* translators: %s: series or video title */
				'watchX'    => __( 'Watch %s', 'faith-tv-series' ),
			),
		);
	}

	/** Visitors never see errors; people who can edit the page get a clear note. */
	private static function problem( WP_Error $error ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<!-- Faith TV Series: ' . esc_html( $error->get_error_message() ) . ' -->';
		}
		return '<div class="ftvs-notice" style="padding:12px 16px;border-left:4px solid #c40d3c;background:#fff;color:#222;font:14px/1.4 sans-serif">'
			. '<strong>' . esc_html__( 'Faith TV Series:', 'faith-tv-series' ) . '</strong> '
			. esc_html( $error->get_error_message() )
			. ' <em>' . esc_html__( '(Only people who can edit this page see this note.)', 'faith-tv-series' ) . '</em></div>';
	}

	public static function duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return '';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	private static function chevron( $direction ) {
		$path = 'left' === $direction ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
		return '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	private static function play_icon( $size = 22 ) {
		$size = (int) $size;
		return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
	}
}
