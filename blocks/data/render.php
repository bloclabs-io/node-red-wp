<?php
/**
 * Server side rendering of the nrwp/data block.
 *
 * @package node-red-wp
 *
 * @var array $attributes Block attributes.
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nrwp_markup = Node_Red_WP::render_data_point(
	array(
		'key'      => isset( $attributes['dataKey'] ) ? $attributes['dataKey'] : '',
		'title'    => isset( $attributes['title'] ) ? $attributes['title'] : '',
		'unit'     => isset( $attributes['unit'] ) ? $attributes['unit'] : '',
		'fallback' => isset( $attributes['fallback'] ) ? $attributes['fallback'] : '—',
	)
);

if ( '' !== $nrwp_markup ) {
	printf(
		'<div %s>%s</div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
		$nrwp_markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_data_point().
	);
}
