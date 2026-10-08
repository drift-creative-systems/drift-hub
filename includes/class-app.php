<?php
/**
 * class-app.php — the hub itself, at /hub/.
 *
 * A standalone page (no theme): logged-out visitors go to the login screen
 * and come back; logged-in users see their roster and edit artists. The
 * screens are drawn by assets/hub.js from the schema, talking to
 * class-app-api.php. Uses the WordPress media library for uploads.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_App {

	const QUERY_VAR   = 'drift_hub';
	const OPTION_ROOT = 'drift_hub_at_root';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars', static fn( $vars ) => array_merge( $vars, [ self::QUERY_VAR ] ) );
		add_action( 'template_redirect', [ __CLASS__, 'render' ], 0 );
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_style' ] );
		add_filter( 'login_headerurl', static fn() => self::url() );
		add_filter( 'wp_sitemaps_enabled', static fn( $on ) => self::at_root() ? false : $on );
		add_action( 'admin_init', [ __CLASS__, 'register_setting' ] );
		add_action( 'admin_menu', [ __CLASS__, 'settings_menu' ] );
	}

	/**
	 * Whether the hub is the whole site (e.g. https://surface.driftcreativesystems.co.uk/)
	 * rather than living at /hub/. The DRIFT_HUB_AT_ROOT constant in wp-config.php wins
	 * over the setting.
	 */
	public static function at_root(): bool {
		return defined( 'DRIFT_HUB_AT_ROOT' ) ? (bool) DRIFT_HUB_AT_ROOT : (bool) get_option( self::OPTION_ROOT, false );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^hub/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/** Hub URL, optionally straight to one artist. */
	public static function url( int $artist_id = 0 ): string {
		if ( self::at_root() ) {
			$base = home_url( '/' );
		} else {
			$base = get_option( 'permalink_structure' ) ? home_url( '/hub/' ) : add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
		}
		return $artist_id ? $base . '#/artist/' . $artist_id : $base;
	}

	public static function render(): void {
		$hub_path = (bool) get_query_var( self::QUERY_VAR );

		if ( self::at_root() ) {
			if ( is_robots() || is_favicon() ) {
				return;
			}
			if ( $hub_path ) { // Old /hub/ links and bookmarks.
				wp_safe_redirect( home_url( '/' ), 301 );
				exit;
			}
			if ( ! is_front_page() && ! is_home() ) { // Nothing else on this site is public.
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
		} elseif ( ! $hub_path ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		$allowed = Drift_Hub_Access::is_admin_user() || Drift_Hub_Access::is_member();
		if ( $allowed ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'drift-hub-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'drift-hub', DRIFT_HUB_URL . 'assets/hub.css', [ 'drift-hub-fonts' ], DRIFT_HUB_VERSION );
		wp_enqueue_script( 'drift-hub', DRIFT_HUB_URL . 'assets/hub.js', $allowed ? [ 'media-editor' ] : [], DRIFT_HUB_VERSION, true );
		wp_localize_script( 'drift-hub', 'DriftHub', [
			'api'      => esc_url_raw( rest_url( Drift_Hub_App_Api::NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'logout'   => wp_logout_url( self::url() ),
			'admin'    => Drift_Hub_Access::is_admin_user() ? admin_url( 'edit.php?post_type=' . Drift_Hub_Artists::POST_TYPE ) : '',
			'allowed'  => $allowed,
		] );

		$mark = '<svg class="dh-mark" viewBox="80 40 180 160" aria-hidden="true" focusable="false"><path fill="currentColor" d="M80 40H180C220 40 260 80 260 120C260 160 220 200 180 200H80L130 150H180C196 150 210 136 210 120C210 104 196 90 180 90H80V40Z"/><path fill="currentColor" d="M90 170L150 110H210L150 170H90Z"/></svg>';
		?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Drift: Surface Hub</title>
<?php wp_print_styles(); ?>
<?php wp_print_head_scripts(); ?>
</head>
<body class="dh-body">
<a class="dh-skip" href="#dh-main">Skip to content</a>
<header class="dh-top">
	<a class="dh-brand" href="#/" aria-label="Drift: Surface Hub — all artists"><?php echo $mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span>DRIFT<small>Surface Hub</small></span></a>
	<div class="dh-top__right">
		<a class="dh-user" href="#/account" title="Your account"><?php echo esc_html( wp_get_current_user()->display_name ); ?></a>
		<a class="dh-link" href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">Log out</a>
	</div>
</header>
<main id="dh-main" class="dh-main" tabindex="-1">
	<?php if ( ! $allowed ) : ?>
		<div class="dh-empty"><h1>No access yet</h1><p>Your account isn't connected to any artists. Ask your Drift contact to give you access.</p></div>
	<?php else : ?>
		<div class="dh-loading" aria-live="polite">Loading…</div>
	<?php endif; ?>
</main>
<div class="dh-toasts" aria-live="polite" aria-atomic="false"></div>
<?php
		wp_print_footer_scripts();
		if ( $allowed && function_exists( 'wp_print_media_templates' ) ) {
			wp_print_media_templates();
		}
		?>
</body>
</html>
		<?php
		exit;
	}

	/* ── Settings ─────────────────────────────────────────────────────── */

	public static function register_setting(): void {
		register_setting( 'drift_hub_settings', self::OPTION_ROOT, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
	}

	public static function settings_menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Drift_Hub_Artists::POST_TYPE, 'Drift: Surface Hub settings', 'Settings', 'manage_options', 'drift-hub-settings', [ __CLASS__, 'settings_page' ] );
	}

	public static function settings_page(): void {
		$locked = defined( 'DRIFT_HUB_AT_ROOT' );
		?>
		<div class="wrap">
			<h1>Drift: Surface Hub settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'drift_hub_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Hub address</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_ROOT ); ?>" value="1" <?php checked( self::at_root() ); ?> <?php disabled( $locked ); ?>> Make the hub the whole site</label>
							<p class="description">
								On: the hub opens at <code><?php echo esc_html( home_url( '/' ) ); ?></code>, <code>/hub/</code> redirects there, and every other front-end page goes to the hub too. Use this on a dedicated subdomain such as <code>surface.driftcreativesystems.co.uk</code>.<br>
								Off: the hub lives at <code><?php echo esc_html( get_option( 'permalink_structure' ) ? home_url( '/hub/' ) : add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) ) ); ?></code>.
							</p>
							<?php if ( $locked ) : ?>
								<p class="description"><strong>Set by <code>DRIFT_HUB_AT_ROOT</code> in wp-config.php.</strong></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Current hub link</th>
						<td><a href="<?php echo esc_url( self::url() ); ?>"><?php echo esc_html( self::url() ); ?></a></td>
					</tr>
					<tr>
						<th scope="row">Data source URL for websites</th>
						<td><code><?php echo esc_html( rest_url( Drift_Hub_Api::NAMESPACE . '/' ) ); ?></code><p class="description">Paste into Encore Website → Connection → Data source on each artist site. Not affected by the setting above.</p></td>
					</tr>
				</table>
				<?php if ( ! $locked ) { submit_button(); } ?>
			</form>
		</div>
		<?php
	}

	/** Drift look for the login screen hub users see. */
	public static function login_style(): void {
		?>
		<style>
			body.login{background:#000;font-family:Inter,system-ui,sans-serif}
			.login h1 a{background:none!important;text-indent:0!important;width:auto!important;height:auto!important;font:600 28px/1 Poppins,system-ui,sans-serif;letter-spacing:.16em;color:#fff!important}
			.login h1 a::after{content:"SURFACE HUB";display:block;margin-top:6px;font:500 11px Inter,sans-serif;letter-spacing:.32em;color:#7a7f87}
			.login form{border:0;border-radius:14px}
			.login .button-primary{background:#000!important;border-color:#000!important;border-radius:999px!important}
			.login #nav a,.login #backtoblog a{color:#c9ccd1!important}
		</style>
		<?php
	}
}
