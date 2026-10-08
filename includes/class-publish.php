<?php
/**
 * class-publish.php — the hub's Publish button.
 *
 * Stamps the artist's Site Settings → Last Published (which the website's
 * daily safety check compares), then calls the website's publish webhook
 * (Encore Website: POST /wp-json/encore/v1/publish with X-Encore-Secret).
 * The website queues a sync and pulls everything from the hub.
 *
 * The website's publish secret is stored encrypted (AES-256-GCM, key from
 * DRIFT_HUB_KEY in wp-config.php or the WordPress salts).
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Publish {

	const META_LAST = '_drift_hub_published';

	public static function init(): void {
		// Nothing to hook yet; kept for symmetry and future scheduled publishing.
	}

	/* ── Secret storage ──────────────────────────────────────────────── */

	private static function key(): string {
		$material = defined( 'DRIFT_HUB_KEY' ) && DRIFT_HUB_KEY ? (string) DRIFT_HUB_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', 'drift-hub|' . $material, true );
	}

	public static function save_secret( int $artist_id, string $secret ): void {
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false !== $cipher ) {
			update_post_meta( $artist_id, Drift_Hub_Artists::META_SECRET, 'dh1:' . base64_encode( $iv . $tag . $cipher ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
	}

	public static function secret( int $artist_id ): string {
		$stored = (string) get_post_meta( $artist_id, Drift_Hub_Artists::META_SECRET, true );
		if ( 0 !== strpos( $stored, 'dh1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	public static function ready( int $artist_id ): bool {
		return '' !== (string) get_post_meta( $artist_id, Drift_Hub_Artists::META_SITE, true ) && '' !== self::secret( $artist_id );
	}

	/* ── Publish ─────────────────────────────────────────────────────── */

	/** @return array{ok: bool, message: string} */
	public static function publish( int $artist_id ): array {
		if ( ! self::ready( $artist_id ) ) {
			return [ 'ok' => false, 'message' => 'This artist\'s website isn\'t connected to the hub yet. Ask your web team to add the website address and publish secret.' ];
		}

		$stamp    = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		$settings = Drift_Hub_Store::singleton( $artist_id, 'Site Settings' );
		Drift_Hub_Store::update( $settings['record_id'], [ 'Last Published' => $stamp ] );

		$site = untrailingslashit( (string) get_post_meta( $artist_id, Drift_Hub_Artists::META_SITE, true ) );
		$args = [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json', 'X-Encore-Secret' => self::secret( $artist_id ) ],
			'body'    => wp_json_encode( [ 'last_published' => $stamp, 'by' => 'drift-hub' ] ),
		];

		$response = wp_remote_post( $site . '/wp-json/encore/v1/publish', $args );
		if ( ! is_wp_error( $response ) && 404 === (int) wp_remote_retrieve_response_code( $response ) ) {
			// Site without pretty permalinks.
			$response = wp_remote_post( add_query_arg( 'rest_route', '/encore/v1/publish', $site . '/' ), $args );
		}

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => 'Couldn\'t reach the website: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 === $code || 200 === $code ) {
			update_post_meta( $artist_id, self::META_LAST, time() );
			return [ 'ok' => true, 'message' => 'Publishing — the website will show your changes in a minute or two.' ];
		}
		if ( in_array( $code, [ 401, 403 ], true ) ) {
			return [ 'ok' => false, 'message' => 'The website didn\'t accept the publish secret. Ask your web team to re-copy it from the website into the hub.' ];
		}
		return [ 'ok' => false, 'message' => sprintf( 'The website answered with an error (HTTP %d).', $code ) ];
	}
}
