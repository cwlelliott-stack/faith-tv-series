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
				'placeholder' => __( 'Everyday issues that challenge our faith', 'faith-tv-series' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Heading (optional)', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Faith Mini Series', 'faith-tv-series' ),
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
					'dark'  => __( 'Dark section (light text)', 'faith-tv-series' ),
					'light' => __( 'Light section (dark text)', 'faith-tv-series' ),
				),
				'default' => 'dark',
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
		echo FTVS_Renderer::render( // phpcs:ignore WordPress.Security.EscapeOutput -- the renderer escapes everything.
			array(
				'category'     => $category,
				'layout'        => 'default' === $s['layout'] ? null : $s['layout'],
				'mobile_layout' => isset( $s['mobile_layout'] ) && 'default' !== $s['mobile_layout'] ? $s['mobile_layout'] : null,
				'play'         => $s['play'],
				'limit'        => $s['limit'],
				'title'        => $s['title'],
				'descriptions' => $s['descriptions'],
				'theme'        => $s['theme'],
				'eyebrow'      => isset( $s['eyebrow'] ) ? $s['eyebrow'] : '',
				'label'        => '' !== trim( (string) ( isset( $s['label'] ) ? $s['label'] : '' ) ) ? $s['label'] : null,
				'badge'        => $this->badge( $s ),
				'autoplay'     => isset( $s['autoplay'] ) ? $s['autoplay'] : 7,
			)
		);
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

	private function category_options() {
		$options = array( '' => __( '- Pick a category -', 'faith-tv-series' ) );
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
