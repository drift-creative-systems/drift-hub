<?php
/**
 * class-media.php — keeps each artist's media library separate.
 *
 * Attachments carry one `_drift_hub_artist` meta row per artist they
 * belong to (a shared image can belong to several). An attachment gets
 * tagged when:
 *   - it's uploaded from an artist's screen in the hub (the media picker
 *     sends drift_hub_artist with the upload), or
 *   - it's saved into that artist's content (covers anything an admin
 *     picks from the full library in wp-admin).
 *
 * The hub's media picker asks for one artist's media only, for everyone,
 * admins included. wp-admin → Media is unaffected for admins.
 *
 * Media used before 1.1.0 is tagged once, from the artist's content
 * (backfill()). Uploads that were never used anywhere stay untagged and
 * only show in wp-admin.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

final class Drift_Hub_Media {

	const META     = '_drift_hub_artist';
	const BACKFILL = 'drift_hub_media_tagged';

	public static function init(): void {
		add_action( 'add_attachment', [ __CLASS__, 'tag_upload' ] );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'scope_library' ] );
		add_action( 'init', [ __CLASS__, 'backfill' ], 20 ); // After the artist post type is registered; no-op once done.
	}

	/** Tag a hub upload with the artist whose screen it came from. */
	public static function tag_upload( int $attachment_id ): void {
		$artist = absint( $_POST['drift_hub_artist'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- async-upload.php has already checked its own nonce.
		if ( $artist && Drift_Hub_Access::can_edit( $artist ) ) {
			self::tag( $attachment_id, $artist );
		}
	}

	/**
	 * Media picker query. From the hub (drift_hub_artist set): that artist's
	 * media only, and nothing at all for an artist the user can't edit.
	 * Elsewhere: admins see everything, members only their own uploads.
	 */
	public static function scope_library( array $query ): array {
		// wp_ajax_query_attachments() strips unknown keys before this filter, so read the raw request.
		$raw    = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only an int is read.
		$artist = absint( $raw['drift_hub_artist'] ?? 0 );

		if ( $artist ) {
			if ( ! Drift_Hub_Access::can_edit( $artist ) ) {
				$query['post__in'] = [ 0 ];
				return $query;
			}
			$query['meta_query'] = [ [ 'key' => self::META, 'value' => (string) $artist ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
			return $query;
		}

		if ( ! Drift_Hub_Access::is_admin_user() ) {
			$query['author'] = get_current_user_id();
		}
		return $query;
	}

	/** Does this attachment belong to the artist? */
	public static function belongs( int $attachment_id, int $artist ): bool {
		return in_array( (string) $artist, array_map( 'strval', (array) get_post_meta( $attachment_id, self::META ) ), true );
	}

	public static function tag( int $attachment_id, int $artist ): void {
		if ( 'attachment' === get_post_type( $attachment_id ) && ! self::belongs( $attachment_id, $artist ) ) {
			add_post_meta( $attachment_id, self::META, $artist );
		}
	}

	/** Tag every attachment in a saved record's fields with its artist. */
	public static function tag_fields( array $table, array $fields, int $artist ): void {
		foreach ( $table['fields'] as $name => $field ) {
			if ( 'multipleAttachments' === $field['type'] && ! empty( $fields[ $name ] ) ) {
				foreach ( (array) $fields[ $name ] as $id ) {
					self::tag( (int) $id, $artist );
				}
			}
		}
	}

	/** One-off: tag the media already used in every artist's content. */
	public static function backfill(): void {
		if ( get_option( self::BACKFILL ) ) {
			return;
		}
		$artists = get_posts( [ 'post_type' => Drift_Hub_Artists::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] );
		foreach ( $artists as $artist ) {
			foreach ( Drift_Hub_Schema::get()['tables'] as $table ) {
				foreach ( Drift_Hub_Store::all( (int) $artist, $table['name'] ) as $row ) {
					self::tag_fields( $table, (array) $row['fields'], (int) $artist );
				}
			}
		}
		update_option( self::BACKFILL, time(), true ); // Autoloaded: checked on every request.
	}
}
