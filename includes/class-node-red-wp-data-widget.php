<?php
/**
 * Node-RED WordPress classic widget.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic widget showing a live data point.
 *
 * Block themes can use the "Node-RED Data" block instead.
 *
 * @since 0.0.1
 */
class Node_Red_WP_Data_Widget extends WP_Widget {

	/**
	 * Register the widget.
	 */
	public function __construct() {
		parent::__construct(
			'node_red_wp_data_widget',
			esc_html__( 'Node-RED Data', 'node-red-wp' ),
			array(
				'classname'             => 'nrwp_data_widget',
				'description'           => esc_html__( 'Display real time data from Node-RED.', 'node-red-wp' ),
				'show_instance_in_rest' => true,
			)
		);
	}

	/**
	 * Output the widget.
	 *
	 * @param array $args     Display arguments.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args(
			(array) $instance,
			array(
				'title'   => '',
				'datakey' => '',
				'unit'    => '',
			)
		);

		$markup = Node_Red_WP::render_data_point(
			array(
				'key'  => $instance['datakey'],
				'unit' => $instance['unit'],
			)
		);

		if ( '' === $markup ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.

		if ( '' !== $instance['title'] ) {
			/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
			$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
		}

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_data_point().

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
	}

	/**
	 * Output the settings form.
	 *
	 * @param array $instance Current settings.
	 * @return string
	 */
	public function form( $instance ) {
		$fields = array(
			'title'   => __( 'Title:', 'node-red-wp' ),
			'datakey' => __( 'Data key:', 'node-red-wp' ),
			'unit'    => __( 'Unit (optional):', 'node-red-wp' ),
		);

		foreach ( $fields as $name => $label ) {
			$value = isset( $instance[ $name ] ) ? $instance[ $name ] : '';
			?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( $name ) ); ?>"><?php echo esc_html( $label ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( $name ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $name ) ); ?>" type="text" value="<?php echo esc_attr( $value ); ?>">
			</p>
			<?php
		}

		return '';
	}

	/**
	 * Sanitize the settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Previous settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'   => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'datakey' => isset( $new_instance['datakey'] ) ? Node_Red_WP_Data::sanitize_key( $new_instance['datakey'] ) : '',
			'unit'    => isset( $new_instance['unit'] ) ? sanitize_text_field( $new_instance['unit'] ) : '',
		);
	}
}
