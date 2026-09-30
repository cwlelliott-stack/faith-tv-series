<?php
/**
 * The shortcodes. The Elementor widgets and blocks render through here too.
 *
 *   [faith_tv_series category="..."]   a row, series or automatic pick, in any layout
 *   [faith_tv_series video="..."]      one video
 *   [faith_tv_live]                    Sunday live: countdown, live stream, replay
 *   [faith_tv_library]                 the searchable sermon library
 *   [faithstream ...]                  the shortcode Faith Stream's own Embeds page hands out
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Renderer {

	const LAYOUTS = array( 'showcase', 'coverflow', 'list', 'row', 'grid', 'library' );
	// A 1x1 transparent picture: what the hidden (other device's) layout downloads instead of its hero.
	const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	public static function register() {
		wp_register_style( 'faith-tv-series', FTVS_URL . 'assets/faith-tv-series.css', array(), FTVS_VERSION );
		// Right-to-left languages get a mirrored copy (made with rtlcss; see README).
		wp_style_add_data( 'faith-tv-series', 'rtl', 'replace' );
		wp_register_script( 'faith-tv-series', FTVS_URL . 'assets/faith-tv-series.js', array(), FTVS_VERSION, true );
		// Attached at registration so it prints whenever the script does, even when
		// a page builder serves the widget HTML without calling render().
		wp_add_inline_script( 'faith-tv-series', 'window.FTVS_CONFIG = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
		wp_add_inline_style( 'faith-tv-series', self::site_css() );
		add_shortcode( 'faith_tv_series', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'faithstream', array( __CLASS__, 'faithstream' ) );
		add_shortcode( 'faith_tv_live', array( __CLASS__, 'live_shortcode' ) );
		add_shortcode( 'faith_tv_library', array( __CLASS__, 'library_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early_enqueue' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 10, 2 );
	}

	/** The site's color, text sizes and font as CSS variables. */
	public static function site_css() {
		$css    = '';
		$accent = sanitize_hex_color( (string) FTVS_Settings::get( 'accent' ) );
		if ( $accent ) {
			$css .= '.ftvs,.ftvs-dialog,.ftvs-livebar{--ftvs-accent:' . $accent . ';--ftvs-on-accent:' . self::on_color( $accent ) . '}';
		}
		$text = FTVS_Settings::text_css_vars( (array) FTVS_Settings::get( 'text' ) );
		if ( '' !== $text ) {
			$css .= '.ftvs{' . $text . '}';
		}
		if ( 'inherit' === FTVS_Settings::get( 'font' ) ) {
			$css .= '.ftvs,.ftvs-dialog,.ftvs-livebar{--ftvs-font:inherit}';
		}
		return $css;
	}

	/** White or near-black text, whichever reads better on the church color (WCAG contrast). */
	public static function on_color( $hex ) {
		return self::contrast( $hex, '#FFFFFF' ) >= 4.5 || self::contrast( $hex, '#FFFFFF' ) >= self::contrast( $hex, '#111114' ) ? '#FFFFFF' : '#111114';
	}

	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	private static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$rgb = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		foreach ( $rgb as $i => $c ) {
			$c         = $c / 255;
			$rgb[ $i ] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	public static function style_class() {
		$style = FTVS_Settings::get( 'style' );
		return 'ftvs--style-' . ( in_array( $style, FTVS_Settings::STYLES, true ) ? $style : 'bold' );
	}

	/** null means "use the site default" from Faith Stream > Look & feel. */
	public static function defaults() {
		return array(
			'category'      => '',
			'video'         => '',
			'layout'        => null,
			'mobile_layout' => null,
			'limit'         => 0,
			'play'          => 'site',
			'eyebrow'       => '',
			'title'         => '',
			'label'         => null,
			'badge'         => null,
			'autoplay'      => 7,
			'descriptions'  => 'no',
			'theme'         => null,
			// Show between these dates (site time zone), otherwise show another category (or nothing).
			'from'          => '',
			'until'         => '',
			'otherwise'     => '',
			// Next-step buttons after watching: "off", or this section's own button.
			'next'          => '',
			'next_label'    => '',
			'next_url'      => '',
			// Text size / color per part, e.g. heading_size="48" heading_color="#ffffff".
			'heading_size'  => null,
			'heading_color' => null,
			'eyebrow_size'  => null,
			'eyebrow_color' => null,
			'series_size'   => null,
			'series_color'  => null,
			'card_size'     => null,
			'card_color'    => null,
			'text_size'     => null,
			'text_color'    => null,
			'meta_size'     => null,
			'meta_color'    => null,
		);
	}

	/** This section's own text sizes and colors as CSS custom properties ('' when none). */
	public static function text_vars( $atts ) {
		$raw = array();
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			$raw[ $part ] = array(
				'size'  => isset( $atts[ $part . '_size' ] ) ? $atts[ $part . '_size' ] : '',
				'color' => isset( $atts[ $part . '_color' ] ) ? $atts[ $part . '_color' ] : '',
			);
		}
		return FTVS_Settings::text_css_vars( FTVS_Settings::text_styles( $raw ) );
	}

	public static function shortcode( $atts ) {
		return self::render( shortcode_atts( self::defaults(), $atts, 'faith_tv_series' ) );
	}

	/**
	 * Faith Stream's Embeds page hands out [faithstream category="slug" layout="carousel"],
	 * [faithstream video="slug"], [faithstream live="slug"] and [faithstream library="all"].
	 */
	public static function faithstream( $atts ) {
		$atts   = is_array( $atts ) ? $atts : array();
		$layout = isset( $atts['layout'] ) ? strtolower( (string) $atts['layout'] ) : '';
		$map    = array(
			'carousel' => 'coverflow',
			'slider'   => 'coverflow',
			'grid'     => 'grid',
			'row'      => 'row',
			'list'     => 'list',
			'hero'     => 'showcase',
			'showcase' => 'showcase',
		);
		if ( ! empty( $atts['live'] ) ) {
			return self::live( array( 'channel' => (string) $atts['live'] ) );
		}
		$out = array_intersect_key( $atts, self::defaults() );
		if ( ! empty( $atts['library'] ) ) {
			return self::library( array( 'category' => 'all' === $atts['library'] ? '' : (string) $atts['library'] ) + $out );
		}
		$out['layout'] = isset( $map[ $layout ] ) ? $map[ $layout ] : null;
		return self::render( shortcode_atts( self::defaults(), $out, 'faithstream' ) );
	}

	/**
	 * @param array $atts See defaults().
	 * @return string HTML.
	 */
	public static function render( $atts ) {
		$atts = wp_parse_args( $atts, self::defaults() );
		if ( FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' ) ) {
			return '<!-- Faith TV Series: sample videos are shown only to editors -->';
		}
		$atts = self::scheduled( $atts );
		if ( null === $atts ) {
			return '<!-- Faith TV Series: this section is scheduled for other dates -->';
		}
		$layout = in_array( $atts['layout'], self::LAYOUTS, true ) ? $atts['layout'] : FTVS_Settings::get( 'layout' );
		$layout = in_array( $layout, self::LAYOUTS, true ) ? $layout : 'showcase';
		if ( 'library' === $layout ) {
			return self::library( $atts );
		}
		if ( '' !== trim( (string) $atts['video'] ) ) {
			return self::one_video( $atts );
		}
		$play     = 'faithtv' === $atts['play'] ? 'faithtv' : 'site';
		$theme    = self::theme( $atts );
		$autoplay = min( 60, absint( $atts['autoplay'] ) );
		$descs    = in_array( strtolower( (string) $atts['descriptions'] ), array( 'yes', '1', 'true', 'on' ), true );

		$found = FTVS_Catalog::find_category( $atts['category'] );
		if ( is_wp_error( $found ) ) {
			return self::problem( $found );
		}
		$data = FTVS_Catalog::get_children( $found['id'] );
		if ( is_wp_error( $data ) ) {
			return self::problem( $data );
		}
		$items = self::items( $data, $found['id'], absint( $atts['limit'] ) );
		if ( ! $items ) {
			return self::problem( new WP_Error( 'ftvs_empty', __( 'This category has nothing published yet.', 'faith-tv-series' ) ) );
		}

		self::enqueue();

		// Label: this section's own, else the site default, else the category's name, else the church's.
		$label = trim( (string) $atts['label'] );
		if ( '' === $label ) {
			$label = trim( (string) FTVS_Settings::get( 'label' ) );
		}
		if ( '' === $label ) {
			$label = '' !== $found['title'] ? $found['title'] : FTVS_Catalog::title_of( $found['id'] );
		}
		if ( '' === $label ) {
			$label = (string) FTVS_Settings::get( 'church_name' );
		}
		$ctx = array(
			'play'  => $play,
			'label' => $label,
			'badge' => null === $atts['badge'] ? trim( (string) FTVS_Settings::get( 'badge' ) ) : trim( (string) $atts['badge'] ),
			'descs' => $descs,
			'more'  => FTVS_Catalog::category_link( $found['id'] ),
			'kinds' => $data['categories'] ? 'category' : 'video',
		);

		$mobile_choice = null === $atts['mobile_layout'] || '' === $atts['mobile_layout'] || 'default' === $atts['mobile_layout'] ? FTVS_Settings::get( 'mobile_layout' ) : $atts['mobile_layout'];
		$mobile        = self::mobile_layout( $mobile_choice, $layout );
		$split         = $mobile !== $layout;
		// Both versions are in the page when they differ; each one's big picture only downloads on its own screen size.
		$ctx['split'] = $split;
		$root         = array(
			'theme'    => $theme,
			'play'     => $play,
			'category' => $found['id'],
			'label'    => $label,
			'autoplay' => $autoplay,
			'next'     => self::section_next( $atts ),
			// On each root too: the site-wide ".ftvs" rule would otherwise win over the wrapper's values.
			'vars'     => self::text_vars( $atts ),
		);

		ob_start();
		self::demo_note();
		if ( $split ) {
			echo '<div class="' . esc_attr( 'ftvs ftvs--' . $theme . ' ' . self::style_class() . ' ftvs-switch' ) . '"' . ( '' !== $root['vars'] ? ' style="' . esc_attr( $root['vars'] ) . '"' : '' ) . '>';
			self::header( $atts );
			self::root( $layout, 'ftvs-only-desktop', $items, array_merge( $ctx, array( 'device' => 'desktop' ) ), $root );
			self::root( $mobile, 'ftvs-only-mobile', $items, array_merge( $ctx, array( 'device' => 'mobile' ) ), $root );
			echo '</div>';
		} else {
			self::root( $layout, '', $items, array_merge( $ctx, array( 'device' => '' ) ), $root, $atts );
		}
		return trim( ob_get_clean() );
	}

	private static function theme( $atts ) {
		$theme = null === $atts['theme'] || '' === $atts['theme'] ? FTVS_Settings::get( 'theme' ) : $atts['theme'];
		return 'light' === $theme ? 'light' : 'dark';
	}

	/**
	 * Dated sections: from/until in the site's time zone. Outside the dates the section shows
	 * "otherwise" (another category), or nothing. Editors can preview a date with ?ftvs_asof=2026-04-05.
	 *
	 * @return array|null Attributes to render, or null for nothing.
	 */
	private static function scheduled( $atts ) {
		$from  = trim( (string) $atts['from'] );
		$until = trim( (string) $atts['until'] );
		if ( '' === $from && '' === $until ) {
			return $atts;
		}
		$now = time();
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['ftvs_asof'] ) && current_user_can( 'edit_posts' ) ) {
			$asof = self::local_time( sanitize_text_field( wp_unslash( $_GET['ftvs_asof'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$now  = $asof ? $asof : $now;
		}
		$start = '' !== $from ? self::local_time( $from ) : 0;
		$end   = '' !== $until ? self::local_time( $until ) : 0;
		if ( ( ! $start || $now >= $start ) && ( ! $end || $now < $end ) ) {
			return $atts;
		}
		$other = trim( (string) $atts['otherwise'] );
		if ( '' === $other ) {
			return null;
		}
		$atts['category'] = $other;
		$atts['video']    = '';
		$atts['from']     = '';
		$atts['until']    = '';
		return $atts;
	}

	/** "2026-04-05" or "2026-04-05 18:00" in the site's time zone, as a Unix time. */
	public static function local_time( $text ) {
		try {
			$d = new DateTimeImmutable( $text, wp_timezone() );
			return $d->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Which layout phones get. "auto" picks the one that works best on a small screen.
	 */
	private static function mobile_layout( $choice, $desktop ) {
		if ( in_array( $choice, self::LAYOUTS, true ) && 'library' !== $choice ) {
			return $choice;
		}
		if ( 'same' === $choice ) {
			return $desktop;
		}
		$auto = array(
			'showcase'  => 'list',
			'coverflow' => 'list',
			'list'      => 'list',
			'row'       => 'row',
			'grid'      => 'list',
		);
		return isset( $auto[ $desktop ] ) ? $auto[ $desktop ] : 'list';
	}

	/** This section's next-step override as JSON for the script ('' = the site's). */
	private static function section_next( $atts ) {
		if ( 'off' === strtolower( (string) $atts['next'] ) ) {
			return 'off';
		}
		$label = trim( (string) $atts['next_label'] );
		$url   = esc_url_raw( trim( (string) $atts['next_url'] ) );
		return '' !== $label && '' !== $url ? wp_json_encode( array( array( 'label' => $label, 'url' => $url ) ) ) : '';
	}

	/** One section root. $atts is passed only when the heading belongs inside it. */
	private static function root( $layout, $extra_class, $items, $ctx, $root, $atts = null ) {
		$classes = 'ftvs ftvs--' . $root['theme'] . ' ' . self::style_class() . ' ftvs--' . $layout . ( $root['autoplay'] ? '' : ' ftvs--no-autoplay' ) . ( $extra_class ? ' ' . $extra_class : '' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-ftvs data-layout="<?php echo esc_attr( $layout ); ?>" data-play="<?php echo esc_attr( $root['play'] ); ?>" data-category="<?php echo esc_attr( $root['category'] ); ?>" data-label="<?php echo esc_attr( $root['label'] ); ?>" data-autoplay="<?php echo esc_attr( $root['autoplay'] ); ?>"<?php echo '' !== $root['next'] ? ' data-next="' . esc_attr( $root['next'] ) . '"' : ''; ?> style="<?php echo esc_attr( '--ftvs-autoplay:' . max( 1, $root['autoplay'] ) . 's;' . $root['vars'] ); ?>">
			<?php
			if ( $atts ) {
				self::header( $atts );
			}
			if ( 'showcase' === $layout ) {
				self::showcase( $items, $ctx );
			} elseif ( 'coverflow' === $layout ) {
				self::coverflow( $items, $ctx );
			} elseif ( 'list' === $layout ) {
				self::featured_list( $items, $ctx );
			} else {
				self::cards( $items, $ctx, $layout );
			}
			?>
		</div>
		<?php
	}

	/** Categories first, then videos, each with its channel link and a short "3 episodes" / "6:35" line. */
	private static function items( $data, $category_id, $limit ) {
		$items = array();
		foreach ( $data['categories'] as $cat ) {
			$meta = '';
			if ( $cat['videos'] > 0 ) {
				/* translators: %d: number of episodes */
				$meta = sprintf( _n( '%d episode', '%d episodes', $cat['videos'], 'faith-tv-series' ), $cat['videos'] );
			} elseif ( $cat['subcategories'] > 0 ) {
				/* translators: %d: number of series inside this category */
				$meta = sprintf( _n( '%d series', '%d series', $cat['subcategories'], 'faith-tv-series' ), $cat['subcategories'] );
			}
			$cat['link'] = FTVS_Catalog::category_link( $cat['id'] );
			$items[]     = array(
				'kind' => 'category',
				'item' => $cat,
				'href' => $cat['link'],
				'meta' => $meta,
			);
		}
		foreach ( $data['videos'] as $video ) {
			$video['link']  = FTVS_Catalog::video_link( $video['id'], $video['parent'] ? $video['parent'] : $category_id );
			$video['watch'] = FTVS_Watch::url( $video['id'] );
			$items[]        = array(
				'kind' => 'video',
				'item' => $video,
				'href' => '' !== $video['watch'] ? $video['watch'] : $video['link'],
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

	/**
	 * The big picture a visitor sees first: loads right away and ahead of everything else. When
	 * the page carries both a computer and a phone layout, the other one gets a blank instead.
	 */
	private static function hero_img( $src, $ctx, $attrs = '', $alt = '' ) {
		$img = '<img src="' . esc_url( $src ) . '"' . self::srcset( $src, '(max-width: 767px) 100vw, 60vw' ) . ' alt="' . esc_attr( $alt ) . '" fetchpriority="high" decoding="async"' . $attrs . '>';
		if ( empty( $ctx['split'] ) ) {
			return $img;
		}
		$media = 'desktop' === $ctx['device'] ? '(max-width: 767px)' : '(min-width: 768px)';
		return '<picture><source media="' . esc_attr( $media ) . '" srcset="' . self::BLANK . '">' . $img . '</picture>';
	}

	/** Big featured series with the artwork framed on the right, and a strip of the others below. */
	private static function showcase( $items, $ctx ) {
		$first = $items[0];
		?>
		<div class="ftvs-show">
			<div class="ftvs-show__backdrop" aria-hidden="true">
				<img class="is-on" src="<?php echo esc_url( self::art( $first ) ); ?>" alt="" data-ftvs-backdrop<?php echo $ctx['split'] ? ' loading="lazy"' : ''; ?> decoding="async">
				<img alt="" data-ftvs-backdrop>
			</div>
			<div class="ftvs-show__stage">
				<div class="ftvs-show__info" data-ftvs-info>
					<?php self::info( $first, $ctx, true ); ?>
					<div class="ftvs-show__actions">
						<?php self::watch_button( $first, $ctx ); ?>
						<?php if ( '' !== $ctx['more'] ) : ?>
							<a class="ftvs-btn ftvs-btn--light" href="<?php echo esc_url( $ctx['more'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More videos', 'faith-tv-series' ); ?></a>
						<?php endif; ?>
						<?php self::pause_button( count( $items ) ); ?>
					</div>
				</div>
				<a class="ftvs-show__art" <?php echo self::card_attrs( $first, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-ftvs-watch aria-label="<?php echo esc_attr( self::watch_label( $first ) ); ?>">
					<?php echo self::hero_img( self::art( $first ), $ctx, ' data-ftvs-art' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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

	/** Stops and starts the rotation (touch and keyboard users can't "hover" to pause). */
	private static function pause_button( $count ) {
		if ( $count < 2 ) {
			return;
		}
		?>
		<button type="button" class="ftvs-pause" data-ftvs-pause aria-pressed="false" aria-label="<?php esc_attr_e( 'Pause the slideshow', 'faith-tv-series' ); ?>" data-label-play="<?php esc_attr_e( 'Play the slideshow', 'faith-tv-series' ); ?>" data-label-pause="<?php esc_attr_e( 'Pause the slideshow', 'faith-tv-series' ); ?>">
			<svg class="ftvs-pause__off" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor"/></svg>
			<svg class="ftvs-pause__on" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>
		</button>
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
									<?php if ( 0 === $i ) : ?>
										<?php echo self::hero_img( self::art( $entry ), $ctx, '', $entry['item']['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php else : ?>
										<img src="<?php echo esc_url( self::art( $entry ) ); ?>"<?php echo self::srcset( self::art( $entry ), '(max-width: 600px) 72vw, 640px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> alt="<?php echo esc_attr( $entry['item']['title'] ); ?>" loading="<?php echo ( ! $ctx['split'] && ( $i < 3 || $i > $count - 3 ) ) ? 'eager' : 'lazy'; ?>" decoding="async">
									<?php endif; ?>
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
					<?php self::pause_button( $count ); ?>
				</div>
				<?php if ( $count > 1 ) : ?>
					<span class="ftvs-cf__bar" aria-hidden="true"><span data-ftvs-timer></span></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Phones (and anywhere calm is better): the newest one big, the rest as a simple list. */
	private static function featured_list( $items, $ctx ) {
		$first = $items[0];
		$rest  = array_slice( $items, 1 );
		$attrs = self::card_attrs( $first, $ctx );
		?>
		<div class="ftvs-list">
			<a class="ftvs__card ftvs-list__art" <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php echo esc_attr( self::watch_label( $first ) ); ?>">
				<span class="ftvs__thumb">
					<?php echo self::hero_img( self::art( $first ), $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="ftvs__play ftvs__play--mid" aria-hidden="true"><?php echo self::play_icon( 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</span>
			</a>
			<p class="ftvs-list__meta">
				<?php if ( '' !== $ctx['badge'] ) : ?>
					<span class="ftvs-badge"><?php echo esc_html( $ctx['badge'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $first['meta'] ) : ?>
					<span class="ftvs__meta"><?php echo esc_html( $first['meta'] ); ?></span>
				<?php endif; ?>
			</p>
			<h3 class="ftvs-feature__title ftvs-list__title"><?php echo esc_html( $first['item']['title'] ); ?></h3>
			<?php if ( '' !== $first['item']['description'] ) : ?>
				<p class="ftvs-feature__desc ftvs-list__desc"><?php echo esc_html( $first['item']['description'] ); ?></p>
			<?php endif; ?>
			<a class="ftvs-btn ftvs-btn--primary ftvs-list__watch" data-ftvs-open <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php echo self::play_icon( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span><?php echo 'video' === $first['kind'] ? esc_html__( 'Watch now', 'faith-tv-series' ) : esc_html__( 'Watch the series', 'faith-tv-series' ); ?></span>
			</a>
			<?php if ( $rest ) : ?>
				<p class="ftvs-list__label"><span><?php echo 'video' === $ctx['kinds'] ? esc_html__( 'More episodes', 'faith-tv-series' ) : esc_html__( 'More series', 'faith-tv-series' ); ?></span></p>
				<ul class="ftvs-list__rows" role="list">
					<?php foreach ( $rest as $i => $entry ) : ?>
						<li class="<?php echo $i >= 5 ? 'is-extra' : ''; ?>">
							<a class="ftvs__card ftvs-list__row" <?php echo self::card_attrs( $entry, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
								<span class="ftvs__thumb"><img src="<?php echo esc_url( $entry['item']['image'] ); ?>"<?php echo self::srcset( $entry['item']['image'], '140px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> alt="" loading="lazy" decoding="async"></span>
								<span class="ftvs-list__text">
									<span class="ftvs__name"><?php echo esc_html( $entry['item']['title'] ); ?></span>
									<?php if ( '' !== $entry['meta'] ) : ?>
										<span class="ftvs__meta"><?php echo esc_html( $entry['meta'] ); ?></span>
									<?php endif; ?>
								</span>
								<?php echo self::chevron( 'right' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $rest ) > 5 ) : ?>
					<button type="button" class="ftvs-btn ftvs-btn--ghost ftvs-list__more" data-ftvs-more>
						<?php
						/* translators: %d: how many there are in total */
						printf( esc_html__( 'Show all %d', 'faith-tv-series' ), count( $items ) );
						?>
					</button>
				<?php endif; ?>
			<?php endif; ?>
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
						<img src="<?php echo esc_url( $item['image'] ); ?>"<?php echo self::srcset( $item['image'], '(max-width: 600px) 72vw, 330px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> alt="" loading="lazy" decoding="async">
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
			<?php if ( '' !== $ctx['label'] ) : ?>
				<span class="ftvs-kicker__label"><?php echo esc_html( $ctx['label'] ); ?></span>
			<?php endif; ?>
		</p>
		<h3 class="ftvs-feature__title" data-ftvs-title><?php echo esc_html( $item['title'] ); ?></h3>
		<p class="ftvs-feature__meta" data-ftvs-meta><?php echo esc_html( $entry['meta'] ); ?></p>
		<p class="ftvs-feature__desc" data-ftvs-desc><?php echo esc_html( $item['description'] ); ?></p>
		<?php
	}

	private static function watch_button( $entry, $ctx ) {
		?>
		<a class="ftvs-btn ftvs-btn--primary" <?php echo self::card_attrs( $entry, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-ftvs-watch aria-label="<?php echo esc_attr( self::watch_label( $entry ) ); ?>">
			<?php echo self::play_icon( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php echo 'video' === $entry['kind'] ? esc_html__( 'Watch now', 'faith-tv-series' ) : esc_html__( 'Watch the series', 'faith-tv-series' ); ?></span>
		</a>
		<?php
	}

	/** Only what the page script uses, so each card's data stays small. */
	public static function item_json( $item ) {
		$keep = array( 'id', 'parent', 'title', 'description', 'image', 'poster', 'length', 'added', 'speaker', 'scripture', 'watch', 'link', 'series' );
		$out  = array();
		foreach ( $keep as $key ) {
			if ( isset( $item[ $key ] ) && '' !== $item[ $key ] && array() !== $item[ $key ] && ! is_array( $item[ $key ] ) ) {
				$out[ $key ] = $item[ $key ];
			}
		}
		$out['id'] = (string) $item['id'];
		return wp_json_encode( $out );
	}

	/** Link plus the item data the page script needs. Without a channel link, the card opens the player. */
	private static function card_attrs( $entry, $ctx ) {
		$href = '' !== $entry['href'] ? $entry['href'] : '#faith-tv-' . $entry['item']['id'];
		return 'href="' . esc_url( $href ) . '"' . ( '' !== $entry['href'] ? self::target( $ctx, $entry ) : '' )
			. ' data-kind="' . esc_attr( $entry['kind'] ) . '"'
			. ' data-meta="' . esc_attr( $entry['meta'] ) . '"'
			. ' data-item="' . esc_attr( self::item_json( $entry['item'] ) ) . '"';
	}

	private static function target( $ctx, $entry ) {
		// Our own message pages open in the same tab; the channel opens in a new one.
		if ( ! empty( $entry['item']['watch'] ) && $entry['href'] === $entry['item']['watch'] && 'faithtv' !== $ctx['play'] ) {
			return '';
		}
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

	/**
	 * srcset + sizes for a picture that can be asked for at any width (unsigned Mux frames), so
	 * phones download a small copy and sharp screens a big one. '' for any other picture.
	 *
	 * @param string $sizes The sizes attribute for where the picture sits.
	 */
	public static function srcset( $url, $sizes ) {
		if ( '' === (string) $url || false === strpos( $url, '://image.mux.com/' ) || false !== strpos( $url, 'token=' ) ) {
			return '';
		}
		$set = array();
		foreach ( array( 320, 480, 640, 960, 1280 ) as $w ) {
			$set[] = esc_url( add_query_arg( 'width', $w, remove_query_arg( 'width', $url ) ) ) . ' ' . $w . 'w';
		}
		return ' srcset="' . esc_attr( implode( ', ', $set ) ) . '" sizes="' . esc_attr( $sizes ) . '"';
	}

	/* ---------- One video ---------- */

	/** [faith_tv_series video="..."]: one message with a big play button, for blog posts and landing pages. */
	private static function one_video( $atts ) {
		$id = trim( (string) $atts['video'] );
		$id = 'gideo' === FTVS_Catalog::source() ? strtolower( $id ) : $id;
		if ( ! FTVS_Catalog::is_known( $id ) ) {
			FTVS_Catalog::library(); // teaches every video id
		}
		$video = FTVS_Catalog::find_video( $id );
		if ( ! $video && FTVS_Catalog::is_known( $id ) ) {
			$full  = FTVS_Catalog::get_video( $id );
			$video = is_wp_error( $full ) || empty( $full['title'] ) ? null : $full;
		}
		if ( ! $video ) {
			/* translators: %s: video id */
			return self::problem( new WP_Error( 'ftvs_not_found', sprintf( __( 'No video on your channel has the ID "%s".', 'faith-tv-series' ), $id ) ) );
		}
		self::enqueue();
		$video['watch'] = FTVS_Watch::url( $video['id'] );
		$video['link']  = FTVS_Catalog::video_link( $video['id'], $video['parent'] );
		$entry          = array(
			'kind' => 'video',
			'item' => $video,
			'href' => '' !== $video['watch'] ? $video['watch'] : $video['link'],
			'meta' => self::duration( $video['length'] ),
		);
		$ctx            = array( 'play' => 'faithtv' === $atts['play'] ? 'faithtv' : 'site', 'split' => false, 'device' => '' );
		$vars           = self::text_vars( $atts );
		$next           = self::section_next( $atts );
		ob_start();
		self::demo_note();
		?>
		<div class="<?php echo esc_attr( 'ftvs ftvs--' . self::theme( $atts ) . ' ' . self::style_class() . ' ftvs--player' ); ?>" data-ftvs data-layout="player" data-play="<?php echo esc_attr( $ctx['play'] ); ?>" data-category="<?php echo esc_attr( $video['parent'] ); ?>" data-label="<?php echo esc_attr( isset( $video['series'] ) && is_string( $video['series'] ) ? $video['series'] : '' ); ?>"<?php echo '' !== $next ? ' data-next="' . esc_attr( $next ) . '"' : ''; ?><?php echo '' !== $vars ? ' style="' . esc_attr( $vars ) . '"' : ''; ?>>
			<?php self::header( $atts ); ?>
			<a class="ftvs__card ftvs-player" <?php echo self::card_attrs( $entry, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php echo esc_attr( self::watch_label( $entry ) ); ?>">
				<span class="ftvs__thumb">
					<?php echo self::hero_img( self::art( $entry ), $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="ftvs__play ftvs__play--big" aria-hidden="true"><?php echo self::play_icon( 34 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</span>
				<span class="ftvs__name"><?php echo esc_html( $video['title'] ); ?></span>
				<span class="ftvs__meta"><?php echo esc_html( implode( '  ·  ', array_filter( array( $video['speaker'], self::date( $video['added'] ), $entry['meta'] ) ) ) ); ?></span>
			</a>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	/* ---------- Sunday live ---------- */

	public static function live_shortcode( $atts ) {
		return self::live( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * The Live block: countdown to the next service, the live stream while it's on, the replay after.
	 * The page script checks every 30 seconds (the page itself may be cached).
	 */
	public static function live( $atts ) {
		$atts = shortcode_atts(
			array(
				'channel'  => '',
				'title'    => '',
				'eyebrow'  => '',
				'theme'    => null,
				'replay'   => 'yes',
				'remind'   => 'yes',
			),
			$atts,
			'faith_tv_live'
		);
		if ( FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		if ( ! FTVS_Catalog::has_live() && '' === (string) FTVS_Settings::get( 'live_url' ) && ! FTVS_Settings::get( 'services' ) ) {
			return self::problem( new WP_Error( 'ftvs_no_live', __( 'Add your service times or live link under Faith Stream > Church > Sunday live.', 'faith-tv-series' ) ) );
		}
		self::enqueue();
		$state  = FTVS_Live::state( preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $atts['channel'] ) ) );
		$theme  = self::theme( $atts );
		$replay = $state['replay'] && 'no' !== $atts['replay'] ? $state['replay'] : null;
		$image  = 'live' === $state['status'] ? $state['image'] : ( $replay ? ( '' !== $replay['poster'] ? $replay['poster'] : $replay['image'] ) : $state['image'] );
		$remind = 'no' !== $atts['remind'] && FTVS_Settings::get( 'remind' ) && '' !== (string) FTVS_Settings::get( 'remind_webhook' );
		ob_start();
		?>
		<div class="<?php echo esc_attr( 'ftvs ftvs--' . $theme . ' ' . self::style_class() . ' ftvs--live is-' . $state['status'] ); ?>" data-ftvs data-layout="live" data-play="site" data-channel="<?php echo esc_attr( $atts['channel'] ); ?>" data-replay="<?php echo 'no' === $atts['replay'] ? '0' : '1'; ?>" data-state="<?php echo esc_attr( wp_json_encode( $state ) ); ?>">
			<?php self::header( $atts + array( 'title' => '', 'eyebrow' => '' ) ); ?>
			<div class="ftvs-live">
				<button type="button" class="ftvs-live__stage" data-ftvs-live-play aria-label="<?php echo esc_attr( 'live' === $state['status'] ? __( 'Watch live', 'faith-tv-series' ) : __( 'Watch the replay', 'faith-tv-series' ) ); ?>"<?php echo ( 'live' !== $state['status'] && ! $replay ) ? ' disabled' : ''; ?>>
					<?php if ( '' !== $image ) : ?>
						<img src="<?php echo esc_url( $image ); ?>" alt="" fetchpriority="high" decoding="async" data-ftvs-live-img>
					<?php else : ?>
						<img alt="" hidden data-ftvs-live-img>
					<?php endif; ?>
					<span class="ftvs-live__badge" data-ftvs-live-badge><?php echo 'live' === $state['status'] ? esc_html__( 'Live', 'faith-tv-series' ) : esc_html__( 'Replay', 'faith-tv-series' ); ?></span>
					<span class="ftvs__play ftvs__play--big" aria-hidden="true"><?php echo self::play_icon( 34 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</button>
				<div class="ftvs-live__info">
					<p class="ftvs-kicker"><span class="ftvs-kicker__label" data-ftvs-live-kicker><?php echo 'live' === $state['status'] ? esc_html__( 'Live now', 'faith-tv-series' ) : esc_html__( 'Join us online', 'faith-tv-series' ); ?></span></p>
					<h3 class="ftvs-feature__title" data-ftvs-live-title><?php echo esc_html( 'live' === $state['status'] || ! $replay ? $state['title'] : $replay['title'] ); ?></h3>
					<p class="ftvs-live__when" data-ftvs-live-when aria-live="polite">
						<?php
						if ( 'live' === $state['status'] ) {
							esc_html_e( 'The service is streaming now.', 'faith-tv-series' );
						} elseif ( '' !== $state['next_label'] ) {
							/* translators: %s: day and time, like "Sunday 10:30 AM" */
							printf( esc_html__( 'Next service: %s', 'faith-tv-series' ), esc_html( $state['next_label'] ) );
						}
						?>
					</p>
					<p class="ftvs-live__count" data-ftvs-countdown hidden></p>
					<div class="ftvs-show__actions">
						<button type="button" class="ftvs-btn ftvs-btn--primary" data-ftvs-live-play<?php echo ( 'live' !== $state['status'] && ! $replay ) ? ' hidden' : ''; ?>><?php echo self::play_icon( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span data-ftvs-live-cta><?php echo 'live' === $state['status'] ? esc_html__( 'Watch live', 'faith-tv-series' ) : esc_html__( 'Watch the replay', 'faith-tv-series' ); ?></span></button>
						<?php if ( $remind ) : ?>
							<button type="button" class="ftvs-btn ftvs-btn--ghost" data-ftvs-remind-open<?php echo 'live' === $state['status'] ? ' hidden' : ''; ?>><?php esc_html_e( 'Remind me', 'faith-tv-series' ); ?></button>
						<?php endif; ?>
						<a class="ftvs-link ftvs-live__chat" data-ftvs-live-chat href="<?php echo esc_url( '' !== $state['chat'] ? $state['chat'] : '#' ); ?>" target="_blank" rel="noopener"<?php echo '' === $state['chat'] ? ' hidden' : ''; ?>><?php esc_html_e( 'Open the chat', 'faith-tv-series' ); ?></a>
					</div>
					<?php if ( $remind ) : ?>
						<?php self::remind_form( 'live' ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	/** "Remind me": email or mobile number, with the texting consent the church's texting rules require. */
	public static function remind_form( $kind ) {
		?>
		<form class="ftvs-remind" data-ftvs-remind data-kind="<?php echo esc_attr( $kind ); ?>" hidden>
			<p class="ftvs-remind__lead"><?php echo 'live' === $kind ? esc_html__( 'We\'ll let you know when the service starts.', 'faith-tv-series' ) : esc_html__( 'We\'ll let you know when a new series starts.', 'faith-tv-series' ); ?></p>
			<label><span><?php esc_html_e( 'Email', 'faith-tv-series' ); ?></span><input type="email" name="email" autocomplete="email"></label>
			<label><span><?php esc_html_e( 'or mobile number', 'faith-tv-series' ); ?></span><input type="tel" name="phone" autocomplete="tel"></label>
			<label class="ftvs-remind__consent"><input type="checkbox" name="sms" value="1"> <span><?php echo esc_html( FTVS_Followup::consent_text() ); ?></span></label>
			<input type="text" name="hp" value="" tabindex="-1" autocomplete="off" class="ftvs-remind__hp" aria-hidden="true">
			<button type="submit" class="ftvs-btn ftvs-btn--primary"><?php esc_html_e( 'Remind me', 'faith-tv-series' ); ?></button>
			<p class="ftvs-remind__msg" role="status"></p>
		</form>
		<?php
	}

	/* ---------- Sermon library ---------- */

	public static function library_shortcode( $atts ) {
		return self::library( shortcode_atts( array_merge( self::defaults(), array( 'per' => 24, 'category' => '' ) ), $atts, 'faith_tv_library' ) );
	}

	/** "Find a message": search, filters by speaker, series and year, newest first, 24 at a time. */
	public static function library( $atts ) {
		$atts = wp_parse_args( $atts, array_merge( self::defaults(), array( 'per' => 24 ) ) );
		if ( FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$list = FTVS_Catalog::library();
		if ( is_wp_error( $list ) ) {
			return self::problem( $list );
		}
		$scope = trim( (string) $atts['category'] );
		if ( '' !== $scope && '@' !== $scope[0] ) {
			$found = FTVS_Catalog::find_category( $scope );
			if ( is_wp_error( $found ) ) {
				return self::problem( $found );
			}
			$scope = $found['id'];
			$in    = array( $scope => 1 );
			$tree  = FTVS_Catalog::get_tree();
			foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
				if ( $row['id'] === $scope ) {
					foreach ( $row['children'] as $child ) {
						$in[ $child['id'] ] = 1;
					}
				}
			}
			$list = array_values(
				array_filter(
					$list,
					function ( $v ) use ( $in ) {
						return isset( $in[ $v['parent'] ] );
					}
				)
			);
		} else {
			$scope = '';
		}
		self::enqueue();
		$per      = max( 6, min( 48, absint( $atts['per'] ) ) );
		$speakers = array();
		$years    = array();
		foreach ( $list as $v ) {
			if ( '' !== $v['speaker'] ) {
				$speakers[ $v['speaker'] ] = isset( $speakers[ $v['speaker'] ] ) ? $speakers[ $v['speaker'] ] + 1 : 1;
			}
			$y = (int) substr( (string) $v['added'], 0, 4 );
			if ( $y > 1990 ) {
				$years[ $y ] = 1;
			}
		}
		arsort( $speakers );
		krsort( $years );
		$theme = self::theme( $atts );
		ob_start();
		self::demo_note();
		?>
		<div class="<?php echo esc_attr( 'ftvs ftvs--' . $theme . ' ' . self::style_class() . ' ftvs--library' ); ?>" data-ftvs data-layout="library" data-play="site" data-category="<?php echo esc_attr( $scope ); ?>" data-per="<?php echo esc_attr( $per ); ?>" data-total="<?php echo esc_attr( count( $list ) ); ?>" data-label="">
			<?php self::header( $atts ); ?>
			<form class="ftvs-lib__search" role="search" data-ftvs-lib-form>
				<label class="screen-reader-text" for="ftvs-lib-q-<?php echo esc_attr( md5( $scope ) ); ?>"><?php esc_html_e( 'Find a message', 'faith-tv-series' ); ?></label>
				<input id="ftvs-lib-q-<?php echo esc_attr( md5( $scope ) ); ?>" type="search" name="q" placeholder="<?php esc_attr_e( 'Find a message: a topic, a speaker, a verse', 'faith-tv-series' ); ?>" autocomplete="off">
				<button type="submit" class="ftvs-btn ftvs-btn--primary"><?php esc_html_e( 'Search', 'faith-tv-series' ); ?></button>
			</form>
			<div class="ftvs-lib__filters" data-ftvs-lib-filters>
				<?php if ( count( $speakers ) > 1 ) : ?>
					<label><span><?php esc_html_e( 'Speaker', 'faith-tv-series' ); ?></span>
						<select name="speaker"><option value=""><?php esc_html_e( 'Everyone', 'faith-tv-series' ); ?></option>
							<?php foreach ( array_slice( $speakers, 0, 30, true ) as $name => $n ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( count( $years ) > 1 ) : ?>
					<label><span><?php esc_html_e( 'Year', 'faith-tv-series' ); ?></span>
						<select name="year"><option value=""><?php esc_html_e( 'Any year', 'faith-tv-series' ); ?></option>
							<?php foreach ( array_keys( $years ) as $y ) : ?>
								<option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</div>
			<p class="ftvs-lib__count" data-ftvs-lib-count aria-live="polite">
				<?php
				/* translators: %s: number of messages */
				printf( esc_html( _n( '%s message', '%s messages', count( $list ), 'faith-tv-series' ) ), esc_html( number_format_i18n( count( $list ) ) ) );
				?>
			</p>
			<ul class="ftvs-lib__list" role="list" data-ftvs-lib-list>
				<?php foreach ( array_slice( $list, 0, $per ) as $video ) : ?>
					<?php self::library_row( $video ); ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $list ) > $per ) : ?>
				<button type="button" class="ftvs-btn ftvs-btn--ghost ftvs-lib__more" data-ftvs-lib-more><?php esc_html_e( 'Load more', 'faith-tv-series' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	private static function library_row( $video ) {
		$video['watch'] = FTVS_Watch::url( $video['id'] );
		$video['link']  = FTVS_Catalog::video_link( $video['id'], $video['parent'] );
		$entry          = array(
			'kind' => 'video',
			'item' => $video,
			'href' => '' !== $video['watch'] ? $video['watch'] : $video['link'],
			'meta' => self::duration( $video['length'] ),
		);
		$meta           = array_filter( array( $video['speaker'], self::date( $video['added'] ), $video['scripture'] ) );
		?>
		<li>
			<a class="ftvs__card ftvs-lib__row" <?php echo self::card_attrs( $entry, array( 'play' => 'site' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<span class="ftvs__thumb"><?php if ( '' !== $video['image'] ) : ?><img src="<?php echo esc_url( $video['image'] ); ?>"<?php echo self::srcset( $video['image'], '(max-width: 600px) 132px, 200px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> alt="" loading="lazy" decoding="async"><?php endif; ?><?php if ( '' !== $entry['meta'] ) : ?><span class="ftvs-lib__len"><?php echo esc_html( $entry['meta'] ); ?></span><?php endif; ?></span>
				<span class="ftvs-lib__text">
					<?php if ( ! empty( $video['series'] ) && is_string( $video['series'] ) ) : ?>
						<span class="ftvs-lib__series"><?php echo esc_html( $video['series'] ); ?></span>
					<?php endif; ?>
					<span class="ftvs__name"><?php echo esc_html( $video['title'] ); ?></span>
					<?php if ( $meta ) : ?>
						<span class="ftvs__meta"><?php echo esc_html( implode( '  ·  ', $meta ) ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		</li>
		<?php
	}

	/* ---------- Next steps ---------- */

	/** The church's next-step buttons as HTML (message pages; the player builds its own). */
	public static function next_steps_html( $video ) {
		$steps  = (array) FTVS_Settings::get( 'next_steps' );
		$series = (array) FTVS_Settings::get( 'series_steps' );
		if ( ! empty( $video['parent'] ) && isset( $series[ $video['parent'] ] ) ) {
			array_unshift( $steps, $series[ $video['parent'] ] );
			$steps = array_slice( $steps, 0, 3 );
		}
		if ( ! $steps ) {
			return '';
		}
		$title = trim( (string) FTVS_Settings::get( 'next_title' ) );
		$out   = '<div class="ftvs-next"><p class="ftvs-next__title">' . esc_html( '' !== $title ? $title : __( 'Take a next step', 'faith-tv-series' ) ) . '</p><div class="ftvs-next__btns">';
		foreach ( $steps as $step ) {
			$out .= '<a class="ftvs-btn ftvs-btn--light" data-ftvs-next-step href="' . esc_url( self::tagged( $step['url'], $video ) ) . '">' . esc_html( $step['label'] ) . '</a>';
		}
		return $out . '</div></div>';
	}

	/** Adds where the visitor came from, so the church's forms and funnels can tell which message led there. */
	public static function tagged( $url, $video ) {
		return add_query_arg(
			array(
				'utm_source'   => 'faith-tv',
				'utm_medium'   => 'website',
				'utm_campaign' => rawurlencode( ! empty( $video['parent'] ) ? $video['parent'] : 'video' ),
				'utm_content'  => rawurlencode( $video['id'] ),
			),
			$url
		);
	}

	/* ---------- Assets ---------- */

	private static function enqueue() {
		wp_enqueue_style( 'faith-tv-series' );
		wp_enqueue_script( 'faith-tv-series' );
	}

	/** Does this page have a video section? (Then the stylesheet goes in the head, where it belongs.) */
	private static function page_has_section() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		foreach ( array( 'faith_tv_series', 'faithstream', 'faith_tv_live', 'faith_tv_library', 'faith_tv_watch' ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}
		if ( false !== strpos( $post->post_content, '<!-- wp:faith-tv/' ) ) {
			return true;
		}
		if ( FTVS_Watch::page_id() === (int) $post->ID ) {
			return true;
		}
		$el = get_post_meta( $post->ID, '_elementor_data', true );
		return is_string( $el ) && false !== strpos( $el, '"widgetType":"faith_tv_' );
	}

	public static function early_enqueue() {
		if ( self::page_has_section() ) {
			wp_enqueue_style( 'faith-tv-series' );
			$GLOBALS['ftvs_hints'] = true;
		}
	}

	/** Connect early to where pictures and video come from. */
	public static function resource_hints( $urls, $type ) {
		if ( 'preconnect' !== $type || empty( $GLOBALS['ftvs_hints'] ) ) {
			return $urls;
		}
		$hosts = array();
		switch ( FTVS_Catalog::source() ) {
			case 'faithstream':
				$hosts = array( FTVS_FaithStream_Client::base(), 'https://image.mux.com', 'https://stream.mux.com' );
				break;
			case 'gideo':
				$hosts = array( 'https://cdn.gideo.video' );
				break;
			case 'youtube':
				$hosts = array( 'https://i.ytimg.com' );
				break;
		}
		foreach ( $hosts as $host ) {
			if ( '' !== $host ) {
				$urls[] = array(
					'href'        => $host,
					'crossorigin' => 'anonymous',
				);
			}
		}
		return $urls;
	}

	private static function script_config() {
		$source = FTVS_Catalog::source();
		$report = null;
		if ( 'faithstream' === $source && FTVS_Settings::get( 'report_plays' ) ) {
			$report = array(
				'url'    => FTVS_FaithStream_Client::base() . '/api/analytics/events?tenant=' . rawurlencode( FTVS_FaithStream_Client::tenant() ),
				'tenant' => FTVS_FaithStream_Client::tenant(),
			);
		}
		$steps = array();
		foreach ( (array) FTVS_Settings::get( 'next_steps' ) as $step ) {
			$steps[] = array(
				'label' => $step['label'],
				'url'   => $step['url'],
			);
		}
		$next_title = trim( (string) FTVS_Settings::get( 'next_title' ) );
		$brand      = FTVS_Admin::brand();
		return array(
			'rest'    => esc_url_raw( rest_url( 'faith-tv/v1/' ) ),
			// Sample videos answer only editors, and the REST API knows who is signed in only with a nonce.
			'nonce'   => FTVS_Catalog::is_demo() && is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'hls'     => FTVS_URL . 'assets/vendor/hls.min.js?ver=1.7.3',
			'powered' => (bool) FTVS_Settings::get( 'powered_by' ) && '' !== $brand['name'],
			'brand'   => array(
				'name' => $brand['name'],
				'url'  => $brand['url'],
			),
			'church'  => (string) FTVS_Settings::get( 'church_name' ),
			'share'   => (bool) FTVS_Settings::get( 'share' ),
			'resume'  => (bool) FTVS_Settings::get( 'resume' ),
			'upnext'  => (bool) FTVS_Settings::get( 'upnext' ),
			'count'   => (bool) FTVS_Settings::get( 'count_plays' ),
			'report'  => $report,
			// Online check-in: viewers of the live service sign in with Faith Stream and are counted present.
			'checkin' => 'faithstream' === $source && FTVS_Settings::get( 'checkin' ) ? array(
				'api'    => FTVS_FaithStream_Client::base(),
				'tenant' => FTVS_FaithStream_Client::tenant(),
			) : null,
			'next'    => array(
				'title'  => '' !== $next_title ? $next_title : __( 'Take a next step', 'faith-tv-series' ),
				'steps'  => $steps,
				'series' => (object) FTVS_Settings::get( 'series_steps' ),
			),
			'remind'  => FTVS_Settings::get( 'remind' ) && '' !== (string) FTVS_Settings::get( 'remind_webhook' ),
			'consent' => FTVS_Settings::get( 'remind' ) ? FTVS_Followup::consent_text() : '',
			'strings' => array(
				'close'       => __( 'Close', 'faith-tv-series' ),
				'back'        => __( 'Back', 'faith-tv-series' ),
				'episodes'    => __( 'Episodes', 'faith-tv-series' ),
				'loading'     => __( 'Loading...', 'faith-tv-series' ),
				'failed'      => __( 'This video could not be loaded right now.', 'faith-tv-series' ),
				'retrying'    => __( 'Reconnecting...', 'faith-tv-series' ),
				/* translators: %s: the church's video website, e.g. tv.faithtabernacle.com */
				'watchOnTv'   => FTVS_Catalog::channel_host() ? sprintf( __( 'Watch on %s', 'faith-tv-series' ), FTVS_Catalog::channel_host() ) : __( 'Watch on our channel', 'faith-tv-series' ),
				'nowPlay'     => __( 'Now playing', 'faith-tv-series' ),
				'play'        => __( 'Play', 'faith-tv-series' ),
				/* translators: %d: episode number */
				'episodeN'    => __( 'Episode %d', 'faith-tv-series' ),
				/* translators: %s: series or video title */
				'watchX'      => __( 'Watch %s', 'faith-tv-series' ),
				'upNext'      => __( 'Up next', 'faith-tv-series' ),
				/* translators: %d: seconds */
				'startsIn'    => __( 'Starts in %d', 'faith-tv-series' ),
				'playNow'     => __( 'Play now', 'faith-tv-series' ),
				'cancel'      => __( 'Cancel', 'faith-tv-series' ),
				'moreLike'    => __( 'More like this', 'faith-tv-series' ),
				'watchAgain'  => __( 'Watch again', 'faith-tv-series' ),
				'share'       => __( 'Share', 'faith-tv-series' ),
				'copyLink'    => __( 'Copy link', 'faith-tv-series' ),
				'copied'      => __( 'Link copied', 'faith-tv-series' ),
				/* translators: %s: time like 12:40 */
				'startAt'     => __( 'Start at %s', 'faith-tv-series' ),
				/* translators: %s: time like 12:40 */
				'resumed'     => __( 'Picking up where you left off (%s)', 'faith-tv-series' ),
				'startOver'   => __( 'Start over', 'faith-tv-series' ),
				'watched'     => __( 'Watched', 'faith-tv-series' ),
				'live'        => __( 'Live', 'faith-tv-series' ),
				'liveNow'     => __( 'Live now', 'faith-tv-series' ),
				'watchLive'   => __( 'Watch live', 'faith-tv-series' ),
				'replay'      => __( 'Replay', 'faith-tv-series' ),
				'watchReplay' => __( 'Watch the replay', 'faith-tv-series' ),
				'joinOnline'  => __( 'Join us online', 'faith-tv-series' ),
				'streaming'   => __( 'The service is streaming now.', 'faith-tv-series' ),
				/* translators: %s: day and time */
				'nextService' => __( 'Next service: %s', 'faith-tv-series' ),
				/* translators: %s: countdown like 2d 4h 10m */
				'startsInT'   => __( 'Starts in %s', 'faith-tv-series' ),
				/* translators: %d: number of people */
				'watching'    => __( '%d watching', 'faith-tv-series' ),
				'liveBar'     => __( 'We\'re live', 'faith-tv-series' ),
				'watchNow'    => __( 'Watch now', 'faith-tv-series' ),
				'thanks'      => __( 'Thank you! We\'ll remind you.', 'faith-tv-series' ),
				'tryAgain'    => __( 'That did not go through. Please try again.', 'faith-tv-series' ),
				'noResults'   => __( 'No messages match. Try other words.', 'faith-tv-series' ),
				/* translators: %s: number */
				'nMessages'   => __( '%s messages', 'faith-tv-series' ),
				'oneMessage'  => __( '1 message', 'faith-tv-series' ),
				'poweredBy'   => __( 'Powered by', 'faith-tv-series' ),
				'openChat'    => __( 'Open the chat', 'faith-tv-series' ),
				'countMe'       => __( 'Count me present', 'faith-tv-series' ),
				'countTitle'    => __( 'Watching from home? Be counted in today\'s attendance.', 'faith-tv-series' ),
				'countHint'     => __( 'Sign in once. After a few minutes of the service, you are checked in automatically.', 'faith-tv-series' ),
				'emailOrName'   => __( 'Email or first name', 'faith-tv-series' ),
				'password'      => __( 'Password', 'faith-tv-series' ),
				'lastInitial'   => __( 'First letter of your last name', 'faith-tv-series' ),
				'churchAccount' => __( 'Your church account (the one you use for the church app)', 'faith-tv-series' ),
				'signIn'        => __( 'Sign in', 'faith-tv-series' ),
				'justEmail'     => __( 'Or just your email and name', 'faith-tv-series' ),
				'yourName'      => __( 'Your name', 'faith-tv-series' ),
				'useEmail'      => __( 'Use my email', 'faith-tv-series' ),
				/* translators: %s: the viewer's name */
				'signedInAs'    => __( 'Signed in as %s', 'faith-tv-series' ),
				'notYou'        => __( 'Not you?', 'faith-tv-series' ),
				'checkedIn'     => __( 'You\'re checked in. Thank you for joining us!', 'faith-tv-series' ),
				'checkinFailed' => __( 'We couldn\'t check you in automatically. Let the church office know you joined online.', 'faith-tv-series' ),
				'checkinLater'  => __( 'Attendance counts while the service is live, during service times.', 'faith-tv-series' ),
				/* translators: 1: minutes watched, 2: minutes needed */
				'watchedOf'     => __( 'Watched %1$d of %2$d min. Keep watching and you\'ll be checked in.', 'faith-tv-series' ),
				'withFamily'    => __( 'Watching with family?', 'faith-tv-series' ),
				'whoWatching'   => __( 'Who\'s watching with you?', 'faith-tv-series' ),
				'already'       => __( 'already checked in', 'faith-tv-series' ),
				'checkThemIn'   => __( 'Check them in', 'faith-tv-series' ),
				'familyDone'    => __( 'Thank you! They\'re checked in too.', 'faith-tv-series' ),
				'noFamily'      => __( 'No one else is in your household on file.', 'faith-tv-series' ),
				'continueWatching' => __( 'Continue watching', 'faith-tv-series' ),
				/* translators: %d: minutes */
				'minLeft'          => __( '%d min left', 'faith-tv-series' ),
				'listen'       => __( 'Listen', 'faith-tv-series' ),
				'watchVideo'   => __( 'Watch the video', 'faith-tv-series' ),
				'transcript'   => __( 'Transcript', 'faith-tv-series' ),
				'remindSeries' => __( 'Remind me when a new series starts', 'faith-tv-series' ),
				'remindMe'     => __( 'Remind me', 'faith-tv-series' ),
				'email'        => __( 'Email', 'faith-tv-series' ),
				'orPhone'      => __( 'or mobile number', 'faith-tv-series' ),
				'd'           => _x( 'd', 'days, in a countdown', 'faith-tv-series' ),
				'h'           => _x( 'h', 'hours, in a countdown', 'faith-tv-series' ),
				'm'           => _x( 'm', 'minutes, in a countdown', 'faith-tv-series' ),
				's'           => _x( 's', 'seconds, in a countdown', 'faith-tv-series' ),
			),
		);
	}

	/** Visitors never see errors; people who can edit the page get a clear note. */
	public static function problem( WP_Error $error ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<!-- Faith TV Series: ' . esc_html( $error->get_error_message() ) . ' -->';
		}
		return '<div class="ftvs-notice" style="padding:12px 16px;border-left:4px solid #c40d3c;background:#fff;color:#222;font:14px/1.4 sans-serif">'
			. '<strong>' . esc_html__( 'Faith TV Series:', 'faith-tv-series' ) . '</strong> '
			. esc_html( $error->get_error_message() )
			. ' <em>' . esc_html__( '(Only people who can edit this page see this note.)', 'faith-tv-series' ) . '</em></div>';
	}

	/** Sample videos: a note to the editor (visitors never see sample sections). */
	private static function demo_note() {
		if ( FTVS_Catalog::is_demo() ) {
			echo '<div class="ftvs-demo-note" style="padding:10px 14px;border-left:4px solid #084073;background:#fff;color:#222;font:14px/1.4 sans-serif;margin:0 0 12px">'
				. '<strong>' . esc_html__( 'Sample videos.', 'faith-tv-series' ) . '</strong> '
				. esc_html__( 'Only people who can edit the site see this section. Connect your church under Faith Stream > Church to show your own videos.', 'faith-tv-series' )
				. '</div>';
		}
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

	private static function date( $raw ) {
		$t = $raw ? strtotime( (string) $raw ) : false;
		return $t ? wp_date( get_option( 'date_format' ), $t ) : '';
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
