<?php
/**
 * class-artists.php — artists and labels (wp-admin, for the agency).
 *
 * Each artist is a drift_artist post. Its "Website connection" box holds
 * everything needed to point their Surface website at the hub:
 *   Base ID   'app…' — goes in the website's Base ID field
 *   Token     'hub_…' — shown once when generated; stored as a hash
 *   Hub URL   the API address — goes in the website's Data source field
 * plus the website's URL and publish secret, so the hub's Publish button can
 * tell the website to sync.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Artists {

	const POST_TYPE    = 'drift_artist';
	const TAXONOMY     = 'drift_label';
	const META_BASE    = '_drift_hub_base_id';
	const META_TOKEN   = '_drift_hub_token_hash';
	const META_SITE    = '_drift_hub_site_url';
	const META_SECRET  = '_drift_hub_site_secret';
	const NEW_TOKEN    = 'drift_hub_new_token_';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ] );
		add_action( 'admin_post_drift_hub_token', [ __CLASS__, 'handle_token' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'        => [ 'name' => 'Artists', 'singular_name' => 'Artist', 'add_new_item' => 'Add artist', 'edit_item' => 'Edit artist', 'all_items' => 'All artists', 'menu_name' => 'Drift: Surface Hub' ],
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-album',
			'menu_position' => 3,
			'supports'      => [ 'title' ],
			'capability_type' => 'post',
			'map_meta_cap'  => true,
			'capabilities'  => [ 'create_posts' => 'manage_options', 'edit_posts' => 'manage_options', 'edit_others_posts' => 'manage_options', 'delete_posts' => 'manage_options', 'publish_posts' => 'manage_options' ],
		] );
		register_taxonomy( self::TAXONOMY, self::POST_TYPE, [
			'labels'            => [ 'name' => 'Labels', 'singular_name' => 'Label', 'add_new_item' => 'Add label', 'menu_name' => 'Labels' ],
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'hierarchical'      => true,
			'capabilities'      => [ 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'manage_options' ],
		] );
	}

	/* ── IDs and tokens ──────────────────────────────────────────────── */

	public static function base_id( int $artist_id ): string {
		$id = (string) get_post_meta( $artist_id, self::META_BASE, true );
		if ( ! preg_match( '/^app[A-Za-z0-9]{14}$/', $id ) ) {
			$id = 'app' . substr( str_replace( [ '+', '/', '=' ], '', base64_encode( random_bytes( 24 ) ) ), 0, 14 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			update_post_meta( $artist_id, self::META_BASE, $id );
		}
		return $id;
	}

	public static function by_base( string $base_id ): ?int {
		if ( ! preg_match( '/^app[A-Za-z0-9]{14}$/', $base_id ) ) {
			return null;
		}
		$ids = get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'meta_key' => self::META_BASE, 'meta_value' => $base_id, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : null;
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	public static function new_token( int $artist_id ): string {
		$token = 'hub_' . wp_generate_password( 40, false, false );
		update_post_meta( $artist_id, self::META_TOKEN, self::hash( $token ) );
		return $token;
	}

	public static function check_token( int $artist_id, string $token ): bool {
		$hash = (string) get_post_meta( $artist_id, self::META_TOKEN, true );
		return '' !== $hash && '' !== $token && hash_equals( $hash, self::hash( $token ) );
	}

	public static function api_url(): string {
		return rest_url( Drift_Hub_Api::NAMESPACE . '/' );
	}

	/* ── Admin screen ────────────────────────────────────────────────── */

	public static function meta_boxes( WP_Post $post ): void {
		add_meta_box( 'drift_hub_connection', 'Website connection', [ __CLASS__, 'box_connection' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'drift_hub_content', 'Content', [ __CLASS__, 'box_content' ], self::POST_TYPE, 'side' );
	}

	public static function box_connection( WP_Post $post ): void {
		wp_nonce_field( 'drift_hub_artist', 'drift_hub_artist_nonce' );
		$saved    = 'publish' === $post->post_status;
		$token    = get_transient( self::NEW_TOKEN . $post->ID . '_' . get_current_user_id() );
		$has      = (bool) get_post_meta( $post->ID, self::META_TOKEN, true );
		$site     = (string) get_post_meta( $post->ID, self::META_SITE, true );
		$secret   = Drift_Hub_Publish::secret( $post->ID );
		if ( $token ) {
			delete_transient( self::NEW_TOKEN . $post->ID . '_' . get_current_user_id() );
		}
		?>
		<style>.dh-code{display:inline-block;padding:4px 8px;background:#f0f0f1;border-radius:4px;font-family:Menlo,Consolas,monospace;user-select:all}.dh-new{padding:10px 12px;border-left:4px solid #FF4FA3;background:#fff6fb;margin:8px 0}</style>
		<?php if ( ! $saved ) : ?>
			<p>Publish the artist first — the connection details appear once it's saved.</p>
			<?php return; ?>
		<?php endif; ?>
		<p><strong>On the artist's website</strong> (Encore Website → Connection), enter:</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Data source</th><td><span class="dh-code"><?php echo esc_html( self::api_url() ); ?></span></td></tr>
			<tr><th scope="row">Base ID</th><td><span class="dh-code"><?php echo esc_html( self::base_id( $post->ID ) ); ?></span></td></tr>
			<tr><th scope="row">Token</th><td>
				<?php if ( $token ) : ?>
					<div class="dh-new"><span class="dh-code"><?php echo esc_html( (string) $token ); ?></span><br><strong>Copy it now — it won't be shown again.</strong></div>
				<?php elseif ( $has ) : ?>
					<p>A token is set. Generating a new one disconnects the website until you paste it in.</p>
				<?php else : ?>
					<p>No token yet.</p>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=drift_hub_token&artist=' . $post->ID ), 'drift_hub_token_' . $post->ID ) ); ?>" <?php echo $has ? 'onclick="return confirm(\'Replace the token? The website stops syncing until the new one is pasted in.\')"' : ''; ?>><?php echo $has ? 'Generate a new token' : 'Generate token'; ?></a>
			</td></tr>
		</table>
		<p><strong>From the artist's website</strong> (Encore Website → Connection), so the hub's Publish button can update it:</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="dh-site">Website address</label></th><td><input type="url" id="dh-site" class="regular-text" name="drift_hub_site" value="<?php echo esc_attr( $site ); ?>" placeholder="https://theband.co.uk"></td></tr>
			<tr><th scope="row"><label for="dh-secret">Publish secret</label></th><td><input type="password" id="dh-secret" class="regular-text" name="drift_hub_secret" value="" autocomplete="new-password" placeholder="<?php echo $secret ? esc_attr( '•••••••• saved — leave blank to keep' ) : ''; ?>"></td></tr>
		</table>
		<?php
	}

	public static function box_content( WP_Post $post ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( Drift_Hub_Schema::get()['tables'] as $table ) {
			if ( $table['singleton'] ) {
				continue;
			}
			printf( '<li>%s %s: <strong>%d</strong></li>', esc_html( $table['icon'] ), esc_html( $table['label'] ), (int) Drift_Hub_Store::count( $post->ID, $table['name'] ) );
		}
		echo '</ul>';
		printf( '<p><a class="button button-primary" href="%s">Open in the hub</a></p>', esc_url( Drift_Hub_App::url( $post->ID ) ) );
		$last = (int) get_post_meta( $post->ID, Drift_Hub_Publish::META_LAST, true );
		if ( $last ) {
			printf( '<p class="description">Last published %s ago.</p>', esc_html( human_time_diff( $last ) ) );
		}
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['drift_hub_artist_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drift_hub_artist_nonce'] ) ), 'drift_hub_artist' ) || ! current_user_can( 'manage_options' ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::base_id( $post_id );

		if ( isset( $_POST['drift_hub_site'] ) ) {
			update_post_meta( $post_id, self::META_SITE, esc_url_raw( trim( wp_unslash( (string) $_POST['drift_hub_site'] ) ), [ 'http', 'https' ] ) );
		}
		$secret = trim( wp_unslash( (string) ( $_POST['drift_hub_secret'] ?? '' ) ) );
		if ( '' !== $secret ) {
			Drift_Hub_Publish::save_secret( $post_id, $secret );
		}

		// Keep Site Settings → Artist Name in step with a brand-new artist's title.
		if ( 'publish' === $post->post_status ) {
			Drift_Hub_Store::singleton( $post_id, 'Site Settings' );
		}
	}

	public static function handle_token(): void {
		$artist = absint( $_GET['artist'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'manage_options' ) || ! $artist ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'drift_hub_token_' . $artist );
		$token = self::new_token( $artist );
		set_transient( self::NEW_TOKEN . $artist . '_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( get_edit_post_link( $artist, 'raw' ) );
		exit;
	}

	public static function on_delete( int $post_id ): void {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			Drift_Hub_Store::delete_artist( $post_id );
		}
	}

	/* ── List table ──────────────────────────────────────────────────── */

	public static function columns( array $cols ): array {
		$cols['drift_site']      = 'Website';
		$cols['drift_published'] = 'Last published';
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'drift_site' === $col ) {
			$site = (string) get_post_meta( $post_id, self::META_SITE, true );
			echo $site ? '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $site, PHP_URL_HOST ) ) . '</a>' : '—';
		}
		if ( 'drift_published' === $col ) {
			$last = (int) get_post_meta( $post_id, Drift_Hub_Publish::META_LAST, true );
			echo $last ? esc_html( human_time_diff( $last ) . ' ago' ) : '—';
		}
	}

	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			$actions['drift_hub'] = '<a href="' . esc_url( Drift_Hub_App::url( $post->ID ) ) . '">Open in hub</a>';
		}
		return $actions;
	}
}
