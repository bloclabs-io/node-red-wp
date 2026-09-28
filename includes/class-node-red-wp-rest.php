<?php
/**
 * Node-RED WordPress REST API endpoints.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node-RED WordPress REST API endpoints
 *
 * @since 0.0.1
 */
class Node_Red_WP_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'nrwp/v1';

	/**
	 * Header carrying the API token.
	 *
	 * @var string
	 */
	const TOKEN_HEADER = 'X-NRWP-Token';

	/**
	 * Route fragment matching a data key.
	 *
	 * @var string
	 */
	const KEY_PATTERN = '(?P<key>[a-zA-Z0-9_-]+)';

	/**
	 * Class constructor
	 *
	 * Hook in our class to rest_api_init.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'action__rest_api_init' ) );
	}

	/**
	 * Register our endpoints.
	 */
	public function action__rest_api_init() {
		$key_arg = array(
			'key' => array(
				'description' => __( 'Data key.', 'node-red-wp' ),
				'type'        => 'string',
				'required'    => true,
			),
		);

		// Read all data points, or write several at once.
		register_rest_route(
			self::NAMESPACE_V1,
			'/data',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'endpoint__list' ),
					'permission_callback' => array( $this, 'permission__read' ),
					'args'                => array(
						'keys' => array(
							'description' => __( 'Limit the result to these keys (array or comma separated list).', 'node-red-wp' ),
							'type'        => array( 'array', 'string' ),
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'endpoint__batch_set' ),
					'permission_callback' => array( $this, 'permission__write' ),
				),
			)
		);

		// Read, write or delete a single data point.
		register_rest_route(
			self::NAMESPACE_V1,
			'/data/' . self::KEY_PATTERN,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'endpoint__get' ),
					'permission_callback' => array( $this, 'permission__read' ),
					'args'                => $key_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'endpoint__set' ),
					'permission_callback' => array( $this, 'permission__write' ),
					'args'                => array_merge(
						$key_arg,
						array(
							'value' => array(
								'description' => __( 'Value to store.', 'node-red-wp' ),
								'type'        => array( 'string', 'number', 'integer', 'boolean' ),
								'required'    => true,
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'endpoint__delete' ),
					'permission_callback' => array( $this, 'permission__write' ),
					'args'                => $key_arg,
				),
			)
		);

		// List all data keys.
		register_rest_route(
			self::NAMESPACE_V1,
			'/keys',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'endpoint__keys' ),
				'permission_callback' => array( $this, 'permission__read' ),
			)
		);

		// Get stats from Jetpack.
		register_rest_route(
			self::NAMESPACE_V1,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'endpoint__stats' ),
				'permission_callback' => array( $this, 'permission__stats' ),
			)
		);

		if ( Node_Red_WP::get_setting( 'legacy_routes' ) ) {
			$this->register_legacy_routes();
		}
	}

	/**
	 * Register the 0.x routes, which answer with the original response envelope.
	 */
	protected function register_legacy_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/set/' . self::KEY_PATTERN . '/(?P<val>[^/]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'legacy__set' ),
				'permission_callback' => array( $this, 'permission__write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/get/' . self::KEY_PATTERN,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'legacy__get' ),
				'permission_callback' => array( $this, 'permission__read' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/get_keys',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'legacy__get_keys' ),
				'permission_callback' => array( $this, 'permission__read' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/get_all',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'legacy__get_all' ),
				'permission_callback' => array( $this, 'permission__read' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/get_stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'legacy__get_stats' ),
				'permission_callback' => array( $this, 'permission__stats' ),
			)
		);
	}

	/*
	 * Permissions.
	 */

	/**
	 * Check whether the request carries a valid API token.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return bool
	 */
	public function has_valid_token( $request ) {
		$hash  = (string) Node_Red_WP::get_setting( 'token_hash' );
		$token = (string) $request->get_header( self::TOKEN_HEADER );

		return '' !== $hash && '' !== $token && hash_equals( $hash, hash( 'sha256', $token ) );
	}

	/**
	 * Whether the current request may write data.
	 *
	 * Authenticate with the API token header, or as a user having the `manage_options`
	 * capability (for example with an Application Password over HTTP Basic auth).
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return true|WP_Error
	 */
	public function permission__write( $request ) {
		/**
		 * Filter the capability a user needs to write Node-RED data.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = apply_filters( 'nrwp_write_capability', 'manage_options' );

		if ( $this->has_valid_token( $request ) || current_user_can( $capability ) ) {
			return true;
		}

		return $this->forbidden();
	}

	/**
	 * Whether the current request may read data.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return true|WP_Error
	 */
	public function permission__read( $request ) {
		/**
		 * Filter whether anonymous visitors may read Node-RED data.
		 *
		 * @param bool            $public  Value of the "Public read access" setting.
		 * @param WP_REST_Request $request The REST API request object.
		 */
		$public = apply_filters( 'nrwp_public_read', (bool) Node_Red_WP::get_setting( 'public_read' ), $request );

		if ( $public || current_user_can( 'read' ) || $this->has_valid_token( $request ) ) {
			return true;
		}

		return $this->forbidden();
	}

	/**
	 * Whether the current request may read Jetpack stats.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return true|WP_Error
	 */
	public function permission__stats( $request ) {
		if ( current_user_can( 'view_stats' ) ) {
			return true;
		}

		return $this->permission__write( $request );
	}

	/**
	 * Build the error returned when a permission check fails.
	 *
	 * @return WP_Error
	 */
	protected function forbidden() {
		return new WP_Error(
			'rest_forbidden',
			sprintf(
				/* translators: %s: HTTP header name. */
				__( 'Sorry, you are not allowed to do that. Authenticate with an Application Password or the %s header.', 'node-red-wp' ),
				self::TOKEN_HEADER
			),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/*
	 * v2 endpoints.
	 */

	/**
	 * Get every data point as a key => value map.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response
	 */
	public function endpoint__list( $request ) {
		$keys = $request['keys'];

		if ( is_string( $keys ) ) {
			$keys = '' === $keys ? array() : explode( ',', $keys );
		}

		$data = Node_Red_WP::init()->data->get_all( (array) $keys );

		return $this->no_cache( rest_ensure_response( (object) $data ) );
	}

	/**
	 * Get a single data point.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function endpoint__get( $request ) {
		$key    = Node_Red_WP_Data::sanitize_key( $request['key'] );
		$record = Node_Red_WP::init()->data->get_record( $key );

		if ( null === $record ) {
			return new WP_Error( 'nrwp_not_found', __( 'No data was found for this key.', 'node-red-wp' ), array( 'status' => 404 ) );
		}

		return $this->no_cache( rest_ensure_response( $this->prepare_record( $key, $record ) ) );
	}

	/**
	 * Set a single data point.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function endpoint__set( $request ) {
		$data   = Node_Red_WP::init()->data;
		$key    = Node_Red_WP_Data::sanitize_key( $request['key'] );
		$result = $data->set( $key, $request['value'] );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $this->prepare_record( $key, $data->get_record( $key ) ) );
	}

	/**
	 * Set several data points at once.
	 *
	 * Expects a JSON object of key => value pairs as the request body.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function endpoint__batch_set( $request ) {
		$values = $request->get_json_params();

		if ( ! is_array( $values ) || empty( $values ) ) {
			$values = $request->get_body_params();
		}

		if ( ! is_array( $values ) || empty( $values ) ) {
			return new WP_Error( 'nrwp_empty_batch', __( 'Send a JSON object of key/value pairs.', 'node-red-wp' ), array( 'status' => 400 ) );
		}

		$data    = Node_Red_WP::init()->data;
		$updated = array();
		$errors  = array();

		foreach ( $values as $key => $val ) {
			$result = $data->set( $key, $val );

			if ( is_wp_error( $result ) ) {
				$errors[ (string) $key ] = $result->get_error_message();
			} else {
				$updated[] = Node_Red_WP_Data::sanitize_key( $key );
			}
		}

		$response = rest_ensure_response(
			array(
				'updated' => $updated,
				'errors'  => (object) $errors,
			)
		);

		if ( empty( $updated ) ) {
			$response->set_status( 400 );
		}

		return $response;
	}

	/**
	 * Delete a single data point.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function endpoint__delete( $request ) {
		$key = Node_Red_WP_Data::sanitize_key( $request['key'] );

		if ( ! Node_Red_WP::init()->data->delete( $key ) ) {
			return new WP_Error( 'nrwp_not_found', __( 'No data was found for this key.', 'node-red-wp' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'key'     => $key,
				'deleted' => true,
			)
		);
	}

	/**
	 * List all data keys.
	 *
	 * @return WP_REST_Response
	 */
	public function endpoint__keys() {
		return $this->no_cache( rest_ensure_response( array_keys( Node_Red_WP::init()->data->get_all_records() ) ) );
	}

	/**
	 * Get site stats from Jetpack.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function endpoint__stats() {
		$stats = $this->fetch_jetpack_stats();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		return rest_ensure_response( $stats );
	}

	/*
	 * Legacy (0.x) endpoints.
	 */

	/**
	 * Legacy: get the value for a specific data key.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return array Formatted API response
	 */
	public function legacy__get( $request ) {
		$data = Node_Red_WP::init()->data->get( $request['key'] );

		if ( null === $data ) {
			return $this->format_response( false, false, true, 'No data was found for this key.' );
		}

		return $this->format_response( true, $data );
	}

	/**
	 * Legacy: set the value at a specific data key.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return array Formatted API response
	 */
	public function legacy__set( $request ) {
		$result = Node_Red_WP::init()->data->set( $request['key'], rawurldecode( $request['val'] ) );

		if ( is_wp_error( $result ) ) {
			return $this->format_response( false, false, true, $result->get_error_message() );
		}

		return $this->format_response( true, false, false, 'Value updated.' );
	}

	/**
	 * Legacy: get an array of data keys.
	 *
	 * @return array Formatted API response
	 */
	public function legacy__get_keys() {
		return $this->format_response( true, array_keys( Node_Red_WP::init()->data->get_all_records() ) );
	}

	/**
	 * Legacy: get an array of data keys and their associated values.
	 *
	 * @return array Formatted API response
	 */
	public function legacy__get_all() {
		return $this->format_response( true, Node_Red_WP::init()->data->get_all() );
	}

	/**
	 * Legacy: get an array of stats data from Jetpack.
	 *
	 * @return array Formatted API response
	 */
	public function legacy__get_stats() {
		$stats = $this->fetch_jetpack_stats();

		if ( is_wp_error( $stats ) ) {
			return $this->format_response( false, false, true, $stats->get_error_message() );
		}

		return $this->format_response( true, isset( $stats['stats'] ) ? $stats['stats'] : $stats );
	}

	/*
	 * Helpers.
	 */

	/**
	 * Fetch site stats from Jetpack.
	 *
	 * @return array|WP_Error
	 */
	protected function fetch_jetpack_stats() {
		if ( class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			$stats = ( new \Automattic\Jetpack\Stats\WPCOM_Stats() )->get_stats();
		} elseif ( function_exists( 'stats_get_from_restapi' ) ) {
			// Jetpack < 11.5.
			$stats = stats_get_from_restapi();
		} else {
			return new WP_Error( 'nrwp_no_jetpack', __( 'This feature is not available. Please install and connect the Jetpack plugin with Stats enabled.', 'node-red-wp' ), array( 'status' => 501 ) );
		}

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		return json_decode( wp_json_encode( $stats ), true );
	}

	/**
	 * Shape a stored record for API output.
	 *
	 * @param string $key    Data key.
	 * @param array  $record Record from Node_Red_WP_Data::get_record().
	 * @return array
	 */
	protected function prepare_record( $key, $record ) {
		return array(
			'key'     => $key,
			'value'   => $record['value'],
			'updated' => $record['updated'] ? gmdate( 'c', $record['updated'] ) : null,
		);
	}

	/**
	 * Keep proxies and page caches from serving stale live data.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	protected function no_cache( $response ) {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}

		return $response;
	}

	/**
	 * Standardize a legacy API response payload.
	 *
	 * @param bool  $status  True if the request succeeded, false on failure.
	 * @param mixed $data    False on failure, array|string on success.
	 * @param bool  $error   True if an error is present, false on success.
	 * @param mixed $message False if no message, string when there is an error or notice.
	 * @return array Formatted response payload
	 */
	public function format_response( $status, $data = false, $error = false, $message = false ) {
		return array(
			'status'        => $status,
			'data'          => $data,
			'error'         => (bool) $error,
			'error_message' => $message,
		);
	}
}
