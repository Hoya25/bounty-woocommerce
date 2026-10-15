<?php
/**
 * The Open Bounty Schema v0.5 `rewards` block, composed from a loyalty
 * program's live data. Same rules and wording as the Bounty app for Shopify
 * (app/lib/rewards.ts there):
 *
 *   - `value` is one sentence from fixed words plus numbers the program
 *     publishes. No rate field, nothing the program doesn't state.
 *   - `ladder` comes from the tiers, entry tier first, thresholds in the
 *     program's own unit, and `unit` says which.
 *   - `perks` are the entry tier's perks, "funded" only because the program
 *     shows them as live for that tier. A state is never guessed.
 *   - If a field can't be read truthfully, the whole block is refused and the
 *     object publishes without it. The earn sentence is unaffected.
 *
 * Discovery for each provider: ADAPTERS.md in the Bounty app repository.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Rewards {

	/** The same table as the Shopify app's rewards.ts, so both say amounts byte for byte alike. */
	const SYMBOLS = array(
		'USD' => '$',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'GBP' => '£',
		'EUR' => '€',
		'JPY' => '¥',
		'INR' => '₹',
		'MXN' => 'MX$',
		'HKD' => 'HK$',
		'BRL' => 'R$',
		'CNY' => 'CN¥',
		'KRW' => '₩',
		'ILS' => '₪',
		'TWD' => 'NT$',
		'PHP' => '₱',
		'VND' => '₫',
	);

	const DOLLAR_CURRENCIES = array( 'USD', 'CAD', 'AUD', 'NZD' );

	public static function refuse( $reason ) {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}

	/** "$5", "$7.50", "A$1", "£5", "€1,250"; a currency without a symbol here is "SEK 5". */
	public static function money( $amount, $currency ) {
		$whole = floor( $amount ) == $amount; // phpcs:ignore Universal.Operators.StrictComparisons
		$n     = number_format( (float) $amount, $whole ? 0 : 2, '.', ',' );
		return isset( self::SYMBOLS[ $currency ] ) ? self::SYMBOLS[ $currency ] . $n : $currency . ' ' . $n;
	}

	public static function is_currency( $code ) {
		return is_string( $code ) && (bool) preg_match( '/^[A-Z]{3}$/', $code );
	}

	/** "per dollar" for dollars (as every sentence has said); "per £1" otherwise (spec v0.6). */
	public static function per_unit( $currency ) {
		return in_array( $currency, self::DOLLAR_CURRENCIES, true ) ? 'per dollar' : 'per ' . self::money( 1, $currency );
	}

	/* ── v0.6 completeness ────────────────────────────────────────────────── */

	/** An absolute http(s) URL, as the app's cleanUrl() writes it, or null. */
	public static function clean_url( $raw ) {
		$s = is_string( $raw ) ? trim( $raw ) : '';
		if ( 0 === strpos( $s, '//' ) ) {
			$s = 'https:' . $s;
		}
		$p = wp_parse_url( $s );
		if ( ! $p || empty( $p['host'] ) || ! isset( $p['scheme'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? preg_replace( '~/{2,}~', '/', $p['path'] ) : '/';
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path
			. ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) . ( isset( $p['fragment'] ) ? '#' . $p['fragment'] : '' );
	}

	public static function members( $join_url = null ) {
		return $join_url ? array( 'who' => 'members', 'join_url' => $join_url ) : array( 'who' => 'members' );
	}

	private static function counted( $n, $unit ) {
		return $n . ' ' . $unit . ( 1 === (int) $n ? '' : 's' );
	}

	public static function condition_pending( $days, $label ) {
		return array( 'type' => 'pending_period', 'value' => (int) $days, 'note' => ucfirst( $label ) . ' are pending for ' . self::counted( (int) $days, 'day' ) . ' after purchase.' );
	}

	public static function condition_expires( $count, $unit, $label ) {
		return array( 'type' => 'points_expire', 'value' => (int) $count, 'note' => ucfirst( $label ) . ' expire after ' . self::counted( (int) $count, $unit ) . ' without account activity.' );
	}

	public static function condition_min_order( $amount, $currency, $what = 'Purchases earn' ) {
		$v = ( floor( $amount ) == $amount ) ? (int) $amount : (float) $amount; // phpcs:ignore Universal.Operators.StrictComparisons
		return array( 'type' => 'min_order', 'value' => $v, 'note' => $what . ' on orders of ' . self::money( $amount, $currency ) . ' or more.' );
	}

	/** Smile: points expire after a stretch without activity, when the program says so. */
	public static function smile_conditions( $pp, $label ) {
		$n    = isset( $pp['points_expiry_interval_count'] ) ? (int) $pp['points_expiry_interval_count'] : 0;
		$unit = isset( $pp['points_expiry_interval'] ) ? preg_replace( '/s$/', '', (string) $pp['points_expiry_interval'] ) : '';
		return ( isset( $pp['points_expiry_is_enabled'] ) && true === $pp['points_expiry_is_enabled'] && $n > 0 && in_array( $unit, array( 'day', 'week', 'month', 'year' ), true ) )
			? array( self::condition_expires( $n, $unit, $label ) )
			: array();
	}

	/** Yotpo: what the purchase campaign itself states. Returns array( conditions, valid_until|null ). */
	public static function yotpo_conditions( $c, $currency, $label ) {
		$out = array();
		$min = isset( $c['min_spend_cents'] ) ? (float) $c['min_spend_cents'] / 100 : 0;
		if ( $min > 0 ) {
			$out[] = self::condition_min_order( $min, $currency );
		}
		$days = isset( $c['delay_reward_days'] ) ? (int) $c['delay_reward_days'] : 0;
		if ( isset( $c['delay_reward'] ) && true === $c['delay_reward'] && $days > 0 ) {
			$out[] = self::condition_pending( $days, $label );
		}
		$ends = isset( $c['expires_at'] ) && is_string( $c['expires_at'] ) && false !== strtotime( $c['expires_at'] ) ? self::iso( strtotime( $c['expires_at'] ) ) : null;
		return array( $out, $ends );
	}

	/** LoyaltyLion: expiry after inactivity, when on. */
	public static function ll_conditions( $settings, $label ) {
		$m = isset( $settings['pointExpiryMonths'] ) ? (int) $settings['pointExpiryMonths'] : 0;
		return ( ! empty( $settings['pointExpiryEnabled'] ) && true === $settings['pointExpiryEnabled'] && isset( $settings['pointExpiryMode'] ) && 'account_inactivity' === $settings['pointExpiryMode'] && $m > 0 )
			? array( self::condition_expires( $m, 'month', $label ) )
			: array();
	}

	/** "2026-12-31T23:59:59.000Z", as the app's toISOString() writes it. */
	public static function iso( $ts ) {
		return gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $ts );
	}

	private static function usable( array $r ) {
		return is_finite( $r['points'] ) && $r['points'] > 0 && is_finite( $r['amount'] ) && $r['amount'] > 0
			&& ( 'percent_off' !== $r['kind'] || $r['amount'] <= 100 );
	}

	private static function num( $n ) {
		return ( floor( $n ) == $n ) ? (string) (int) $n : rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	/**
	 * The value sentence from one redemption: a conversion rule if there is one,
	 * else the cheapest money-off or store-credit reward, else the cheapest
	 * percent-off. Each option: points, kind (amount_off|percent_off|store_credit),
	 * amount, and optionally conversion, min_order, restricted.
	 */
	public static function compose_value( array $options, array $label, $currency ) {
		$ok = array_values( array_filter( $options, array( __CLASS__, 'usable' ) ) );
		usort(
			$ok,
			function ( $a, $b ) {
				return $a['points'] <=> $b['points'];
			}
		);
		$pick = null;
		foreach ( array( 'conversion', 'money', 'percent' ) as $pass ) {
			foreach ( $ok as $r ) {
				$is_pct = 'percent_off' === $r['kind'];
				if ( ( 'conversion' === $pass && ! empty( $r['conversion'] ) && ! $is_pct ) || ( 'money' === $pass && ! $is_pct ) || ( 'percent' === $pass && $is_pct ) ) {
					$pick = $r;
					break 2;
				}
			}
		}
		if ( ! $pick ) {
			return null;
		}
		$amount = self::money( $pick['amount'], $currency );
		if ( 'percent_off' !== $pick['kind'] && null === $amount ) {
			return null;
		}
		$pts   = self::num( $pick['points'] ) . ' ' . ( 1 == $pick['points'] ? $label['one'] : $label['other'] ); // phpcs:ignore Universal.Operators.StrictComparisons
		$worth = 'percent_off' === $pick['kind'] ? self::num( $pick['amount'] ) . '% off at checkout'
			: ( 'store_credit' === $pick['kind'] ? $amount . ' in store credit' : $amount . ' off at checkout' );
		$q = array();
		if ( ! empty( $pick['min_order'] ) && $pick['min_order'] > 0 ) {
			$min = self::money( $pick['min_order'], $currency );
			if ( null === $min ) {
				return null;
			}
			$q[] = 'on orders of ' . $min . ' or more';
		}
		if ( ! empty( $pick['restricted'] ) ) {
			$q[] = 'on eligible products';
		}
		$sentence = $pts . ' = ' . $worth . ( $q ? ', ' . implode( ', ', $q ) : '' ) . '.';
		return mb_strlen( $sentence, 'UTF-8' ) <= 200 ? $sentence : null;
	}

	/** Entry tier first, thresholds ascending from 0, names present, no duplicates. Null if not. */
	public static function compose_ladder( $basis, $unit, array $rungs, $note = null ) {
		if ( '' === (string) $unit || ! $rungs ) {
			return null;
		}
		usort(
			$rungs,
			function ( $a, $b ) {
				return $a['threshold'] <=> $b['threshold'];
			}
		);
		if ( 0 != $rungs[0]['threshold'] ) { // phpcs:ignore Universal.Operators.StrictComparisons
			return null;
		}
		$seen = array();
		$out  = array();
		foreach ( $rungs as $r ) {
			$name = trim( (string) $r['name'] );
			$t    = $r['threshold'];
			if ( '' === $name || ! is_numeric( $t ) || $t < 0 || isset( $seen[ (string) $t ] ) ) {
				return null;
			}
			$seen[ (string) $t ] = true;
			$out[]               = array(
				'rung'      => $name,
				'threshold' => ( floor( $t ) == $t ) ? (int) $t : round( (float) $t, 2 ), // phpcs:ignore Universal.Operators.StrictComparisons
			);
		}
		$ladder = array(
			'basis' => $basis,
			'unit'  => (string) $unit,
		);
		if ( $note ) {
			$ladder['note'] = ucfirst( $note );
		}
		$ladder['rungs'] = $out;
		return $ladder;
	}

	/** Entry-tier perks the program shows as live. Over-long or empty names are left out, never cut. */
	public static function compose_perks( array $perks ) {
		$seen = array();
		$out  = array();
		foreach ( $perks as $p ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string) ( isset( $p['name'] ) ? $p['name'] : '' ) ) );
			$desc = trim( preg_replace( '/\s+/u', ' ', (string) ( isset( $p['description'] ) ? $p['description'] : '' ) ) );
			$key  = mb_strtolower( $name, 'UTF-8' );
			if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > 120 || mb_strlen( $desc, 'UTF-8' ) > 300 || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$perk         = array( 'name' => $name );
			if ( '' !== $desc ) {
				$perk['description'] = $desc;
			}
			$perk['state'] = 'funded';
			$out[]         = $perk;
		}
		return $out;
	}

	/** $ladder: null (no tiers), an array, or a string (tiers that can't be read: refuse). */
	public static function compose( $value, $value_refusal, $ladder, array $perks ) {
		if ( ! $value ) {
			return self::refuse( $value_refusal );
		}
		if ( is_string( $ladder ) ) {
			return self::refuse( $ladder );
		}
		$block = array( 'value' => $value );
		if ( $ladder ) {
			$block['ladder'] = $ladder;
		}
		if ( $perks ) {
			$block['perks'] = $perks;
		}
		return array(
			'ok'    => true,
			'block' => $block,
		);
	}

	private static function label( array $rule ) {
		return array(
			'one'   => $rule['label_one'],
			'other' => $rule['label_other'],
		);
	}

	/* ── Smile.io ─────────────────────────────────────────────────────────── */

	/** Typed Smile rewards. Free products, shipping and subscription-only rewards state no worth for an order. */
	public static function smile_redemptions( array $products, $entry_tier_id ) {
		$out = array();
		foreach ( $products as $p ) {
			$w     = isset( $p['reward'] ) && is_array( $p['reward'] ) ? $p['reward'] : array();
			$tiers = isset( $p['vip_tier_ids'] ) ? (array) $p['vip_tier_ids'] : array();
			if ( ( isset( $p['is_enabled'] ) && false === $p['is_enabled'] ) || ( isset( $w['applies_on_one_time_purchase'] ) && false === $w['applies_on_one_time_purchase'] ) ) {
				continue;
			}
			if ( $tiers && ! ( null !== $entry_tier_id && in_array( $entry_tier_id, array_map( 'intval', $tiers ), true ) ) ) {
				continue;
			}
			$type = isset( $w['type'] ) ? (string) $w['type'] : '';
			if ( ! preg_match( '/^(shopify_price_rule|bigcommerce)_/', $type ) ) {
				continue;
			}
			$restricted = ! empty( $w['entitled_collection_ids'] ) || ! empty( $w['entitled_product_ids'] );
			$min        = isset( $w['minimum_order_amount'] ) && (float) $w['minimum_order_amount'] > 0 ? (float) $w['minimum_order_amount'] : null;
			$xt         = isset( $p['exchange_type'] ) ? $p['exchange_type'] : '';
			if ( 'variable' === $xt && preg_match( '/fixed_amount_discount$/', $type ) ) {
				$out[] = array( 'points' => (float) $p['variable_points_step'], 'kind' => 'amount_off', 'amount' => (float) $p['variable_points_step_reward_value'], 'conversion' => true, 'min_order' => $min, 'restricted' => $restricted );
			} elseif ( 'fixed' === $xt && preg_match( '/fixed_amount_discount$/', $type ) ) {
				$out[] = array( 'points' => (float) $p['points_price'], 'kind' => 'amount_off', 'amount' => (float) $w['value'], 'min_order' => $min, 'restricted' => $restricted );
			} elseif ( 'fixed' === $xt && preg_match( '/percentage_discount$/', $type ) ) {
				$out[] = array( 'points' => (float) $p['points_price'], 'kind' => 'percent_off', 'amount' => (float) $w['value'], 'min_order' => $min, 'restricted' => $restricted );
			}
		}
		return $out;
	}

	public static function smile( array $r, array $rule ) {
		if ( empty( $r['uiPointsProducts'] ) || ! is_array( $r['uiPointsProducts'] ) ) {
			return self::refuse( __( 'Smile.io\'s reward details couldn\'t be read.', 'open-bounty' ) );
		}
		$label  = self::label( $rule );
		$vip    = isset( $r['init']['milestone_vip_program'] ) ? $r['init']['milestone_vip_program'] : array();
		$vip_on = isset( $vip['is_enabled'] ) && true === $vip['is_enabled'];
		$tiers  = array();
		if ( $vip_on ) {
			$tiers = isset( $r['vipTiers']['vip_tiers'] ) ? (array) $r['vipTiers']['vip_tiers'] : ( isset( $vip['vip_tiers'] ) ? (array) $vip['vip_tiers'] : array() );
		}
		$entry = null;
		foreach ( $tiers as $t ) {
			if ( isset( $t['milestone'] ) && 0 == $t['milestone'] ) { // phpcs:ignore Universal.Operators.StrictComparisons
				$entry = $t;
				break;
			}
		}
		$ladder = null;
		if ( $vip_on ) {
			$type  = isset( $vip['milestone_type'] ) ? $vip['milestone_type'] : '';
			$basis = 'points_earned' === $type ? 'earned' : ( 'amount_spent' === $type ? 'spend' : null );
			if ( ! $basis ) {
				$ladder = __( 'Smile.io\'s VIP tiers are measured in a way this ladder can\'t state.', 'open-bounty' );
			} else {
				$n        = isset( $vip['milestone_interval_count'] ) ? (int) $vip['milestone_interval_count'] : 0;
				$interval = isset( $vip['milestone_interval'] ) ? $vip['milestone_interval'] : '';
				$period   = null;
				if ( isset( $vip['milestone_period'] ) && 'within_interval' === $vip['milestone_period'] && in_array( $interval, array( 'year', 'month', 'week', 'day' ), true ) && $n >= 1 ) {
					$period = 'each ' . ( 1 === $n ? $interval : $n . ' ' . $interval . 's' );
				} elseif ( isset( $vip['milestone_period'] ) && 'lifetime' === $vip['milestone_period'] ) {
					$period = "a member's lifetime";
				}
				$note   = $period ? ( 'earned' === $basis ? $label['other'] . ' earned are' : 'Spend is' ) . ' counted over ' . $period . '.' : null;
				$rungs  = array_map(
					function ( $t ) {
						return array( 'name' => isset( $t['name'] ) ? $t['name'] : '', 'threshold' => isset( $t['milestone'] ) ? (float) $t['milestone'] : -1 );
					},
					$tiers
				);
				$ladder = self::compose_ladder( $basis, 'spend' === $basis ? $rule['currency'] : $label['other'], $rungs, $note );
				if ( null === $ladder ) {
					$ladder = __( 'Smile.io\'s VIP tiers don\'t read as a ladder starting at zero.', 'open-bounty' );
				}
			}
		}
		$products = isset( $r['uiPointsProducts']['points_products'] ) ? (array) $r['uiPointsProducts']['points_products'] : array();
		$perks    = array();
		if ( $entry && ! empty( $entry['perks'] ) ) {
			foreach ( (array) $entry['perks'] as $p ) {
				$perks[] = array( 'name' => isset( $p['name'] ) ? $p['name'] : '' );
			}
		}
		return self::compose(
			self::compose_value( self::smile_redemptions( $products, $entry && isset( $entry['id'] ) ? (int) $entry['id'] : null ), $label, $rule['currency'] ),
			__( 'Smile.io lists no reward with a stated worth (money off or percent off an order), so what points are worth can\'t be said.', 'open-bounty' ),
			$ladder,
			self::compose_perks( $perks )
		);
	}

	/* ── Yotpo ────────────────────────────────────────────────────────────── */

	public static function yotpo_redemptions( array $options ) {
		$out = array();
		foreach ( $options as $o ) {
			if ( ! empty( $o['deleted'] ) || ! isset( $o['type'] ) || 'Coupon' !== $o['type'] || ! empty( $o['segment_ids'] ) || ! empty( $o['usedByActiveVipTier'] ) || ! empty( $o['usedByActiveCampaign'] ) ) {
				continue;
			}
			$restricted = ( isset( $o['applies_to_resource'] ) && 'all' !== $o['applies_to_resource'] ) || ! empty( $o['collection_ids'] ) || ! empty( $o['product_ids'] ) || ! empty( $o['variant_ids'] );
			$min        = isset( $o['cart_greater_than_cents'] ) && (float) $o['cart_greater_than_cents'] > 0 ? (float) $o['cart_greater_than_cents'] / 100 : null;
			$dt         = isset( $o['discount_type'] ) ? $o['discount_type'] : '';
			if ( 'fixed_amount' === $dt ) {
				$out[] = array( 'points' => (float) $o['amount'], 'kind' => 'amount_off', 'amount' => (float) $o['discount_amount_cents'] / 100, 'min_order' => $min, 'restricted' => $restricted );
			} elseif ( 'percentage' === $dt ) {
				$out[] = array( 'points' => (float) $o['amount'], 'kind' => 'percent_off', 'amount' => (float) $o['discount_percentage'], 'min_order' => $min, 'restricted' => $restricted );
			}
		}
		return $out;
	}

	public static function yotpo( array $l, array $rule ) {
		$label  = self::label( $rule );
		$tiers  = isset( $l['vipTiers'] ) ? array_values( array_filter( (array) $l['vipTiers'], 'is_array' ) ) : array();
		$ladder = null;
		if ( $tiers ) {
			$used = array();
			foreach ( $tiers as $t ) {
				foreach ( array( 'amount_spent_cents', 'points_earned', 'purchases_made', 'referrals_completed' ) as $k ) {
					if ( isset( $t[ $k ] ) && (float) $t[ $k ] > 0 ) {
						$used[ $k ] = true;
					}
				}
			}
			$only  = 1 === count( $used ) ? array_keys( $used )[0] : null;
			$basis = 'amount_spent_cents' === $only ? 'spend' : ( 'points_earned' === $only ? 'earned' : null );
			if ( ! $basis ) {
				$ladder = __( 'Yotpo\'s VIP tiers are reached by more than spend or points earned, so the ladder can\'t be stated.', 'open-bounty' );
			} else {
				$rungs  = array_map(
					function ( $t ) use ( $basis ) {
						return array(
							'name'      => isset( $t['name'] ) ? $t['name'] : '',
							'threshold' => 'spend' === $basis ? (float) $t['amount_spent_cents'] / 100 : (float) $t['points_earned'],
						);
					},
					$tiers
				);
				$ladder = self::compose_ladder( $basis, 'spend' === $basis ? $rule['currency'] : $label['other'], $rungs );
				if ( null === $ladder ) {
					$ladder = __( 'Yotpo\'s VIP tiers don\'t read as a ladder starting at zero.', 'open-bounty' );
				}
			}
		}
		return self::compose(
			self::compose_value( self::yotpo_redemptions( isset( $l['redemptions'] ) ? (array) $l['redemptions'] : array() ), $label, $rule['currency'] ),
			__( 'Yotpo lists no reward with a stated worth (money off or percent off an order), so what points are worth can\'t be said.', 'open-bounty' ),
			$ladder,
			array()
		);
	}

	/* ── LoyaltyLion ──────────────────────────────────────────────────────── */

	public static function ll_redemptions( array $rewards, $entry ) {
		$over = array();
		foreach ( ( $entry && isset( $entry['rewardOverrides'] ) ) ? (array) $entry['rewardOverrides'] : array() as $o ) {
			$over[ (int) $o['rewardId'] ] = $o;
		}
		$out = array();
		foreach ( $rewards as $w ) {
			$o = isset( $w['id'], $over[ (int) $w['id'] ] ) ? $over[ (int) $w['id'] ] : null;
			if ( $o && isset( $o['enabled'] ) && false === $o['enabled'] ) {
				continue;
			}
			$points     = (float) ( $o && isset( $o['cost'] ) ? $o['cost'] : ( isset( $w['point_cost'] ) ? $w['point_cost'] : 0 ) );
			$restricted = ! isset( $w['target_type'] ) || 'all' !== $w['target_type'];
			$min        = isset( $w['minimum_spend'] ) && (float) $w['minimum_spend'] > 0 ? (float) $w['minimum_spend'] : null;
			$kind       = isset( $w['kind'] ) ? (string) $w['kind'] : '';
			$dt         = isset( $w['discount_type'] ) ? $w['discount_type'] : '';
			$amount     = isset( $w['discount_amount'] ) ? (float) $w['discount_amount'] : 0;
			if ( 'checkout_redemption' === $kind && 'flat' === $dt ) {
				$out[] = array( 'points' => $points, 'kind' => 'amount_off', 'amount' => $amount, 'conversion' => true, 'min_order' => $min, 'restricted' => $restricted );
			} elseif ( preg_match( '/discount_voucher$/', $kind ) && 'flat' === $dt ) {
				$out[] = array( 'points' => $points, 'kind' => 'amount_off', 'amount' => $amount, 'min_order' => $min, 'restricted' => $restricted );
			} elseif ( preg_match( '/discount_voucher$/', $kind ) && 'percentage' === $dt ) {
				$out[] = array( 'points' => $points, 'kind' => 'percent_off', 'amount' => $amount, 'min_order' => $min, 'restricted' => $restricted );
			}
		}
		return $out;
	}

	/** Spend tiers' bounds are in minor units (cents): The Pulse Boutique's 25000 is its page's "Spend $250+". */
	public static function loyaltylion( array $cfg, array $rule ) {
		$label    = self::label( $rule );
		$program  = isset( $cfg['program'] ) ? $cfg['program'] : array();
		$settings = isset( $program['settings'] ) ? $program['settings'] : array();
		$tiers_on = isset( $settings['loyaltyTiersEnabled'] ) && true === $settings['loyaltyTiersEnabled'];
		$tiers    = array();
		$entry    = null;
		if ( $tiers_on ) {
			foreach ( isset( $program['loyaltyTiers'] ) ? (array) $program['loyaltyTiers'] : array() as $t ) {
				if ( ! empty( $t['hidden'] ) ) {
					continue;
				}
				$tiers[] = $t;
				if ( ! empty( $t['default'] ) && ! $entry ) {
					$entry = $t;
				}
			}
		}
		$ladder = null;
		if ( $tiers_on ) {
			$mode   = isset( $settings['loyaltyTiersMode'] ) ? $settings['loyaltyTiersMode'] : '';
			$basis  = 'spend' === $mode ? 'spend' : ( 'points' === $mode ? 'earned' : null );
			$months = isset( $settings['loyaltyTiersDurationMonths'] ) ? (int) $settings['loyaltyTiersDurationMonths'] : 0;
			$note   = $months > 0 ? ( 'spend' === $basis ? 'Spend is' : $label['other'] . ' earned are' ) . ' counted over the last ' . $months . ' month' . ( 1 === $months ? '' : 's' ) . '.' : null;
			if ( ! $basis || ! $entry ) {
				$ladder = __( 'LoyaltyLion\'s tiers are measured in a way this ladder can\'t state.', 'open-bounty' );
			} else {
				$rungs  = array_map(
					function ( $t ) use ( $basis ) {
						$lower = isset( $t['bounds']['lower'] ) ? (float) $t['bounds']['lower'] : -1;
						return array(
							'name'      => isset( $t['name'] ) ? $t['name'] : '',
							'threshold' => 'spend' === $basis ? $lower / 100 : $lower,
						);
					},
					$tiers
				);
				$ladder = self::compose_ladder( $basis, 'spend' === $basis ? $rule['currency'] : $label['other'], $rungs, $note );
				if ( null === $ladder ) {
					$ladder = __( 'LoyaltyLion\'s tiers don\'t read as a ladder starting at zero.', 'open-bounty' );
				}
			}
		}
		// A benefit is the entry tier's only when it names that tier; a null tier id has no stated meaning.
		$perks = array();
		if ( $entry ) {
			foreach ( isset( $program['loyaltyTierBenefits'] ) ? (array) $program['loyaltyTierBenefits'] : array() as $b ) {
				if ( empty( $b['secret'] ) && in_array( $entry['id'], isset( $b['loyaltyTierIds'] ) ? (array) $b['loyaltyTierIds'] : array(), true ) ) {
					$perks[] = array( 'name' => isset( $b['content'] ) ? $b['content'] : '', 'description' => isset( $b['details'] ) ? $b['details'] : '' );
				}
			}
		}
		return self::compose(
			self::compose_value( self::ll_redemptions( isset( $program['rewards'] ) ? (array) $program['rewards'] : array(), $entry ), $label, $rule['currency'] ),
			__( 'LoyaltyLion lists no reward with a stated worth (money off or percent off an order), so what points are worth can\'t be said.', 'open-bounty' ),
			$ladder,
			self::compose_perks( $perks )
		);
	}
}
