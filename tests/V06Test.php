<?php
/**
 * Spec v0.6 in the plugin: the Klaviyo offer's fields, any currency, the
 * labeled on-page statement, the validity window and localized display.
 * (Loyalty fields are covered by RewardsTest's parity with the Shopify app.)
 */

use PHPUnit\Framework\TestCase;

final class V06Test extends TestCase {

	const NOW = 1792087200; // 2026-10-15T18:00:00Z

	private static function coupon( array $o = array() ) {
		return $o + array(
			'id' => 42, 'status' => 'publish', 'discount_type' => 'percent', 'amount' => 15.0, 'free_shipping' => false,
			'date_expires' => null, 'usage_limit' => 0, 'usage_count' => 3, 'usage_limit_per_user' => 1,
			'email_restrictions' => array(), 'product_ids' => array(), 'excluded_product_ids' => array(),
			'product_categories' => array(), 'excluded_product_categories' => array(), 'exclude_sale_items' => false,
			'limit_usage_to_x_items' => 0, 'minimum_amount' => 0.0, 'maximum_amount' => 0.0, 'individual_use' => true,
		);
	}

	private static function ref() {
		return array( 'kind' => 'static', 'code' => 'JUSTFORYOU', 'channels' => array( 'email' ), 'forms' => array( 'f1' ) );
	}

	public function test_klaviyo_offer_fields_from_the_coupon() {
		$r = Open_Bounty_Klaviyo::describe_coupon( self::coupon( array( 'discount_type' => 'fixed_cart', 'amount' => 10.0, 'minimum_amount' => 50.0, 'date_expires' => 1798761599, 'individual_use' => false ) ), self::ref(), 'USD', self::NOW );
		$this->assertTrue( $r['ok'] );
		$f = Open_Bounty_Klaviyo::offer_fields( $r['offer'], self::ref() );
		$this->assertSame(
			array(
				'currency'    => 'USD',
				'eligibility' => array( 'who' => 'new_subscribers', 'note' => 'For new email subscribers.' ),
				'conditions'  => array(
					array( 'type' => 'min_order', 'value' => 50, 'note' => 'On orders of $50 or more.' ),
					array( 'type' => 'once_per_customer', 'note' => 'One use per customer.' ),
					array( 'type' => 'limited_time', 'note' => 'Through Dec 31, 2026.' ),
				),
				'valid_until' => '2026-12-31T23:59:59.000Z',
				'stacking'    => array( 'combinable' => true, 'note' => 'Combines with other coupons.' ),
			),
			$f
		);
		$obj    = Open_Bounty_Klaviyo::build_object( array( 'offer' => $r['offer'], 'ref' => self::ref() ), 'Example Outfitters', 'https://shop.example/' );
		$schema = json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
		$this->assertSame( '0.6', $obj['bounty_schema_version'] );
		$this->assertSame( array(), Open_Bounty_Schema::validate( $obj, $schema ) );
	}

	public function test_klaviyo_in_pounds_and_individual_use() {
		$r = Open_Bounty_Klaviyo::describe_coupon( self::coupon( array( 'discount_type' => 'fixed_cart', 'amount' => 10.0 ) ), self::ref(), 'GBP', self::NOW );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'New email subscribers get £10 off one order.', Open_Bounty_Klaviyo::compose( $r['offer'], self::ref() ) );
		$f = Open_Bounty_Klaviyo::offer_fields( $r['offer'], self::ref() );
		$this->assertSame( array( 'combinable' => false, 'note' => 'Doesn\'t combine with other coupons.' ), $f['stacking'] );
		$this->assertSame( 'GBP', $f['currency'] );
	}

	public function test_money_matches_the_app_table() {
		$this->assertSame( '€1,250', Open_Bounty_Rewards::money( 1250, 'EUR' ) );
		$this->assertSame( 'SEK 5', Open_Bounty_Rewards::money( 5, 'SEK' ) );
		$this->assertSame( 'per £1', Open_Bounty_Rewards::per_unit( 'GBP' ) );
		$this->assertSame( 'per dollar', Open_Bounty_Rewards::per_unit( 'AUD' ) );
		$this->assertSame( 'https://oliveandjune.com/account/register', Open_Bounty_Rewards::clean_url( '//oliveandjune.com//account/register' ) );
		$this->assertNull( Open_Bounty_Rewards::clean_url( 'javascript:alert(1)' ) );
	}

	private static function obj( array $o = array() ) {
		return $o + array(
			'bounty_schema_version' => '0.6', 'active' => true,
			'program' => array( 'name' => 'Example Outfitters', 'url' => 'https://shop.example/' ),
			'earn' => array( 'display' => 'Members earn 1 point per dollar, redeemable at checkout.' ),
			'updated_at' => '2026-10-15',
		);
	}

	public function test_statement_is_a_labeled_region_with_the_sentence_verbatim() {
		$html = Open_Bounty_Render::product_block( self::obj() );
		$this->assertMatchesRegularExpression( '~<section[^>]*\sdata-bounty-display[^>]*\saria-label="Rewards on this purchase"~', $html );
		$this->assertStringContainsString( '>Members earn 1 point per dollar, redeemable at checkout.<', $html );
	}

	public function test_outside_the_window_nothing_renders() {
		$this->assertSame( '', Open_Bounty_Render::product_block( self::obj( array( 'valid_until' => '2026-01-31' ) ) ) );
		$this->assertSame( '', Open_Bounty_Render::product_block( self::obj( array( 'valid_from' => '2999-01-01T00:00:00Z' ) ) ) );
		$this->assertTrue( Open_Bounty_Render::in_window( self::obj( array( 'valid_until' => '2026-10-15' ) ), self::NOW ) );
	}

	public function test_localized_display_uses_default_without_a_matching_locale() {
		$o = self::obj( array( 'earn' => array( 'display' => array( 'default' => 'Members earn 5 points per €1.', 'fr' => 'Les membres gagnent 5 points par 1 €.' ) ) ) );
		$this->assertContains( Open_Bounty_Render::display( $o ), array( 'Members earn 5 points per €1.', 'Les membres gagnent 5 points par 1 €.' ) );
		$this->assertStringContainsString( 'data-bounty-display', Open_Bounty_Render::product_block( $o ) );
	}
}
