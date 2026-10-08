<?php
/**
 * class-api.php — the API artist websites sync from.
 *
 * Serves exactly what the Encore Website plugin reads. A site connects by
 * entering its data source URL, base ID and token — nothing else:
 *
 *   GET  {ns}/meta/bases/{base}/tables         schema (Check connection)
 *   GET  {ns}/{base}/{table}                    list: pageSize, offset,
 *                                               fields[], sort[n][field|direction],
 *                                               maxRecords
 *   GET  {ns}/{base}/{table}/{record}           one record
 *   POST {ns}/{base}/{table}                    create (website forms; only
 *                                               tables marked 'writable')
 *
 * Auth: "Authorization: Bearer hub_…" — the artist's token for that base.
 * Errors use the shape { error: { type, message } } with matching status
 * codes, including 422 UNKNOWN_FIELD_NAME, which the plugin's handling expects.
 *
 * Note for hosting: some Apache/CGI setups strip the Authorization header.
 * The README has the one-line .htaccess fix.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Api {

	const NAMESPACE = 'drift-hub/v0';
	const BASE      = '(?P<base>app[A-Za-z0-9]{14})';
	const PAGE_MAX  = 100;

	/** @var int|null Artist authenticated for this request. */
	private static $artist = null;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'api_errors' ], 10, 3 );
	}

	/**
	 * Auth failures are raised by WordPress before our callbacks run, in
	 * WordPress's error shape. Rewrite them into the API's, so the website
	 * shows the real reason.
	 */
	public static function api_errors( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['code'], $data['message'] ) && ! isset( $data['error'] ) ) {
			$type = (string) ( $data['data']['api_type'] ?? strtoupper( (string) $data['code'] ) );
			$response->set_data( [ 'error' => [ 'type' => $type, 'message' => (string) $data['message'] ] ] );
		}
		return $response;
	}

	public static function routes(): void {
		$auth = [ __CLASS__, 'authenticate' ];
		register_rest_route( self::NAMESPACE, '/meta/bases/' . self::BASE . '/tables', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'meta' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/' . self::BASE . '/(?P<table>[^/]+)', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list' ], 'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NAMESPACE, '/' . self::BASE . '/(?P<table>[^/]+)/(?P<record>rec[A-Za-z0-9]{14})', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'one' ],
			'permission_callback' => $auth,
		] );
	}

	/* ── Auth ────────────────────────────────────────────────────────── */

	/** @return true|WP_Error */
	public static function authenticate( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			$header = (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$token  = preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $m ) ? $m[1] : '';
		$artist = Drift_Hub_Artists::by_base( (string) $request['base'] );

		if ( '' === $token ) {
			return self::error( 'AUTHENTICATION_REQUIRED', 'Authentication required', 401 );
		}
		if ( ! $artist || ! Drift_Hub_Artists::check_token( $artist, $token ) ) {
			// Same answer for unknown base and wrong token, so neither leaks.
			return self::error( 'INVALID_PERMISSIONS_OR_MODEL_NOT_FOUND', 'Invalid permissions, or the requested model was not found. Check the base ID and token.', 403 );
		}
		self::$artist = $artist;
		return true;
	}

	private static function error( string $type, string $message, int $status ): WP_Error {
		return new WP_Error( 'drift_hub_' . strtolower( $type ), $message, [ 'status' => $status, 'api_type' => $type ] );
	}

	/** WP_Error → API error body. */
	private static function respond_error( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();
		return new WP_REST_Response( [ 'error' => [ 'type' => (string) ( $data['api_type'] ?? 'ERROR' ), 'message' => $error->get_error_message() ] ], (int) ( $data['status'] ?? 400 ) );
	}

	private static function table_from( WP_REST_Request $request ) {
		$table = Drift_Hub_Schema::table( rawurldecode( (string) $request['table'] ) );
		return $table ?: self::error( 'TABLE_NOT_FOUND', sprintf( 'Could not find table %s in the base.', rawurldecode( (string) $request['table'] ) ), 404 );
	}

	/* ── Endpoints ───────────────────────────────────────────────────── */

	public static function meta(): WP_REST_Response {
		return new WP_REST_Response( Drift_Hub_Schema::meta(), 200 );
	}

	public static function list( WP_REST_Request $request ): WP_REST_Response {
		$table = self::table_from( $request );
		if ( is_wp_error( $table ) ) {
			return self::respond_error( $table );
		}

		// fields[] — unknown names are a 422.
		$only = $request->get_param( 'fields' );
		$only = null === $only ? null : array_values( array_map( 'strval', (array) $only ) );
		foreach ( (array) $only as $name ) {
			if ( ! isset( $table['fields'][ $name ] ) || $table['fields'][ $name ]['hub_only'] ) {
				return self::respond_error( self::error( 'UNKNOWN_FIELD_NAME', sprintf( 'Unknown field name: "%s"', $name ), 422 ) );
			}
		}

		$records = Drift_Hub_Store::all( self::$artist, $table['name'] );
		$inverse = Drift_Hub_Store::inverse_links( self::$artist, $table );
		$rows    = array_map( static fn( $r ) => Drift_Hub_Schema::to_api( $table, $r, $inverse[ $r['record_id'] ] ?? [] ), $records );

		$rows = self::sort( $rows, (array) $request->get_param( 'sort' ) );

		$max = absint( $request->get_param( 'maxRecords' ) );
		if ( $max ) {
			$rows = array_slice( $rows, 0, $max );
		}

		// Trim to the requested fields after sorting (sort fields needn't be requested).
		if ( null !== $only ) {
			$rows = array_map( static function ( $row ) use ( $only ) {
				$row['fields'] = (object) array_intersect_key( (array) $row['fields'], array_flip( $only ) );
				return $row;
			}, $rows );
		}

		$size   = max( 1, min( self::PAGE_MAX, absint( $request->get_param( 'pageSize' ) ) ?: self::PAGE_MAX ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$page   = array_slice( $rows, $offset, $size );
		$body   = [ 'records' => array_values( $page ) ];
		if ( $offset + $size < count( $rows ) ) {
			$body['offset'] = (string) ( $offset + $size );
		}

		return new WP_REST_Response( $body, 200 );
	}

	public static function one( WP_REST_Request $request ): WP_REST_Response {
		$table = self::table_from( $request );
		if ( is_wp_error( $table ) ) {
			return self::respond_error( $table );
		}
		$record = Drift_Hub_Store::get( (string) $request['record'] );
		if ( ! $record || (int) $record['artist_id'] !== self::$artist || $record['tbl'] !== $table['name'] ) {
			return self::respond_error( self::error( 'NOT_FOUND', 'Record not found.', 404 ) );
		}
		$inverse = Drift_Hub_Store::inverse_links( self::$artist, $table );
		return new WP_REST_Response( Drift_Hub_Schema::to_api( $table, $record, $inverse[ $record['record_id'] ] ?? [] ), 200 );
	}

	public static function create( WP_REST_Request $request ): WP_REST_Response {
		$table = self::table_from( $request );
		if ( is_wp_error( $table ) ) {
			return self::respond_error( $table );
		}
		if ( empty( $table['writable'] ) ) {
			return self::respond_error( self::error( 'INVALID_PERMISSIONS', 'This table is read-only for websites.', 403 ) );
		}

		$body     = (array) $request->get_json_params();
		$typecast = ! empty( $body['typecast'] );
		$input    = (array) ( $body['fields'] ?? [] );
		$fields   = [];

		foreach ( $input as $name => $value ) {
			$field = $table['fields'][ $name ] ?? null;
			if ( ! $field || $field['hub_only'] ) {
				return self::respond_error( self::error( 'UNKNOWN_FIELD_NAME', sprintf( 'Unknown field name: "%s"', $name ), 422 ) );
			}
			if ( ! in_array( $field['type'], [ 'singleLineText', 'multilineText', 'richText', 'url', 'email', 'phoneNumber', 'singleSelect', 'multipleSelects', 'date', 'dateTime', 'number', 'checkbox' ], true ) ) {
				continue; // Websites can't set attachments, links or computed fields.
			}
			$clean = Drift_Hub_Schema::clean( $field, $value, $typecast );
			if ( is_wp_error( $clean ) ) {
				return self::respond_error( self::error( 'INVALID_VALUE_FOR_COLUMN', $clean->get_error_message(), 422 ) );
			}
			if ( null !== $clean ) {
				$fields[ $name ] = $clean;
			}
		}

		foreach ( $table['fields'] as $name => $field ) {
			if ( ! isset( $fields[ $name ] ) && isset( $field['default'] ) ) {
				$fields[ $name ] = $field['default'];
			}
		}

		$record = Drift_Hub_Store::create( self::$artist, $table['name'], $fields );
		do_action( 'drift_hub_record_created_by_website', $record, self::$artist );

		return new WP_REST_Response( Drift_Hub_Schema::to_api( $table, $record ), 200 );
	}

	/**
	 * Sort: sort[0][field]=Date&sort[0][direction]=asc …
	 * Empty values sort first ascending, last descending.
	 */
	private static function sort( array $rows, array $sort ): array {
		$keys = [];
		foreach ( $sort as $s ) {
			if ( is_array( $s ) && ! empty( $s['field'] ) ) {
				$keys[] = [ (string) $s['field'], 'desc' === strtolower( (string) ( $s['direction'] ?? 'asc' ) ) ? -1 : 1 ];
			}
		}
		if ( ! $keys ) {
			return $rows;
		}
		$i = 0;
		foreach ( $rows as &$row ) {
			$row['_i'] = $i++;
		}
		unset( $row );
		usort( $rows, static function ( $a, $b ) use ( $keys ) {
			foreach ( $keys as [ $field, $dir ] ) {
				$va  = ( (array) $a['fields'] )[ $field ] ?? null;
				$vb  = ( (array) $b['fields'] )[ $field ] ?? null;
				$va  = is_array( $va ) ? implode( ',', array_map( 'strval', $va ) ) : $va;
				$vb  = is_array( $vb ) ? implode( ',', array_map( 'strval', $vb ) ) : $vb;
				if ( $va === $vb ) {
					continue;
				}
				if ( null === $va ) {
					return -1 * $dir;
				}
				if ( null === $vb ) {
					return 1 * $dir;
				}
				$cmp = ( is_numeric( $va ) && is_numeric( $vb ) ) ? ( $va <=> $vb ) : strnatcasecmp( (string) $va, (string) $vb );
				if ( 0 !== $cmp ) {
					return $cmp * $dir;
				}
			}
			return $a['_i'] <=> $b['_i'];
		} );
		return array_map( static function ( $row ) {
			unset( $row['_i'] );
			return $row;
		}, $rows );
	}
}
