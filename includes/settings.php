<?php
/**
 * Settings > Headless: deploy hook URL, triggers, schedule, Build now and build log.
 *
 * @package MrkWp\Headless
 */

defined( 'ABSPATH' ) || die( 'No Access!' );

add_action( 'admin_menu', 'mrkwp_headless_add_settings_page' );
add_action( 'admin_init', 'mrkwp_headless_register_settings' );
add_action( 'admin_post_mrkwp_headless_build_now', 'mrkwp_headless_handle_build_now' );

/**
 * Add Settings > Headless.
 *
 * @return void
 */
function mrkwp_headless_add_settings_page(): void {
	add_options_page(
		__( 'MRK WP Headless', 'mrkwp-headless' ),
		__( 'Headless', 'mrkwp-headless' ),
		'manage_options',
		'mrkwp-headless',
		'mrkwp_headless_render_settings_page'
	);
}

/**
 * Register the settings option.
 *
 * @return void
 */
function mrkwp_headless_register_settings(): void {
	register_setting(
		'mrkwp_headless',
		MRKWP_HEADLESS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mrkwp_headless_sanitize_settings',
			'show_in_rest'      => false,
		)
	);
}

/**
 * Sanitize submitted settings. A blank hook URL keeps the saved one.
 *
 * @param mixed $input Submitted values.
 * @return array<string, mixed>
 */
function mrkwp_headless_sanitize_settings( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$current = mrkwp_headless_get_settings();
	$clean   = array();

	$url = trim( (string) ( $input['hook_url'] ?? '' ) );
	if ( ! empty( $input['clear_hook_url'] ) ) {
		$clean['hook_url'] = '';
	} elseif ( '' === $url ) {
		$clean['hook_url'] = $current['hook_url'];
	} elseif ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && wp_http_validate_url( $url ) ) {
		$clean['hook_url'] = esc_url_raw( $url, array( 'https' ) );
	} else {
		$clean['hook_url'] = $current['hook_url'];
		add_settings_error( MRKWP_HEADLESS_OPTION, 'hook_url', __( 'The deploy hook URL must be a valid https:// URL. The previous URL was kept.', 'mrkwp-headless' ) );
	}

	$triggers          = array_map( 'sanitize_key', (array) ( $input['triggers'] ?? array() ) );
	$clean['triggers'] = array_values( array_intersect( $triggers, array_keys( mrkwp_headless_trigger_labels() ) ) );

	$post_types          = array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) );
	$clean['post_types'] = array_values( array_intersect( $post_types, mrkwp_headless_watchable_post_types() ) );

	$schedule          = sanitize_key( (string) ( $input['schedule'] ?? 'off' ) );
	$clean['schedule'] = isset( mrkwp_headless_schedule_intervals()[ $schedule ] ) ? $schedule : 'off';

	$clean['debounce'] = min( 60, max( 0, absint( $input['debounce'] ?? 5 ) ) );

	return $clean;
}

/**
 * Trigger checkbox labels.
 *
 * @return array<string, string>
 */
function mrkwp_headless_trigger_labels(): array {
	return array(
		'publish'   => __( 'New content is published', 'mrkwp-headless' ),
		'update'    => __( 'Published content is updated', 'mrkwp-headless' ),
		'unpublish' => __( 'Content is unpublished, trashed or deleted', 'mrkwp-headless' ),
		'terms'     => __( 'Categories, tags or menus change', 'mrkwp-headless' ),
	);
}

/**
 * Schedule select labels.
 *
 * @return array<string, string>
 */
function mrkwp_headless_schedule_labels(): array {
	return array(
		'off'        => __( 'Off', 'mrkwp-headless' ),
		'hourly'     => __( 'Hourly', 'mrkwp-headless' ),
		'twicedaily' => __( 'Twice daily', 'mrkwp-headless' ),
		'daily'      => __( 'Daily', 'mrkwp-headless' ),
		'weekly'     => __( 'Weekly', 'mrkwp-headless' ),
	);
}

/**
 * Run a build straight away from the Build now button.
 *
 * @return void
 */
function mrkwp_headless_handle_build_now(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'mrkwp-headless' ), 403 );
	}

	check_admin_referer( 'mrkwp_headless_build_now' );

	/* translators: %s: user display name. */
	$entry = mrkwp_headless_trigger_build( sprintf( __( 'Build now: %s', 'mrkwp-headless' ), wp_get_current_user()->display_name ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'  => 'mrkwp-headless',
				'built' => mrkwp_headless_build_succeeded( $entry ) ? 'ok' : 'failed',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Format a timestamp in the site's date and time format.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function mrkwp_headless_format_time( int $timestamp ): string {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Render Settings > Headless.
 *
 * @return void
 */
function mrkwp_headless_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings  = mrkwp_headless_get_settings();
	$name      = MRKWP_HEADLESS_OPTION;
	$log       = (array) get_option( MRKWP_HEADLESS_LOG_OPTION, array() );
	$has_url   = '' !== mrkwp_headless_get_hook_url();
	$queued    = mrkwp_headless_next_run( MRKWP_HEADLESS_QUEUED_HOOK );
	$scheduled = mrkwp_headless_next_run( MRKWP_HEADLESS_SCHEDULE_HOOK );
	$built     = isset( $_GET['built'] ) ? sanitize_key( wp_unslash( $_GET['built'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MRK WP Headless', 'mrkwp-headless' ); ?></h1>

		<?php if ( 'ok' === $built ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Build requested. Cloudflare Pages accepted the deploy hook.', 'mrkwp-headless' ); ?></p></div>
		<?php elseif ( 'failed' === $built || ( $log && ! mrkwp_headless_build_succeeded( $log[0] ) ) ) : ?>
			<div class="notice notice-error"><p>
				<?php
				printf(
					/* translators: %s: error message. */
					esc_html__( 'The last deploy hook call failed: %s', 'mrkwp-headless' ),
					esc_html( $log ? trim( $log[0]['code'] . ' ' . $log[0]['message'] ) : '' )
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'mrkwp_headless' ); ?>

			<h2><?php esc_html_e( 'Cloudflare Pages deploy hook', 'mrkwp-headless' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mrkwp-headless-hook-url"><?php esc_html_e( 'Deploy hook URL', 'mrkwp-headless' ); ?></label></th>
					<td>
						<?php if ( mrkwp_headless_hook_url_is_constant() ) : ?>
							<input type="text" id="mrkwp-headless-hook-url" class="regular-text" value="<?php esc_attr_e( 'Set by MRKWP_HEADLESS_DEPLOY_HOOK in wp-config.php', 'mrkwp-headless' ); ?>" readonly>
						<?php else : ?>
							<input type="password" id="mrkwp-headless-hook-url" name="<?php echo esc_attr( $name ); ?>[hook_url]" class="large-text" autocomplete="off"
								placeholder="<?php echo $settings['hook_url'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'mrkwp-headless' ) : 'https://api.cloudflare.com/client/v4/pages/webhooks/deploy_hooks/...'; ?>">
							<?php if ( $settings['hook_url'] ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_hook_url]" value="1"> <?php esc_html_e( 'Remove the saved URL', 'mrkwp-headless' ); ?></label></p>
							<?php endif; ?>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Cloudflare dashboard > Workers & Pages > your project > Settings > Builds > Deploy hooks. Keep it secret: anyone with this URL can start a build. To keep it out of the database, define MRKWP_HEADLESS_DEPLOY_HOOK in wp-config.php instead.', 'mrkwp-headless' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Rebuild when', 'mrkwp-headless' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Content changes', 'mrkwp-headless' ); ?></th>
					<td><fieldset>
						<?php foreach ( mrkwp_headless_trigger_labels() as $key => $label ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[triggers][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['triggers'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'mrkwp-headless' ); ?></th>
					<td><fieldset>
						<?php foreach ( mrkwp_headless_watchable_post_types() as $type ) : ?>
							<?php $object = get_post_type_object( $type ); ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['post_types'], true ) ); ?>> <?php echo esc_html( $object ? $object->labels->name : $type ); ?> <code><?php echo esc_html( $type ); ?></code></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><label for="mrkwp-headless-debounce"><?php esc_html_e( 'Wait before building', 'mrkwp-headless' ); ?></label></th>
					<td>
						<input type="number" id="mrkwp-headless-debounce" name="<?php echo esc_attr( $name ); ?>[debounce]" value="<?php echo esc_attr( (string) $settings['debounce'] ); ?>" min="0" max="60" class="small-text"> <?php esc_html_e( 'minutes', 'mrkwp-headless' ); ?>
						<p class="description"><?php esc_html_e( 'Changes made within this window are sent as one build, which saves Cloudflare build minutes.', 'mrkwp-headless' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mrkwp-headless-schedule"><?php esc_html_e( 'Schedule', 'mrkwp-headless' ); ?></label></th>
					<td>
						<select id="mrkwp-headless-schedule" name="<?php echo esc_attr( $name ); ?>[schedule]">
							<?php foreach ( mrkwp_headless_schedule_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['schedule'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							if ( $scheduled ) {
								/* translators: %s: date and time. */
								printf( esc_html__( 'Next scheduled build: %s.', 'mrkwp-headless' ), esc_html( mrkwp_headless_format_time( $scheduled ) ) );
								echo ' ';
							}
							esc_html_e( 'Scheduled and queued builds run on WP-Cron, which needs site traffic. On a quiet site, call wp-cron.php from a server cron every 5 minutes.', 'mrkwp-headless' );
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Build now', 'mrkwp-headless' ); ?></h2>
		<?php if ( $queued ) : ?>
			<p>
				<?php
				/* translators: %s: date and time. */
				printf( esc_html__( 'A build is queued for %s.', 'mrkwp-headless' ), esc_html( mrkwp_headless_format_time( $queued ) ) );
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mrkwp_headless_build_now">
			<?php wp_nonce_field( 'mrkwp_headless_build_now' ); ?>
			<?php submit_button( __( 'Build now', 'mrkwp-headless' ), 'secondary', 'submit', false, $has_url ? array() : array( 'disabled' => 'disabled' ) ); ?>
			<?php if ( ! $has_url ) : ?>
				<span class="description"><?php esc_html_e( 'Save a deploy hook URL first.', 'mrkwp-headless' ); ?></span>
			<?php endif; ?>
		</form>

		<h2><?php esc_html_e( 'Recent builds', 'mrkwp-headless' ); ?></h2>
		<?php if ( ! $log ) : ?>
			<p><?php esc_html_e( 'No builds yet.', 'mrkwp-headless' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'mrkwp-headless' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'mrkwp-headless' ); ?></th>
						<th><?php esc_html_e( 'Response', 'mrkwp-headless' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( mrkwp_headless_format_time( (int) $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $entry['reason'] ); ?></td>
							<td>
								<?php if ( mrkwp_headless_build_succeeded( $entry ) ) : ?>
									<span style="color:#008a20;">&#10003;</span>
								<?php else : ?>
									<span style="color:#d63638;">&#10007;</span>
								<?php endif; ?>
								<?php echo esc_html( trim( ( $entry['code'] ? $entry['code'] . ' ' : '' ) . $entry['message'] ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
