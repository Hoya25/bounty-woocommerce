<?php
/**
 * What this store publishes, and where it is kept.
 *
 * NOTHING IS PUBLISHED UNTIL THE MERCHANT SAVES. Until then current() returns
 * null, the well-known path is a 404, and no product page carries anything.
 *
 * Two options:
 *   open_bounty_settings   the form: mode, the merchant's own fields, toggles
 *   open_bounty_published  the object that passed validation at the last save,
 *                          with the schema it was validated against
 *
 * In NCTR Alliance mode the object is the alliance's own, refreshed live (every
 * ten minutes) and re-validated on every refresh. If a refresh fails, the last
 * object that passed is served — never an object that has not passed.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Store {

	const SETTINGS  = 'open_bounty_settings';
	const PUBLISHED = 'open_bounty_published';
	const LIVE      = 'open_bounty_alliance_live';
	const LIVE_TTL  = 600;

	const EARN_TYPES = array( 'points', 'token', 'cash', 'other' );

	public static function settings() {
		$defaults = array(
			'mode'               => 'alliance',
			'alliance_confirmed' => false,
			'program_name'       => '',
			'program_url'        => '',
			'earn_display'       => '',
			'earn_type'          => 'points',
			'denomination'       => '',
			'terms_url'          => '',
			'show_block'         => true,
			'smile_noun'         => 'Members',
		);
		$saved = get_option( self::SETTINGS, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/** The published record, or null when nothing has been saved. */
	public static function published() {
		$p = get_option( self::PUBLISHED, null );
		return is_array( $p ) && isset( $p['object'] ) && is_array( $p['object'] ) ? $p : null;
	}

	/**
	 * The object to serve right now, or null when nothing is published.
	 *
	 * @return array|null
	 */
	public static function current() {
		$published = self::published();
		if ( ! $published ) {
			return null;
		}
		if ( 'alliance' !== self::settings()['mode'] ) {
			return $published['object'];
		}

		$live = get_transient( self::LIVE );
		if ( is_array( $live ) ) {
			return $live;
		}
		$object = self::refresh_alliance( $published['object'] );
		set_transient( self::LIVE, $object, self::LIVE_TTL );
		return $object;
	}

	/** Fetch and re-validate the alliance object; fall back to the last good copy. */
	private static function refresh_alliance( array $last_good ) {
		$schema = Open_Bounty_Schema::get();
		$doc    = Open_Bounty_Schema::fetch_json( Open_Bounty_Schema::ALLIANCE_URL );
		if ( is_wp_error( $schema ) || is_wp_error( $doc ) || Open_Bounty_Schema::check( $doc, $schema ) ) {
			return $last_good;
		}
		return $doc;
	}

	/**
	 * Builds the merchant's own object from the form. Optional fields that are
	 * blank are omitted, never published empty: a blank value is not the same
	 * claim as no claim.
	 */
	public static function build_own( array $s ) {
		$earn = array( 'display' => $s['earn_display'] );
		if ( '' !== $s['denomination'] ) {
			$earn['denomination'] = $s['denomination'];
		}
		$earn['type'] = $s['earn_type'];

		$object = array(
			'bounty_schema_version' => Open_Bounty_Schema::OBJECT_VERSION,
			'active'                => true,
			'program'               => array(
				'name' => $s['program_name'],
				'url'  => $s['program_url'],
			),
			'earn'                  => $earn,
		);
		if ( '' !== $s['terms_url'] ) {
			$object['terms_url'] = $s['terms_url'];
		}
		$object['updated_at'] = gmdate( 'Y-m-d' );
		return $object;
	}

	public static function save( array $settings, array $object, array $schema ) {
		update_option( self::SETTINGS, $settings, false );
		update_option(
			self::PUBLISHED,
			array(
				'object'         => $object,
				'schema_id'      => isset( $schema['$id'] ) ? (string) $schema['$id'] : Open_Bounty_Schema::SCHEMA_URL,
				'object_version' => (string) $object['bounty_schema_version'],
				'saved_at'       => gmdate( 'c' ),
			),
			true
		);
		delete_transient( self::LIVE );
	}

	/** Stops publishing. The well-known path returns to 404 and product pages go quiet. */
	public static function unpublish() {
		delete_option( self::PUBLISHED );
		delete_transient( self::LIVE );
	}
}
