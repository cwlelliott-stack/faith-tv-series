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
		return array( 'faith', 'tv', 'video', 'series', 'mini series', 'gideo', 'sermon' );
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
		$this->start_controls_section( 'ftvs_content', array( 'label' => __( 'Faith TV', 'faith-tv-series' ) ) );

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
				'description' => __( 'For a category deeper than this list shows, like one season. Copy the ID from Settings > Faith TV Series.', 'faith-tv-series' ),
				'condition'   => array( 'category' => 'custom' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'showcase'  => __( 'Showcase (big featured series that rotates)', 'faith-tv-series' ),
					'coverflow' => __( '3D carousel', 'faith-tv-series' ),
					'row'       => __( 'Sliding row', 'faith-tv-series' ),
					'grid'      => __( 'Grid', 'faith-tv-series' ),
				),
				'default' => 'showcase',
			)
		);

		$this->add_control(
			'mobile_layout',
			array(
				'label'       => __( 'Phone layout', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'auto'      => __( 'Automatic (best for phones)', 'faith-tv-series' ),
					'same'      => __( 'Same as desktop', 'faith-tv-series' ),
					'showcase'  => __( 'Showcase', 'faith-tv-series' ),
					'coverflow' => __( 'Swipe carousel', 'faith-tv-series' ),
					'row'       => __( 'Sliding row', 'faith-tv-series' ),
					'grid'      => __( 'Grid', 'faith-tv-series' ),
				),
				'default'     => 'auto',
				'description' => __( 'Automatic gives phones the swipe carousel for Showcase, and a sliding row for Grid.', 'faith-tv-series' ),
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
				'condition'   => array( 'layout' => array( 'showcase', 'coverflow' ) ),
			)
		);

		$this->add_control(
			'play',
			array(
				'label'   => __( 'When someone clicks', 'faith-tv-series' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'site'    => __( 'Watch here on this page', 'faith-tv-series' ),
					'faithtv' => __( 'Open Faith TV in a new tab', 'faith-tv-series' ),
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
				'description' => __( '0 shows all of them, newest first (the Faith TV order).', 'faith-tv-series' ),
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
				'description' => __( 'Leave empty to use the category name.', 'faith-tv-series' ),
				'condition'   => array( 'layout' => array( 'showcase', 'coverflow' ) ),
			)
		);

		$this->add_control(
			'badge',
			array(
				'label'       => __( 'Badge on the newest one', 'faith-tv-series' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'New', 'faith-tv-series' ),
				'description' => __( 'Leave empty for no badge.', 'faith-tv-series' ),
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
				'layout'       => $s['layout'],
				'mobile_layout' => isset( $s['mobile_layout'] ) ? $s['mobile_layout'] : 'auto',
				'play'         => $s['play'],
				'limit'        => $s['limit'],
				'title'        => $s['title'],
				'descriptions' => $s['descriptions'],
				'theme'        => $s['theme'],
				'eyebrow'      => isset( $s['eyebrow'] ) ? $s['eyebrow'] : '',
				'label'        => '' !== trim( (string) ( isset( $s['label'] ) ? $s['label'] : '' ) ) ? $s['label'] : $this->category_label( $category ),
				'badge'        => isset( $s['badge'] ) ? $s['badge'] : '',
				'autoplay'     => isset( $s['autoplay'] ) ? $s['autoplay'] : 7,
			)
		);
	}

	/** The picked category's own name (from the cached list) for the label over each series. */
	private function category_label( $id ) {
		$tree = FTVS_Gideo_Client::get_tree();
		if ( is_wp_error( $tree ) ) {
			return '';
		}
		foreach ( $tree as $row ) {
			if ( $row['id'] === $id ) {
				return $row['title'];
			}
			foreach ( $row['children'] as $child ) {
				if ( $child['id'] === $id ) {
					return $child['title'];
				}
			}
		}
		return '';
	}

	private function category_options() {
		$options = array( '' => __( '- Pick a category -', 'faith-tv-series' ) );
		// On live pages Elementor drops control options anyway; only the editor needs the list.
		if ( class_exists( '\Elementor\Core\Frontend\Performance' ) && \Elementor\Core\Frontend\Performance::should_optimize_controls() ) {
			return $options;
		}
		$tree = FTVS_Gideo_Client::get_tree();
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
