<?php

/*
 * Plugin Name: Jetpack: VIP Specific Changes
 * Plugin URI: https://github.com/Automattic/vipv2-mu-plugins/blob/master/jetpack-mandatory.php
 * Description: VIP-specific customisations to Jetpack.
 * Author: Automattic
 * Version: 1.0.2
 * License: GPL2+
 */

/** 
 * Lowest incremental sync queue size allowed on VIP - JP default is 1000, but we're bumping to 10000 to give VIPs more
 * headroom as they tend to publish more than average
 */
define( 'VIP_GO_JETPACK_SYNC_MAX_QUEUE_SIZE_LOWER_LIMIT', 10000 );

/**
 * The largest incremental sync queue size allowed - items will not get enqueued if there are already this many pending
 * 
 * The queue is stored in the option table, so if the queue gets _too_ large, site performance suffers
 */
define( 'VIP_GO_JETPACK_SYNC_MAX_QUEUE_SIZE_UPPER_LIMIT', 100000 );

/**
 * The lower bound for the incremental sync queue lag - if the oldest item has been sitting unsynced for this long,
 * new items will not be added to the queue
 * 
 * The default is 15 minutes, but VIP sites often have more busy queues and we must prevent dropping items if the sync is
 * running behind
 */
define( 'VIP_GO_JETPACK_SYNC_MAX_QUEUE_LAG_LOWER_LIMIT', 2 * HOUR_IN_SECONDS );

/**
 * The maximum incremental sync queue lag allowed - just sets a reasonable upper bound on this limit to prevent extremely
 * stale incremental sync queues
 */
define( 'VIP_GO_JETPACK_SYNC_MAX_QUEUE_LAG_UPPER_LIMIT', DAY_IN_SECONDS );

/**
 * Add the Connection Pilot. Ensures Jetpack is consistently connected.
 */
require_once( __DIR__ . '/connection-pilot/class-jetpack-connection-pilot.php' );

/**
 * Enable VIP modules required as part of the platform
 */
require_once( __DIR__ . '/jetpack-mandatory.php' );

/**
 * Remove certain modules from the list of those that can be activated
 * Blocks access to certain functionality that isn't compatible with the platform.
 */
add_filter( 'jetpack_get_available_modules', function( $modules ) {
	// The Photon service is not necessary on VIP Go since the same features are built-in.
	// Note that we do utilize some of the Photon module's code with our own Files Service.
	unset( $modules['photon'] );
	unset( $modules['photon-cdn'] );

	unset( $modules['site-icon'] );
	unset( $modules['protect'] );

	return $modules;
}, 999 );

/**
 * Lock down the jetpack_sync_settings_max_queue_size to an allowed range
 * 
 * Still allows changing the value per site, but locks it into the range
 */
add_filter( 'option_jetpack_sync_settings_max_queue_size', function( $value ) {
	$value = intval( $value );

	$value = min( $value, VIP_GO_JETPACK_SYNC_MAX_QUEUE_SIZE_UPPER_LIMIT );
	$value = max( $value, VIP_GO_JETPACK_SYNC_MAX_QUEUE_SIZE_LOWER_LIMIT );

	return $value;
}, 9999 );

/**
 * Lock down the jetpack_sync_settings_max_queue_lag to an allowed range
 * 
 * Still allows changing the value per site, but locks it into the range
 */
add_filter( 'option_jetpack_sync_settings_max_queue_lag', function( $value ) {
	$value = intval( $value );

	$value = min( $value, VIP_GO_JETPACK_SYNC_MAX_QUEUE_LAG_UPPER_LIMIT );
	$value = max( $value, VIP_GO_JETPACK_SYNC_MAX_QUEUE_LAG_LOWER_LIMIT );

	return $value;
}, 9999 );

/**
 * Allow incremental syncing via cron to take longer than the default 30 seconds.
 *
 * This will allow more items to be processed per cron event, while leaving a small buffer between completion and the start of the next event (the event interval is 5 mins).
 * 
 */
add_filter( 'option_jetpack_sync_settings_cron_sync_time_limit', function( $value ) {
	return 4 * MINUTE_IN_SECONDS;
}, 9999 );

/**
 * Reduce the time between sync batches on VIP for performance gains.
 *
 * By default, this is 10 seconds, but VIP can be more aggressive and doesn't need to wait as long (we'll still wait a small amount).
 * 
 */
add_filter( 'option_jetpack_sync_settings_sync_wait_time', function( $value ) {
	return 1;
}, 9999 );

// Prevent Jetpack version ping-pong when a sandbox has an old version of stacks
if ( true === WPCOM_SANDBOXED ) {
	add_action( 'updating_jetpack_version', function( $new_version, $old_version ) {
		// This is a brand new site with no Jetpack data
		if ( empty( $old_version ) ) {
			return;
		}

		// If we're upgrading, then it's fine. We only want to prevent accidental downgrades
		// Jetpack::maybe_set_version_option() already does this check, but other spots
		// in JP can trigger this, without the check
		if ( version_compare( $new_version, $old_version, '>' ) ) {
			return;
		}

		wp_die( sprintf( '😱😱😱 Oh no! Looks like your sandbox is trying to change the version of Jetpack (from %1$s => %2$s). This is probably not a good idea. As a precaution, we\'re killing this request to prevent potentially bad things. Please run `vip stacks update` on your sandbox before doing anything else.', $old_version, $new_version ), 400 );
	}, 0, 2 ); // No need to wait till priority 10 since we're going to die anyway
}

// On production servers, only our machine user can manage the Jetpack connection
if ( true === WPCOM_IS_VIP_ENV && is_admin() ) {
	add_filter( 'map_meta_cap', function( $caps, $cap, $user_id, $args ) {
		switch ( $cap ) {
			case 'jetpack_connect':
			case 'jetpack_reconnect':
			case 'jetpack_disconnect':
				$user = get_userdata( $user_id );
				if ( $user && WPCOM_VIP_MACHINE_USER_LOGIN !== $user->user_login ) {
					return [ 'do_not_allow' ];
				}
				break;
		}

		return $caps;
	}, 10, 4 );
}

function wpcom_vip_did_jetpack_search_query( $query ) {
	if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
		return;
	}

	global $wp_elasticsearch_queries_log;

	if ( ! isset( $wp_elasticsearch_queries_log ) || ! is_array( $wp_elasticsearch_queries_log ) ) {
		$wp_elasticsearch_queries_log = array();
	}

	$query['backtrace'] = wp_debug_backtrace_summary();

	$wp_elasticsearch_queries_log[] = $query;
}

add_action( 'did_jetpack_search_query', 'wpcom_vip_did_jetpack_search_query' );

/**
 * Decide when Jetpack's Sync Listener should be loaded.
 *
 * Sync Listener looks for events that need to be added to the sync queue. On
 * many requests, such as frontend views, we wouldn't expect there to be any DB
 * writes so there should be nothing for Jetpack to listen for.
 *
 * @param  bool $should_load Current value.
 * @return bool              Whether (true) or not (false) Listener should load.
 */
function wpcom_vip_disable_jetpack_sync_for_frontend_get_requests( $should_load ) {
	// Don't run listener for frontend, non-cron GET requests

	if ( is_admin() ) {
		return $should_load;
	}

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return $should_load;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return $should_load;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
		$should_load = false;
	}

	return $should_load;

}
add_filter( 'jetpack_sync_listener_should_load', 'wpcom_vip_disable_jetpack_sync_for_frontend_get_requests' );

/**
 * Disable Email Sharing if Recaptcha is not setup.
 *
 * To prevent spam and abuse, we should only allow sharing via e-mail when reCAPTCHA is enabled.
 *
 * @see https://jetpack.com/support/sharing/#captcha Instructions on how to set up reCAPTCHA for your site
 *
 * @param  bool $is_enabled Current value.
 * @return bool              Whether (true) or not (false) email sharing is enabled.
 */
function wpcom_vip_disable_jetpack_email_no_recaptcha( $is_enabled ) {
	if ( ! $is_enabled ) {
		return $is_enabled;
	}

	return defined( 'RECAPTCHA_PUBLIC_KEY' ) && defined( 'RECAPTCHA_PRIVATE_KEY' );
}
add_filter( 'sharing_services_email', 'wpcom_vip_disable_jetpack_email_no_recaptcha', PHP_INT_MAX );

/**
 * Enable the new Full Sync method on sites with the VIP_JETPACK_FULL_SYNC_IMMEDIATELY constant
 */
add_filter( 'jetpack_sync_modules', function( $modules ) {
	if ( ! class_exists( 'Automattic\\Jetpack\\Sync\\Modules\\Full_Sync_Immediately' ) ) {
		return $modules;
	}

	if ( defined( 'VIP_JETPACK_FULL_SYNC_IMMEDIATELY' ) && true === VIP_JETPACK_FULL_SYNC_IMMEDIATELY ) {
		foreach ( $modules as $key => $module ) {
			// Replace Jetpack_Sync_Modules_Full_Sync or Full_Sync with the new module
			if ( in_array( $module, [ 'Automattic\\Jetpack\\Sync\\Modules\\Full_Sync', 'Jetpack_Sync_Modules_Full_Sync' ], true ) ) {
				$modules[ $key ] = 'Automattic\\Jetpack\\Sync\\Modules\\Full_Sync_Immediately';
			}
		}
	}

	return $modules;
} );
