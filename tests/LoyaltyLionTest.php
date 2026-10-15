<?php
/**
 * LoyaltyLion adapter: liveness, entry-tier rate, labels, refusals. Same cases
 * as the Shopify app's loyaltylion.server.test.ts.
 */

use PHPUnit\Framework\TestCase;

final class LoyaltyLionTest extends TestCase {

	const LIVE  = array( 'live' => true, 'digest' => 'd', 'uiMode' => 'integrated_page_only' );
	const LABEL = array( 'one' => 'point', 'other' => 'points' );

	private static function cfg( array $o = array() ) {
		$tiers    = isset( $o['tiers'] ) ? $o['tiers'] : array( array( null, 1 ), array( 695, 1 ), array( 696, 2 ), array( 697, 3 ) );
		$purchase = function ( $id ) use ( $o, $tiers ) {
			return array(
				'id' => $id, 'kind' => 'purchase', 'title' => 'Make a purchase', 'enabled' => true, 'deactivated' => false, 'deleted' => false,
				'value' => isset( $o['value'] ) ? $o['value'] : 1, 'limitAmount' => isset( $o['limit'] ) ? $o['limit'] : 0,
				'configurations' => array_map( function ( $t ) use ( $o ) { return array( 'tierId' => $t[0], 'value' => $t[1], 'enabled' => true, 'multiplier' => isset( $o['multiplier'] ) ? $o['multiplier'] : null ); }, $tiers ),
			);
		};
		$rules = array( $purchase( 40752 ), array( 'id' => 1, 'kind' => 'birthday', 'enabled' => true, 'value' => 100, 'limitAmount' => 1 ) );
		if ( ! empty( $o['extra'] ) ) {
			$rules[] = $purchase( 40753 );
		}
		return array(
			'site'    => array( 'currency' => array( 'code' => isset( $o['currency'] ) ? $o['currency'] : 'usd' ) ),
			'program' => array(
				'settings'     => array( 'loyaltyTiersEnabled' => isset( $o['tiersOn'] ) ? $o['tiersOn'] : true ),
				'loyaltyTiers' => array( array( 'id' => 695, 'default' => true ), array( 'id' => 696 ) ),
				'rules'        => $rules,
				'rewards'      => array_fill( 0, isset( $o['rewards'] ) ? $o['rewards'] : 7, array( 'id' => 1 ) ),
			),
		);
	}

	public function test_entry_tier_rate_and_sentence() {
		$r = Open_Bounty_LoyaltyLion::select_rule( self::LIVE, self::cfg(), self::LABEL );
		$this->assertTrue( $r['ok'] );
		$this->assertTrue( $r['rule']['entry_tier'] );
		$this->assertSame( 'Members earn 1 point per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Members' ) );
		$r = Open_Bounty_LoyaltyLion::select_rule( self::LIVE, self::cfg( array( 'tiers' => array( array( null, 4 ), array( 695, 10 ), array( 696, 12 ) ) ) ), self::LABEL );
		$this->assertSame( 'Members earn 10 points per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Members' ) );
	}

	public function test_refusals() {
		$no_rule = self::cfg();
		$no_rule['program']['rules'] = array( $no_rule['program']['rules'][1] );
		$cases = array(
			array( array( 'live' => false ) + self::LIVE, self::cfg(), self::LABEL, 'program' ),
			array( self::LIVE, $no_rule, self::LABEL, 'rule' ),
			array( self::LIVE, self::cfg( array( 'extra' => true ) ), self::LABEL, 'rule' ),
			array( self::LIVE, self::cfg( array( 'limit' => 3 ) ), self::LABEL, 'rule' ),
			array( self::LIVE, self::cfg( array( 'multiplier' => '2' ) ), self::LABEL, 'rule' ),
			array( self::LIVE, self::cfg( array( 'currency' => '' ) ), self::LABEL, 'currency' ),
			array( self::LIVE, self::cfg(), null, 'label' ),
			array( self::LIVE, self::cfg( array( 'rewards' => 0 ) ), self::LABEL, 'redeem' ),
		);
		foreach ( $cases as $i => $c ) {
			$r = Open_Bounty_LoyaltyLion::select_rule( $c[0], $c[1], $c[2] );
			$this->assertFalse( $r['ok'], "case $i" );
			$this->assertSame( $c[3], $r['step'], "case $i" );
		}
		$this->assertTrue( Open_Bounty_LoyaltyLion::select_rule( self::LIVE, self::cfg( array( 'limit' => 0 ) ), self::LABEL )['ok'], 'limitAmount 0 = unlimited' );
	}

	public function test_start_labels_and_token() {
		$live = Open_Bounty_LoyaltyLion::parse_start( '(function(w) { var configuration = {"config":{"translationsDigest":"abc"},"site":{"uiMode":"integrated_page_only"}}; w.loyaltylion && typeof w.loyaltylion.bootstrap === \'function\' ? w.loyaltylion.bootstrap(configuration) : !0; })(window);' );
		$this->assertSame( array( 'live' => true, 'digest' => 'abc', 'uiMode' => 'integrated_page_only' ), $live );
		$this->assertFalse( Open_Bounty_LoyaltyLion::parse_start( '(function(w) { w.loyaltylion.shutdown({"sdkHost":"x"}) })(window)' )['live'] );
		$this->assertSame( array( 'one' => 'Chive Point', 'other' => 'Chive Points' ), Open_Bounty_LoyaltyLion::parse_count_label( '%{smart_count} Chive Point |||| %{smart_count} Chive Points' ) );
		$this->assertNull( Open_Bounty_LoyaltyLion::parse_count_label( '%{smart_count} points' ) );
		$def = array( 'translations' => array( 'ui.general.points_count_raw' => '%{smart_count} point |||| %{smart_count} points' ) );
		$this->assertSame( 'point', Open_Bounty_LoyaltyLion::points_label( array(), $def )['one'] );
		$this->assertSame( 'Stars', Open_Bounty_LoyaltyLion::points_label( array( 'ui.general.points_count_raw_react' => '%{smart_count} Star |||| %{smart_count} Stars' ), $def )['other'] );
		$this->assertSame( '8689b0583c803cd6623b75d02f824b7b', Open_Bounty_LoyaltyLion::token( 'loyaltylion.init({ token: "8689b0583c803cd6623b75d02f824b7b" })' ) );
		$this->assertNull( Open_Bounty_LoyaltyLion::token( '<html></html>' ) );
	}
}
