<?php
/**
 * "Use my Klaviyo welcome offer": the coupon this store's live Klaviyo signup
 * forms hand out, stated as a new-subscriber bounty. The sentence is composed
 * from structured data, never typed; anything that can't be read is a refusal.
 *
 * Two halves (ADAPTERS.md §7 in the Bounty app repository):
 *   1. WHICH coupon: Klaviyo, credential-free. The home page loads Klaviyo's
 *      onsite script with the public company id; Klaviyo's WooCommerce plugin
 *      enqueues it as static.klaviyo.com/onsite/js/<id>/klaviyo.js.
 *      static-forms.klaviyo.com/forms/api/v7/<id>/full-forms lists the live
 *      signup forms, and a form's COUPON block names the offer: a static code,
 *      or a unique-code coupon whose name is the WooCommerce coupon Klaviyo
 *      issues codes from ("the same code name you assigned to the linked coupon
 *      in WooCommerce", Klaviyo help 22168739689627). The form's own wording
 *      ("Get 15% off") is free text and is never read.
 *   2. WHAT it gives: this store's own WooCommerce coupon, read locally.
 *
 * Eligibility is in the sentence ("New email subscribers get …") until spec
 * v0.6 adds structured fields for it.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Klaviyo {

	/** The coupon block's default text in Klaviyo's editor, left unchanged (kosas.com). Not a code. */
	const PLACEHOLDER = '~^enter coupon$~i';

	private static function fail( $step, $message ) {
		return array(
			'ok'      => false,
			'step'    => $step,
			'message' => $message,
		);
	}

	/** The public company id from the store's HTML (Shopify and WooCommerce script shapes), or null. */
	public static function company_id( $html ) {
		$html = (string) $html;
		if ( preg_match( '~static\.klaviyo\.com/onsite/js/([A-Za-z0-9]{6})/klaviyo\.js~', $html, $m ) ) {
			return $m[1];
		}
		return preg_match( '~klaviyo\.com/onsite/js/klaviyo\.js\?(?:[^"\'\s]*&(?:amp;)?)?company_id=([A-Za-z0-9]{6})~', $html, $m ) ? $m[1] : null;
	}

	private static function components( $version ) {
		$out = array();
		foreach ( isset( $version['views'] ) ? (array) $version['views'] : array() as $view ) {
			foreach ( isset( $view['columns'] ) ? (array) $view['columns'] : array() as $col ) {
				foreach ( isset( $col['rows'] ) ? (array) $col['rows'] : array() as $row ) {
					foreach ( isset( $row['components'] ) ? (array) $row['components'] : array() as $c ) {
						if ( is_array( $c ) ) {
							$out[] = $c;
						}
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Pure: the one coupon the live signup forms hand out. Forms that collect
	 * no email or phone number aren't signup forms. Every coupon found must be
	 * the same offer. A unique coupon must be a WooCommerce one.
	 */
	public static function select_coupon( $full_forms ) {
		$forms  = is_array( $full_forms ) && isset( $full_forms['full_forms'] ) ? (array) $full_forms['full_forms'] : array();
		$refs   = array();
		$signup = 0;
		foreach ( $forms as $f ) {
			foreach ( isset( $f['live_form_versions'] ) ? (array) $f['live_form_versions'] : array() as $v ) {
				$all      = self::components( $v );
				$channels = array();
				foreach ( $all as $c ) {
					$type = isset( $c['component_type'] ) ? $c['component_type'] : '';
					if ( 'EMAIL' === $type || ( isset( $c['data']['field_id'] ) && '$email' === $c['data']['field_id'] ) ) {
						$channels['email'] = true;
					}
					if ( 'PHONE_NUMBER' === $type ) {
						$channels['sms'] = true;
					}
				}
				if ( ! $channels ) {
					continue;
				}
				++$signup;
				foreach ( $all as $c ) {
					if ( ! isset( $c['component_type'] ) || 'COUPON' !== $c['component_type'] ) {
						continue;
					}
					$data = isset( $c['data'] ) ? $c['data'] : array();
					$cd   = isset( $data['coupon_data'] ) && is_array( $data['coupon_data'] ) ? $data['coupon_data'] : array();
					$kind = isset( $data['coupon_type'] ) ? $data['coupon_type'] : '';
					$text = isset( $cd['text'] ) && is_string( $cd['text'] ) ? trim( $cd['text'] ) : '';
					$name = isset( $cd['name'] ) && is_string( $cd['name'] ) ? trim( $cd['name'] ) : '';
					if ( 'static' === $kind && '' !== $text && ! preg_match( self::PLACEHOLDER, $text ) ) {
						$key = 'static:' . strtolower( $text );
						$ref = array( 'kind' => 'static', 'code' => $text );
					} elseif ( 'unique' === $kind && '' !== $name && isset( $cd['type'] ) && 'woocommerce' === $cd['type'] ) {
						$key = 'unique:' . strtolower( $name );
						$ref = array( 'kind' => 'unique', 'code' => $name );
					} else {
						continue;
					}
					if ( ! isset( $refs[ $key ] ) ) {
						$refs[ $key ] = $ref + array(
							'channels' => array(),
							'forms'    => array(),
						);
					}
					$refs[ $key ]['channels'] = array_values( array_unique( array_merge( $refs[ $key ]['channels'], array_keys( $channels ) ) ) );
					$id                       = isset( $f['form_id'] ) ? (string) $f['form_id'] : '';
					if ( '' !== $id && ! in_array( $id, $refs[ $key ]['forms'], true ) ) {
						$refs[ $key ]['forms'][] = $id;
					}
				}
			}
		}
		if ( 0 === $signup ) {
			return self::fail( 'forms', __( 'Klaviyo shows no live signup form on your store.', 'open-bounty' ) );
		}
		if ( ! $refs ) {
			return self::fail( 'coupon', __( 'None of your live Klaviyo signup forms has a WooCommerce coupon block, so the offer exists only as the form\'s text, which isn\'t read. Add a coupon block to the form\'s success step, then try again.', 'open-bounty' ) );
		}
		if ( count( $refs ) > 1 ) {
			/* translators: %d: number of coupons */
			return self::fail( 'coupon', sprintf( __( 'Your live Klaviyo signup forms hand out %d different coupons, so there\'s no single welcome offer to state.', 'open-bounty' ), count( $refs ) ) );
		}
		$ref = array_values( $refs )[0];
		sort( $ref['channels'] );
		return array(
			'ok'  => true,
			'ref' => $ref,
		);
	}

	/**
	 * Pure: what a WooCommerce coupon gives, from its fields (an array, so the
	 * test needs no WooCommerce). `$now` is a Unix time.
	 */
	public static function describe_coupon( $c, array $ref, $currency, $now ) {
		$code = isset( $ref['code'] ) ? $ref['code'] : '';
		if ( ! is_array( $c ) ) {
			return self::fail(
				'coupon',
				'static' === $ref['kind']
					/* translators: %s: code */
					? sprintf( __( 'Your Klaviyo signup form gives the code “%s”, and no coupon on this store has that code.', 'open-bounty' ), $code )
					/* translators: %s: coupon name */
					: sprintf( __( 'Your Klaviyo signup form issues codes from the coupon “%s”, and no coupon on this store has that code. Klaviyo links to the WooCommerce coupon with the same code.', 'open-bounty' ), $code )
			);
		}
		$named = '“' . $code . '”';
		if ( 'publish' !== $c['status'] ) {
			/* translators: %s: code */
			return self::fail( 'status', sprintf( __( 'The coupon %s isn\'t published.', 'open-bounty' ), $named ) );
		}
		// Klaviyo overrides the expiry on the codes it issues, so a unique coupon's own date says nothing about them.
		$expires = 'static' === $ref['kind'] && $c['date_expires'] ? (int) $c['date_expires'] : null;
		if ( null !== $expires && $expires <= $now ) {
			/* translators: %s: code */
			return self::fail( 'status', sprintf( __( 'The coupon %s has expired.', 'open-bounty' ), $named ) );
		}
		if ( 'static' === $ref['kind'] && $c['usage_limit'] > 0 && $c['usage_count'] >= $c['usage_limit'] ) {
			/* translators: %s: code */
			return self::fail( 'status', sprintf( __( 'The coupon %s has been used up.', 'open-bounty' ), $named ) );
		}
		if ( $c['email_restrictions'] ) {
			/* translators: %s: code */
			return self::fail( 'eligibility', sprintf( __( 'The coupon %s is limited to some email addresses, so it can\'t be stated for every new subscriber.', 'open-bounty' ), $named ) );
		}
		if ( $c['product_ids'] || $c['excluded_product_ids'] || $c['product_categories'] || $c['excluded_product_categories'] || $c['exclude_sale_items'] || $c['limit_usage_to_x_items'] ) {
			/* translators: %s: code */
			return self::fail( 'scope', sprintf( __( 'The coupon %s applies only to some products, so it can\'t be stated store-wide.', 'open-bounty' ), $named ) );
		}
		if ( $c['maximum_amount'] > 0 ) {
			/* translators: %s: code */
			return self::fail( 'scope', sprintf( __( 'The coupon %s has a maximum order amount, which this sentence can\'t state.', 'open-bounty' ), $named ) );
		}
		// v0.6: any currency.
		if ( ! Open_Bounty_Rewards::is_currency( $currency ) ) {
			return self::fail( 'currency', __( 'WooCommerce didn\'t say which currency your store uses.', 'open-bounty' ) );
		}

		$amount = (float) $c['amount'];
		if ( $amount > 0 ) {
			switch ( $c['discount_type'] ) {
				case 'percent':
					if ( $amount > 100 ) {
						return self::fail( 'value', __( 'The coupon\'s percentage can\'t be read.', 'open-bounty' ) );
					}
					$worth = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '% off';
					break;
				case 'fixed_cart':
					$worth = Open_Bounty_Rewards::money( $amount, $currency ) . ' off';
					break;
				case 'fixed_product':
					$worth = Open_Bounty_Rewards::money( $amount, $currency ) . ' off each item';
					break;
				default:
					/* translators: %s: code */
					return self::fail( 'value', sprintf( __( 'The coupon %s is a kind of discount this sentence can\'t state.', 'open-bounty' ), $named ) );
			}
			if ( $c['free_shipping'] ) {
				$worth .= ' and free shipping';
			}
		} elseif ( $c['free_shipping'] ) {
			$worth = 'free shipping';
		} else {
			/* translators: %s: code */
			return self::fail( 'value', sprintf( __( 'The coupon %s takes nothing off.', 'open-bounty' ), $named ) );
		}

		$conditions = array();
		if ( $c['minimum_amount'] > 0 ) {
			$conditions[] = 'on orders of ' . Open_Bounty_Rewards::money( (float) $c['minimum_amount'], $currency ) . ' or more';
		}
		if ( null !== $expires ) {
			$conditions[] = 'through ' . gmdate( 'M j, Y', $expires );
		}
		return array(
			'ok'    => true,
			'offer' => array(
				'worth'      => $worth,
				'conditions' => $conditions,
				// Once per customer, or a single-use code: Klaviyo's article suggests a usage limit of 1, but it's optional.
				'once'       => 1 === (int) $c['usage_limit_per_user'] || ( 'unique' === $ref['kind'] && 1 === (int) $c['usage_limit'] ),
				'id'         => (int) $c['id'],
				// v0.6: the same facts as fields.
				'facts'      => array(
					'min'        => (float) $c['minimum_amount'],
					'expires'    => $expires,
					'individual' => ! empty( $c['individual_use'] ),
					'currency'   => $currency,
				),
			),
		);
	}

	/** Pure: "New email subscribers get 15% off one order, on orders of $50 or more." */
	public static function compose( array $offer, array $ref ) {
		$names = array_map(
			function ( $ch ) {
				return 'email' === $ch ? 'email' : 'text';
			},
			$ref['channels']
		);
		$worth = $offer['worth'];
		if ( $offer['once'] ) {
			// "15% off one order", "$5 off each item on one order", "free shipping on one order".
			$worth .= preg_match( '~(item|shipping)$~', $worth ) ? ' on one order' : ' one order';
		}
		$tail = $offer['conditions'] ? ', ' . implode( ', ', $offer['conditions'] ) : '';
		return 'New ' . implode( ' or ', $names ) . ' subscribers get ' . $worth . $tail . '.';
	}

	/**
	 * Pure: the v0.6 fields, from the same coupon the sentence came from. Every
	 * note is fixed words plus a field; nothing is read from the form's text.
	 */
	public static function offer_fields( array $offer, array $ref ) {
		$f          = isset( $offer['facts'] ) ? $offer['facts'] : array();
		$currency   = isset( $f['currency'] ) ? $f['currency'] : '';
		$conditions = array();
		if ( ! empty( $f['min'] ) && $f['min'] > 0 ) {
			$min          = ( floor( $f['min'] ) == $f['min'] ) ? (int) $f['min'] : (float) $f['min']; // phpcs:ignore Universal.Operators.StrictComparisons
			$conditions[] = array( 'type' => 'min_order', 'value' => $min, 'note' => 'On orders of ' . Open_Bounty_Rewards::money( $f['min'], $currency ) . ' or more.' );
		}
		if ( ! empty( $offer['once'] ) ) {
			$conditions[] = array( 'type' => 'once_per_customer', 'note' => 'One use per customer.' );
		}
		if ( ! empty( $f['expires'] ) ) {
			$conditions[] = array( 'type' => 'limited_time', 'note' => 'Through ' . gmdate( 'M j, Y', (int) $f['expires'] ) . '.' );
		}
		$names = array_map(
			function ( $ch ) {
				return 'email' === $ch ? 'email' : 'text';
			},
			$ref['channels']
		);
		$out = array(
			'currency'    => $currency,
			'eligibility' => array( 'who' => 'new_subscribers', 'note' => 'For new ' . implode( ' or ', $names ) . ' subscribers.' ),
		);
		if ( $conditions ) {
			$out['conditions'] = $conditions;
		}
		if ( ! empty( $f['expires'] ) ) {
			$out['valid_until'] = Open_Bounty_Rewards::iso( $f['expires'] );
		}
		// WooCommerce's "Individual use only" is the coupon's own stacking setting.
		if ( isset( $f['individual'] ) ) {
			$out['stacking'] = $f['individual']
				? array( 'combinable' => false, 'note' => 'Doesn\'t combine with other coupons.' )
				: array( 'combinable' => true, 'note' => 'Combines with other coupons.' );
		}
		return $out;
	}

	public static function build_object( array $read, $store_name, $store_url ) {
		$fields = self::offer_fields( $read['offer'], $read['ref'] );
		return array(
			'bounty_schema_version' => '0.6',
			'active'                => true,
			'program'               => array(
				'name' => $store_name,
				'url'  => $store_url,
			),
			'earn'                  => array(
				'display' => self::compose( $read['offer'], $read['ref'] ),
				'type'    => 'other',
			),
		) + $fields + array( 'updated_at' => gmdate( 'Y-m-d' ) );
	}

	public static function provenance( array $read ) {
		$ref = $read['ref'];
		return sprintf(
			/* translators: 1: number of forms, 2: form ids, 3: coupon, 4: coupon id, 5: worth */
			__( 'Read live from Klaviyo: %1$d live signup form(s) (%2$s) give %3$s. Read from WooCommerce: coupon %4$d, %5$s.', 'open-bounty' ),
			count( $ref['forms'] ),
			implode( ', ', $ref['forms'] ),
			'static' === $ref['kind'] ? '“' . $ref['code'] . '”' : 'single codes from the coupon “' . $ref['code'] . '”',
			$read['offer']['id'],
			implode( ', ', array_merge( array( $read['offer']['worth'] ), $read['offer']['conditions'] ) )
		);
	}

	/** This store's coupon as the plain array describe_coupon() reads, or null. */
	private static function coupon_fields( $code ) {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$c       = new WC_Coupon( $id );
		$expires = $c->get_date_expires();
		return array(
			'id'                          => (int) $id,
			'status'                      => get_post_status( $id ),
			'discount_type'               => $c->get_discount_type(),
			'amount'                      => (float) $c->get_amount(),
			'free_shipping'               => (bool) $c->get_free_shipping(),
			'date_expires'                => $expires ? $expires->getTimestamp() : null,
			'usage_limit'                 => (int) $c->get_usage_limit(),
			'usage_count'                 => (int) $c->get_usage_count(),
			'usage_limit_per_user'        => (int) $c->get_usage_limit_per_user(),
			'email_restrictions'          => (array) $c->get_email_restrictions(),
			'product_ids'                 => (array) $c->get_product_ids(),
			'excluded_product_ids'        => (array) $c->get_excluded_product_ids(),
			'product_categories'          => (array) $c->get_product_categories(),
			'excluded_product_categories' => (array) $c->get_excluded_product_categories(),
			'exclude_sale_items'          => (bool) $c->get_exclude_sale_items(),
			'limit_usage_to_x_items'      => (int) $c->get_limit_usage_to_x_items(),
			'minimum_amount'              => (float) $c->get_minimum_amount(),
			'maximum_amount'              => (float) $c->get_maximum_amount(),
			'individual_use'              => (bool) $c->get_individual_use(),
		);
	}

	/** Both halves, against this store's own home page and coupons. */
	public static function read( $storefront_url ) {
		$res = wp_remote_get( $storefront_url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::fail( 'storefront', __( 'Your store\'s home page couldn\'t be read.', 'open-bounty' ) );
		}
		$id = self::company_id( wp_remote_retrieve_body( $res ) );
		if ( ! $id ) {
			return self::fail( 'install', __( 'Klaviyo isn\'t on your store\'s pages. Install Klaviyo for WooCommerce and connect it, then try again.', 'open-bounty' ) );
		}
		$forms = wp_remote_get( 'https://static-forms.klaviyo.com/forms/api/v7/' . $id . '/full-forms', array( 'timeout' => 10 ) );
		if ( is_wp_error( $forms ) || 200 !== (int) wp_remote_retrieve_response_code( $forms ) ) {
			return self::fail( 'klaviyo', __( 'Klaviyo couldn\'t be reached to read your signup forms.', 'open-bounty' ) );
		}
		$pick = self::select_coupon( json_decode( wp_remote_retrieve_body( $forms ), true ) );
		if ( ! $pick['ok'] ) {
			return $pick;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : '';
		$read     = self::describe_coupon( self::coupon_fields( $pick['ref']['code'] ), $pick['ref'], $currency, time() );
		if ( ! $read['ok'] ) {
			return $read;
		}
		return $read + array( 'ref' => $pick['ref'] );
	}
}
