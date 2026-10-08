<?php
/**
 * class-app-api.php — the REST API behind the /hub/ screens (logged-in
 * users, cookie + nonce auth). Every route checks the user may edit that
 * artist.
 *
 *   GET    app/v1/bootstrap                 schema + the user's artists
 *   GET    app/v1/artists/{id}/{table}      records for the editor
 *   POST   app/v1/artists/{id}/{table}      create { fields }
 *   POST   app/v1/records/{rec}             update { fields }
 *   DELETE app/v1/records/{rec}
 *   POST   app/v1/artists/{id}/publish      tell the website to sync
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_App_Api {

	const NAMESPACE = 'drift-hub/app/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$logged_in = static fn() => is_user_logged_in();
		$artist    = static fn( WP_REST_Request $r ) => Drift_Hub_Access::can_edit( (int) $r['artist'] );
		$record    = static function ( WP_REST_Request $r ) {
			$rec = Drift_Hub_Store::get( (string) $r['record'] );
			return $rec && Drift_Hub_Access::can_edit( (int) $rec['artist_id'] );
		};

		register_rest_route( self::NAMESPACE, '/bootstrap', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'bootstrap' ], 'permission_callback' => $logged_in ] );
		register_rest_route( self::NAMESPACE, '/account', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'account' ], 'permission_callback' => $logged_in ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_account' ], 'permission_callback' => $logged_in ],
		] );
		register_rest_route( self::NAMESPACE, '/artists/(?P<artist>\d+)/publish', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'publish' ], 'permission_callback' => $artist ] );
		register_rest_route( self::NAMESPACE, '/artists/(?P<artist>\d+)/(?P<table>[^/]+)', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list' ], 'permission_callback' => $artist ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create' ], 'permission_callback' => $artist ],
		] );
		register_rest_route( self::NAMESPACE, '/records/(?P<record>rec[A-Za-z0-9]{14})', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'update' ], 'permission_callback' => $record ],
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete' ], 'permission_callback' => $record ],
		] );
	}

	/* ── Bootstrap ───────────────────────────────────────────────────── */

	public static function bootstrap(): WP_REST_Response {
		$schema = Drift_Hub_Schema::get();
		$tables = [];
		foreach ( $schema['tables'] as $table ) {
			$fields = [];
			foreach ( $table['fields'] as $field ) {
				if ( ! empty( $field['hidden'] ) ) {
					continue;
				}
				$f = array_intersect_key( $field, array_flip( [ 'name', 'type', 'choices', 'help', 'readonly', 'required', 'group', 'max', 'format', 'symbol', 'default' ] ) );
				if ( 'multipleRecordLinks' === $field['type'] ) {
					$f['link'] = [ 'table' => $field['link']['table'], 'inverse' => ! empty( $field['link']['inverse'] ) ];
				}
				$fields[] = $f;
			}
			$tables[] = [
				'name'      => $table['name'],
				'label'     => $table['label'],
				'icon'      => $table['icon'],
				'singleton' => (bool) $table['singleton'],
				'inbox'     => (bool) $table['writable'],
				'columns'   => $table['columns'] ?: array_slice( array_column( $fields, 'name' ), 0, 3 ),
				'sort'      => $table['sort'],
				'status'    => $table['status'],
				'addLabel'  => $table['add_label'],
				'primary'   => Drift_Hub_Schema::primary( $table ),
				'fields'    => $fields,
			];
		}

		$artists = [];
		foreach ( Drift_Hub_Access::artist_ids() as $id ) {
			$artists[] = self::artist_summary( $id );
		}

		$user = wp_get_current_user();
		return new WP_REST_Response( [
			'product' => $schema['label'],
			'user'    => [ 'name' => $user->display_name, 'admin' => Drift_Hub_Access::is_admin_user() ],
			'tables'  => $tables,
			'artists' => $artists,
			'tz'      => wp_timezone_string(),
		], 200 );
	}

	private static function artist_summary( int $id ): array {
		$labels   = wp_get_post_terms( $id, Drift_Hub_Artists::TAXONOMY, [ 'fields' => 'names' ] );
		$settings = Drift_Hub_Store::singleton( $id, 'Site Settings' );
		$pictures = Drift_Hub_Artists::pictures( (array) $settings['fields'] );
		$new      = 0;
		foreach ( Drift_Hub_Store::all( $id, 'Enquiries' ) as $row ) {
			if ( 'New' === ( $row['fields']['Status'] ?? 'New' ) ) {
				$new++;
			}
		}
		return [
			'id'        => $id,
			'name'      => get_the_title( $id ),
			'labels'    => is_wp_error( $labels ) ? [] : $labels,
			'site'      => (string) get_post_meta( $id, Drift_Hub_Artists::META_SITE, true ),
			'canPublish' => Drift_Hub_Publish::ready( $id ),
			'published' => (int) get_post_meta( $id, Drift_Hub_Publish::META_LAST, true ),
			'changed'   => (int) get_post_meta( $id, '_drift_hub_changed', true ),
			// Roster picture: Hub Avatar (a photo, cropped to fill), else the Logo (fitted).
			'logo'      => $pictures['logo'],
			'avatar'    => $pictures['avatar'],
			'newEnquiries' => $new,
			'upcomingGigs' => count( array_filter( Drift_Hub_Store::all( $id, 'Gigs' ), static fn( $g ) => ( $g['fields']['Date'] ?? '' ) >= wp_date( 'Y-m-d' ) ) ),
		];
	}

	/* ── Account (name, email, password) ──────────────────────────────── */

	public static function account(): WP_REST_Response {
		$user = get_userdata( get_current_user_id() );
		return new WP_REST_Response( [ 'name' => $user->display_name, 'email' => $user->user_email, 'login' => $user->user_login ], 200 );
	}

	/**
	 * Updates the signed-in user's own name, email and password. Changing the
	 * email or password needs the current password; changing the password
	 * signs them out everywhere, so they log in again with the new one.
	 */
	public static function save_account( WP_REST_Request $request ) {
		$user     = wp_get_current_user();
		$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$current  = (string) $request->get_param( 'current' );
		$new      = (string) $request->get_param( 'password' );
		$changes  = [ 'ID' => $user->ID ];
		$needs_pw = false;

		if ( '' === $name ) {
			return new WP_Error( 'drift_hub_account', 'Please enter your name.', [ 'status' => 422, 'fields' => [ 'name' ] ] );
		}
		if ( $name !== $user->display_name ) {
			$changes['display_name'] = $name;
			$parts                   = preg_split( '/\s+/', $name, 2 );
			$changes['first_name']   = $parts[0];
			$changes['last_name']    = $parts[1] ?? '';
		}

		if ( $email !== $user->user_email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'drift_hub_account', 'That email address doesn\'t look right.', [ 'status' => 422, 'fields' => [ 'email' ] ] );
			}
			if ( email_exists( $email ) ) {
				return new WP_Error( 'drift_hub_account', 'Another account already uses that email address.', [ 'status' => 422, 'fields' => [ 'email' ] ] );
			}
			$changes['user_email'] = $email;
			$needs_pw              = true;
		}

		if ( '' !== $new ) {
			if ( strlen( $new ) < 10 ) {
				return new WP_Error( 'drift_hub_account', 'Use at least 10 characters for your new password.', [ 'status' => 422, 'fields' => [ 'password' ] ] );
			}
			$needs_pw = true;
		}

		if ( $needs_pw && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'drift_hub_account', 'Your current password isn\'t right.', [ 'status' => 422, 'fields' => [ 'current' ] ] );
		}

		if ( count( $changes ) > 1 ) {
			$result = wp_update_user( $changes );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'drift_hub_account', $result->get_error_message(), [ 'status' => 422 ] );
			}
		}

		if ( '' !== $new ) {
			wp_set_password( $new, $user->ID ); // Ends every session, this one included.
			return new WP_REST_Response( [ 'saved' => true, 'relogin' => true, 'login' => wp_login_url( Drift_Hub_App::url() ) ], 200 );
		}

		clean_user_cache( $user->ID );
		return new WP_REST_Response( array_merge( [ 'saved' => true, 'relogin' => false ], self::account()->get_data() ), 200 );
	}

	/* ── Records ─────────────────────────────────────────────────────── */

	private static function table( WP_REST_Request $request ) {
		$table = Drift_Hub_Schema::table( rawurldecode( (string) $request['table'] ) );
		return $table ?: new WP_Error( 'drift_hub_table', 'Unknown table.', [ 'status' => 404 ] );
	}

	/** A stored record, shaped for the editor. */
	public static function for_ui( array $table, array $record, array $inverse = [] ): array {
		$out = [];
		foreach ( $table['fields'] as $name => $field ) {
			if ( ! empty( $field['hidden'] ) ) {
				continue;
			}
			$value = $record['fields'][ $name ] ?? null;
			switch ( $field['type'] ) {
				case 'multipleAttachments':
					$value = array_values( array_filter( array_map( static function ( $id ) {
						$url = wp_get_attachment_url( (int) $id );
						if ( ! $url ) {
							return null;
						}
						$thumb = wp_get_attachment_image_url( (int) $id, 'medium' );
						return [ 'id' => (int) $id, 'url' => $url, 'thumb' => $thumb ?: '', 'name' => wp_basename( $url ), 'mime' => (string) get_post_mime_type( (int) $id ) ];
					}, (array) $value ) ) );
					break;
				case 'dateTime':
					$value = $value ? wp_date( 'Y-m-d\TH:i', (int) strtotime( (string) $value ) ) : null;
					break;
				case 'formula':
					$value = is_callable( $field['compute'] ?? null ) ? call_user_func( $field['compute'], (array) $record['fields'], $record ) : '';
					break;
				case 'createdTime':
					$value = get_date_from_gmt( (string) $record['created'], 'Y-m-d H:i' );
					break;
				case 'multipleRecordLinks':
					if ( ! empty( $field['link']['inverse'] ) ) {
						$value = $inverse[ $name ] ?? [];
					}
					break;
			}
			$out[ $name ] = $value;
		}
		return [ 'id' => $record['record_id'], 'created' => (string) $record['created'], 'fields' => (object) $out ];
	}

	public static function list( WP_REST_Request $request ) {
		$table = self::table( $request );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$artist = (int) $request['artist'];
		if ( $table['singleton'] ) {
			$records = [ Drift_Hub_Store::singleton( $artist, $table['name'] ) ];
		} else {
			$records = Drift_Hub_Store::all( $artist, $table['name'] );
		}
		$inverse = Drift_Hub_Store::inverse_links( $artist, $table );
		return new WP_REST_Response( array_map( static fn( $r ) => self::for_ui( $table, $r, $inverse[ $r['record_id'] ] ?? [] ), $records ), 200 );
	}

	/**
	 * Cleans editor input for one table.
	 *
	 * @return array|WP_Error Stored values (null = clear).
	 */
	private static function clean_fields( array $table, array $input, int $artist ) {
		$out    = [];
		$errors = [];
		foreach ( $input as $name => $value ) {
			$field = $table['fields'][ $name ] ?? null;
			if ( ! $field || ! empty( $field['hidden'] ) || ! empty( $field['readonly'] ) || ! in_array( $field['type'], Drift_Hub_Schema::EDITABLE, true ) ) {
				continue;
			}
			$clean = Drift_Hub_Schema::clean( $field, $value );
			if ( is_wp_error( $clean ) ) {
				$errors[ $name ] = $clean->get_error_message();
				continue;
			}
			// Links must point at the same artist's records in the right table.
			if ( 'multipleRecordLinks' === $field['type'] && is_array( $clean ) ) {
				$clean = array_values( array_filter( $clean, static function ( $id ) use ( $field, $artist ) {
					$target = Drift_Hub_Store::get( $id );
					return $target && (int) $target['artist_id'] === $artist && $target['tbl'] === $field['link']['table'];
				} ) );
			}
			// Attachments: members may only use this artist's media (see class-media.php).
			if ( 'multipleAttachments' === $field['type'] && is_array( $clean ) && ! Drift_Hub_Access::is_admin_user() ) {
				$clean = array_values( array_filter( $clean, static fn( $id ) => Drift_Hub_Media::belongs( (int) $id, $artist ) ) );
			}
			$out[ $name ] = $clean;
		}
		return $errors ? new WP_Error( 'drift_hub_invalid', implode( ' ', $errors ), [ 'status' => 422, 'fields' => array_keys( $errors ) ] ) : $out;
	}

	private static function missing_required( array $table, array $fields ): array {
		$missing = [];
		foreach ( $table['fields'] as $name => $field ) {
			if ( ! empty( $field['required'] ) && ( ! isset( $fields[ $name ] ) || null === $fields[ $name ] || '' === $fields[ $name ] || [] === $fields[ $name ] ) ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}

	public static function create( WP_REST_Request $request ) {
		$table = self::table( $request );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$artist = (int) $request['artist'];
		if ( $table['singleton'] ) {
			return new WP_Error( 'drift_hub_singleton', 'This table has one record — update it instead.', [ 'status' => 400 ] );
		}
		$fields = self::clean_fields( $table, (array) $request->get_param( 'fields' ), $artist );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		foreach ( $table['fields'] as $name => $field ) {
			if ( ! array_key_exists( $name, $fields ) && isset( $field['default'] ) ) {
				$fields[ $name ] = $field['default'];
			}
		}
		$fields  = array_filter( $fields, static fn( $v ) => null !== $v );
		$missing = self::missing_required( $table, $fields );
		if ( $missing ) {
			return new WP_Error( 'drift_hub_required', 'Please fill in: ' . implode( ', ', $missing ) . '.', [ 'status' => 422, 'fields' => $missing ] );
		}
		$record  = Drift_Hub_Store::create( $artist, $table['name'], $fields );
		Drift_Hub_Media::tag_fields( $table, $fields, $artist );
		$inverse = Drift_Hub_Store::inverse_links( $artist, $table );
		return new WP_REST_Response( self::for_ui( $table, $record, $inverse[ $record['record_id'] ] ?? [] ), 201 );
	}

	public static function update( WP_REST_Request $request ) {
		$record = Drift_Hub_Store::get( (string) $request['record'] );
		$table  = Drift_Hub_Schema::table( (string) $record['tbl'] );
		$artist = (int) $record['artist_id'];
		$fields = self::clean_fields( $table, (array) $request->get_param( 'fields' ), $artist );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$merged  = array_filter( array_merge( (array) $record['fields'], $fields ), static fn( $v ) => null !== $v );
		$missing = self::missing_required( $table, $merged );
		if ( $missing ) {
			return new WP_Error( 'drift_hub_required', 'Please fill in: ' . implode( ', ', $missing ) . '.', [ 'status' => 422, 'fields' => $missing ] );
		}
		$record  = Drift_Hub_Store::update( $record['record_id'], $fields );
		Drift_Hub_Media::tag_fields( $table, $fields, $artist );
		$inverse = Drift_Hub_Store::inverse_links( $artist, $table );
		return new WP_REST_Response( self::for_ui( $table, $record, $inverse[ $record['record_id'] ] ?? [] ), 200 );
	}

	public static function delete( WP_REST_Request $request ) {
		$record = Drift_Hub_Store::get( (string) $request['record'] );
		$table  = Drift_Hub_Schema::table( (string) $record['tbl'] );
		if ( $table && $table['singleton'] ) {
			return new WP_Error( 'drift_hub_singleton', 'Site settings can\'t be deleted.', [ 'status' => 400 ] );
		}
		Drift_Hub_Store::delete( $record['record_id'] );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public static function publish( WP_REST_Request $request ): WP_REST_Response {
		$artist = (int) $request['artist'];
		$result = Drift_Hub_Publish::publish( $artist );
		return new WP_REST_Response( array_merge( $result, [ 'artist' => self::artist_summary( $artist ) ] ), $result['ok'] ? 200 : 502 );
	}
}
