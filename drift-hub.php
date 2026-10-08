<?php
/**
 * Plugin Name:       Drift: Surface Hub
 * Plugin URI:        https://github.com/drift-creative-systems/drift-hub
 * Update URI:        https://github.com/drift-creative-systems/drift-hub
 * Description:       Drift: Surface Hub — labels, managers and artists log in at /hub/ to manage every artist's gigs, releases, photos and more in one place. Artist websites (Drift: Surface plugin) sync their content from the hub's API.
 * Version:           1.5.2
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Drift Creative Systems / The Bonsai Digital Collective
 * License:           GPL-2.0-or-later
 * Text Domain:       drift-hub
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

define( 'DRIFT_HUB_VERSION', '1.5.2' );
define( 'DRIFT_HUB_FILE', __FILE__ );
define( 'DRIFT_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRIFT_HUB_URL', plugin_dir_url( __FILE__ ) );
define( 'DRIFT_HUB_REPO', 'https://github.com/drift-creative-systems/drift-hub' );

/*
|--------------------------------------------------------------------------
| Self-updates from GitHub releases
|--------------------------------------------------------------------------
| WordPress checks the repo's latest release and offers the attached
| drift-hub.zip under Dashboard → Updates, like any other plugin.
|
| Plugin Update Checker is bundled directly (lib/), not via Composer, so two
| plugins shipping it can never collide on Composer's autoloader class. PUC's
| own loader is safe to include from multiple plugins.
|
| Private repo? Define DRIFT_HUB_GITHUB_TOKEN in wp-config.php with a
| fine-grained, read-only token for drift-creative-systems/drift-hub.
*/
$drift_hub_puc = DRIFT_HUB_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $drift_hub_puc ) ) {
	require_once $drift_hub_puc;

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$drift_hub_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			DRIFT_HUB_REPO,
			__FILE__,
			'drift-hub',
			6 // Hours between update checks.
		);
		$drift_hub_updater->setBranch( 'main' );
		$drift_hub_updater->getVcsApi()->enableReleaseAssets();

		if ( defined( 'DRIFT_HUB_GITHUB_TOKEN' ) && DRIFT_HUB_GITHUB_TOKEN ) {
			$drift_hub_updater->setAuthentication( DRIFT_HUB_GITHUB_TOKEN );
		}
	}
}
unset( $drift_hub_puc, $drift_hub_updater );

foreach ( [ 'schema', 'store', 'access', 'media', 'artists', 'api', 'app-api', 'app', 'admin', 'publish' ] as $drift_hub_file ) {
	require_once DRIFT_HUB_DIR . 'includes/class-' . $drift_hub_file . '.php';
}
unset( $drift_hub_file );

add_action( 'plugins_loaded', static function () {
	Drift_Hub_Store::maybe_upgrade();
	if ( get_option( 'drift_hub_version' ) !== DRIFT_HUB_VERSION ) {
		Drift_Hub_Access::add_roles();
		update_option( 'drift_hub_version', DRIFT_HUB_VERSION, false );
	}
	Drift_Hub_Access::init();
	Drift_Hub_Media::init();
	Drift_Hub_Artists::init();
	Drift_Hub_Api::init();
	Drift_Hub_App_Api::init();
	Drift_Hub_App::init();
	Drift_Hub_Admin::init();
	Drift_Hub_Publish::init();
} );

register_activation_hook( __FILE__, static function () {
	Drift_Hub_Store::install();
	Drift_Hub_Access::add_roles();
	Drift_Hub_Artists::register();
	Drift_Hub_App::add_rewrite();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules();
} );
