<?php
/**
 * Elementor widget "Faith TV Series". Loaded only when Elementor is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'faith_tv_series';
	}

	public function get_title() {
		return __( 'Faith TV Series', 'faith-tv-series' );
	}

	public function get_icon() {
		return 'eicon-video-playlist';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'faith', 'tv', 'stream', 'faith stream', 'video', 'series', 'mini series', 'gideo', 'sermon' );
	}

	// The list comes live from Faith TV, so Elementor must never cache this widget's HTML.
	protected function is_dynamic_content(): bool {
		return true;
	}

	public function get_style_depends() {
		return array( 'faith-tv-series' );
	}

	public function get_script_depends() {
		return array( 'faith-tv-series' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'ftvs_content', array( 'label' => __( 'Faith Stream', 'faith-tv-series' ) ) );

		$this->add_control(
			'category',
			array(
				'label'       => __( 'Category', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->category_options(),
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'category_id',
			array(
				'label'       => __( 'Category ID', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'For a category deeper than this list shows, like one season. Copy it from Faith Stream > Videos.', 'faith-tv-series' ),
				'condition'   => array( 'category' => 'custom' ),
			)
		);

		$this->add_control(
			'video',
			array(
				'label'       => __( 'Or show one video (its ID)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'For a blog post or landing page: one message with a big play button.', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'default'   => __( 'Site default (Faith Stream > Look & feel)', 'faith-tv-series' ),
					'showcase'  => __( 'Showcase (big featured series that rotates)', 'faith-tv-series' ),
					'coverflow' => __( '3D carousel', 'faith-tv-series' ),
					'list'      => __( 'Featured + list', 'faith-tv-series' ),
					'row'       => __( 'Sliding row', 'faith-tv-series' ),
					'grid'      => __( 'Grid', 'faith-tv-series' ),
				),
				'default' => 'default',
			)
		);

		$this->add_control(
			'mobile_layout',
			array(
				'label'       => __( 'Phone layout', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'default'   => __( 'Site default (Faith Stream > Look & feel)', 'faith-tv-series' ),
					'auto'      => __( 'Automatic (best for phones)', 'faith-tv-series' ),
					'same'      => __( 'Same as desktop', 'faith-tv-series' ),
					'list'      => __( 'Featured + list', 'faith-tv-series' ),
					'coverflow' => __( 'Swipe carousel', 'faith-tv-series' ),
					'row'       => __( 'Sliding row', 'faith-tv-series' ),
					'showcase'  => __( 'Showcase', 'faith-tv-series' ),
				),
				'default'     => 'default',
				'description' => __( 'Automatic: the newest one big, the rest as a list.', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'autoplay',
			array(
				'label'       => __( 'Rotate every (seconds)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 60,
				'default'     => 7,
				'description' => __( '0 turns rotation off. It always pauses while someone points at it.', 'faith-tv-series' ),
				'condition'   => array( 'layout' => array( 'default', 'showcase', 'coverflow' ) ),
			)
		);

		$this->add_control(
			'play',
			array(
				'label'   => __( 'When someone clicks', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'site'    => __( 'Watch here on this page', 'faith-tv-series' ),
					'faithtv' => __( 'Open it on your channel in a new tab', 'faith-tv-series' ),
				),
				'default' => 'site',
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => __( 'How many to show', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
				'description' => __( '0 shows all of them, in your channel\'s order.', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'eyebrow',
			array(
				'label'       => __( 'Small red line above the heading (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'New every week', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Heading (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Our series', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'label',
			array(
				'label'       => __( 'Label over each featured series', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Leave empty for the site default (Faith Stream > Look & feel) or the category name.', 'faith-tv-series' ),
				'condition'   => array( 'layout' => array( 'default', 'showcase', 'coverflow', 'list' ) ),
			)
		);

		$this->add_control(
			'hide_badge',
			array(
				'label'        => __( 'Hide the "New" badge', 'faith-tv-series' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'badge',
			array(
				'label'       => __( 'Badge text', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Site default', 'faith-tv-series' ),
				'condition'   => array( 'hide_badge!' => 'yes' ),
			)
		);

		$this->add_control(
			'descriptions',
			array(
				'label'        => __( 'Show descriptions on cards', 'faith-tv-series' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'theme',
			array(
				'label'   => __( 'Background', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'default' => __( 'Site default (Faith Stream > Look & feel)', 'faith-tv-series' ),
					'dark'    => __( 'Dark section (light text)', 'faith-tv-series' ),
					'light'   => __( 'Light section (dark text)', 'faith-tv-series' ),
				),
				'default' => 'dark',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'ftvs_schedule',
			array( 'label' => __( 'Schedule (optional)', 'faith-tv-series' ) )
		);
		$this->add_control(
			'from',
			array(
				'label'          => __( 'Show from', 'faith-tv-series' ),
				'type'           => \Elementor\Controls_Manager::DATE_TIME,
				'picker_options' => array( 'enableTime' => false ),
				'description'    => __( 'Like a series until Easter: pick the dates, and what to show the rest of the time. Your site\'s time zone.', 'faith-tv-series' ),
			)
		);
		$this->add_control(
			'until',
			array(
				'label'          => __( 'Show until', 'faith-tv-series' ),
				'type'           => \Elementor\Controls_Manager::DATE_TIME,
				'picker_options' => array( 'enableTime' => false ),
			)
		);
		$this->add_control(
			'otherwise',
			array(
				'label'   => __( 'Other times, show', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( '' => __( 'Nothing', 'faith-tv-series' ) ) + array_diff_key( $this->category_options(), array( '' => 1, 'custom' => 1 ) ),
				'default' => '',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'ftvs_colors',
			array(
				'label' => __( 'Colors', 'faith-tv-series' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent',
			array(
				'label'     => __( 'Accent color', 'faith-tv-series' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .ftvs' => '--ftvs-accent: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		// Size, font and color for each part of the section's words.
		$parts = array(
			'heading' => array( __( 'Heading', 'faith-tv-series' ), '{{WRAPPER}} .ftvs__title' ),
			'eyebrow' => array( __( 'Small line above the heading', 'faith-tv-series' ), '{{WRAPPER}} .ftvs__head .ftvs-kicker' ),
			'series'  => array( __( 'Big series title', 'faith-tv-series' ), '{{WRAPPER}} .ftvs-feature__title' ),
			'card'    => array( __( 'Series names on cards', 'faith-tv-series' ), '{{WRAPPER}} .ftvs__name' ),
			'text'    => array( __( 'Descriptions', 'faith-tv-series' ), '{{WRAPPER}} .ftvs-feature__desc, {{WRAPPER}} .ftvs__desc' ),
			'meta'    => array( __( 'Episode counts and labels', 'faith-tv-series' ), '{{WRAPPER}} .ftvs__meta, {{WRAPPER}} .ftvs-feature__meta, {{WRAPPER}} .ftvs-kicker__label, {{WRAPPER}} .ftvs-cf__count' ),
		);
		foreach ( $parts as $part => $info ) {
			$this->start_controls_section(
				'ftvs_text_' . $part,
				array(
					'label' => $info[0],
					'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
				)
			);
			$this->add_control(
				'ftvs_' . $part . '_color',
				array(
					'label'     => __( 'Color', 'faith-tv-series' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( $info[1] => 'color: {{VALUE}};' ),
				)
			);
			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				array(
					'name'     => 'ftvs_' . $part . '_type',
					'label'    => __( 'Size and font', 'faith-tv-series' ),
					'selector' => $info[1],
				)
			);
			$this->end_controls_section();
		}
	}

	protected function render() {
		$s        = $this->get_settings_for_display();
		$category = isset( $s['category'] ) ? $s['category'] : '';
		if ( 'custom' === $category ) {
			$category = isset( $s['category_id'] ) ? trim( $s['category_id'] ) : '';
		}
		$html = FTVS_Renderer::render(
			array(
				'category'     => $category,
				'video'         => isset( $s['video'] ) ? trim( (string) $s['video'] ) : '',
				'from'          => isset( $s['from'] ) ? (string) $s['from'] : '',
				'until'         => isset( $s['until'] ) ? (string) $s['until'] : '',
				'otherwise'     => isset( $s['otherwise'] ) ? (string) $s['otherwise'] : '',
				'layout'        => 'default' === $s['layout'] ? null : $s['layout'],
				'mobile_layout' => isset( $s['mobile_layout'] ) && 'default' !== $s['mobile_layout'] ? $s['mobile_layout'] : null,
				'play'         => $s['play'],
				'limit'        => $s['limit'],
				'title'        => $s['title'],
				'descriptions' => $s['descriptions'],
				'theme'        => 'default' === $s['theme'] ? null : $s['theme'],
				'eyebrow'      => isset( $s['eyebrow'] ) ? $s['eyebrow'] : '',
				'label'        => '' !== trim( (string) ( isset( $s['label'] ) ? $s['label'] : '' ) ) ? $s['label'] : null,
				'badge'        => $this->badge( $s ),
				'autoplay'     => isset( $s['autoplay'] ) ? $s['autoplay'] : 7,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FTVS_Renderer escapes every value it prints.
	}

	/** null = the site default; '' = no badge. */
	private function badge( $s ) {
		if ( ! empty( $s['hide_badge'] ) ) {
			return '';
		}
		// Widgets from 1.1 hid the badge by emptying its text (its default was "New" then).
		$raw = $this->get_data( 'settings' );
		if ( is_array( $raw ) && array_key_exists( 'badge', $raw ) && '' === $raw['badge'] ) {
			return '';
		}
		$text = isset( $s['badge'] ) ? trim( (string) $s['badge'] ) : '';
		return '' !== $text ? $text : null;
	}

	public function category_options() {
		$options = array(
			''          => __( '- Pick a category -', 'faith-tv-series' ),
			'@newest'   => __( 'Automatic: newest messages', 'faith-tv-series' ),
			'@featured' => __( 'Automatic: what we feature on our channel', 'faith-tv-series' ),
		);
		// On live pages Elementor drops control options anyway; only the editor needs the list.
		if ( class_exists( '\Elementor\Core\Frontend\Performance' ) && \Elementor\Core\Frontend\Performance::should_optimize_controls() ) {
			return $options;
		}
		$tree = FTVS_Catalog::get_tree();
		if ( ! is_wp_error( $tree ) ) {
			foreach ( $tree as $row ) {
				$options[ $row['id'] ] = $row['title'];
				foreach ( $row['children'] as $child ) {
					$options[ $child['id'] ] = $row['title'] . ' / ' . $child['title'];
				}
			}
		}
		$options['custom'] = __( 'Other (paste an ID)', 'faith-tv-series' );
		return $options;
	}
}

/**
 * Elementor widget "Sunday Live": countdown to the next service, the live stream, then the replay.
 */
class FTVS_Elementor_Live_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'faith_tv_live';
	}

	public function get_title() {
		return __( 'Sunday Live', 'faith-tv-series' );
	}

	public function get_icon() {
		return 'eicon-youtube';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'faith', 'live', 'stream', 'service', 'sunday', 'countdown' );
	}

	protected function is_dynamic_content(): bool {
		return true;
	}

	public function get_style_depends() {
		return array( 'faith-tv-series' );
	}

	public function get_script_depends() {
		return array( 'faith-tv-series' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'faith_tv_live_content', array( 'label' => __( 'Sunday Live', 'faith-tv-series' ) ) );
		$this->add_control(
			'title',
			array(
				'label' => __( 'Heading (optional)', 'faith-tv-series' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'eyebrow',
			array(
				'label' => __( 'Small line above the heading (optional)', 'faith-tv-series' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'channel',
			array(
				'label'       => __( 'Channel (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'For a campus page: that campus\'s channel ID. Empty = whichever is live. Service times and a live link for other platforms are under Faith Stream > Church > Sunday live.', 'faith-tv-series' ),
			)
		);
		$this->add_control(
			'theme',
			array(
				'label'   => __( 'Background', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'default' => __( 'Site default', 'faith-tv-series' ),
					'dark'    => __( 'Dark section (light text)', 'faith-tv-series' ),
					'light'   => __( 'Light section (dark text)', 'faith-tv-series' ),
				),
				'default' => 'default',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		$html = FTVS_Renderer::live(
			array(
				'title'     => isset( $s['title'] ) ? $s['title'] : '',
				'eyebrow'   => isset( $s['eyebrow'] ) ? $s['eyebrow'] : '',
				'channel' => isset( $s['channel'] ) ? trim( (string) $s['channel'] ) : '',
				'theme'     => isset( $s['theme'] ) && 'default' !== $s['theme'] ? $s['theme'] : null,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FTVS_Renderer escapes every value it prints.
	}
}

/**
 * Elementor widget "Sermon Library": search and filters over every message.
 */
class FTVS_Elementor_Library_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'faith_tv_library';
	}

	public function get_title() {
		return __( 'Sermon Library', 'faith-tv-series' );
	}

	public function get_icon() {
		return 'eicon-search-results';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'faith', 'sermon', 'library', 'search', 'archive', 'messages' );
	}

	protected function is_dynamic_content(): bool {
		return true;
	}

	public function get_style_depends() {
		return array( 'faith-tv-series' );
	}

	public function get_script_depends() {
		return array( 'faith-tv-series' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'faith_tv_library_content', array( 'label' => __( 'Sermon Library', 'faith-tv-series' ) ) );
		$this->add_control(
			'title',
			array(
				'label' => __( 'Heading (optional)', 'faith-tv-series' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'eyebrow',
			array(
				'label' => __( 'Small line above the heading (optional)', 'faith-tv-series' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'category',
			array(
				'label'       => __( 'Only messages in (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'A category ID from Faith Stream > Videos. Empty = every message.', 'faith-tv-series' ),
			)
		);
		$this->add_control(
			'theme',
			array(
				'label'   => __( 'Background', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'default' => __( 'Site default', 'faith-tv-series' ),
					'dark'    => __( 'Dark section (light text)', 'faith-tv-series' ),
					'light'   => __( 'Light section (dark text)', 'faith-tv-series' ),
				),
				'default' => 'default',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		$html = FTVS_Renderer::library(
			array(
				'title'     => isset( $s['title'] ) ? $s['title'] : '',
				'eyebrow'   => isset( $s['eyebrow'] ) ? $s['eyebrow'] : '',
				'category' => isset( $s['category'] ) ? trim( (string) $s['category'] ) : '',
				'theme'     => isset( $s['theme'] ) && 'default' !== $s['theme'] ? $s['theme'] : null,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FTVS_Renderer escapes every value it prints.
	}
}

/**
 * Elementor widget "Faith TV Channel": the whole channel on this page, like the church's TV site.
 * Put it in a full-width section; visitors browse, search and watch without leaving the website.
 */
class FTVS_Elementor_Channel_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'faith_tv_channel';
	}

	public function get_title() {
		return __( 'Faith TV Channel', 'faith-tv-series' );
	}

	public function get_icon() {
		return 'eicon-video-playlist';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'faith', 'channel', 'tv', 'watch', 'series', 'sermons', 'video' );
	}

	protected function is_dynamic_content(): bool {
		return true;
	}

	public function get_style_depends() {
		return array( 'faith-tv-series', 'faith-tv-channel' );
	}

	public function get_script_depends() {
		return array( 'faith-tv-series' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'faith_tv_channel_content', array( 'label' => __( 'Faith TV Channel', 'faith-tv-series' ) ) );
		$this->add_control(
			'about',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => esc_html__( 'Your whole channel: the banner, the Featured slider, every series, the player and search. Use a full-width section. Row layouts, the name and the logo are under Faith Stream > Channel page.', 'faith-tv-series' ),
			)
		);
		$this->add_control(
			'name',
			array(
				'label'       => __( 'Name in the bar (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Empty: the name under Faith Stream > Channel page.', 'faith-tv-series' ),
			)
		);
		$this->add_control(
			'backdrop',
			array(
				'label'   => __( 'Photo behind the rows', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'default' => __( 'Site default', 'faith-tv-series' ),
					'on'      => __( 'On', 'faith-tv-series' ),
					'off'     => __( 'Off', 'faith-tv-series' ),
				),
				'default' => 'default',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$s    = $this->get_settings_for_display();
		$html = FTVS_Channel::render(
			array(
				'name'     => isset( $s['name'] ) ? (string) $s['name'] : '',
				'backdrop' => isset( $s['backdrop'] ) && 'default' !== $s['backdrop'] ? (string) $s['backdrop'] : '',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FTVS_Channel escapes every value it prints.
	}
}
