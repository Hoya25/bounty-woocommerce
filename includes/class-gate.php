<?php
/**
 * DISCLOSURE GATE — banned vocabulary, ported from the NCTR Alliance Registry.
 *
 * A bounty sentence is shown to shoppers verbatim by agents, so it has to be
 * plain and truthful. This gate refuses language that describes a reward as a
 * financial product, or explains how a rewards pool is funded:
 *
 *   - finance vocabulary (word-boundary matched, so "priceless" or
 *     "a returns policy" pass)
 *   - funding-mechanics vocabulary
 *   - two vendor names, matched as bare substrings in any form. They are held
 *     here as SHA-256 digests rather than as text, because this file is public
 *     and the names must not be published, not even in a denylist.
 *
 * The gate runs when a merchant saves (nothing that fails is written) and again
 * on every render (nothing that fails is shown). Fail closed both times.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Gate {

	const FINANCE_TERMS = array(
		'cashback', 'cash back',
		'yield', 'yields', 'apy', 'apr',
		'roi', 'returns on', 'rate of return',
		'invest', 'investment', 'investor', 'investing',
		'revenue share', 'profit share', 'revenue split', 'treasury split',
		'dividend', 'equity stake', 'securities',
	);

	const MECHANICS_TERMS = array(
		'treasury destination', 'funding source', 'defi', 'aerodrome',
		'liquidity pool', 'lp token', 'saac fee', 'fee rate', 'commitment rate',
	);

	/**
	 * Vendor names as length => SHA-256 digests of the lowercase name. Every
	 * window of that length in the scanned text is hashed and compared, which is
	 * a bare-substring match without the name ever appearing in this source.
	 */
	const VENDOR_DIGESTS = array(
		8 => array( '97d435dc58ae430a7c015715a37239b8972decedcc122a6a8cee6959483587bf' ),
		5 => array( '9cdf9febca2a5cf2bf8dcd0fae2ccfadf191dfaa81198945f0781fbe5cc088e4' ),
	);

	/**
	 * Scans text, or any value that serialises to JSON, for banned vocabulary.
	 *
	 * @param mixed $payload String, array or object.
	 * @return array{clean: bool, hits: string[]}
	 */
	public static function scan( $payload ) {
		$text = is_string( $payload ) ? $payload : (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$hits = array();

		foreach ( array_merge( self::FINANCE_TERMS, self::MECHANICS_TERMS ) as $term ) {
			$quoted  = preg_quote( $term, '~' );
			// Multiword terms: \b is unreliable next to spaces, so no boundaries.
			$pattern = false !== strpos( $term, ' ' ) ? "~{$quoted}~u" : "~\\b{$quoted}\\b~u";
			if ( preg_match( $pattern, $text ) ) {
				$hits[] = $term;
			}
		}

		foreach ( self::VENDOR_DIGESTS as $length => $digests ) {
			if ( self::contains_digest( $text, $length, $digests ) ) {
				$hits[] = 'a vendor name';
				break;
			}
		}

		return array(
			'clean' => empty( $hits ),
			'hits'  => $hits,
		);
	}

	/**
	 * True when any $length-byte window of $text hashes (SHA-256) to one of $digests.
	 * A bare-substring match against terms that are never written down.
	 */
	public static function contains_digest( $text, $length, array $digests ) {
		$last = strlen( $text ) - $length;
		for ( $i = 0; $i <= $last; $i++ ) {
			if ( in_array( hash( 'sha256', substr( $text, $i, $length ) ), $digests, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when the payload passes the gate. */
	public static function is_clean( $payload ) {
		$result = self::scan( $payload );
		return $result['clean'];
	}
}
