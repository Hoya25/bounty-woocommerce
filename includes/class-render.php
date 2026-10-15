<?php
/**
 * Every byte this plugin shows the public is produced here.
 *
 * One place, so the disclosure gate can be run over all of it (tests/), and so
 * the honesty rules hold everywhere at once:
 *   - earn.display is shown VERBATIM — never reworded, never shortened, never
 *     decorated with a number. Nothing here adds a rate or a digit.
 *   - an NCTR Alliance object that fails the gate renders as nothing, not as a
 *     redacted copy. A merchant's own sentence is theirs and renders as written.
 *   - an inactive object renders no product claim: active:false is an honest
 *     "not right now", and a product page saying "Rewards on this purchase"
 *     beside it would contradict it.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Render {

	const PATH = '/.well-known/bounty.json';

	/** The body served at /.well-known/bounty.json, or null if it must not be served. */
	public static function well_known_body( $object ) {
		if ( ! is_array( $object ) || ! Open_Bounty_Gate::allows( $object ) ) {
			return null;
		}
		return wp_json_encode( $object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** The <link rel="bounty"> element for wp_head. */
	public static function head_link( $href ) {
		return sprintf(
			'<link rel="bounty" type="application/json" href="%s" />' . "\n",
			esc_url( $href )
		);
	}

	/**
	 * The spec §2c PropertyValue that goes inside a product's Offer.
	 *
	 * @return array|null
	 */
	public static function offer_property( $object ) {
		if ( ! self::claimable( $object ) ) {
			return null;
		}
		$property = array(
			'@type'      => 'PropertyValue',
			'propertyID' => 'bounty',
			'name'       => $object['program']['name'] . ' bounty',
			'value'      => self::display( $object ),
		);
		if ( ! empty( $object['api'] ) ) {
			$property['url'] = $object['api'];
		}
		return Open_Bounty_Gate::allows( $object, $property ) ? $property : null;
	}

	/** The visible "Rewards on this purchase" block, or '' when there is nothing true to say. */
	public static function product_block( $object ) {
		if ( ! self::claimable( $object ) ) {
			return '';
		}
		// Spec v0.6 §2e: a labeled region (data-bounty-display + aria-label) with the
		// sentence verbatim, rendered after the price, in the purchase region.
		$html = sprintf(
			'<section class="open-bounty" data-open-bounty data-bounty-display aria-label="%1$s">'
			. '<p class="open-bounty__heading">%1$s</p>'
			. '<p class="open-bounty__display">%2$s</p>'
			. '<p class="open-bounty__program">%3$s <a href="%4$s" rel="noopener" target="_blank">%5$s</a></p>'
			. '</section>',
			esc_html__( 'Rewards on this purchase', 'open-bounty' ),
			esc_html( self::display( $object ) ),
			esc_html__( 'Through', 'open-bounty' ),
			esc_url( $object['program']['url'] ),
			esc_html( $object['program']['name'] )
		);
		return Open_Bounty_Gate::allows( $object, $html ) ? $html : '';
	}

	/** earn.display as one sentence: the string, or a localized object's entry for the site's language, else its default (v0.6). */
	public static function display( $object ) {
		$d = isset( $object['earn']['display'] ) ? $object['earn']['display'] : '';
		if ( ! is_array( $d ) ) {
			return (string) $d;
		}
		$locale = function_exists( 'get_locale' ) ? strtolower( str_replace( '_', '-', get_locale() ) ) : '';
		foreach ( array( $locale, strtok( $locale, '-' ) ) as $k ) {
			if ( $k && isset( $d[ $k ] ) && is_string( $d[ $k ] ) ) {
				return $d[ $k ];
			}
		}
		return isset( $d['default'] ) ? (string) $d['default'] : '';
	}

	/** v0.6: outside valid_from/valid_until a bounty is inactive. */
	public static function in_window( $object, $now = null ) {
		$now = null === $now ? time() : $now;
		if ( ! empty( $object['valid_from'] ) && strtotime( $object['valid_from'] ) > $now ) {
			return false;
		}
		if ( ! empty( $object['valid_until'] ) ) {
			$until = strtotime( $object['valid_until'] );
			if ( 10 === strlen( $object['valid_until'] ) ) {
				$until += 86399; // A date-only valid_until runs to the end of that day.
			}
			if ( false !== $until && $until < $now ) {
				return false;
			}
		}
		return true;
	}

	/** True when the object makes a claim a product page may repeat. */
	private static function claimable( $object ) {
		return is_array( $object )
			&& ! empty( $object['active'] )
			&& isset( $object['earn']['display'], $object['program']['name'], $object['program']['url'] )
			&& self::in_window( $object )
			&& Open_Bounty_Gate::allows( $object );
	}
}
