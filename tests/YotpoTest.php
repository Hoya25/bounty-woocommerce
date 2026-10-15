<?php
/**
 * Yotpo adapter: loader parsing, rule selection, refusals. Same cases as the
 * Shopify app's yotpo.server.test.ts. Loaders are built the way Yotpo's are:
 * JSON inside JSON.parse("…") string literals.
 */

use PHPUnit\Framework\TestCase;

final class YotpoTest extends TestCase {

	private static function lit( $v ) {
		$inner = json_encode( json_encode( $v, JSON_UNESCAPED_SLASHES ), JSON_UNESCAPED_SLASHES );
		return str_replace( "'", "\\'", substr( $inner, 1, -1 ) );
	}

	private static function campaign( $id, $restriction = 'entire_cart', $points = 1, $cents = 100, $extra = array() ) {
		return array_merge( array( 'id' => $id, 'type' => 'PointsForPurchasesCampaign', 'active' => true, 'reward_points' => $points, 'goal_spend_cents' => $cents, 'product_restrictions_reward' => $restriction, 'customer_tags' => '', 'campaign_tiers' => array(), 'details' => 'Earn points' ), $extra );
	}

	private static function loader( array $o = array() ) {
		$campaigns = isset( $o['campaigns'] ) ? $o['campaigns'] : array( self::campaign( 995767 ), self::campaign( 1026389, 'specific_products' ), array( 'id' => 1, 'type' => 'BirthdayCampaign' ) );
		$loc       = array_key_exists( 'points', $o ) && null === $o['points'] ? new stdClass() : array( 'points' => isset( $o['points'] ) ? $o['points'] : 'Points' );
		$tiers     = array_map( function ( $m ) { return array( 'points_multiplier' => $m ); }, isset( $o['tiers'] ) ? $o['tiers'] : array( '1.0', '2.0' ) );
		$live      = isset( $o['live'] ) && ! $o['live'] ? 'false' : 'true';
		return "var c = {\n    localization: JSON.parse(\"" . self::lit( $loc ) . "\"),\n"
			. "    activeCampaigns: JSON.parse(\"" . self::lit( $campaigns ) . "\"),\n"
			. "    activeRedemptionOptions: JSON.parse(\"" . self::lit( array_fill( 0, isset( $o['redeem'] ) ? $o['redeem'] : 3, array( 'id' => 1 ) ) ) . "\"),\n"
			. "    activeVipTiers: JSON.parse(\"" . self::lit( $tiers ) . "\"),\n"
			. "    merchant: JSON.parse(\"" . self::lit( array( 'company_name' => "Olive and June's", 'currency' => array( 'currency_iso' => isset( $o['currency'] ) ? $o['currency'] : 'USD' ) ) ) . "\"),\n"
			. "    programLive: $live,\n};";
	}

	private static function read( $src, $labels = array() ) {
		return Open_Bounty_Yotpo::select_rule( Open_Bounty_Yotpo::parse_loader( $src ), $labels );
	}

	public function test_picks_the_store_wide_campaign_not_the_product_bonus() {
		$r = self::read( self::loader() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 995767, $r['rule']['rule_id'] );
		$this->assertTrue( $r['rule']['entry_tier'] );
		$this->assertSame( 'Members earn 1 Point per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Members' ) );
	}

	public function test_x_per_y_is_divided() {
		$r = self::read( self::loader( array( 'campaigns' => array( self::campaign( 7, 'entire_cart', 5, 200 ) ) ) ) );
		$this->assertSame( 'Customers earn 2.5 Points per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( $r['rule'], 'Customers' ) );
	}

	public function test_refusals() {
		$two   = str_replace( 'specific_products', 'entire_cart', self::loader() );
		$cases = array(
			array( self::loader( array( 'live' => false ) ), 'program' ),
			array( self::loader( array( 'campaigns' => array( self::campaign( 2, 'specific_products' ) ) ) ), 'rule' ),
			array( $two, 'rule' ),
			array( self::loader( array( 'campaigns' => array( self::campaign( 4, 'entire_cart', 1, 100, array( 'customer_tags' => 'wholesale' ) ) ) ) ), 'rule' ),
			array( self::loader( array( 'campaigns' => array( self::campaign( 5, 'entire_cart', 1, 100, array( 'campaign_tiers' => array( array( 'id' => 1 ) ) ) ) ) ) ), 'rule' ),
			array( self::loader( array( 'campaigns' => array( self::campaign( 3, 'entire_cart', 0 ) ) ) ), 'rule' ),
			array( self::loader( array( 'tiers' => array( '1.5', '2.0' ) ) ), 'tiers' ),
			array( self::loader( array( 'currency' => '' ) ), 'currency' ),
			array( self::loader( array( 'redeem' => 0 ) ), 'redeem' ),
			array( self::loader( array( 'points' => 'Kisses' ) ), 'label' ),
		);
		foreach ( $cases as $i => $c ) {
			$r = self::read( $c[0] );
			$this->assertFalse( $r['ok'], "case $i" );
			$this->assertSame( $c[1], $r['step'], "case $i" );
		}
	}

	public function test_label_fallback_to_unanimous_widget_label() {
		$src = self::loader( array( 'points' => null ) );
		$this->assertFalse( self::read( $src )['ok'] );
		$this->assertSame( 'Members earn 1 point per dollar, redeemable at checkout.', Open_Bounty_Smile::compose( self::read( $src, array( 'points' ) )['rule'], 'Members' ) );
		$this->assertSame( 'label', self::read( $src, array( 'points', 'stars' ) )['step'] );
	}

	public function test_guid_and_decoding() {
		$this->assertSame( 'Ci8rx_FyPFH4B_VxpdKhhA', Open_Bounty_Yotpo::guid( '<script src="https://cdn-loyalty.yotpo.com/loader/Ci8rx_FyPFH4B_VxpdKhhA.js">' ) );
		$this->assertSame( 'LmHBN13Zs3i7CzR3h1v6rw', Open_Bounty_Yotpo::guid( 'cdn-widgetsrepository.yotpo.com/v1/loader/LmHBN13Zs3i7CzR3h1v6rw"' ) );
		$this->assertNull( Open_Bounty_Yotpo::guid( 'cdn-widgetsrepository.yotpo.com/v1/loader/kxsexXwHlT77dMAupSHKvbfxIs6MaGivuYechC2U"' ) );
		$this->assertSame( "don't Aé / \n", Open_Bounty_Yotpo::js_unescape( "don\\'t \\x41\\u00e9 \\/ \\n" ) );
		$this->assertNull( Open_Bounty_Yotpo::parse_loader( 'var x = 1;' ) );
		$this->assertSame( 'Point', Open_Bounty_Yotpo::singular_of( 'Points' ) );
		$this->assertNull( Open_Bounty_Yotpo::singular_of( 'Cactus' ) );
	}

	public function test_a_large_real_sized_campaigns_block_still_parses() {
		// A real loader's campaigns literal is ~80 KB. The backtracking regex form
		// failed silently on it (PCRE JIT stack), reading as "no rule".
		$filler = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$filler[] = array( 'id' => 10000 + $i, 'type' => 'BirthdayCampaign', 'details' => str_repeat( "Earn points \"now\" don't wait ", 10 ) );
		}
		$src = self::loader( array( 'campaigns' => array_merge( $filler, array( self::campaign( 995767 ) ) ) ) );
		$this->assertGreaterThan( 150000, strlen( $src ) );
		$r = self::read( $src );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 995767, $r['rule']['rule_id'] );
	}

	public function test_the_object_validates() {
		$r      = self::read( self::loader() );
		$object = Open_Bounty_Smile::build_object( $r['rule'], 'Members', 'Example Outfitters', 'https://shop.example/' );
		$schema = json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
		$this->assertSame( array(), Open_Bounty_Schema::check( $object, $schema ) );
	}
}
