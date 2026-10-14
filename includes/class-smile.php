<?php
/**
 * "Use my Smile.io program": the bounty sentence composed from the store's live
 * Smile.io rule. Never typed. If any part of the rule can't be read, nothing is
 * composed and nothing is published.
 *
 * SMILE.IO DOES NOT RUN ON WOOCOMMERCE. Its supported platforms are Shopify and
 * BigCommerce, and it ships no WooCommerce loader. On an ordinary WooCommerce
 * store this option therefore finds no Smile program and says so plainly. It
 * still runs the real chain, because a store whose public pages do carry
 * Smile's markup (a front end on a supported platform) can be read the same way.
 *
 * Chain (credential-free; see ADAPTERS.md in the Bounty app repository):
 *   home page → data-channel-key on .smile-*-init
 *   → platform.smile.io/smile_ui/exchange_tokens          publishable key
 *   → api.smile.io/smile_js/earning_rules                  rule values (documented)
 *   → platform.smile.io/smile_ui/customer_activity_rules   the PURCHASE rule, by type
 *   → api.smile.io/smile_js/preloadable_program_data       points label, ways to redeem
 *   → platform.smile.io/smile_ui/init                      enabled flag, currency
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Smile {

	const PURCHASE_TYPES   = array( 'shopify_online_order', 'bigcommerce_order' );
	const DOLLAR_CURRENCIES = array( 'USD', 'CAD', 'AUD', 'NZD' );
	const NOUNS            = array( 'Members', 'Customers' );

	/** Channel key from Smile's init markup, or null. */
	public static function channel_key( $html ) {
		if ( preg_match( '~class="smile-[a-z]+-init"[^>]*data-channel-key="((?:pk|channel)_[A-Za-z0-9]+)"~', (string) $html, $m )
			|| preg_match( '~data-channel-key="((?:pk|channel)_[A-Za-z0-9]+)"[^>]*class="smile-[a-z]+-init"~', (string) $html, $m ) ) {
			return $m[1];
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

	/**
	 * Pure: picks the purchase rule and checks everything the sentence depends on.
	 *
	 * @param array $r earningRules, activityRules, programData, init — decoded JSON.
	 */
	public static function select_rule( array $r ) {
		if ( true !== self::dig( $r, array( 'init', 'points_program', 'is_enabled' ) ) ) {
			return self::fail( 'program', __( 'Your Smile.io points program is turned off, so purchases don\'t earn points right now.', 'open-bounty' ) );
		}

		$purchase_ids = array();
		foreach ( (array) self::dig( $r, array( 'activityRules', 'customer_activity_rules' ) ) as $a ) {
			$rule = isset( $a['activity_rule'] ) ? $a['activity_rule'] : null;
			if ( $rule && in_array( isset( $rule['type'] ) ? $rule['type'] : '', self::PURCHASE_TYPES, true ) && false !== ( isset( $rule['is_enabled'] ) ? $rule['is_enabled'] : true ) ) {
				$purchase_ids[] = $rule['id'];
			}
		}
		$purchase = array_values(
			array_filter(
				(array) self::dig( $r, array( 'earningRules', 'earning_rules' ) ),
				function ( $x ) use ( $purchase_ids ) {
					return isset( $x['id'] ) && in_array( $x['id'], $purchase_ids, true );
				}
			)
		);
		if ( ! $purchase ) {
			return self::fail( 'rule', __( 'Smile.io doesn\'t publish a points-for-purchases rule for this store.', 'open-bounty' ) );
		}
		if ( count( $purchase ) > 1 ) {
			return self::fail( 'rule', __( 'Smile.io reports more than one purchase rule, so there\'s no single rate to state.', 'open-bounty' ) );
		}
		$rule = $purchase[0];

		if ( 'variable' !== self::dig( $rule, array( 'reward_value', 'type' ) ) ) {
			return self::fail( 'rule', __( 'Your Smile.io purchase rule pays a fixed amount per order, not per dollar, so this sentence can\'t describe it.', 'open-bounty' ) );
		}
		$value = (float) self::dig( $rule, array( 'reward_value', 'variable', 'value' ) );
		$per   = self::dig( $rule, array( 'reward_value', 'variable', 'per_amount' ) );
		$per   = null === $per ? 1.0 : (float) $per;
		if ( $value <= 0 || $per <= 0 ) {
			return self::fail( 'rule', __( 'The Smile.io purchase rule has no readable rate.', 'open-bounty' ) );
		}
		$rate = round( $value / $per, 2 );

		$currency = strtoupper( (string) self::dig( $r, array( 'init', 'account', 'currency', 'iso_code' ) ) );
		if ( ! in_array( $currency, self::DOLLAR_CURRENCIES, true ) ) {
			return self::fail( 'currency', __( 'Your Smile.io program doesn\'t earn in dollars, and this sentence says "per dollar".', 'open-bounty' ) );
		}

		$one   = trim( (string) self::dig( $r, array( 'programData', 'points_settings', 'points_label', 'one' ) ) );
		$other = trim( (string) self::dig( $r, array( 'programData', 'points_settings', 'points_label', 'other' ) ) );
		if ( '' === $one || '' === $other ) {
			return self::fail( 'label', __( 'Smile.io doesn\'t publish a name for your points.', 'open-bounty' ) );
		}

		$redeem = count( (array) self::dig( $r, array( 'programData', 'points_products' ) ) );
		if ( 0 === $redeem ) {
			return self::fail( 'redeem', __( 'Your Smile.io program has no rewards to spend points on, so "redeemable at checkout" wouldn\'t be true.', 'open-bounty' ) );
		}

		return array(
			'ok'   => true,
			'rule' => array(
				'rate'        => $rate,
				'label_one'   => $one,
				'label_other' => $other,
				'currency'    => $currency,
				'rule_id'     => (int) $rule['id'],
				'rule_name'   => isset( $rule['name'] ) ? (string) $rule['name'] : '',
				'entry_tier'  => ! empty( $rule['restricted_to_vip_tier_ids'] ),
				'redeem'      => $redeem,
			),
		);
	}

	/** Fixed words plus the live number and the program's own label. */
	public static function compose( array $rule, $noun ) {
		$noun  = in_array( $noun, self::NOUNS, true ) ? $noun : 'Members';
		$rate  = $rule['rate'];
		$n     = ( floor( $rate ) == $rate ) ? (string) (int) $rate : rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ); // phpcs:ignore Universal.Operators.StrictComparisons
		$label = '1' === $n ? $rule['label_one'] : $rule['label_other'];
		return sprintf( '%s earn %s %s per dollar, redeemable at checkout.', $noun, $n, $label );
	}

	/** The object for this store, or a refusal. */
	public static function build_object( array $rule, $noun, $store_name, $store_url ) {
		return array(
			'bounty_schema_version' => Open_Bounty_Schema::OBJECT_VERSION,
			'active'                => true,
			'program'               => array(
				'name' => $store_name,
				'url'  => $store_url,
			),
			'earn'                  => array(
				'display'      => self::compose( $rule, $noun ),
				'denomination' => $rule['label_other'],
				'type'         => 'points',
			),
			'updated_at'            => gmdate( 'Y-m-d' ),
		);
	}

	/** One line saying exactly which rule was read. */
	public static function provenance( array $rule, $label = 'Smile.io' ) {
		return sprintf(
			/* translators: 1: provider, 2: rule id, 3: rule name, 4: rate, 5: currency */
			__( 'Read live from %1$s: purchase rule %2$d (“%3$s”), %4$s per 1 %5$s.', 'open-bounty' ),
			$label,
			$rule['rule_id'],
			$rule['rule_name'],
			$rule['rate'],
			$rule['currency']
		) . ( $rule['entry_tier'] ? ' ' . __( 'This is the entry VIP tier\'s rate; higher tiers earn more.', 'open-bounty' ) : '' );
	}

	/** Runs the chain against this store's own home page. */
	public static function read( $storefront_url ) {
		$res = wp_remote_get( $storefront_url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::fail( 'storefront', __( 'Your store\'s home page couldn\'t be read.', 'open-bounty' ) );
		}
		$key = self::channel_key( wp_remote_retrieve_body( $res ) );
		if ( ! $key ) {
			return self::fail( 'install', __( 'No Smile.io program was found on your store\'s pages. Smile.io runs on Shopify and BigCommerce and doesn\'t offer a WooCommerce integration, so there\'s nothing here to read.', 'open-bounty' ) );
		}

		$exchange = Open_Bounty_Schema::fetch_json( 'https://platform.smile.io/smile_ui/exchange_tokens?channel_key=' . rawurlencode( $key ) );
		$pub      = is_array( $exchange ) && isset( $exchange['publishable_key'] ) ? (string) $exchange['publishable_key'] : '';
		if ( ! preg_match( '/^pub_[A-Za-z0-9]+$/', $pub ) ) {
			return self::fail( 'smile', __( 'Smile.io didn\'t return a key for your program.', 'open-bounty' ) );
		}
		$h    = array( 'X-Smile-Publishable-Key' => $pub );
		$ui   = array_merge( $h, array( 'Smile-Client' => 'smile-ui' ) );
		$data = array(
			'earningRules'  => self::get( 'https://api.smile.io/smile_js/earning_rules', $h ),
			'activityRules' => self::get( 'https://platform.smile.io/smile_ui/customer_activity_rules', $ui ),
			'programData'   => self::get( 'https://api.smile.io/smile_js/preloadable_program_data?include=points_settings,points_products', $h ),
			'init'          => self::get( 'https://platform.smile.io/smile_ui/init?publishable_key=' . rawurlencode( $pub ), $ui ),
		);
		foreach ( $data as $v ) {
			if ( null === $v ) {
				return self::fail( 'smile', __( 'Smile.io couldn\'t be reached to read your program.', 'open-bounty' ) );
			}
		}
		return self::select_rule( $data );
	}

	private static function get( $url, array $headers ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $doc ) ? $doc : null;
	}

	private static function dig( $arr, array $path ) {
		foreach ( $path as $k ) {
			if ( ! is_array( $arr ) || ! array_key_exists( $k, $arr ) ) {
				return null;
			}
			$arr = $arr[ $k ];
		}
		return $arr;
	}
}
