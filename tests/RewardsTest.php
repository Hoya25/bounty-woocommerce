<?php
/**
 * v0.5 `rewards`. Two halves:
 *
 *  1. PARITY: live responses captured 2026-10-15 from k-swiss.com and
 *     liquiddeath.com (Smile), oliveandjune.com (Yotpo) and pacificabeauty.com
 *     (LoyaltyLion), with the block the Bounty app for Shopify composed from the
 *     same bytes. The plugin must compose the identical block, or the same
 *     refusal. Fixtures: tests/fixtures/rewards/.
 *  2. The composer's rules, as in the Shopify app's rewards.test.ts.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RewardsTest extends TestCase {

	private static function fixture( $name ) {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/rewards/' . $name . '.json' ), true );
	}

	public static function parity_cases() {
		return array( array( 'k-swiss' ), array( 'liquiddeath' ), array( 'oliveandjune' ), array( 'pacificabeauty' ), array( 'ohpolly-uk' ) );
	}

	#[DataProvider( 'parity_cases' )]
	public function test_same_block_as_the_shopify_app_from_the_same_live_data( $name ) {
		$f  = self::fixture( $name );
		$in = $f['input'];
		switch ( $f['provider'] ) {
			case 'smile':
				$read = Open_Bounty_Smile::select_rule( $in );
				break;
			case 'yotpo':
				$read = Open_Bounty_Yotpo::select_rule( $in, array() );
				break;
			default:
				$read = Open_Bounty_LoyaltyLion::select_rule( $in['start'], $in['cfg'], $in['label'] );
		}
		$this->assertSame( $f['sentence_ok'], $read['ok'] );
		$got = $read['rule']['rewards'];
		$this->assertSame( $f['expected']['ok'], $got['ok'] );
		if ( $got['ok'] ) {
			// JSON round-trip on both sides: what is published is the JSON.
			$this->assertSame( wp_json_encode( $f['expected']['block'] ), wp_json_encode( $got['block'] ) );
		} else {
			$this->assertSame( $f['expected']['reason'], $got['reason'] );
		}

		// v0.6: the sentence (any currency), eligibility, conditions and end date, byte for byte.
		$v = $f['expected_v06'];
		$this->assertSame( $v['sentence'], Open_Bounty_Smile::compose( $read['rule'], 'Members' ) );
		$this->assertSame( $v['currency'], $read['rule']['currency'] );
		$this->assertSame( wp_json_encode( $v['eligibility'] ), wp_json_encode( $read['rule']['eligibility'] ) );
		$this->assertSame( wp_json_encode( $v['conditions'] ), wp_json_encode( $read['rule']['conditions'] ) );
		$this->assertSame( $v['valid_until'], isset( $read['rule']['valid_until'] ) ? $read['rule']['valid_until'] : null );
	}

	public function test_object_carries_rewards_only_when_composed_and_validates() {
		$f    = self::fixture( 'oliveandjune' );
		$read = Open_Bounty_Yotpo::select_rule( $f['input'], array() );
		$obj  = Open_Bounty_Smile::build_object( $read['rule'], 'Members', 'Olive & June', 'https://oliveandjune.example/' );
		$this->assertSame( '0.6', $obj['bounty_schema_version'] );
		$this->assertSame( '250 Points = $5 off at checkout, on orders of $15 or more.', $obj['rewards']['value'] );
		$schema = json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
		$this->assertSame( array(), Open_Bounty_Schema::validate( $obj, $schema ) );

		$ld   = self::fixture( 'liquiddeath' );
		$read = Open_Bounty_Smile::select_rule( $ld['input'] );
		$obj  = Open_Bounty_Smile::build_object( $read['rule'], 'Members', 'Liquid Death', 'https://liquiddeath.example/' );
		$this->assertArrayNotHasKey( 'rewards', $obj );
		$this->assertSame( 'Members earn 1 Skull per dollar, redeemable at checkout.', $obj['earn']['display'] );
	}

	public function test_validator_now_checks_inside_rungs_and_perks() {
		$schema = json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
		$base   = array( 'bounty_schema_version' => '0.5', 'active' => true, 'program' => array( 'name' => 'x', 'url' => 'https://x.test' ), 'earn' => array( 'display' => 'y' ), 'updated_at' => '2026-10-15' );
		$bad    = array(
			array( 'ladder' => array( 'basis' => 'spend', 'unit' => 'USD', 'rungs' => array( array( 'rung' => 'A', 'threshold' => -1 ) ) ) ),
			array( 'ladder' => array( 'basis' => 'spend', 'unit' => 'USD', 'rungs' => array( array( 'rung' => 'A' ) ) ) ),
			array( 'ladder' => array( 'basis' => 'visits', 'unit' => 'x', 'rungs' => array( array( 'rung' => 'A', 'threshold' => 0 ) ) ) ),
			array( 'perks' => array( array( 'name' => 'Free shipping', 'state' => 'live' ) ) ),
			array( 'perks' => array( array( 'name' => 'Free shipping' ) ) ),
			array( 'value' => '100 points = $5 off.', 'rate' => 0.05 ),
		);
		foreach ( $bad as $i => $rewards ) {
			$this->assertNotEmpty( Open_Bounty_Schema::validate( $base + array( 'rewards' => $rewards ), $schema ), "case $i should be rejected" );
		}
		$this->assertSame( array(), Open_Bounty_Schema::validate( $base + array( 'rewards' => array( 'perks' => array( array( 'name' => 'Free shipping', 'state' => 'funded' ) ) ) ), $schema ) );
	}

	public function test_value_picks_and_wording() {
		$pts = array( 'one' => 'Point', 'other' => 'Points' );
		$this->assertSame( '30 Points = $1 off at checkout.', Open_Bounty_Rewards::compose_value( array( array( 'points' => 100, 'kind' => 'amount_off', 'amount' => 3 ), array( 'points' => 30, 'kind' => 'amount_off', 'amount' => 1, 'conversion' => true ) ), $pts, 'USD' ) );
		$this->assertSame( '150 Points = 10% off at checkout.', Open_Bounty_Rewards::compose_value( array( array( 'points' => 150, 'kind' => 'percent_off', 'amount' => 10 ) ), $pts, 'USD' ) );
		$this->assertSame( '100 Points = A$1 in store credit, on eligible products.', Open_Bounty_Rewards::compose_value( array( array( 'points' => 100, 'kind' => 'store_credit', 'amount' => 1, 'restricted' => true ) ), $pts, 'AUD' ) );
		$this->assertSame( '250 Points = $7.50 off at checkout.', Open_Bounty_Rewards::compose_value( array( array( 'points' => 250, 'kind' => 'amount_off', 'amount' => 7.5 ) ), $pts, 'USD' ) );
		$this->assertNull( Open_Bounty_Rewards::compose_value( array(), $pts, 'USD' ) );
		$this->assertNull( Open_Bounty_Rewards::compose_value( array( array( 'points' => 100, 'kind' => 'percent_off', 'amount' => 150 ) ), $pts, 'USD' ) );
	}

	public function test_ladder_and_perks_rules() {
		$l = Open_Bounty_Rewards::compose_ladder( 'spend', 'USD', array( array( 'name' => 'Pro', 'threshold' => 250 ), array( 'name' => 'Starter', 'threshold' => 0 ) ), 'spend is counted over the last 12 months.' );
		$this->assertSame( array( 'basis' => 'spend', 'unit' => 'USD', 'note' => 'Spend is counted over the last 12 months.', 'rungs' => array( array( 'rung' => 'Starter', 'threshold' => 0 ), array( 'rung' => 'Pro', 'threshold' => 250 ) ) ), $l );
		$this->assertNull( Open_Bounty_Rewards::compose_ladder( 'spend', 'USD', array( array( 'name' => 'A', 'threshold' => 100 ) ) ) );
		$this->assertNull( Open_Bounty_Rewards::compose_ladder( 'spend', 'USD', array( array( 'name' => 'A', 'threshold' => 0 ), array( 'name' => 'B', 'threshold' => 0 ) ) ) );
		$p = Open_Bounty_Rewards::compose_perks( array( array( 'name' => 'Free shipping' ), array( 'name' => 'free shipping' ), array( 'name' => str_repeat( 'x', 121 ) ), array( 'name' => 'Early access', 'description' => 'To sales' ) ) );
		$this->assertSame( array( array( 'name' => 'Free shipping', 'state' => 'funded' ), array( 'name' => 'Early access', 'description' => 'To sales', 'state' => 'funded' ) ), $p );
	}

	public function test_loyaltylion_null_tier_benefit_and_hidden_tier_are_left_out() {
		$f = self::fixture( 'pacificabeauty' );
		$r = Open_Bounty_LoyaltyLion::select_rule( $f['input']['start'], $f['input']['cfg'], $f['input']['label'] )['rule']['rewards'];
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'perks', $r['block'] ); // Pacifica's entry tier names no benefit of its own.
		$this->assertSame( array( 0, 200, 500 ), array_column( $r['block']['ladder']['rungs'], 'threshold' ) );
	}

	public function test_store_copy_of_a_token_program_object_leaves_rewards_out() {
		$doc = array( 'tokens' => array( 'https://api.nctr.live/v1/tokens/nctr' ), 'rewards' => array( 'ladder' => array() ) );
		$this->assertArrayNotHasKey( 'rewards', Open_Bounty_Store::store_copy( $doc ) );
		$own = array( 'rewards' => array( 'value' => 'v.' ) );
		$this->assertSame( $own, Open_Bounty_Store::store_copy( $own ) );
	}
}
