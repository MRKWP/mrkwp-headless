<?php
/**
 * Cloudflare Pages deploy hook: queues, schedules and sends rebuild requests.
 *
 * Content events never call the hook directly. They queue one build, run by
 * Action Scheduler (or WP-Cron) after the debounce window, so a burst of saves
 * becomes a single Cloudflare build.
 *
 * @package MrkWp\Headless
 */

defined( 'ABSPATH' ) || die( 'No Access!' );

const MRKWP_HEADLESS_OPTION         = 'mrkwp_headless_settings';
const MRKWP_HEADLESS_LOG_OPTION     = 'mrkwp_headless_build_log';
const MRKWP_HEADLESS_PENDING_OPTION = 'mrkwp_headless_pending_reasons';
const MRKWP_HEADLESS_QUEUED_HOOK    = 'mrkwp_headless_run_queued_build';
const MRKWP_HEADLESS_SCHEDULE_HOOK  = 'mrkwp_headless_run_scheduled_build';
const MRKWP_HEADLESS_GROUP          = 'mrkwp-headless';
const MRKWP_HEADLESS_LOG_LIMIT      = 20;

add_action( MRKWP_HEADLESS_QUEUED_HOOK, 'mrkwp_headless_run_queued_build' );
add_action( MRKWP_HEADLESS_SCHEDULE_HOOK, 'mrkwp_headless_run_scheduled_build' );
add_action( 'transition_post_status', 'mrkwp_headless_on_post_transition', 10, 3 );
add_action( 'before_delete_post', 'mrkwp_headless_on_post_delete', 10, 2 );
add_action( 'created_term', 'mrkwp_headless_on_term_change', 10, 3 );
add_action( 'edited_term', 'mrkwp_headless_on_term_change', 10, 3 );
add_action( 'delete_term', 'mrkwp_headless_on_term_change', 10, 3 );
add_action( 'wp_update_nav_menu', 'mrkwp_headless_on_menu_change' );
add_action( 'add_option_' . MRKWP_HEADLESS_OPTION, 'mrkwp_headless_sync_schedule' );
add_action( 'update_option_' . MRKWP_HEADLESS_OPTION, 'mrkwp_headless_sync_schedule' );

/**
 * Seconds between scheduled builds, keyed by schedule setting.
 *
 * @return array<string, int>
 */
function mrkwp_headless_schedule_intervals(): array {
	return array(
		'hourly'     => HOUR_IN_SECONDS,
		'twicedaily' => 12 * HOUR_IN_SECONDS,
		'daily'      => DAY_IN_SECONDS,
		'weekly'     => WEEK_IN_SECONDS,
	);
}

/**
 * Default settings.
 *
 * @return array<string, mixed>
 */
function mrkwp_headless_default_settings(): array {
	return array(
		'hook_url'   => '',
		'triggers'   => array( 'publish', 'update', 'unpublish' ),
		'post_types' => array_values( mrkwp_headless_watchable_post_types() ),
		'schedule'   => 'off',
		'debounce'   => 5,
	);
}

/**
 * Saved settings merged over the defaults.
 *
 * @return array<string, mixed>
 */
function mrkwp_headless_get_settings(): array {
	$saved = get_option( MRKWP_HEADLESS_OPTION, array() );

	return array_merge( mrkwp_headless_default_settings(), is_array( $saved ) ? $saved : array() );
}

/**
 * Public post types that can trigger a build.
 *
 * @return array<string, string> Post type name => post type name.
 */
function mrkwp_headless_watchable_post_types(): array {
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );

	return $types;
}

/**
 * Whether the deploy hook URL is set in wp-config.php.
 *
 * @return bool
 */
function mrkwp_headless_hook_url_is_constant(): bool {
	return defined( 'MRKWP_HEADLESS_DEPLOY_HOOK' ) && '' !== (string) MRKWP_HEADLESS_DEPLOY_HOOK;
}

/**
 * The deploy hook URL; the wp-config.php constant wins over the saved setting.
 *
 * @return string
 */
function mrkwp_headless_get_hook_url(): string {
	if ( mrkwp_headless_hook_url_is_constant() ) {
		return (string) MRKWP_HEADLESS_DEPLOY_HOOK;
	}

	return (string) mrkwp_headless_get_settings()['hook_url'];
}

/**
 * Whether a trigger is switched on.
 *
 * @param string $trigger Trigger key: publish, update, unpublish or terms.
 * @return bool
 */
function mrkwp_headless_trigger_enabled( string $trigger ): bool {
	return in_array( $trigger, (array) mrkwp_headless_get_settings()['triggers'], true );
}

/**
 * Queue a post status change as a build.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function mrkwp_headless_on_post_transition( $new_status, $old_status, $post ): void {
	if ( ! mrkwp_headless_watches_post( $post ) ) {
		return;
	}

	$title = get_the_title( $post );

	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		$trigger = 'publish';
		/* translators: %s: post title. */
		$reason = sprintf( __( 'Published: %s', 'mrkwp-headless' ), $title );
	} elseif ( 'publish' === $new_status ) {
		$trigger = 'update';
		/* translators: %s: post title. */
		$reason = sprintf( __( 'Updated: %s', 'mrkwp-headless' ), $title );
	} elseif ( 'publish' === $old_status ) {
		$trigger = 'unpublish';
		/* translators: %s: post title. */
		$reason = sprintf( __( 'Unpublished: %s', 'mrkwp-headless' ), $title );
	} else {
		return;
	}

	if ( mrkwp_headless_trigger_enabled( $trigger ) ) {
		mrkwp_headless_queue_build( $reason );
	}
}

/**
 * Queue a build when a published post is deleted without going through the trash.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function mrkwp_headless_on_post_delete( $post_id, $post ): void {
	if ( 'publish' !== $post->post_status || ! mrkwp_headless_watches_post( $post ) ) {
		return;
	}

	if ( mrkwp_headless_trigger_enabled( 'unpublish' ) ) {
		/* translators: %s: post title. */
		mrkwp_headless_queue_build( sprintf( __( 'Deleted: %s', 'mrkwp-headless' ), get_the_title( $post ) ) );
	}
}

/**
 * Whether a post's changes should trigger a build.
 *
 * @param WP_Post $post Post object.
 * @return bool
 */
function mrkwp_headless_watches_post( $post ): bool {
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return false;
	}

	return in_array( $post->post_type, (array) mrkwp_headless_get_settings()['post_types'], true );
}

/**
 * Queue a build when a public taxonomy term changes.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function mrkwp_headless_on_term_change( $term_id, $tt_id, $taxonomy ): void {
	if ( ! mrkwp_headless_trigger_enabled( 'terms' ) || 'nav_menu' === $taxonomy || ! is_taxonomy_viewable( $taxonomy ) ) {
		return;
	}

	/* translators: %s: taxonomy slug. */
	mrkwp_headless_queue_build( sprintf( __( 'Terms changed: %s', 'mrkwp-headless' ), $taxonomy ) );
}

/**
 * Queue a build when a navigation menu is saved.
 *
 * @return void
 */
function mrkwp_headless_on_menu_change(): void {
	if ( mrkwp_headless_trigger_enabled( 'terms' ) ) {
		mrkwp_headless_queue_build( __( 'Menu updated', 'mrkwp-headless' ) );
	}
}

/**
 * Queue one build after the debounce window. Events arriving while a build is
 * already queued add their reason to it instead of queueing another.
 *
 * @param string $reason Why the build is needed.
 * @return void
 */
function mrkwp_headless_queue_build( string $reason ): void {
	if ( '' === mrkwp_headless_get_hook_url() ) {
		return;
	}

	$reasons = (array) get_option( MRKWP_HEADLESS_PENDING_OPTION, array() );
	if ( ! in_array( $reason, $reasons, true ) ) {
		$reasons[] = $reason;
		update_option( MRKWP_HEADLESS_PENDING_OPTION, $reasons, false );
	}

	if ( mrkwp_headless_next_run( MRKWP_HEADLESS_QUEUED_HOOK ) ) {
		return;
	}

	$delay = max( 0, (int) mrkwp_headless_get_settings()['debounce'] ) * MINUTE_IN_SECONDS;

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + $delay, MRKWP_HEADLESS_QUEUED_HOOK, array(), MRKWP_HEADLESS_GROUP );
	} else {
		wp_schedule_single_event( time() + $delay, MRKWP_HEADLESS_QUEUED_HOOK );
	}
}

/**
 * Send the queued build with every reason collected since it was queued.
 *
 * @return void
 */
function mrkwp_headless_run_queued_build(): void {
	$reasons = (array) get_option( MRKWP_HEADLESS_PENDING_OPTION, array() );
	delete_option( MRKWP_HEADLESS_PENDING_OPTION );

	$reason = implode( '; ', array_slice( $reasons, 0, 5 ) );
	if ( count( $reasons ) > 5 ) {
		/* translators: %d: number of further changes. */
		$reason .= sprintf( __( '; +%d more', 'mrkwp-headless' ), count( $reasons ) - 5 );
	}

	mrkwp_headless_trigger_build( '' !== $reason ? $reason : __( 'Content changed', 'mrkwp-headless' ) );
}

/**
 * Send the scheduled build.
 *
 * @return void
 */
function mrkwp_headless_run_scheduled_build(): void {
	mrkwp_headless_trigger_build( __( 'Schedule', 'mrkwp-headless' ) );
}

/**
 * POST to the deploy hook now and log the result.
 *
 * @param string $reason Why the build was requested.
 * @return array{time: int, reason: string, code: int, message: string} Log entry.
 */
function mrkwp_headless_trigger_build( string $reason ): array {
	$url = mrkwp_headless_get_hook_url();

	if ( '' === $url ) {
		$code    = 0;
		$message = __( 'No deploy hook URL set.', 'mrkwp-headless' );
	} else {
		$response = wp_remote_post( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			$code    = 0;
			$message = $response->get_error_message();
		} else {
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$message = (string) wp_remote_retrieve_response_message( $response );
		}
	}

	$entry = array(
		'time'    => time(),
		'reason'  => $reason,
		'code'    => $code,
		'message' => $message,
	);

	$log = (array) get_option( MRKWP_HEADLESS_LOG_OPTION, array() );
	array_unshift( $log, $entry );
	update_option( MRKWP_HEADLESS_LOG_OPTION, array_slice( $log, 0, MRKWP_HEADLESS_LOG_LIMIT ), false );

	return $entry;
}

/**
 * Whether a log entry is a successful hook call.
 *
 * @param array $entry Log entry.
 * @return bool
 */
function mrkwp_headless_build_succeeded( array $entry ): bool {
	return $entry['code'] >= 200 && $entry['code'] < 300;
}

/**
 * Next run time for one of the plugin's hooks.
 *
 * @param string $hook Hook name.
 * @return int|false Timestamp, or false if nothing is scheduled.
 */
function mrkwp_headless_next_run( string $hook ) {
	if ( function_exists( 'as_next_scheduled_action' ) ) {
		$next = as_next_scheduled_action( $hook, array(), MRKWP_HEADLESS_GROUP );

		// true means the action is running right now.
		if ( false !== $next ) {
			return true === $next ? time() : (int) $next;
		}
	}

	return wp_next_scheduled( $hook );
}

/**
 * Replace the recurring build to match the schedule setting.
 *
 * @return void
 */
function mrkwp_headless_sync_schedule(): void {
	mrkwp_headless_unschedule( MRKWP_HEADLESS_SCHEDULE_HOOK );

	$intervals = mrkwp_headless_schedule_intervals();
	$schedule  = (string) mrkwp_headless_get_settings()['schedule'];

	if ( ! isset( $intervals[ $schedule ] ) ) {
		return;
	}

	$first = time() + $intervals[ $schedule ];

	if ( function_exists( 'as_schedule_recurring_action' ) ) {
		as_schedule_recurring_action( $first, $intervals[ $schedule ], MRKWP_HEADLESS_SCHEDULE_HOOK, array(), MRKWP_HEADLESS_GROUP );
	} else {
		wp_schedule_event( $first, $schedule, MRKWP_HEADLESS_SCHEDULE_HOOK );
	}
}

/**
 * Remove every pending run of a hook from Action Scheduler and WP-Cron.
 *
 * @param string $hook Hook name.
 * @return void
 */
function mrkwp_headless_unschedule( string $hook ): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $hook, array(), MRKWP_HEADLESS_GROUP );
	}

	wp_clear_scheduled_hook( $hook );
}

/**
 * Remove all queued and recurring builds. Runs on plugin deactivation.
 *
 * @return void
 */
function mrkwp_headless_unschedule_all(): void {
	mrkwp_headless_unschedule( MRKWP_HEADLESS_QUEUED_HOOK );
	mrkwp_headless_unschedule( MRKWP_HEADLESS_SCHEDULE_HOOK );
	delete_option( MRKWP_HEADLESS_PENDING_OPTION );
}
