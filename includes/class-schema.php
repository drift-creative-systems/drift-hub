<?php
/**
 * class-schema.php — loads and normalises the hub schema (schemas/*.php),
 * converts values between what the hub stores and what the website
 * API returns, and validates input from the hub editor.
 *
 * Stored values are in API shape, except:
 *   multipleAttachments → array of WordPress attachment IDs
 *   multipleRecordLinks → array of record IDs ('rec…'); inverse links aren't stored
 *   formula / createdTime → never stored, computed on output
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Schema {

	const EDITABLE = [ 'singleLineText', 'multilineText', 'richText', 'url', 'email', 'phoneNumber', 'multipleAttachments', 'checkbox', 'date', 'dateTime', 'number', 'currency', 'rating', 'singleSelect', 'multipleSelects', 'multipleRecordLinks' ];

	/** @var array|null */
	private static $schema = null;

	public static function get(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}
		$raw = include DRIFT_HUB_DIR . 'schemas/surface.php';
		$raw = (array) apply_filters( 'drift_hub_schema', $raw );

		$tables = [];
		foreach ( (array) ( $raw['tables'] ?? [] ) as $name => $table ) {
			$fields = [];
			foreach ( (array) ( $table['fields'] ?? [] ) as $field_name => $field ) {
				$field          = array_merge( [ 'type' => 'singleLineText', 'help' => '', 'hidden' => false, 'readonly' => false, 'required' => false, 'hub_only' => false ], $field );
				$field['name']  = (string) $field_name;
				$field['id']    = self::id( 'fld', $name . '|' . $field_name );
				if ( in_array( $field['type'], [ 'formula', 'createdTime' ], true ) ) {
					$field['readonly'] = true;
				}
				if ( 'multipleRecordLinks' === $field['type'] && ! empty( $field['link']['inverse'] ) ) {
					$field['readonly'] = true;
				}
				$fields[ $field_name ] = $field;
			}
			$tables[ $name ] = array_merge(
				[ 'label' => $name, 'icon' => '', 'singleton' => false, 'writable' => false, 'columns' => [], 'sort' => null, 'status' => '', 'add_label' => 'Add' ],
				$table,
				[ 'name' => (string) $name, 'id' => self::id( 'tbl', $name ), 'fields' => $fields ]
			);
		}

		self::$schema = [ 'label' => (string) ( $raw['label'] ?? 'Drift' ), 'tables' => $tables ];
		return self::$schema;
	}

	/** Stable record ID: prefix + 14 characters. */
	public static function id( string $prefix, string $seed ): string {
		return $prefix . substr( preg_replace( '/[^A-Za-z0-9]/', '', base64_encode( hash( 'sha256', 'drift-hub|' . $seed, true ) ) ), 0, 14 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function table( string $name_or_id ): ?array {
		$tables = self::get()['tables'];
		if ( isset( $tables[ $name_or_id ] ) ) {
			return $tables[ $name_or_id ];
		}
		foreach ( $tables as $table ) {
			if ( $table['id'] === $name_or_id ) {
				return $table;
			}
		}
		return null;
	}

	/** Primary field = first field. */
	public static function primary( array $table ): string {
		return (string) array_key_first( $table['fields'] );
	}

	/* ── Meta endpoint ───────────────────────────────────────────────── */

	/** GET meta/bases/{base}/tables response body. */
	public static function meta(): array {
		$out = [];
		foreach ( self::get()['tables'] as $table ) {
			$fields = [];
			foreach ( $table['fields'] as $field ) {
				if ( $field['hub_only'] ) {
					continue;
				}
				$item = [ 'id' => $field['id'], 'name' => $field['name'], 'type' => $field['type'] ];
				if ( ! empty( $field['choices'] ) ) {
					$item['options'] = [ 'choices' => array_map( static fn( $c ) => [ 'id' => self::id( 'sel', $field['id'] . $c ), 'name' => $c ], $field['choices'] ) ];
				}
				if ( 'multipleRecordLinks' === $field['type'] ) {
					$target          = self::table( (string) $field['link']['table'] );
					$item['options'] = [ 'linkedTableId' => $target ? $target['id'] : '' ];
				}
				$fields[] = $item;
			}
			$out[] = [ 'id' => $table['id'], 'name' => $table['name'], 'primaryFieldId' => $fields[0]['id'] ?? '', 'fields' => $fields ];
		}
		return [ 'tables' => $out ];
	}

	/* ── Output (API) ────────────────────────────────────────────────── */

	/**
	 * A stored record as the website API returns it. Empty values are
	 * left out.
	 *
	 * @param array      $table   Table spec.
	 * @param array      $record  Store row: record_id, fields, created.
	 * @param array      $inverse Inverse links: field => [ record ids ].
	 * @param array|null $only    Field names to include, null for all.
	 */
	public static function to_api( array $table, array $record, array $inverse = [], ?array $only = null ): array {
		$stored = (array) $record['fields'];
		$fields = [];

		foreach ( $table['fields'] as $name => $field ) {
			if ( $field['hub_only'] || ( null !== $only && ! in_array( $name, $only, true ) ) ) {
				continue;
			}
			switch ( $field['type'] ) {
				case 'formula':
					$value = is_callable( $field['compute'] ?? null ) ? call_user_func( $field['compute'], $stored, $record ) : '';
					break;
				case 'createdTime':
					$value = self::iso( (string) $record['created'] );
					break;
				case 'multipleAttachments':
					$value = array_values( array_filter( array_map( [ __CLASS__, 'attachment' ], (array) ( $stored[ $name ] ?? [] ) ) ) );
					break;
				case 'multipleRecordLinks':
					$value = ! empty( $field['link']['inverse'] ) ? ( $inverse[ $name ] ?? [] ) : array_values( (array) ( $stored[ $name ] ?? [] ) );
					break;
				default:
					$value = $stored[ $name ] ?? null;
			}

			if ( null === $value || '' === $value || [] === $value || false === $value ) {
				continue;
			}
			$fields[ $name ] = $value;
		}

		return [ 'id' => (string) $record['record_id'], 'createdTime' => self::iso( (string) $record['created'] ), 'fields' => (object) $fields ];
	}

	/** WordPress attachment → API attachment object. */
	public static function attachment( $id ): ?array {
		$id   = (int) $id;
		$url  = $id ? wp_get_attachment_url( $id ) : '';
		if ( ! $url ) {
			return null;
		}
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		$out  = [
			'id'       => 'att' . str_pad( (string) $id, 14, '0', STR_PAD_LEFT ),
			'url'      => $url,
			'filename' => $file ? wp_basename( $file ) : wp_basename( $url ),
			'size'     => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
			'type'     => (string) get_post_mime_type( $id ),
		];
		if ( ! empty( $meta['width'] ) ) {
			$out['width']  = (int) $meta['width'];
			$out['height'] = (int) $meta['height'];
		}
		return $out;
	}

	/** 'Y-m-d H:i:s' (UTC) → '2026-10-08T09:00:00.000Z'. */
	public static function iso( string $mysql_gmt ): string {
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ) : '';
	}

	/* ── Input ───────────────────────────────────────────────────────── */

	/**
	 * Cleans one value for storage.
	 *
	 * @param array  $field    Field spec.
	 * @param mixed  $value    Raw input.
	 * @param bool   $typecast Accept unknown select options (website forms do).
	 * @return mixed|WP_Error  Cleaned value; null clears the field.
	 */
	public static function clean( array $field, $value, bool $typecast = false ) {
		$name = $field['name'];

		if ( null === $value || '' === $value || [] === $value ) {
			return 'checkbox' === $field['type'] ? false : null;
		}

		switch ( $field['type'] ) {
			case 'singleLineText':
			case 'phoneNumber':
				return sanitize_text_field( (string) $value );

			case 'multilineText':
			case 'richText':
				// Embed fields keep their markup; the website decides what to allow.
				return str_replace( "\r\n", "\n", trim( current_user_can( 'unfiltered_html' ) || str_contains( $name, 'Embed' ) ? (string) $value : wp_kses_post( (string) $value ) ) );

			case 'url':
				$url = trim( (string) $value );
				// "theband.co.uk/tickets" → "https://theband.co.uk/tickets".
				if ( ! preg_match( '#^https?://#i', $url ) && preg_match( '#^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$#i', $url ) ) {
					$url = 'https://' . $url;
				}
				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! str_contains( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) && 'localhost' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
					return new WP_Error( 'drift_hub_invalid', sprintf( '"%s" needs a full web address, like https://example.com', $name ) );
				}
				return esc_url_raw( $url, [ 'http', 'https' ] );

			case 'email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : new WP_Error( 'drift_hub_invalid', sprintf( '"%s" isn\'t a valid email address.', $name ) );

			case 'checkbox':
				return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true );

			case 'date':
				$value = substr( trim( (string) $value ), 0, 10 );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : new WP_Error( 'drift_hub_invalid', sprintf( '"%s" needs a date.', $name ) );

			case 'dateTime':
				// Accepts ISO (API) or "Y-m-d\TH:i" in the hub's timezone (editor).
				try {
					$tz   = preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', (string) $value ) ? null : wp_timezone();
					$date = new DateTimeImmutable( (string) $value, $tz );
					return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.000\Z' );
				} catch ( Exception $e ) {
					return new WP_Error( 'drift_hub_invalid', sprintf( '"%s" needs a date and time.', $name ) );
				}

			case 'number':
			case 'rating':
				return is_numeric( $value ) ? (int) round( (float) $value ) : new WP_Error( 'drift_hub_invalid', sprintf( '"%s" needs a number.', $name ) );

			case 'currency':
				return is_numeric( $value ) ? round( (float) $value, 2 ) : new WP_Error( 'drift_hub_invalid', sprintf( '"%s" needs a price.', $name ) );

			case 'singleSelect':
				$value = sanitize_text_field( (string) $value );
				return ( $typecast || in_array( $value, (array) ( $field['choices'] ?? [] ), true ) ) ? $value : new WP_Error( 'drift_hub_invalid', sprintf( '"%s" can\'t be "%s".', $name, $value ) );

			case 'multipleSelects':
				$values = array_values( array_unique( array_map( 'sanitize_text_field', (array) $value ) ) );
				return $typecast ? $values : array_values( array_intersect( $values, (array) ( $field['choices'] ?? [] ) ) );

			case 'multipleAttachments':
				$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $value ) ) ) );
				$ids = array_values( array_filter( $ids, static fn( $id ) => 'attachment' === get_post_type( $id ) ) );
				if ( ! empty( $field['max'] ) ) {
					$ids = array_slice( $ids, 0, (int) $field['max'] );
				}
				return $ids;

			case 'multipleRecordLinks':
				$ids = array_values( array_unique( array_filter( (array) $value, static fn( $id ) => is_string( $id ) && preg_match( '/^rec[A-Za-z0-9]{14}$/', $id ) ) ) );
				if ( ! empty( $field['max'] ) ) {
					$ids = array_slice( $ids, 0, (int) $field['max'] );
				}
				return $ids;
		}

		return null;
	}
}
