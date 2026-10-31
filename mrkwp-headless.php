<?php
/**
 * Plugin Name:     MRK WP Headless
 * Plugin URI:      https://mrkwp.com
 * Description:     Sends all front-end traffic to the login screen, except REST API calls and FluentCRM webhooks, tracking links and email signup confirmations.
 * Author:          MRK WP
 * Author URI:      https://mrkwp.com
 * Text Domain:     mrkwp-headless
 * Version:         1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 *
 * @package MrkWp\Headless
 */

defined( 'ABSPATH' ) || die( 'No Access!' );

/*
 * template_redirect only fires for front-end template loads, so wp-admin,
 * wp-login.php, the REST API (/wp-json/), admin-ajax.php, admin-post.php,
 * wp-cron.php and xmlrpc.php never reach this and keep working untouched.
 */
add_action( 'template_redirect', 'mrkwp_headless_maybe_redirect', 0 );

/**
 * Redirect guests hitting the front end to the login page.
 *
 * @return void
 */
function mrkwp_headless_maybe_redirect(): void {
	if ( is_user_logged_in() || mrkwp_headless_is_allowed_request() ) {
		return;
	}

	wp_safe_redirect( wp_login_url(), 302, 'MRK WP Headless' );
	exit;
}

/**
 * Whether the current front-end request must stay publicly reachable.
 *
 * @return bool
 */
function mrkwp_headless_is_allowed_request(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing checks.

	// FluentCRM public routes: ?fluentcrm=1&route=confirmation|contact|webhook|open|unsubscribe|manage_subscription|bounce_handler|...
	$crm_param = defined( 'FLUENTCRM_EXTERNAL_URL_PARAM' ) ? FLUENTCRM_EXTERNAL_URL_PARAM : 'fluentcrm';
	if ( isset( $_GET[ $crm_param ] ) ) {
		return true;
	}

	// FluentCRM click-tracking short links: ?ns_url=xxxx.
	if ( isset( $_GET['ns_url'] ) ) {
		return true;
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( is_robots() || is_favicon() ) {
		return true;
	}

	if ( mrkwp_headless_is_fluentcrm_landing_page() ) {
		return true;
	}

	/**
	 * Filter whether a front-end request bypasses the login redirect.
	 *
	 * @param bool $allowed Whether the request is allowed through.
	 */
	return (bool) apply_filters( 'mrkwp_headless/allow_request', false );
}

/**
 * Whether this request is a page FluentCRM sends contacts to: the double
 * opt-in / unsubscribe redirect URLs, or a page with the preferences shortcode.
 *
 * @return bool
 */
function mrkwp_headless_is_fluentcrm_landing_page(): bool {
	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'fluentcrm_pref' ) ) {
			return true;
		}
	}

	$helper = '\FluentCrm\App\Services\Helper';
	if ( ! class_exists( $helper ) ) {
		return false;
	}

	$urls = array();

	$optin = (array) $helper::getDoubleOptinSettings();
	if ( 'redirect' === ( $optin['after_confirmation_type'] ?? '' ) ) {
		$urls[] = $optin['after_conf_redirect_url'] ?? '';
	}

	$email  = (array) $helper::getGlobalEmailSettings();
	$urls[] = $email['unsubscribe_redirect'] ?? '';

	$current = mrkwp_headless_normalise_path( home_url( add_query_arg( array() ) ) );

	foreach ( array_filter( $urls ) as $url ) {
		if ( mrkwp_headless_normalise_path( $url ) === $current ) {
			return true;
		}
	}

	return false;
}

/**
 * Reduce a URL to its host + path for comparison, ignoring query and trailing slash.
 *
 * @param string $url URL to normalise.
 * @return string
 */
function mrkwp_headless_normalise_path( string $url ): string {
	$parts = wp_parse_url( $url );
	$host  = strtolower( $parts['host'] ?? '' );
	$path  = untrailingslashit( $parts['path'] ?? '' );

	return $host . $path;
}
