<?php
/**
 * The Open Bounty Schema: fetching it, and validating an object against it.
 *
 * Validation is driven entirely by the fetched schema — required fields,
 * additionalProperties, types, enums, patterns, lengths, URI formats. It is
 * deliberately not a second opinion about the rules: when the schema moves,
 * this follows without a plugin release.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Schema {

	/** The stable alias. Tracks the latest 0.x; every 0.x object stays valid under it. */
	const SCHEMA_URL = 'https://api.nctr.live/schema/bounty/v1.json';

	/** The NCTR Alliance's own program-level object. */
	const ALLIANCE_URL = 'https://api.nctr.live/.well-known/bounty.json';

	/** The spec version objects built by this plugin declare. */
	const OBJECT_VERSION = '0.3';

	const SCHEMA_TTL = 600; // 10 minutes: the alias moves when a new 0.x ships.

	/** Object versions this plugin will publish: every 0.x, per the spec. */
	const SUPPORTED_VERSION = '/^0\.\d+$/';

	/**
	 * The schema, from a 10-minute cache or the network.
	 *
	 * @return array|WP_Error
	 */
	public static function get() {
		$cached = get_transient( 'open_bounty_schema' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$schema = self::fetch_json( self::SCHEMA_URL );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		set_transient( 'open_bounty_schema', $schema, self::SCHEMA_TTL );
		return $schema;
	}

	/**
	 * GET a URL and decode it as a JSON object.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_json( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'open_bounty_fetch', sprintf( 'Could not reach %s.', $url ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'open_bounty_fetch', sprintf( '%s returned HTTP %d.', $url, $code ) );
		}
		$doc = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $doc ) ) {
			return new WP_Error( 'open_bounty_fetch', sprintf( '%s did not return a JSON object.', $url ) );
		}
		return $doc;
	}

	/**
	 * Schema validation, then the version rule, then (alliance objects only) the register rules.
	 *
	 * @param array $doc    The bounty object.
	 * @param array $schema The fetched schema.
	 * @return array<int, array{field: string, message: string}> Empty when valid.
	 */
	public static function check( array $doc, array $schema ) {
		$issues = self::validate( $doc, $schema );

		$version = isset( $doc['bounty_schema_version'] ) ? $doc['bounty_schema_version'] : null;
		if ( is_string( $version ) && ! preg_match( self::SUPPORTED_VERSION, $version ) ) {
			// The schema's pattern admits any major version; the spec's compatibility
			// promise covers 0.x only. A 1.x object may change meaning.
			$issues[] = array(
				'field'   => 'bounty_schema_version',
				'message' => sprintf( 'version %s is not a 0.x version this plugin can publish', $version ),
			);
		}

		// Register rules, for alliance objects only. A merchant's own sentence is
		// theirs; an object that speaks for the NCTR Alliance is held to the
		// alliance's copy rules (ruled 2026-10-14).
		$hits = Open_Bounty_Gate::register_hits( $doc );
		if ( $hits ) {
			$issues[] = array(
				'field'   => 'earn.display',
				'message' => 'contains language the NCTR Alliance cannot publish: ' . implode( ', ', $hits ),
			);
		}
		return $issues;
	}

	/**
	 * The generic, schema-driven validator.
	 *
	 * @return array<int, array{field: string, message: string}>
	 */
	public static function validate( $doc, array $schema, $path = '' ) {
		$issues = array();
		if ( ! is_array( $doc ) ) {
			return array( self::issue( '' === $path ? '(root)' : rtrim( $path, '.' ), 'expected an object' ) );
		}
		$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $key ) {
			if ( ! array_key_exists( $key, $doc ) ) {
				$issues[] = self::issue( $path . $key, 'is required' );
			}
		}
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( array_keys( $doc ) as $key ) {
				if ( ! isset( $props[ $key ] ) ) {
					$issues[] = self::issue( $path . $key, 'is not permitted by the schema' );
				}
			}
		}

		foreach ( $props as $key => $sub ) {
			if ( ! array_key_exists( $key, $doc ) ) {
				continue;
			}
			$val  = $doc[ $key ];
			$at   = $path . $key;
			$type = isset( $sub['type'] ) ? $sub['type'] : null;

			if ( 'object' === $type ) {
				$issues = array_merge( $issues, self::validate( $val, $sub, $at . '.' ) );
				continue;
			}
			if ( 'array' === $type ) {
				if ( ! is_array( $val ) || array_values( $val ) !== $val ) {
					$issues[] = self::issue( $at, 'must be a list' );
					continue;
				}
				if ( ! empty( $sub['uniqueItems'] ) && count( array_unique( $val, SORT_REGULAR ) ) !== count( $val ) ) {
					$issues[] = self::issue( $at, 'must not repeat an entry' );
				}
				foreach ( $val as $i => $item ) {
					$issues = array_merge( $issues, self::scalar( $item, isset( $sub['items'] ) ? $sub['items'] : array(), "{$at}[{$i}]" ) );
				}
				continue;
			}
			$issues = array_merge( $issues, self::scalar( $val, $sub, $at ) );
		}
		return $issues;
	}

	private static function scalar( $val, array $sub, $at ) {
		$issues = array();
		$type   = isset( $sub['type'] ) ? $sub['type'] : null;

		if ( 'string' === $type && ! is_string( $val ) ) {
			return array( self::issue( $at, 'must be text' ) );
		}
		if ( 'boolean' === $type && ! is_bool( $val ) ) {
			return array( self::issue( $at, 'must be true or false' ) );
		}
		if ( isset( $sub['enum'] ) && ! in_array( $val, $sub['enum'], true ) ) {
			$issues[] = self::issue( $at, 'must be one of: ' . implode( ', ', $sub['enum'] ) );
		}
		if ( is_string( $val ) ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $val, 'UTF-8' ) : strlen( $val );
			if ( isset( $sub['minLength'] ) && $len < $sub['minLength'] ) {
				$issues[] = self::issue( $at, 'must not be empty' );
			}
			if ( isset( $sub['maxLength'] ) && $len > $sub['maxLength'] ) {
				$issues[] = self::issue( $at, sprintf( 'must be %d characters or fewer', $sub['maxLength'] ) );
			}
			if ( isset( $sub['pattern'] ) && ! preg_match( '~' . str_replace( '~', '\~', $sub['pattern'] ) . '~u', $val ) ) {
				$issues[] = self::issue( $at, 'is not in the required format' );
			}
			if ( isset( $sub['format'] ) && 'uri' === $sub['format'] && ! self::is_url( $val ) ) {
				$issues[] = self::issue( $at, 'must be a full URL, starting with https://' );
			}
		}
		return $issues;
	}

	public static function is_url( $val ) {
		return is_string( $val )
			&& preg_match( '~^https?://[^\s/$.?#][^\s]*$~i', $val )
			&& false !== filter_var( $val, FILTER_VALIDATE_URL );
	}

	private static function issue( $field, $message ) {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
