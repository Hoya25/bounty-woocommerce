<?php
/**
 * Carriers on single product pages.
 *
 *   (c) JSON-LD — the `bounty` PropertyValue added to the product's Offer in
 *       WooCommerce's own structured data. Added to the existing Offer, never a
 *       second Product node, so there is one product and one price on the page.
 *   (visible) "Rewards on this purchase" — the sentence and program name, after
 *       the price. Classic themes: woocommerce_single_product_summary at
 *       priority 11 (the price is 10). Block themes do not fire that hook, so
 *       the same block is appended to the single-product Price block instead.
 *       It renders once per page either way.
 *
 * Nothing here runs until the merchant has saved (Open_Bounty_Store::current()
 * is null until then).
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Product {

	private static $rendered = false;

	public static function init() {
		add_filter( 'woocommerce_structured_data_product_offer', array( __CLASS__, 'offer' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'block' ), 11 );
		add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_theme_price' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	/**
	 * @param array      $markup  One Offer.
	 * @param WC_Product $product The product.
	 */
	public static function offer( $markup, $product ) {
		$property = Open_Bounty_Render::offer_property( Open_Bounty_Store::current() );
		if ( null === $property || ! is_array( $markup ) ) {
			return $markup;
		}
		$markup['additionalProperty'] = $property;
		return $markup;
	}

	private static function enabled() {
		return ! empty( Open_Bounty_Store::settings()['show_block'] );
	}

	public static function block() {
		if ( self::$rendered || ! self::enabled() ) {
			return;
		}
		$html = Open_Bounty_Render::product_block( Open_Bounty_Store::current() );
		if ( '' !== $html ) {
			self::$rendered = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Open_Bounty_Render.
		}
	}

	/** Block themes: after the main product's price, once. Not on related-product grids. */
	public static function block_theme_price( $content, $block ) {
		if ( self::$rendered || ! self::enabled() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $content;
		}
		if ( empty( $block['attrs']['isDescendentOfSingleProductTemplate'] ) && empty( $block['attrs']['isDescendentOfSingleProductBlock'] ) ) {
			return $content;
		}
		$html = Open_Bounty_Render::product_block( Open_Bounty_Store::current() );
		if ( '' === $html ) {
			return $content;
		}
		self::$rendered = true;
		return $content . $html;
	}

	public static function styles() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::enabled() ) {
			return;
		}
		wp_enqueue_style( 'open-bounty', plugins_url( 'assets/open-bounty.css', OPEN_BOUNTY_FILE ), array(), OPEN_BOUNTY_VERSION );
	}
}
