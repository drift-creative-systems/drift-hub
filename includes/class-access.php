<?php
/**
 * class-access.php — who can edit which artists.
 *
 *   Administrators         every artist; set everything up in wp-admin.
 *   Manager (role)         the hub only (/hub/), never wp-admin. Sees the
 *                          artists on their label(s) plus any artists
 *                          assigned to them directly. Set on the user's
 *                          profile: "Drift: Surface Hub access".
 *
 * Labels are a taxonomy on artists (Artists → Labels), so a label manager
 * gets every artist on the label, including ones added later.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Access {

	const ROLE         = 'drift_hub_member';
	const META_LABELS  = 'drift_hub_labels';
	const META_ARTISTS = 'drift_hub_artists';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'keep_members_out_of_admin' ] );
		add_action( 'admin_page_access_denied', [ __CLASS__, 'keep_members_out_of_admin' ] );
		add_filter( 'show_admin_bar', [ __CLASS__, 'admin_bar' ] );
		add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 10, 3 );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'own_media_only' ] );
		add_filter( 'upload_mimes', [ __CLASS__, 'member_mimes' ], 20 );

		add_action( 'show_user_profile', [ __CLASS__, 'profile_fields' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_fields' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_profile' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile' ] );
	}

	public static function add_roles(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Manager', [ 'read' => true, 'upload_files' => true ] );
		}
		// 0.1.0 called the role "Hub member".
		$roles = wp_roles();
		if ( isset( $roles->roles[ self::ROLE ] ) && 'Manager' !== $roles->roles[ self::ROLE ]['name'] ) {
			$roles->roles[ self::ROLE ]['name'] = 'Manager';
			update_option( $roles->role_key, $roles->roles );
		}
	}

	public static function is_admin_user( ?int $user_id = null ): bool {
		return user_can( $user_id ?? get_current_user_id(), 'manage_options' );
	}

	public static function is_member( ?int $user_id = null ): bool {
		$user = get_userdata( $user_id ?? get_current_user_id() );
		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	/** @return int[] Artist IDs this user may edit. */
	public static function artist_ids( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		$args = [ 'post_type' => Drift_Hub_Artists::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ];
		if ( self::is_admin_user( $user_id ) ) {
			return array_map( 'intval', get_posts( $args ) );
		}

		$ids    = array_map( 'intval', (array) get_user_meta( $user_id, self::META_ARTISTS, true ) );
		$labels = array_filter( array_map( 'intval', (array) get_user_meta( $user_id, self::META_LABELS, true ) ) );
		if ( $labels ) {
			$args['tax_query'] = [ [ 'taxonomy' => Drift_Hub_Artists::TAXONOMY, 'field' => 'term_id', 'terms' => $labels ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
			$ids = array_merge( $ids, array_map( 'intval', get_posts( $args ) ) );
		}
		$ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => 'publish' === get_post_status( $id ) ) ) );
		usort( $ids, static fn( $a, $b ) => strcasecmp( get_the_title( $a ), get_the_title( $b ) ) );
		return $ids;
	}

	public static function can_edit( int $artist_id, ?int $user_id = null ): bool {
		return in_array( $artist_id, self::artist_ids( $user_id ), true );
	}

	/* ── Keeping members in the hub ──────────────────────────────────── */

	public static function keep_members_out_of_admin(): void {
		if ( wp_doing_ajax() || ! self::is_member() || self::is_admin_user() ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, [ 'async-upload.php', 'media-upload.php', 'admin-post.php' ], true ) ) {
			return; // Media uploads from the hub go through these.
		}
		wp_safe_redirect( Drift_Hub_App::url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return ( self::is_member() && ! self::is_admin_user() ) ? false : $show;
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' ) ) {
			return Drift_Hub_App::url();
		}
		return $redirect;
	}

	/** Members only see (and pick from) images they uploaded themselves. */
	public static function own_media_only( array $query ): array {
		if ( ! self::is_admin_user() ) {
			$query['author'] = get_current_user_id();
		}
		return $query;
	}

	public static function member_mimes( array $mimes ): array {
		if ( self::is_member() && ! self::is_admin_user() ) {
			return array_intersect_key( $mimes, array_flip( [ 'jpg|jpeg|jpe', 'png', 'gif', 'webp', 'avif', 'pdf' ] ) );
		}
		return $mimes;
	}

	/* ── Profile: assign labels and artists ──────────────────────────── */

	public static function profile_fields( WP_User $user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$labels   = get_terms( [ 'taxonomy' => Drift_Hub_Artists::TAXONOMY, 'hide_empty' => false ] );
		$artists  = get_posts( [ 'post_type' => Drift_Hub_Artists::POST_TYPE, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$my_l     = array_map( 'intval', (array) get_user_meta( $user->ID, self::META_LABELS, true ) );
		$my_a     = array_map( 'intval', (array) get_user_meta( $user->ID, self::META_ARTISTS, true ) );
		wp_nonce_field( 'drift_hub_profile', 'drift_hub_profile_nonce' );
		?>
		<h2>Drift: Surface Hub access</h2>
		<p class="description">Give this user the <strong>Manager</strong> role, then choose what they can edit at <a href="<?php echo esc_url( Drift_Hub_App::url() ); ?>"><?php echo esc_html( Drift_Hub_App::url() ); ?></a>.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Labels</th>
				<td>
					<?php if ( ! is_wp_error( $labels ) && $labels ) : foreach ( $labels as $term ) : ?>
						<label style="display:block"><input type="checkbox" name="drift_hub_labels[]" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $my_l, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
					<?php endforeach; else : ?>
						<p class="description">No labels yet — add them under Artists → Labels.</p>
					<?php endif; ?>
					<p class="description">Every artist on these labels, including ones added later.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Individual artists</th>
				<td>
					<?php foreach ( $artists as $artist ) : ?>
						<label style="display:block"><input type="checkbox" name="drift_hub_artists[]" value="<?php echo esc_attr( (string) $artist->ID ); ?>" <?php checked( in_array( (int) $artist->ID, $my_a, true ) ); ?>> <?php echo esc_html( $artist->post_title ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['drift_hub_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drift_hub_profile_nonce'] ) ), 'drift_hub_profile' ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_LABELS, array_map( 'intval', (array) wp_unslash( $_POST['drift_hub_labels'] ?? [] ) ) );
		update_user_meta( $user_id, self::META_ARTISTS, array_map( 'intval', (array) wp_unslash( $_POST['drift_hub_artists'] ?? [] ) ) );
	}
}
