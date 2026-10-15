<?php
/**
 * Klaviyo welcome offer: coupon selection from the live forms, the WooCommerce
 * coupon's fields, the sentence, refusals. Form shapes from the live
 * full-forms responses of lunya.co, ritual.com, rumpl.com and kosas.com
 * (2026-10-15), with the unique coupon typed "woocommerce".
 */

use PHPUnit\Framework\TestCase;

final class KlaviyoTest extends TestCase {

	const NOW = 1792087200; // 2026-10-15T18:00:00Z

	private static function comp( $type, array $data = array() ) {
		return array( 'component_type' => $type, 'data' => $data );
	}

	private static function form( $id, array ...$components ) {
		$rows = array_map( function ( $c ) { return array( 'components' => array( $c ) ); }, $components );
		return array( 'form_id' => $id, 'live_form_versions' => array( array( 'form_type' => 'POPUP', 'views' => array( array( 'columns' => array( array( 'rows' => $rows ) ) ) ) ) ) );
	}

	private static function email() { return self::comp( 'EMAIL', array( 'field_id' => '$email' ) ); }
	private static function phone() { return self::comp( 'PHONE_NUMBER' ); }
	private static function text() { return self::comp( 'TEXT', array( 'content' => array( 'html' => '<span>Get 15% Off</span>' ) ) ); }
	private static function static_coupon( $code ) { return self::comp( 'COUPON', array( 'coupon_type' => 'static', 'coupon_data' => array( 'text' => $code ) ) ); }
	private static function unique_coupon( $name, $type = 'woocommerce' ) { return self::comp( 'COUPON', array( 'coupon_type' => 'unique', 'coupon_data' => array( 'type' => $type, 'name' => $name, 'id' => 1 ) ) ); }

	private static function coupon( array $o = array() ) {
		return $o + array(
			'id' => 42, 'status' => 'publish', 'discount_type' => 'percent', 'amount' => 15.0, 'free_shipping' => false,
			'date_expires' => null, 'usage_limit' => 0, 'usage_count' => 3, 'usage_limit_per_user' => 1,
			'email_restrictions' => array(), 'product_ids' => array(), 'excluded_product_ids' => array(),
			'product_categories' => array(), 'excluded_product_categories' => array(), 'exclude_sale_items' => false,
			'limit_usage_to_x_items' => 0, 'minimum_amount' => 0.0, 'maximum_amount' => 0.0,
		);
	}

	public function test_company_id_woocommerce_and_shopify_script_shapes() {
		$this->assertSame( 'AbC123', Open_Bounty_Klaviyo::company_id( "<script src='//static.klaviyo.com/onsite/js/AbC123/klaviyo.js?ver=3.8.3'></script>" ) );
		$this->assertSame( 'Ntc5BJ', Open_Bounty_Klaviyo::company_id( '<script src="//static.klaviyo.com/onsite/js/klaviyo.js?company_id=Ntc5BJ"></script>' ) );
		$this->assertNull( Open_Bounty_Klaviyo::company_id( '<p>no klaviyo</p>' ) );
	}

	public function test_one_static_code_across_two_forms() {
		$r = Open_Bounty_Klaviyo::select_coupon( array( 'full_forms' => array(
			self::form( 'Sm56De', self::text(), self::email(), self::static_coupon( 'WELCOME-K51FJ' ) ),
			self::form( 'UA7ZsC', self::email(), self::phone(), self::static_coupon( 'welcome-k51fj' ) ),
		) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'kind' => 'static', 'code' => 'WELCOME-K51FJ', 'channels' => array( 'email', 'sms' ), 'forms' => array( 'Sm56De', 'UA7ZsC' ) ), $r['ref'] );
	}

	public function test_unique_woocommerce_coupon() {
		$r = Open_Bounty_Klaviyo::select_coupon( array( 'full_forms' => array( self::form( 'THWbrS', self::phone(), self::unique_coupon( 'welcome15' ) ) ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'unique', $r['ref']['kind'] );
		$this->assertSame( 'welcome15', $r['ref']['code'] );
	}

	public function test_selection_refusals() {
		$cases = array(
			array( array( 'full_forms' => array() ), 'forms' ),
			array( array( 'full_forms' => array( self::form( 'X', self::text(), self::static_coupon( 'X' ) ) ) ), 'forms' ),
			array( array( 'full_forms' => array( self::form( 'VXxAR3', self::text(), self::email() ) ) ), 'coupon' ),
			array( array( 'full_forms' => array( self::form( 'WVukRG', self::email(), self::static_coupon( 'ENTER COUPON' ) ) ) ), 'coupon' ),
			array( array( 'full_forms' => array( self::form( 'A', self::email(), self::unique_coupon( 'X', 'shopify' ) ) ) ), 'coupon' ),
			array( array( 'full_forms' => array( self::form( 'Wawjtr', self::email(), self::unique_coupon( 'a' ) ), self::form( 'V6YiQj', self::email(), self::unique_coupon( 'b' ) ) ) ), 'coupon' ),
		);
		foreach ( $cases as $i => list( $input, $step ) ) {
			$r = Open_Bounty_Klaviyo::select_coupon( $input );
			$this->assertFalse( $r['ok'], "case $i" );
			$this->assertSame( $step, $r['step'], "case $i: " . $r['message'] );
		}
	}

	private static function sentence( array $coupon, array $ref, $currency = 'USD' ) {
		$r = Open_Bounty_Klaviyo::describe_coupon( $coupon, $ref, $currency, self::NOW );
		return $r['ok'] ? Open_Bounty_Klaviyo::compose( $r['offer'], $ref ) : $r;
	}

	public function test_sentences() {
		$static = array( 'kind' => 'static', 'code' => 'WELCOME', 'channels' => array( 'email' ), 'forms' => array( 'A' ) );
		$unique = array( 'kind' => 'unique', 'code' => 'welcome15', 'channels' => array( 'email', 'sms' ), 'forms' => array( 'A' ) );
		$this->assertSame( 'New email subscribers get 15% off one order.', self::sentence( self::coupon(), $static ) );
		$this->assertSame(
			'New email subscribers get $10 off, on orders of $50 or more, through Dec 31, 2026.',
			self::sentence( self::coupon( array( 'discount_type' => 'fixed_cart', 'amount' => 10.0, 'usage_limit_per_user' => 0, 'minimum_amount' => 50.0, 'date_expires' => 1798718400 ) ), $static )
		);
		// A unique coupon's own expiry is overridden by Klaviyo on the codes it issues, so it isn't stated.
		$this->assertSame(
			'New email or text subscribers get $5 off each item on one order.',
			self::sentence( self::coupon( array( 'discount_type' => 'fixed_product', 'amount' => 5.0, 'usage_limit_per_user' => 0, 'usage_limit' => 1, 'date_expires' => 1798718400 ) ), $unique )
		);
		$this->assertSame( 'New email subscribers get free shipping.', self::sentence( self::coupon( array( 'amount' => 0.0, 'free_shipping' => true, 'usage_limit_per_user' => 0 ) ), $static, 'CAD' ) );
		$this->assertSame( 'New email subscribers get 12.5% off and free shipping on one order.', self::sentence( self::coupon( array( 'amount' => 12.5, 'free_shipping' => true ) ), $static ) );
	}

	public function test_coupon_refusals() {
		$static = array( 'kind' => 'static', 'code' => 'WELCOME', 'channels' => array( 'email' ), 'forms' => array( 'A' ) );
		$cases  = array(
			array( null, 'coupon' ),
			array( self::coupon( array( 'status' => 'draft' ) ), 'status' ),
			array( self::coupon( array( 'date_expires' => self::NOW - 1 ) ), 'status' ),
			array( self::coupon( array( 'usage_limit' => 3 ) ), 'status' ),
			array( self::coupon( array( 'email_restrictions' => array( 'a@b.c' ) ) ), 'eligibility' ),
			array( self::coupon( array( 'product_categories' => array( 7 ) ) ), 'scope' ),
			array( self::coupon( array( 'exclude_sale_items' => true ) ), 'scope' ),
			array( self::coupon( array( 'maximum_amount' => 200.0 ) ), 'scope' ),
			array( self::coupon( array( 'discount_type' => 'recurring_percent' ) ), 'value' ),
			array( self::coupon( array( 'amount' => 0.0 ) ), 'value' ),
		);
		foreach ( $cases as $i => list( $coupon, $step ) ) {
			$r = Open_Bounty_Klaviyo::describe_coupon( $coupon, $static, 'USD', self::NOW );
			$this->assertFalse( $r['ok'], "case $i" );
			$this->assertSame( $step, $r['step'], "case $i: " . $r['message'] );
		}
		$r = Open_Bounty_Klaviyo::describe_coupon( self::coupon(), $static, 'GBP', self::NOW );
		$this->assertSame( 'currency', $r['step'] );
	}
}
