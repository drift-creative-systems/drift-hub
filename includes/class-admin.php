<?php
/**
 * class-admin.php — the wp-admin side of the hub, for administrators.
 *
 *   Look      The Drift: Surface Hub screens (Artists, Labels, Settings and
 *             the artist edit screen) get the Drift: Surface website plugin's
 *             admin look: black header bar, white cards, pill buttons, Inter
 *             and Poppins. Scoped to body.dh-admin, so the rest of wp-admin
 *             is untouched.
 *   Home      Administrators land on the Artists list after logging in, and
 *             Dashboard → Home goes there too. The Dashboard menu stays, for
 *             Updates. Managers never see wp-admin (class-access.php).
 *   Tidy      The hub has no posts, pages or comments, so those menus go,
 *             and Screen Options is switched off across wp-admin.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Admin {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'all_admin_notices', [ __CLASS__, 'header' ], 1 );
		add_action( 'admin_init', [ __CLASS__, 'redirect_dashboard' ], 2 );
		add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 20, 3 );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 100 );
		add_action( 'admin_menu', [ __CLASS__, 'remove_menus' ], 999 );
		add_filter( 'screen_options_show_screen', '__return_false' );
	}

	/** The Artists list: the administrators' home screen. */
	public static function home_url(): string {
		return admin_url( 'edit.php?post_type=' . Drift_Hub_Artists::POST_TYPE );
	}

	/** Whether the current admin screen is one of ours (Artists, Labels, Settings, an artist). */
	private static function is_hub_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ( Drift_Hub_Artists::POST_TYPE === $screen->post_type || Drift_Hub_Artists::TAXONOMY === $screen->taxonomy );
	}

	/* ── Look ────────────────────────────────────────────────────────── */

	public static function enqueue(): void {
		if ( ! self::is_hub_screen() ) {
			return;
		}
		Drift_Hub_App::enqueue_fonts();
		wp_enqueue_style( 'drift-hub-admin', DRIFT_HUB_URL . 'assets/admin.css', [ 'drift-hub-fonts' ], DRIFT_HUB_VERSION );
	}

	public static function body_class( string $classes ): string {
		return self::is_hub_screen() ? $classes . ' dh-admin' : $classes;
	}

	/** Black header bar above the screen, with links between the hub's admin screens. */
	public static function header(): void {
		if ( ! self::is_hub_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		$type   = Drift_Hub_Artists::POST_TYPE;
		$links  = [
			'artists'  => [ 'Artists', self::home_url(), 'edit-' . $type === $screen->id || $type === $screen->id ],
			'labels'   => [ 'Labels', admin_url( 'edit-tags.php?taxonomy=' . Drift_Hub_Artists::TAXONOMY . '&post_type=' . $type ), Drift_Hub_Artists::TAXONOMY === $screen->taxonomy ],
			'settings' => [ 'Settings', admin_url( 'edit.php?post_type=' . $type . '&page=drift-hub-settings' ), str_ends_with( $screen->id, '_page_drift-hub-settings' ) ],
		];
		?>
		<header class="dh-head">
			<p class="dh-head__brand">
				<?php echo Drift_Hub_App::mark( 'dh-head__mark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
				<span class="dh-head__name" aria-hidden="true">DRIFT<small>Surface Hub</small></span>
				<span class="screen-reader-text">Drift: Surface Hub</span>
			</p>
			<nav class="dh-head__nav" aria-label="Drift: Surface Hub">
				<?php foreach ( $links as $link ) : ?>
					<a href="<?php echo esc_url( $link[1] ); ?>"<?php echo $link[2] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $link[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="dh-head__meta">
				<a class="dh-head__hub" href="<?php echo esc_url( Drift_Hub_App::url() ); ?>">Open the hub</a>
				<span class="dh-head__version">v<?php echo esc_html( DRIFT_HUB_VERSION ); ?></span>
			</div>
		</header>
		<?php
	}

	/* ── Artists list as the dashboard ───────────────────────────────── */

	/** Dashboard → Artists. Only the dashboard itself: pages hung off index.php (?page=…) still work. */
	public static function redirect_dashboard(): void {
		global $pagenow;
		if ( 'index.php' !== $pagenow || wp_doing_ajax() || isset( $_GET['page'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only.
			return;
		}
		wp_safe_redirect( self::home_url() );
		exit;
	}

	/** After logging in, administrators land on the Artists list, unless they were heading somewhere specific. */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}
		$admin = admin_url();
		if ( '' === $requested || untrailingslashit( $requested ) === untrailingslashit( $admin ) || $admin . 'index.php' === $requested ) {
			return self::home_url();
		}
		return $redirect_to;
	}

	/** Admin bar: the site menu's "Dashboard" link opens the Artists list. */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( current_user_can( 'manage_options' ) && $bar->get_node( 'dashboard' ) ) {
			$bar->add_node( [ 'id' => 'dashboard', 'title' => 'Artists', 'href' => self::home_url() ] );
		}
	}

	/* ── Tidy ────────────────────────────────────────────────────────── */

	/** Posts, Pages and Comments: unused on a hub site. Hidden from the menu only; the screens still exist. */
	public static function remove_menus(): void {
		remove_menu_page( 'edit.php' );
		remove_menu_page( 'edit.php?post_type=page' );
		remove_menu_page( 'edit-comments.php' );
	}
}
