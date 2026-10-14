<?php
/**
 * "Use my LoyaltyLion program": the sentence composed from the store's live
 * LoyaltyLion purchase rule. Never typed. If any part can't be read, nothing
 * is composed and nothing is published.
 *
 * LOYALTYLION AND WOOCOMMERCE. LoyaltyLion's developer docs list three install
 * targets: Shopify (and headless Shopify), Adobe Commerce 2, and "a custom
 * platform using our API". WooCommerce is not listed. A custom install loads
 * the same storefront script this reads, so it works here the same way.
 *
 * Chain (credential-free; ADAPTERS.md §3 in the Bounty app repository), all
 * read as JSON, nothing executed:
 *   home page → 32-hex site token
 *   → sdk.loyaltylion.net/sdk/start/<token>.js        live (bootstrap) or off (shutdown)
 *   → sdk.loyaltylion.net/sdk/2023-11/config/<token>  rules, tiers, rewards, currency
 *   → sdk.loyaltylion.net/sdk/translations/en/<uiMode>/<digest>  default label
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_LoyaltyLion {

	const DOLLAR_CURRENCIES = array( 'USD', 'CAD', 'AUD', 'NZD' );
	const LABEL_KEYS        = array( 'ui.general.points_count_raw', 'ui.general.points_count_raw_react' );

	public static function token( $html ) {
		$html = (string) $html;
		if ( preg_match( '~loyaltylion\.init\(\s*\{[^}]*?token\s*:\s*["\']([0-9a-f]{32})["\']~', $html, $m ) ) {
			return $m[1];
		}
		return preg_match( '~sdk\.loyaltylion\.net/sdk/start/([0-9a-f]{32})~', $html, $m ) ? $m[1] : null;
	}

	/** Liveness and translation pointers from the start script, read as data. */
	public static function parse_start( $src ) {
		$src  = (string) $src;
		$live = (bool) preg_match( '~\.bootstrap\(configuration\)~', $src ) && ! preg_match( '~\bshutdown\b~', $src );
		$out  = array(
			'live'   => $live,
			'digest' => null,
			'uiMode' => null,
		);
		if ( preg_match( '~var configuration = (\{.*?\});\s*w\.~s', $src, $m ) ) {
			$c = json_decode( $m[1], true );
			if ( is_array( $c ) ) {
				$out['digest'] = isset( $c['config']['translationsDigest'] ) ? $c['config']['translationsDigest'] : null;
				$out['uiMode'] = isset( $c['site']['uiMode'] ) ? $c['site']['uiMode'] : null;
			}
		}
		return $out;
	}

	/** "%{smart_count} point |||| %{smart_count} points" → array( one, other ), or null. */
	public static function parse_count_label( $s ) {
		if ( ! is_string( $s ) ) {
			return null;
		}
		$parts = array_map(
			function ( $p ) {
				return trim( preg_replace( '~\s+~', ' ', str_replace( '%{smart_count}', '', $p ) ) );
			},
			explode( '||||', $s )
		);
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] || preg_match( '~[%{}]~', implode( '', $parts ) ) ) {
			return null;
		}
		return array(
			'one'   => $parts[0],
			'other' => $parts[1],
		);
	}

	private static function lookup( $doc, $key ) {
		if ( ! is_array( $doc ) ) {
			return null;
		}
		if ( array_key_exists( $key, $doc ) ) {
			return $doc[ $key ];
		}
		foreach ( explode( '.', $key ) as $k ) {
			if ( ! is_array( $doc ) || ! array_key_exists( $k, $doc ) ) {
				return null;
			}
			$doc = $doc[ $k ];
		}
		return $doc;
	}

	/** The merchant's override first, then LoyaltyLion's default. */
	public static function points_label( $overrides, $translations ) {
		foreach ( self::LABEL_KEYS as $k ) {
			$v = self::parse_count_label( is_array( $overrides ) && isset( $overrides[ $k ] ) ? $overrides[ $k ] : null );
			if ( $v ) {
				return $v;
			}
		}
		$t = is_array( $translations ) && isset( $translations['translations'] ) ? $translations['translations'] : $translations;
		foreach ( self::LABEL_KEYS as $k ) {
			$v = self::parse_count_label( self::lookup( $t, $k ) );
			if ( $v ) {
				return $v;
			}
		}
		return null;
	}

	private static function fail( $step, $message ) {
		return array(
			'ok'      => false,
			'step'    => $step,
			'message' => $message,
		);
	}

	/** Pure: the purchase rule at the entry tier, and everything the sentence needs. */
	public static function select_rule( array $start, array $cfg, $label ) {
		if ( empty( $start['live'] ) ) {
			return self::fail( 'program', __( 'Your LoyaltyLion program is switched off, so purchases don\'t earn points right now.', 'open-bounty' ) );
		}
		$program = isset( $cfg['program'] ) ? $cfg['program'] : array();
		$rules   = array_values(
			array_filter(
				isset( $program['rules'] ) ? (array) $program['rules'] : array(),
				function ( $r ) {
					return is_array( $r ) && isset( $r['kind'] ) && 'purchase' === $r['kind']
						&& isset( $r['enabled'] ) && true === $r['enabled'] && empty( $r['deactivated'] ) && empty( $r['deleted'] );
				}
			)
		);
		if ( ! $rules ) {
			return self::fail( 'rule', __( 'LoyaltyLion doesn\'t publish an active purchase rule for this store.', 'open-bounty' ) );
		}
		if ( count( $rules ) > 1 ) {
			return self::fail( 'rule', __( 'LoyaltyLion reports more than one purchase rule, so there\'s no single rate to state.', 'open-bounty' ) );
		}
		$rule = $rules[0];
		// limitAmount counts pay-outs (0 = unlimited on every live purchase rule).
		if ( isset( $rule['limitAmount'] ) && (float) $rule['limitAmount'] > 0 ) {
			return self::fail( 'rule', __( 'Your LoyaltyLion purchase rule only pays a limited number of times, so "per dollar" wouldn\'t hold for every purchase.', 'open-bounty' ) );
		}

		$confs = array_values(
			array_filter(
				isset( $rule['configurations'] ) ? (array) $rule['configurations'] : array(),
				function ( $c ) {
					return is_array( $c ) && ( ! isset( $c['enabled'] ) || false !== $c['enabled'] );
				}
			)
		);
		$tiers_on = ! empty( $program['settings']['loyaltyTiersEnabled'] );
		$default  = null;
		foreach ( isset( $program['loyaltyTiers'] ) ? (array) $program['loyaltyTiers'] : array() as $t ) {
			if ( ! empty( $t['default'] ) ) {
				$default = $t['id'];
			}
		}
		$entry = null;
		foreach ( $confs as $c ) {
			if ( $tiers_on && null !== $default && isset( $c['tierId'] ) && $c['tierId'] === $default ) {
				$entry = $c;
			}
		}
		if ( null === $entry ) {
			foreach ( $confs as $c ) {
				if ( ! isset( $c['tierId'] ) ) {
					$entry = $c;
					break;
				}
			}
		}
		if ( null === $entry ) {
			$entry = array( 'value' => isset( $rule['value'] ) ? $rule['value'] : 0 );
		}
		if ( isset( $entry['multiplier'] ) && 1.0 !== (float) $entry['multiplier'] ) {
			return self::fail( 'rule', __( 'Your LoyaltyLion purchase rule applies a multiplier this sentence can\'t state.', 'open-bounty' ) );
		}
		$rate = round( (float) ( isset( $entry['value'] ) ? $entry['value'] : 0 ), 2 );
		if ( $rate <= 0 ) {
			return self::fail( 'rule', __( 'The LoyaltyLion purchase rule has no readable rate.', 'open-bounty' ) );
		}
		$higher = false;
		foreach ( $confs as $c ) {
			if ( $tiers_on && isset( $c['tierId'] ) && (float) $c['value'] > $rate ) {
				$higher = true;
			}
		}

		$currency = strtoupper( (string) ( isset( $cfg['site']['currency']['code'] ) ? $cfg['site']['currency']['code'] : '' ) );
		if ( ! in_array( $currency, self::DOLLAR_CURRENCIES, true ) ) {
			return self::fail( 'currency', __( 'Your LoyaltyLion program doesn\'t earn in dollars, and this sentence says "per dollar".', 'open-bounty' ) );
		}
		if ( ! $label ) {
			return self::fail( 'label', __( 'LoyaltyLion doesn\'t publish a name for your points.', 'open-bounty' ) );
		}
		$redeem = count( isset( $program['rewards'] ) ? (array) $program['rewards'] : array() );
		if ( 0 === $redeem ) {
			return self::fail( 'redeem', __( 'Your LoyaltyLion program has no rewards to spend points on, so "redeemable at checkout" wouldn\'t be true.', 'open-bounty' ) );
		}

		return array(
			'ok'   => true,
			'rule' => array(
				'rate'        => $rate,
				'label_one'   => $label['one'],
				'label_other' => $label['other'],
				'currency'    => $currency,
				'rule_id'     => (int) $rule['id'],
				'rule_name'   => isset( $rule['title'] ) ? (string) $rule['title'] : 'Make a purchase',
				'entry_tier'  => $higher,
				'redeem'      => $redeem,
			),
		);
	}

	/** Runs the chain against this store's own home page. */
	public static function read( $storefront_url ) {
		$res = wp_remote_get( $storefront_url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::fail( 'storefront', __( 'Your store\'s home page couldn\'t be read.', 'open-bounty' ) );
		}
		$token = self::token( wp_remote_retrieve_body( $res ) );
		if ( ! $token ) {
			return self::fail( 'install', __( 'No LoyaltyLion program was found on your store\'s pages. LoyaltyLion lists Shopify, Adobe Commerce and custom platforms; a custom install that loads LoyaltyLion\'s script on your pages can be read.', 'open-bounty' ) );
		}
		$start = self::get( 'https://sdk.loyaltylion.net/sdk/start/' . $token . '.js' );
		$cfg   = self::get( 'https://sdk.loyaltylion.net/sdk/2023-11/config/' . $token . '?locale=en' );
		$cfg   = null === $cfg ? null : json_decode( $cfg, true );
		if ( null === $start || ! is_array( $cfg ) ) {
			return self::fail( 'loyaltylion', __( 'LoyaltyLion couldn\'t be reached to read your program.', 'open-bounty' ) );
		}
		$start = self::parse_start( $start );
		$label = self::points_label( isset( $cfg['site']['translationOverrides'] ) ? $cfg['site']['translationOverrides'] : array(), null );
		if ( ! $label && $start['digest'] && $start['uiMode'] ) {
			$tr    = self::get( 'https://sdk.loyaltylion.net/sdk/translations/en/' . rawurlencode( $start['uiMode'] ) . '/' . rawurlencode( $start['digest'] ) );
			$label = null === $tr ? null : self::points_label( array(), json_decode( $tr, true ) );
		}
		return self::select_rule( $start, $cfg, $label );
	}

	private static function get( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $res );
	}
}
