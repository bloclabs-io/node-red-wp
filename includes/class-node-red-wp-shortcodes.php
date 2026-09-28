<?php
/**
 * Node-RED WordPress shortcodes.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node-RED WordPress shortcodes
 *
 * @since 0.0.1
 */
class Node_Red_WP_Shortcodes {

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_shortcode( 'nodered_data', array( $this, 'shortcode__data' ) );
	}

	/**
	 * Data shortcode
	 *
	 * Returns the markup for a live data point on the front end, e.g.
	 * `[nodered_data key="temperature" title="Outside" unit="°C"]`.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Formatted shortcode markup
	 */
	public function shortcode__data( $atts ) {
		$atts = shortcode_atts(
			array(
				'key'      => '',
				'title'    => '',
				'unit'     => '',
				'fallback' => '—',
				'tag'      => 'span',
			),
			$atts,
			'nodered_data'
		);

		return Node_Red_WP::render_data_point( $atts );
	}
}
