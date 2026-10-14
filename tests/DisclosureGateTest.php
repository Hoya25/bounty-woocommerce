<?php
/**
 * The disclosure gate, run over everything this plugin RENDERS.
 *
 * Ported from the NCTR Alliance Registry's gate. The vocabulary checks run over
 * the real output of Open_Bounty_Render — the well-known body, the head link,
 * the JSON-LD property and the product block — for every object a merchant can
 * produce, so a regression in any template fails here.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisclosureGateTest extends TestCase {

	/** Every public byte for one object. */
	private static function rendered( array $object ) {
		return array(
			'well-known' => (string) Open_Bounty_Render::well_known_body( $object ),
			'head link'  => Open_Bounty_Render::head_link( 'https://shop.example/.well-known/bounty.json' ),
			'json-ld'    => (string) json_encode( Open_Bounty_Render::offer_property( $object ) ),
			'block'      => Open_Bounty_Render::product_block( $object ),
		);
	}

	public static function objects() {
		$alliance = json_decode( file_get_contents( __DIR__ . '/fixtures/alliance.json' ), true );
		$own      = Open_Bounty_Store::build_own(
			array(
				'program_name' => 'Example Rewards',
				'program_url'  => 'https://rewards.example',
				'earn_display' => 'Members earn points on every order, redeemable for store credit.',
				'earn_type'    => 'points',
				'denomination' => 'points',
				'terms_url'    => 'https://rewards.example/terms',
			)
		);
		return array(
			'NCTR Alliance (live object)' => array( $alliance ),
			'own reward'                  => array( $own ),
		);
	}

	#[DataProvider( 'objects' )]
	public function test_every_rendered_surface_passes_the_gate( array $object ) {
		foreach ( self::rendered( $object ) as $surface => $out ) {
			$this->assertNotSame( '', $out, "$surface rendered nothing" );
			$gate = Open_Bounty_Gate::scan( $out );
			$this->assertTrue( $gate['clean'], "$surface leaked: " . implode( ', ', $gate['hits'] ) );
		}
	}

	#[DataProvider( 'objects' )]
	public function test_earn_display_is_verbatim_everywhere( array $object ) {
		$display = $object['earn']['display'];
		$this->assertSame( $display, json_decode( Open_Bounty_Render::well_known_body( $object ), true )['earn']['display'] );
		$this->assertSame( $display, Open_Bounty_Render::offer_property( $object )['value'] );
		$this->assertStringContainsString( esc_html( $display ), Open_Bounty_Render::product_block( $object ) );
	}

	#[DataProvider( 'objects' )]
	public function test_the_block_adds_no_digits_of_its_own( array $object ) {
		$block = Open_Bounty_Render::product_block( $object );
		// Remove what the merchant (or program) wrote; what remains is ours.
		$ours = str_replace(
			array( esc_html( $object['earn']['display'] ), esc_html( $object['program']['name'] ), esc_url( $object['program']['url'] ) ),
			'',
			$block
		);
		$this->assertDoesNotMatchRegularExpression( '/\d/', strip_tags( $ours ), 'the plugin added a digit' );
		$this->assertStringContainsString( 'Rewards on this purchase', $block );
		$this->assertStringContainsString( esc_html( $object['program']['name'] ), $block );
	}

	public function test_json_ld_carries_the_spec_shape() {
		$alliance = self::objects()['NCTR Alliance (live object)'][0];
		$p        = Open_Bounty_Render::offer_property( $alliance );
		$this->assertSame( 'PropertyValue', $p['@type'] );
		$this->assertSame( 'bounty', $p['propertyID'] );
		$this->assertSame( $alliance['api'], $p['url'] );
		$own = self::objects()['own reward'][0];
		$this->assertArrayNotHasKey( 'url', Open_Bounty_Render::offer_property( $own ), 'no api, no url' );
	}

	public function test_a_banned_sentence_renders_nothing_anywhere() {
		foreach ( array( 'Get 5% cashback on everything.', 'Earn yield on every order.', 'Purchases are an investment in your future.' ) as $bad ) {
			$o = self::objects()['own reward'][0];
			$o['earn']['display'] = $bad;
			$this->assertNull( Open_Bounty_Render::well_known_body( $o ), $bad );
			$this->assertNull( Open_Bounty_Render::offer_property( $o ), $bad );
			$this->assertSame( '', Open_Bounty_Render::product_block( $o ), $bad );
		}
	}

	public function test_an_inactive_bounty_makes_no_product_claim() {
		$o           = self::objects()['own reward'][0];
		$o['active'] = false;
		$this->assertSame( '', Open_Bounty_Render::product_block( $o ) );
		$this->assertNull( Open_Bounty_Render::offer_property( $o ) );
		$this->assertNotNull( Open_Bounty_Render::well_known_body( $o ), 'active:false is still an honest object to serve' );
	}

	public function test_finance_terms_are_word_bounded() {
		$this->assertFalse( Open_Bounty_Gate::is_clean( 'cash back on orders' ) );
		$this->assertFalse( Open_Bounty_Gate::is_clean( 'APY' ) );
		$this->assertTrue( Open_Bounty_Gate::is_clean( 'a priceless gift with a generous returns policy' ) );
	}

	public function test_the_digest_matcher_is_a_bare_substring_match() {
		$digest = hash( 'sha256', 'zqxv' );
		$this->assertTrue( Open_Bounty_Gate::contains_digest( 'abc_zqxv_rate', 4, array( $digest ) ) );
		$this->assertTrue( Open_Bounty_Gate::contains_digest( 'https://x.example/zqxv/', 4, array( $digest ) ) );
		$this->assertFalse( Open_Bounty_Gate::contains_digest( 'zqx v', 4, array( $digest ) ) );
	}

	public function test_vendor_names_never_appear_in_this_repository_as_text() {
		// The gate holds digests, not names. Checked by the names' digests, so
		// this test can run in public without containing them either.
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $f ) {
			$path = $f->getPathname();
			if ( preg_match( '~/(\.git|dev|node_modules|build)/~', $path ) || ! preg_match( '/\.(php|md|txt|json|js|css|sh)$/', $path ) ) {
				continue;
			}
			$gate = Open_Bounty_Gate::scan( strtolower( file_get_contents( $path ) ) );
			$this->assertNotContains( 'a vendor name', $gate['hits'], "$path contains a vendor name" );
		}
	}

	/**
	 * Local only: OPEN_BOUNTY_VENDOR_TERMS="name1,name2" proves the digests match
	 * the real names. Skipped in public CI, where the names are not available.
	 */
	public function test_digests_match_the_real_vendor_names() {
		$terms = getenv( 'OPEN_BOUNTY_VENDOR_TERMS' );
		if ( ! $terms ) {
			$this->markTestSkipped( 'OPEN_BOUNTY_VENDOR_TERMS not set' );
		}
		foreach ( explode( ',', $terms ) as $t ) {
			$this->assertFalse( Open_Bounty_Gate::is_clean( 'earn at ' . $t . '_affiliate' ), 'digest missing for one term' );
			$this->assertFalse( Open_Bounty_Gate::is_clean( strtoupper( $t ) ), 'case-insensitive' );
		}
	}
}
