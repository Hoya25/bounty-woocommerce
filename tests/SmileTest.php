<?php
/**
 * Smile.io adapter: rule selection and the composed sentence. Same cases as the
 * Shopify app's smile.server.test.ts, on fixtures shaped like live responses
 * (liquiddeath.com, deathwishcoffee.com, rungum.com, monos.com).
 */

use PHPUnit\Framework\TestCase;

final class SmileTest extends TestCase {

	private static function fixed( $id, $name, $v ) {
		return array( 'id' => $id, 'name' => $name, 'restricted_to_vip_tier_ids' => array(), 'reward_value' => array( 'type' => 'fixed', 'fixed' => array( 'value' => $v ) ) );
	}

	private static function variable( $id, $name, $v, $per = 1, $tiers = array() ) {
		return array( 'id' => $id, 'name' => $name, 'restricted_to_vip_tier_ids' => $tiers, 'reward_value' => array( 'type' => 'variable', 'variable' => array( 'value' => $v, 'per_amount' => $per ) ) );
	}

	private static function act( $id, $type ) {
		return array( 'activity_rule' => array( 'id' => $id, 'type' => $type, 'is_enabled' => true ) );
	}

	private static function liquid_death() {
		return array(
			'earningRules'  => array( 'earning_rules' => array( self::fixed( 1196440, 'Link Target', 5 ), self::fixed( 316800, 'Signup', 5 ), self::fixed( 316801, 'Celebrate a birthday', 10 ), self::variable( 316802, 'Place An Order on LiquidDeath.com', 1 ) ) ),
			'activityRules' => array( 'customer_activity_rules' => array( self::act( 316800, 'signup' ), self::act( 316802, 'shopify_online_order' ), self::act( 1196440, 'custom' ), self::act( 316801, 'customer_birthday' ) ) ),
			'programData'   => array( 'points_settings' => array( 'points_label' => array( 'one' => 'Skull', 'other' => 'Skulls' ) ), 'points_products' => array( array( 'id' => 898966 ) ) ),
			'init'          => array( 'points_program' => array( 'is_enabled' => true ), 'account' => array( 'currency' => array( 'iso_code' => 'USD' ) ) ),
		);
	}

	public function test_purchase_rule_by_type_and_the_sentence() {
		$r = Open_Bounty_Smile::select_rule( self::liquid_death() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 316802, $r['rule']['rule_id'] );
		$this->assertSame( 'Members earn 1 Skull per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Members' ) );
	}

	public function test_plural_and_divided_rate() {
		$f = self::liquid_death();
		$f['earningRules']['earning_rules'][3] = self::variable( 316802, 'Place An Order', 10, 5 );
		$r = Open_Bounty_Smile::select_rule( $f );
		$this->assertSame( 'Customers earn 2 Skulls per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Customers' ) );
	}

	public function test_tier_only_program_quotes_the_entry_tier() {
		$f                  = self::liquid_death();
		$f['earningRules']  = array( 'earning_rules' => array( self::variable( 33205, 'Place an order', 1, 1, array( 387 ) ), self::variable( 890391, 'Place an order', 3, 1, array( 388 ) ) ) );
		$f['activityRules'] = array( 'customer_activity_rules' => array( self::act( 33205, 'shopify_online_order' ) ) );
		$r                  = Open_Bounty_Smile::select_rule( $f );
		$this->assertTrue( $r['ok'] );
		$this->assertTrue( $r['rule']['entry_tier'] );
	}

	public function test_refusals() {
		$cases = array();

		$f = self::liquid_death();
		$f['init']['points_program']['is_enabled'] = false;
		$cases['program'] = $f;

		$f = self::liquid_death();
		$f['earningRules']['earning_rules'] = array();
		$cases['rule'] = $f;

		$f = self::liquid_death();
		$f['earningRules']['earning_rules'][3] = self::fixed( 316802, 'Place An Order', 50 );
		$cases['rule '] = $f;

		$f = self::liquid_death();
		$f['init']['account']['currency']['iso_code'] = 'EUR';
		$cases['currency'] = $f;

		$f = self::liquid_death();
		$f['programData']['points_settings'] = array();
		$cases['label'] = $f;

		$f = self::liquid_death();
		$f['programData']['points_products'] = array();
		$cases['redeem'] = $f;

		foreach ( $cases as $step => $fixture ) {
			$r = Open_Bounty_Smile::select_rule( $fixture );
			$this->assertFalse( $r['ok'], $step );
			$this->assertSame( trim( $step ), $r['step'] );
		}
	}

	public function test_the_object_validates_and_passes_the_gate() {
		$r      = Open_Bounty_Smile::select_rule( self::liquid_death() );
		$object = Open_Bounty_Smile::build_object( $r['rule'], 'Members', 'Example Outfitters', 'https://shop.example/' );
		$schema = json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
		$this->assertSame( array(), Open_Bounty_Schema::check( $object, $schema ) );
		$this->assertSame( 'points', $object['earn']['type'] );
		$this->assertSame( 'Skulls', $object['earn']['denomination'] );
		$this->assertSame( 'Example Outfitters', $object['program']['name'] );
	}

	public function test_channel_key_markup() {
		$this->assertSame( 'channel_mMttUn4fUNfUXTuLciBbV6BO', Open_Bounty_Smile::channel_key( '<div class="smile-shopify-init" data-channel-key="channel_mMttUn4fUNfUXTuLciBbV6BO"></div>' ) );
		$this->assertNull( Open_Bounty_Smile::channel_key( '<html>woocommerce store</html>' ) );
	}
}
