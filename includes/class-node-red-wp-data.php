<?php
/**
 * Node-RED WordPress data access interface.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node-RED WordPress data access interface
 *
 * Every data point is stored in its own (non-autoloaded) option so that concurrent
 * writes from Node-RED for different keys can never overwrite each other. Each option
 * holds the value together with the time it was last written.
 *
 * @since 0.0.1
 */
class Node_Red_WP_Data {

	/**
	 * Prefix for the per-key option names.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'nrwp_d_';

	/**
	 * Maximum length of a data key.
	 *
	 * @var int
	 */
	const MAX_KEY_LENGTH = 64;

	/**
	 * Maximum length of a string value.
	 *
	 * @var int
	 */
	const MAX_VALUE_LENGTH = 4096;

	/**
	 * Normalize a data key.
	 *
	 * Keys are lowercase and may contain letters, numbers, dashes and underscores.
	 *
	 * @param mixed $key Raw key.
	 * @return string Sanitized key, empty string when invalid.
	 */
	public static function sanitize_key( $key ) {
		if ( ! is_scalar( $key ) ) {
			return '';
		}

		return substr( sanitize_key( (string) $key ), 0, self::MAX_KEY_LENGTH );
	}

	/**
	 * Validate and normalize a value before it gets stored.
	 *
	 * Scalars (strings, numbers and booleans) are supported. Numeric strings are kept
	 * as strings so values such as "007" are not altered.
	 *
	 * @param mixed $val Raw value.
	 * @return string|int|float|bool|WP_Error
	 */
	public static function sanitize_value( $val ) {
		if ( is_bool( $val ) || is_int( $val ) ) {
			return $val;
		}

		if ( is_float( $val ) ) {
			return is_finite( $val ) ? $val : new WP_Error( 'nrwp_invalid_value', __( 'Numbers must be finite.', 'node-red-wp' ) );
		}

		if ( is_string( $val ) ) {
			$val = wp_check_invalid_utf8( $val, true );

			if ( strlen( $val ) > self::MAX_VALUE_LENGTH ) {
				return new WP_Error(
					'nrwp_invalid_value',
					/* translators: %d: maximum number of bytes. */
					sprintf( __( 'Values may not be longer than %d bytes.', 'node-red-wp' ), self::MAX_VALUE_LENGTH )
				);
			}

			return $val;
		}

		return new WP_Error( 'nrwp_invalid_value', __( 'Values must be a string, number or boolean.', 'node-red-wp' ) );
	}

	/**
	 * Convert a stored value into the string shown to visitors.
	 *
	 * @param mixed $val Stored value.
	 * @return string
	 */
	public static function to_display_string( $val ) {
		if ( is_bool( $val ) ) {
			return $val ? 'true' : 'false';
		}

		return (string) $val;
	}

	/**
	 * Get the option name for a key.
	 *
	 * @param string $key Sanitized key.
	 * @return string
	 */
	protected static function option_name( $key ) {
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * Get the stored record (value and timestamp) for a key.
	 *
	 * @param string $key Data key.
	 * @return array|null Array with `value` and `updated` entries, null when unset.
	 */
	public function get_record( $key ) {
		$key = self::sanitize_key( $key );

		if ( '' === $key ) {
			return null;
		}

		return self::unpack( get_option( self::option_name( $key ), null ) );
	}

	/**
	 * Pluck a specific value from the data store, default to null.
	 *
	 * @param string $key The key to get data for.
	 * @return mixed Value at key location, null when unset.
	 */
	public function get( $key ) {
		$record = $this->get_record( $key );

		return null === $record ? null : $record['value'];
	}

	/**
	 * Get all stored records.
	 *
	 * @param string[] $keys Optional list of keys to limit the result to.
	 * @return array Map of key => array( value, updated ), sorted by key.
	 */
	public function get_all_records( $keys = array() ) {
		global $wpdb;

		$keys = array_values( array_filter( array_map( array( __CLASS__, 'sanitize_key' ), (array) $keys ) ) );

		if ( empty( $keys ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The options table has no API for prefix lookups.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
					$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
				)
			);

			$records = array();

			foreach ( (array) $rows as $row ) {
				$record = self::unpack( maybe_unserialize( $row->option_value ) );

				if ( null !== $record ) {
					$records[ substr( $row->option_name, strlen( self::OPTION_PREFIX ) ) ] = $record;
				}
			}

			return $records;
		}

		$records = array();

		foreach ( array_unique( $keys ) as $key ) {
			$record = $this->get_record( $key );

			if ( null !== $record ) {
				$records[ $key ] = $record;
			}
		}

		ksort( $records );

		return $records;
	}

	/**
	 * Get all data keys and values.
	 *
	 * @param string[] $keys Optional list of keys to limit the result to.
	 * @return array Map of key => value.
	 */
	public function get_all( $keys = array() ) {
		return wp_list_pluck( $this->get_all_records( $keys ), 'value' );
	}

	/**
	 * Set a specific value to a data key.
	 *
	 * @param string $key The key location.
	 * @param mixed  $val The value to store at key.
	 * @return true|WP_Error True on success.
	 */
	public function set( $key, $val ) {
		$key = self::sanitize_key( $key );

		if ( '' === $key ) {
			return new WP_Error( 'nrwp_invalid_key', __( 'Keys may only contain letters, numbers, dashes and underscores.', 'node-red-wp' ) );
		}

		$val = self::sanitize_value( $val );

		if ( is_wp_error( $val ) ) {
			return $val;
		}

		$old = $this->get( $key );

		update_option(
			self::option_name( $key ),
			array(
				'v' => $val,
				't' => time(),
			),
			false
		);

		/**
		 * Fires after a data point was written.
		 *
		 * @param string $key Data key.
		 * @param mixed  $val New value.
		 * @param mixed  $old Previous value, null when the key was not set.
		 */
		do_action( 'nrwp_data_set', $key, $val, $old );

		return true;
	}

	/**
	 * Delete a data key.
	 *
	 * @param string $key Data key.
	 * @return bool True when the key existed and was removed.
	 */
	public function delete( $key ) {
		$key = self::sanitize_key( $key );

		if ( '' === $key || ! delete_option( self::option_name( $key ) ) ) {
			return false;
		}

		/**
		 * Fires after a data point was deleted.
		 *
		 * @param string $key Data key.
		 */
		do_action( 'nrwp_data_deleted', $key );

		return true;
	}

	/**
	 * Delete every data point.
	 *
	 * @return int Number of removed keys.
	 */
	public function delete_all() {
		$count = 0;

		foreach ( array_keys( $this->get_all_records() ) as $key ) {
			$count += (int) $this->delete( $key );
		}

		return $count;
	}

	/**
	 * Turn a stored option value into a record.
	 *
	 * @param mixed $stored Option value.
	 * @return array|null
	 */
	protected static function unpack( $stored ) {
		if ( ! is_array( $stored ) || ! array_key_exists( 'v', $stored ) ) {
			return null;
		}

		return array(
			'value'   => $stored['v'],
			'updated' => isset( $stored['t'] ) ? (int) $stored['t'] : 0,
		);
	}

	/**
	 * Move data stored by 0.x (a single `nrwp_data` array option) into per-key options.
	 */
	public static function maybe_migrate() {
		$legacy = get_option( Node_Red_WP::DATA_OPTION_KEY, null );

		if ( null === $legacy ) {
			return;
		}

		if ( is_array( $legacy ) ) {
			$store = new self();

			foreach ( $legacy as $key => $val ) {
				if ( null === $store->get( $key ) ) {
					$store->set( $key, is_scalar( $val ) ? $val : wp_json_encode( $val ) );
				}
			}
		}

		delete_option( Node_Red_WP::DATA_OPTION_KEY );
	}
}
