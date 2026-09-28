<?php
/**
 * Node-RED WordPress settings screen.
 *
 * @package node-red-wp
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Node-RED screen.
 *
 * @since 2.0.0
 */
class Node_Red_WP_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'node-red-wp';

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'action__admin_menu' ) );
		add_action( 'admin_init', array( $this, 'action__admin_init' ) );
		add_action( 'admin_post_nrwp_token', array( $this, 'action__handle_token' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NRWP_FILE ), array( $this, 'filter__action_links' ) );
	}

	/**
	 * Add the settings page.
	 */
	public function action__admin_menu() {
		add_options_page(
			__( 'Node-RED', 'node-red-wp' ),
			__( 'Node-RED', 'node-red-wp' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Link to the settings from the plugins list.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function filter__action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'node-red-wp' ) )
		);

		return $links;
	}

	/**
	 * Register the setting and its fields.
	 */
	public function action__admin_init() {
		register_setting(
			self::PAGE,
			Node_Red_WP::SETTINGS_OPTION_KEY,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Node_Red_WP::default_settings(),
			)
		);

		add_settings_section( 'nrwp_access', __( 'Access', 'node-red-wp' ), '__return_false', self::PAGE );
		add_settings_section( 'nrwp_display', __( 'Display', 'node-red-wp' ), '__return_false', self::PAGE );

		add_settings_field(
			'public_read',
			__( 'Public read access', 'node-red-wp' ),
			array( $this, 'field__checkbox' ),
			self::PAGE,
			'nrwp_access',
			array(
				'name'  => 'public_read',
				'label' => __( 'Allow anyone to read data through the REST API. Required for live updates for logged-out visitors.', 'node-red-wp' ),
			)
		);

		add_settings_field(
			'legacy_routes',
			__( 'Legacy endpoints', 'node-red-wp' ),
			array( $this, 'field__checkbox' ),
			self::PAGE,
			'nrwp_access',
			array(
				'name'  => 'legacy_routes',
				'label' => __( 'Keep the 0.x endpoints (get, set, get_keys, get_all, get_stats). Writes still require authentication.', 'node-red-wp' ),
			)
		);

		add_settings_field(
			'refresh_interval',
			__( 'Refresh interval', 'node-red-wp' ),
			array( $this, 'field__interval' ),
			self::PAGE,
			'nrwp_display'
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = wp_parse_args( (array) get_option( Node_Red_WP::SETTINGS_OPTION_KEY, array() ), Node_Red_WP::default_settings() );
		$interval = isset( $input['refresh_interval'] ) ? absint( $input['refresh_interval'] ) : $current['refresh_interval'];

		return array(
			'public_read'      => ! empty( $input['public_read'] ),
			'legacy_routes'    => ! empty( $input['legacy_routes'] ),
			'refresh_interval' => min( 600000, max( 1000, $interval ) ),
			// The token is managed by action__handle_token(), never through the form.
			'token_hash'       => isset( $input['token_hash'] ) && is_string( $input['token_hash'] ) ? $input['token_hash'] : $current['token_hash'],
		);
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field__checkbox( $args ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( Node_Red_WP::SETTINGS_OPTION_KEY ),
			esc_attr( $args['name'] ),
			checked( (bool) Node_Red_WP::get_setting( $args['name'] ), true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Render the refresh interval field.
	 */
	public function field__interval() {
		printf(
			'<input type="number" min="1000" max="600000" step="500" class="small-text" name="%1$s[refresh_interval]" value="%2$d"> %3$s',
			esc_attr( Node_Red_WP::SETTINGS_OPTION_KEY ),
			(int) Node_Red_WP::get_setting( 'refresh_interval' ),
			esc_html__( 'milliseconds between front end updates.', 'node-red-wp' )
		);
	}

	/**
	 * Generate or revoke the API token.
	 */
	public function action__handle_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'node-red-wp' ), 403 );
		}

		check_admin_referer( 'nrwp_token' );

		$settings = wp_parse_args( (array) get_option( Node_Red_WP::SETTINGS_OPTION_KEY, array() ), Node_Red_WP::default_settings() );
		$token    = '';

		if ( isset( $_POST['nrwp_generate'] ) ) {
			$token                  = wp_generate_password( 40, false );
			$settings['token_hash'] = hash( 'sha256', $token );
		} else {
			$settings['token_hash'] = '';
		}

		update_option( Node_Red_WP::SETTINGS_OPTION_KEY, $settings );

		if ( $token ) {
			// Shown exactly once on the next page load.
			set_transient( 'nrwp_new_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$new_token = get_transient( 'nrwp_new_token_' . get_current_user_id() );
		$has_token = '' !== (string) Node_Red_WP::get_setting( 'token_hash' );
		$records   = Node_Red_WP::init()->data->get_all_records();
		$endpoint  = rest_url( Node_Red_WP_REST::NAMESPACE_V1 . '/data' );

		if ( $new_token ) {
			delete_transient( 'nrwp_new_token_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Node-RED', 'node-red-wp' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'API token', 'node-red-wp' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: HTTP header name, 2: link to the user profile. */
					esc_html__( 'Node-RED can authenticate write requests by sending the token in the %1$s header. Alternatively, use an Application Password of an administrator (%2$s) with HTTP Basic authentication.', 'node-red-wp' ),
					'<code>' . esc_html( Node_Red_WP_REST::TOKEN_HEADER ) . '</code>',
					'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Users → Profile', 'node-red-wp' ) . '</a>'
				);
				?>
			</p>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-success inline">
					<p><strong><?php esc_html_e( 'Your new token. Copy it now, it will not be shown again:', 'node-red-wp' ); ?></strong></p>
					<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $new_token ); ?>" onfocus="this.select()"></p>
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="nrwp_token">
				<?php wp_nonce_field( 'nrwp_token' ); ?>
				<p>
					<?php
					echo $has_token
						? esc_html__( 'A token is active.', 'node-red-wp' )
						: esc_html__( 'No token is configured.', 'node-red-wp' );
					?>
				</p>
				<p>
					<?php submit_button( $has_token ? __( 'Regenerate token', 'node-red-wp' ) : __( 'Generate token', 'node-red-wp' ), 'secondary', 'nrwp_generate', false ); ?>
					<?php
					if ( $has_token ) {
						submit_button( __( 'Revoke token', 'node-red-wp' ), 'delete', 'nrwp_revoke', false );
					}
					?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Quick start', 'node-red-wp' ); ?></h2>
			<pre class="code" style="white-space:pre-wrap;"><?php echo esc_html( sprintf( "curl -X POST %s \\\n  -H 'Content-Type: application/json' \\\n  -H '%s: <token>' \\\n  -d '{\"temperature\": 21.5}'", $endpoint, Node_Red_WP_REST::TOKEN_HEADER ) ); ?></pre>
			<p><?php esc_html_e( 'Then add the "Node-RED Data" block, the [nodered_data key="temperature"] shortcode or the Node-RED Data widget to your site.', 'node-red-wp' ); ?></p>

			<h2><?php esc_html_e( 'Stored data', 'node-red-wp' ); ?></h2>
			<?php if ( empty( $records ) ) : ?>
				<p><?php esc_html_e( 'No data has been received yet.', 'node-red-wp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:60em;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Key', 'node-red-wp' ); ?></th>
							<th><?php esc_html_e( 'Value', 'node-red-wp' ); ?></th>
							<th><?php esc_html_e( 'Last updated', 'node-red-wp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $records as $key => $record ) : ?>
							<tr>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td><?php echo esc_html( Node_Red_WP_Data::to_display_string( $record['value'] ) ); ?></td>
								<td>
									<?php
									echo $record['updated']
										? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['updated'] ) )
										: '&mdash;';
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
