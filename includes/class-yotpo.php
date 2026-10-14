<?php
/**
 * "Use my Yotpo Loyalty program": the sentence composed from the store's live
 * Yotpo purchase campaign. Never typed. If any part can't be read, nothing is
 * composed and nothing is published.
 *
 * YOTPO AND WOOCOMMERCE. Yotpo's support FAQ states that it "does not currently
 * support new WooCommerce accounts" and that "WooCommerce is not considered a
 * supported platform at this time" (a Generic or Custom integration is
 * required). Existing and custom installs still put Yotpo's loader on the
 * storefront, and that is what this reads, so they work here the same way.
 *
 * Source (credential-free; ADAPTERS.md §2 in the Bounty app repository):
 *   home page → loyalty GUID (cdn-loyalty loader, or a 22-char widgetsrepository
 *   key; a 40-char key there is Yotpo Reviews and is ignored)
 *   → cdn-loyalty.yotpo.com/loader/<GUID>.js, read as DATA: the JSON.parse("…")
 *     string literals are decoded, the script never runs
 *   → cdn-widgetsrepository.yotpo.com/v1/loader/<GUID>, only for "points-label"
 *     when the legacy loader has none, and only if every widget agrees
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Yotpo {

	const DOLLAR_CURRENCIES = array( 'USD', 'CAD', 'AUD', 'NZD' );

	public static function guid( $html ) {
		$html = (string) $html;
		if ( preg_match( '~cdn-loyalty\.yotpo\.com/loader/([A-Za-z0-9_-]{22})\.js~', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '~cdn-widgetsrepository\.yotpo\.com/v1/loader/([A-Za-z0-9_-]{22})(?![A-Za-z0-9_-])~', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** Decodes a JavaScript string literal's body. Never evaluates it. */
	public static function js_unescape( $raw ) {
		$simple = array( 'n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\f", 'v' => "\v", '0' => "\0" );
		$out    = '';
		$len    = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $raw[ $i ];
			if ( '\\' !== $c || $i + 1 >= $len ) {
				$out .= $c;
				continue;
			}
			$n = $raw[ $i + 1 ];
			if ( 'x' === $n ) {
				$out .= self::utf8( hexdec( substr( $raw, $i + 2, 2 ) ) );
				$i   += 3;
			} elseif ( 'u' === $n ) {
				$out .= self::utf8( hexdec( substr( $raw, $i + 2, 4 ) ) );
				$i   += 5;
			} else {
				$out .= isset( $simple[ $n ] ) ? $simple[ $n ] : $n;
				$i   += 1;
			}
		}
		return $out;
	}

	private static function utf8( $code ) {
		return function_exists( 'mb_chr' ) ? mb_chr( $code, 'UTF-8' ) : html_entity_decode( '&#' . (int) $code . ';', ENT_NOQUOTES, 'UTF-8' );
	}

	private static function block( $src, $name ) {
		// Possessive quantifiers: a real loader's campaigns literal is ~80 KB, and
		// the backtracking form exhausts PCRE's JIT stack and silently fails.
		if ( ! preg_match( '~\n\s{0,8}' . preg_quote( $name, '~' ) . ':\s*JSON\.parse\("((?:[^"\\\\]++|\\\\.)*+)"\)~s', $src, $m ) ) {
			return null;
		}
		$doc = json_decode( self::js_unescape( $m[1] ), true );
		return null === $doc ? null : $doc;
	}

	/** Pure: the loader's embedded program data, or null when it isn't a loyalty loader. */
	public static function parse_loader( $src ) {
		$merchant = self::block( (string) $src, 'merchant' );
		if ( ! is_array( $merchant ) ) {
			return null;
		}
		$live = preg_match( '~\bprogramLive:\s*(true|false)\b~', $src, $m ) ? 'true' === $m[1] : null;
		$get  = function ( $name, $default ) use ( $src ) {
			$v = self::block( $src, $name );
			return null === $v ? $default : $v;
		};
		return array(
			'programLive'  => $live,
			'merchant'     => $merchant,
			'campaigns'    => $get( 'activeCampaigns', array() ),
			'redemptions'  => $get( 'activeRedemptionOptions', array() ),
			'vipTiers'     => $get( 'activeVipTiers', array() ),
			'localization' => $get( 'localization', array() ),
		);
	}

	public static function widget_points_labels( $src ) {
		preg_match_all( '~"points-label"\s*:\s*"([^"]{1,40})"~', (string) $src, $m );
		return array_values( array_unique( array_filter( array_map( 'trim', $m[1] ) ) ) );
	}

	/** Singular from a simple plural only ("Points" → "Point"); null otherwise. */
	public static function singular_of( $plural ) {
		if ( ! preg_match( '~s$~i', $plural ) || preg_match( '~(ss|us|is|ses|shes|ches|xes|zes)$~i', $plural ) ) {
			return null;
		}
		return substr( $plural, 0, -1 );
	}

	private static function fail( $step, $message ) {
		return array(
			'ok'      => false,
			'step'    => $step,
			'message' => $message,
		);
	}

	/** Pure: the single store-wide purchase rule, and everything the sentence needs. */
	public static function select_rule( array $l, array $widget_labels = array() ) {
		if ( true !== $l['programLive'] ) {
			return self::fail( 'program', __( 'Your Yotpo Loyalty program isn\'t live, so purchases don\'t earn points right now.', 'open-bounty' ) );
		}

		$purchase = array_values(
			array_filter(
				(array) $l['campaigns'],
				function ( $c ) {
					return is_array( $c )
						&& isset( $c['type'] ) && 'PointsForPurchasesCampaign' === $c['type']
						&& ( ! isset( $c['active'] ) || false !== $c['active'] )
						&& isset( $c['product_restrictions_reward'] ) && 'entire_cart' === $c['product_restrictions_reward']
						&& '' === trim( (string) ( isset( $c['customer_tags'] ) ? $c['customer_tags'] : '' ) )
						&& empty( $c['campaign_tiers'] );
				}
			)
		);
		if ( ! $purchase ) {
			return self::fail( 'rule', __( 'Yotpo doesn\'t publish a store-wide points-for-purchases rule for this store.', 'open-bounty' ) );
		}
		if ( count( $purchase ) > 1 ) {
			return self::fail( 'rule', __( 'Yotpo reports more than one store-wide purchase rule, so there\'s no single rate to state.', 'open-bounty' ) );
		}
		$c      = $purchase[0];
		$points = isset( $c['reward_points'] ) ? (float) $c['reward_points'] : 0;
		$spend  = isset( $c['goal_spend_cents'] ) ? (float) $c['goal_spend_cents'] / 100 : 0;
		if ( $points <= 0 || $spend <= 0 ) {
			return self::fail( 'rule', __( 'Your Yotpo purchase rule doesn\'t pay points per amount spent, so this sentence can\'t describe it.', 'open-bounty' ) );
		}
		$rate = round( $points / $spend, 2 );

		$multipliers = array();
		foreach ( (array) $l['vipTiers'] as $t ) {
			$m = isset( $t['points_multiplier'] ) ? (float) $t['points_multiplier'] : 1.0;
			if ( $m > 0 ) {
				$multipliers[] = $m;
			}
		}
		if ( $multipliers && 1.0 !== min( $multipliers ) ) {
			return self::fail( 'tiers', __( 'Every Yotpo VIP tier multiplies the purchase rate, so the base rate isn\'t what any member earns.', 'open-bounty' ) );
		}

		$currency = strtoupper( (string) ( isset( $l['merchant']['currency']['currency_iso'] ) ? $l['merchant']['currency']['currency_iso'] : '' ) );
		if ( ! in_array( $currency, self::DOLLAR_CURRENCIES, true ) ) {
			return self::fail( 'currency', __( 'Your Yotpo program doesn\'t earn in dollars, and this sentence says "per dollar".', 'open-bounty' ) );
		}

		$legacy = isset( $l['localization']['points'] ) && is_string( $l['localization']['points'] ) ? trim( $l['localization']['points'] ) : '';
		$other  = '' !== $legacy ? $legacy : ( 1 === count( $widget_labels ) ? $widget_labels[0] : '' );
		if ( '' === $other ) {
			return self::fail( 'label', count( $widget_labels ) > 1 ? __( 'Your Yotpo widgets call your points by different names, so there\'s no single name to use.', 'open-bounty' ) : __( 'Yotpo doesn\'t publish a name for your points.', 'open-bounty' ) );
		}
		$one = 1.0 === (float) $rate ? self::singular_of( $other ) : $other;
		if ( null === $one ) {
			/* translators: %s: the plural points label */
			return self::fail( 'label', sprintf( __( 'Yotpo only gives the plural "%s", and its singular can\'t be worked out safely.', 'open-bounty' ), $other ) );
		}

		$redeem = count( (array) $l['redemptions'] );
		if ( 0 === $redeem ) {
			return self::fail( 'redeem', __( 'Your Yotpo program has no rewards to spend points on, so "redeemable at checkout" wouldn\'t be true.', 'open-bounty' ) );
		}

		return array(
			'ok'   => true,
			'rule' => array(
				'rate'        => $rate,
				'label_one'   => $one,
				'label_other' => $other,
				'currency'    => $currency,
				'rule_id'     => (int) $c['id'],
				'rule_name'   => isset( $c['details'] ) ? (string) $c['details'] : 'Points for purchases',
				'entry_tier'  => $multipliers && max( $multipliers ) > 1,
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
		$guid = self::guid( wp_remote_retrieve_body( $res ) );
		if ( ! $guid ) {
			return self::fail( 'install', __( 'No Yotpo Loyalty program was found on your store\'s pages. Yotpo doesn\'t support new WooCommerce accounts; existing or custom installs that load Yotpo\'s loyalty script on your pages can be read.', 'open-bounty' ) );
		}
		$loader = self::get( 'https://cdn-loyalty.yotpo.com/loader/' . $guid . '.js' );
		if ( null === $loader ) {
			return self::fail( 'yotpo', __( 'Yotpo couldn\'t be reached to read your program.', 'open-bounty' ) );
		}
		$parsed = self::parse_loader( $loader );
		if ( null === $parsed ) {
			return self::fail( 'install', __( 'Yotpo Loyalty isn\'t set up on your store\'s pages.', 'open-bounty' ) );
		}
		$labels = array();
		if ( ! isset( $parsed['localization']['points'] ) ) {
			$widgets = self::get( 'https://cdn-widgetsrepository.yotpo.com/v1/loader/' . $guid );
			$labels  = null === $widgets ? array() : self::widget_points_labels( $widgets );
		}
		return self::select_rule( $parsed, $labels );
	}

	private static function get( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $res );
	}
}
