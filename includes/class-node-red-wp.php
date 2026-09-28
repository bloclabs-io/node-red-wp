<?php
/**
 * Node-RED Live Data main class.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node-RED WordPress main class
 *
 * @since 0.0.1
 */
class Node_Red_WP {

	/**
	 * Legacy option key that held every data point in a single array (0.x).
	 *
	 * @var string
	 */
	const DATA_OPTION_KEY = 'nrwp_data';

	/**
	 * Option key for the plugin settings.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'nrwp_settings';

	/**
	 * Front end script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'nrwp';

	/**
	 * Instance holder.
	 *
	 * @var Node_Red_WP|null
	 */
	protected static $instance = null;

	/**
	 * REST API handler.
	 *
	 * @var Node_Red_WP_REST
	 */
	public $api;

	/**
	 * Data store.
	 *
	 * @var Node_Red_WP_Data
	 */
	public $data;

	/**
	 * Shortcode handler.
	 *
	 * @var Node_Red_WP_Shortcodes
	 */
	public $shortcodes;

	/**
	 * Settings screen.
	 *
	 * @var Node_Red_WP_Settings
	 */
	public $settings;

	/**
	 * Get (and lazily create) the instance of the core class.
	 *
	 * @return Node_Red_WP
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor
	 *
	 * Bootstraps all the sub-classes that the plugin uses.
	 */
	protected function __construct() {
		$this->data       = new Node_Red_WP_Data();
		$this->api        = new Node_Red_WP_REST();
		$this->shortcodes = new Node_Red_WP_Shortcodes();
		$this->settings   = new Node_Red_WP_Settings();

		add_action( 'init', array( $this, 'action__init' ) );
		add_action( 'widgets_init', array( $this, 'action__widgets_init' ) );
		add_action( 'init', array( 'Node_Red_WP_Data', 'maybe_migrate' ), 5 );
	}

	/**
	 * Register the front end script and the block.
	 */
	public function action__init() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			NRWP_URL . 'assets/js/nrwp.js',
			array(),
			(string) filemtime( NRWP_DIR . 'assets/js/nrwp.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.nrwp = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( Node_Red_WP_REST::NAMESPACE_V1 . '/data' ) ),
					'interval' => (int) self::get_setting( 'refresh_interval' ),
					// Lets logged-in users read data when public read access is disabled.
					'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				)
			) . ';',
			'before'
		);

		register_block_type( NRWP_DIR . 'blocks/data' );
	}

	/**
	 * Register our widgets.
	 */
	public function action__widgets_init() {
		register_widget( 'Node_Red_WP_Data_Widget' );
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// Allow anonymous visitors to read data (needed for live updates on the front end).
			'public_read'      => true,
			// Keep the 0.x `get`, `set`, `get_keys` and `get_all` routes around.
			'legacy_routes'    => true,
			// How often the front end polls for fresh data, in milliseconds.
			'refresh_interval' => 3000,
			// SHA-256 hash of the API token. Empty when no token has been generated.
			'token_hash'       => '',
		);
	}

	/**
	 * Get a single plugin setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get_setting( $key ) {
		$settings = wp_parse_args( (array) get_option( self::SETTINGS_OPTION_KEY, array() ), self::default_settings() );

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Render the markup for a live data point.
	 *
	 * Shared by the block, the shortcode and the widget so they all behave the same way
	 * and get picked up by the front end script.
	 *
	 * @param array $args {
	 *     Render arguments.
	 *
	 *     @type string $key      Data key to display.
	 *     @type string $title    Optional heading shown above the value.
	 *     @type string $unit     Optional unit appended after the value, e.g. "°C".
	 *     @type string $fallback Text shown while the key has no value.
	 *     @type string $tag      Wrapper element for the value (span, div, p, strong).
	 * }
	 * @return string
	 */
	public static function render_data_point( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'key'      => '',
				'title'    => '',
				'unit'     => '',
				'fallback' => '—',
				'tag'      => 'span',
			)
		);

		$key = Node_Red_WP_Data::sanitize_key( $args['key'] );

		if ( '' === $key ) {
			return '';
		}

		$tag = in_array( $args['tag'], array( 'span', 'div', 'p', 'strong' ), true ) ? $args['tag'] : 'span';
		$val = self::init()->data->get( $key );

		wp_enqueue_script( self::SCRIPT_HANDLE );

		$markup = '';

		if ( '' !== (string) $args['title'] ) {
			$markup .= sprintf( '<h2 class="nrwp-title">%s</h2>', esc_html( $args['title'] ) );
		}

		$markup .= sprintf(
			'<%1$s class="nrwp-data nrwp-data-%2$s" data-key="%2$s" data-fallback="%3$s"%4$s>%5$s</%1$s>',
			$tag,
			esc_attr( $key ),
			esc_attr( $args['fallback'] ),
			null === $val ? '' : sprintf( ' data-value="%s"', esc_attr( Node_Red_WP_Data::to_display_string( $val ) ) ),
			esc_html( null === $val ? $args['fallback'] : Node_Red_WP_Data::to_display_string( $val ) )
		);

		if ( '' !== (string) $args['unit'] ) {
			$markup .= sprintf( '<span class="nrwp-unit">%s</span>', esc_html( $args['unit'] ) );
		}

		/**
		 * Filter the markup of a rendered data point.
		 *
		 * @param string $markup Rendered HTML.
		 * @param array  $args   Render arguments.
		 * @param mixed  $val    Current value, null when unset.
		 */
		return apply_filters( 'nrwp_data_point_markup', $markup, $args, $val );
	}
}
