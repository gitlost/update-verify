<?php
/**
 * Provides CLI interfaces to safe upgrades.
 *
 * @package Update-Verify
 */

namespace UpdateVerify;

use WP_CLI;
use WP_Error;

/**
 * Provides CLI interfaces to safe upgrades.
 */
class CLI {

	/**
	 * Safely updates WordPress to a newer version.
	 *
	 * Performs a `wp core update` by checking site availability heuristics
	 * before and after to determine whether the update process proceeded
	 * without error. Aborts update process if errors are detected beforehand,
	 * and rolls back to the prior WP version if an error was detected afterward.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Specify the path in which to update WordPress. Defaults to current
	 * directory.
	 *
	 * [--version=<version>]
	 * : Update to a specific version, instead of to the latest version.
	 */
	public static function safe_update( $args, $assoc_args ) {

		$current_version = WP_CLI::runcommand(
			'core version', array(
				'return' => true,
			)
		);
		WP_CLI::log( 'Currently running version ' . $current_version );

		/**
		 * Bail early if any errors are detected with the site.
		 */
		WP_CLI::add_wp_hook(
			'upgrade_verify_upgrader_pre_download', function( $retval, $site_response ) {
				if ( 200 !== $site_response['status_code'] ) {
					return new WP_Error( 'upgrade_verify_fail', sprintf( 'Failed pre-update status code check (HTTP code %d).', $site_response['status_code'] ) );
				} elseif ( ! empty( $site_response['php_fatal'] ) ) {
					return new WP_Error( 'upgrade_verify_fail', 'Failed pre-update PHP fatal error check.' );
				} elseif ( empty( $site_response['closing_body'] ) ) {
					return new WP_Error( 'upgrade_verify_fail', 'Failed pre-update closing </body> tag check.' );
				}
				return $retval;
			}, 10, 2
		);

		/**
		 * Roll back to prior version if errors were detected post-update.
		 */
		WP_CLI::add_wp_hook(
			'upgrade_verify_upgrader_process_complete', function( $site_response ) use ( $current_version ) {
				$error_message = '';
				if ( 200 !== $site_response['status_code'] ) {
					$error_message = sprintf( 'Failed post-update status code check (HTTP code %d).', $site_response['status_code'] );
				} elseif ( ! empty( $site_response['php_fatal'] ) ) {
					$error_message = 'Failed post-update PHP fatal error check.';
				} elseif ( empty( $site_response['closing_body'] ) ) {
					$error_message = 'Failed post-update closing </body> tag check.';
				}
				if ( ! empty( $error_message ) ) {
					if ( method_exists( 'WP_Upgrader', 'release_lock' ) ) {
						\WP_Upgrader::release_lock( 'core_updater' );
					}
					WP_CLI::log( "Rolling WordPress back to version {$current_version}..." );
					WP_CLI::runcommand( 'core download --force --version=' . $current_version, array(
						'launch' => false,
					) );
					WP_CLI::error( $error_message );
				}
			}
		);

		$update_version = ! empty( $assoc_args['version'] ) ? ' --version=' . $assoc_args['version'] : '';
		$response       = WP_CLI::runcommand(
			'core update' . $update_version, array(
				'launch' => false,
			)
		);

	}

}
