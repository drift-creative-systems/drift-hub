<?php
/**
 * class-store.php — every artist's records, in one custom table.
 *
 *   {prefix}drift_hub_records
 *     id         internal
 *     record_id  'rec…' (what websites see)
 *     artist_id  drift_artist post ID
 *     tbl        table name from the schema
 *     fields     JSON — stored values (see class-schema.php)
 *     created / updated (UTC)
 *
 * One JSON column rather than a column per field is what lets the schema
 * change without database migrations: a new field is simply absent on old
 * rows until someone fills it in.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Store {

	const DB_VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'drift_hub_records';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				record_id char(17) NOT NULL,
				artist_id bigint(20) unsigned NOT NULL,
				tbl varchar(64) NOT NULL,
				fields longtext NOT NULL,
				created datetime NOT NULL,
				updated datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY record_id (record_id),
				KEY artist_tbl (artist_id,tbl)
			) {$charset};"
		);
		update_option( 'drift_hub_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'drift_hub_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function new_id(): string {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		do {
			$id = 'rec';
			for ( $i = 0; $i < 14; $i++ ) {
				$id .= $chars[ random_int( 0, 61 ) ];
			}
		} while ( self::get( $id ) );
		return $id;
	}

	private static function decode( $row, ?array $table = null ): ?array {
		if ( ! $row ) {
			return null;
		}
		$row           = (array) $row;
		$row['fields'] = json_decode( (string) $row['fields'], true ) ?: [];
		$table         = $table ?: Drift_Hub_Schema::table( (string) $row['tbl'] );
		// Renamed fields: read the old key until the record is next saved.
		foreach ( (array) ( $table['fields'] ?? [] ) as $name => $field ) {
			if ( ! empty( $field['was'] ) && ! array_key_exists( $name, $row['fields'] ) && array_key_exists( $field['was'], $row['fields'] ) ) {
				$row['fields'][ $name ] = $row['fields'][ $field['was'] ];
			}
		}
		return $row;
	}

	public static function get( string $record_id ): ?array {
		global $wpdb;
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE record_id = %s', $record_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array[] All records of one table for one artist, oldest first. */
	public static function all( int $artist_id, string $table ): array {
		global $wpdb;
		$spec = Drift_Hub_Schema::table( $table );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE artist_id = %d AND tbl = %s ORDER BY id ASC', $artist_id, $table ) ); // phpcs:ignore WordPress.DB
		return array_map( static fn( $r ) => self::decode( $r, $spec ), (array) $rows );
	}

	public static function count( int $artist_id, string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE artist_id = %d AND tbl = %s', $artist_id, $table ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Creates a record.
	 *
	 * @param array $fields Already-clean stored values.
	 * @return array The new record.
	 */
	public static function create( int $artist_id, string $table, array $fields ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$id  = self::new_id();
		$wpdb->insert( self::table(), [ // phpcs:ignore WordPress.DB
			'record_id' => $id,
			'artist_id' => $artist_id,
			'tbl'       => $table,
			'fields'    => wp_json_encode( (object) $fields ),
			'created'   => $now,
			'updated'   => $now,
		] );
		self::touch( $artist_id );
		return self::get( $id );
	}

	/** Merges $fields into a record (null removes a field). */
	public static function update( string $record_id, array $fields ): ?array {
		global $wpdb;
		$record = self::get( $record_id );
		if ( ! $record ) {
			return null;
		}
		$merged  = $record['fields'];
		$table   = Drift_Hub_Schema::table( (string) $record['tbl'] );
		$publish = false; // Did anything the website uses change?
		foreach ( $fields as $name => $value ) {
			if ( ( $merged[ $name ] ?? null ) !== $value && empty( $table['fields'][ $name ]['hub_only'] ) ) {
				$publish = true;
			}
			if ( null === $value ) {
				unset( $merged[ $name ] );
			} else {
				$merged[ $name ] = $value;
			}
		}
		$wpdb->update( self::table(), [ 'fields' => wp_json_encode( (object) $merged ), 'updated' => current_time( 'mysql', true ) ], [ 'record_id' => $record_id ] ); // phpcs:ignore WordPress.DB
		if ( $publish ) {
			self::touch( (int) $record['artist_id'] );
		}
		return self::get( $record_id );
	}

	public static function delete( string $record_id ): bool {
		global $wpdb;
		$record = self::get( $record_id );
		if ( ! $record ) {
			return false;
		}
		$wpdb->delete( self::table(), [ 'record_id' => $record_id ] ); // phpcs:ignore WordPress.DB
		// Drop links pointing at it.
		foreach ( Drift_Hub_Schema::get()['tables'] as $table ) {
			foreach ( $table['fields'] as $name => $field ) {
				if ( 'multipleRecordLinks' !== $field['type'] || ! empty( $field['link']['inverse'] ) || $field['link']['table'] !== $record['tbl'] ) {
					continue;
				}
				foreach ( self::all( (int) $record['artist_id'], $table['name'] ) as $row ) {
					$links = (array) ( $row['fields'][ $name ] ?? [] );
					if ( in_array( $record_id, $links, true ) ) {
						self::update( $row['record_id'], [ $name => array_values( array_diff( $links, [ $record_id ] ) ) ] );
					}
				}
			}
		}
		self::touch( (int) $record['artist_id'] );
		return true;
	}

	public static function delete_artist( int $artist_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'artist_id' => $artist_id ] ); // phpcs:ignore WordPress.DB
	}

	/** The artist's single Site Settings record, created on first use. */
	public static function singleton( int $artist_id, string $table ): array {
		$rows = self::all( $artist_id, $table );
		if ( $rows ) {
			return $rows[0];
		}
		$fields = 'Site Settings' === $table ? [ 'Artist Name' => get_the_title( $artist_id ) ] : [];
		return self::create( $artist_id, $table, $fields );
	}

	/**
	 * Inverse links for a table: for Releases → Tracks, which tracks point at
	 * each release, ordered by the link's 'order' field.
	 *
	 * @return array<string, array<string, string[]>> record_id => field => ids
	 */
	public static function inverse_links( int $artist_id, array $table ): array {
		$out = [];
		foreach ( $table['fields'] as $name => $field ) {
			if ( 'multipleRecordLinks' !== $field['type'] || empty( $field['link']['inverse'] ) ) {
				continue;
			}
			$from  = (string) $field['link']['table'];
			$via   = (string) $field['link']['inverse'];
			$order = (string) ( $field['link']['order'] ?? '' );
			$rows  = self::all( $artist_id, $from );
			if ( $order ) {
				usort( $rows, static fn( $a, $b ) => ( (float) ( $a['fields'][ $order ] ?? PHP_INT_MAX ) ) <=> ( (float) ( $b['fields'][ $order ] ?? PHP_INT_MAX ) ) );
			}
			foreach ( $rows as $row ) {
				foreach ( (array) ( $row['fields'][ $via ] ?? [] ) as $target ) {
					$out[ $target ][ $name ][] = $row['record_id'];
				}
			}
		}
		return $out;
	}

	/** Remembers when an artist's content last changed (shown in the hub). */
	private static function touch( int $artist_id ): void {
		update_post_meta( $artist_id, '_drift_hub_changed', time() );
	}
}
