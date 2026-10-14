<?php
/**
 * The schema-driven validator, against a snapshot of the published v1 schema
 * (tests/fixtures/bounty-v1.json). The plugin itself always validates against
 * the live document; the snapshot only keeps these tests offline.
 */

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	private static function schema() {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/bounty-v1.json' ), true );
	}

	private static function own( array $over = array() ) {
		return Open_Bounty_Store::build_own(
			array_merge(
				array(
					'program_name' => 'Example Rewards',
					'program_url'  => 'https://rewards.example',
					'earn_display' => 'Members earn points on every order.',
					'earn_type'    => 'points',
					'denomination' => '',
					'terms_url'    => '',
				),
				$over
			)
		);
	}

	public function test_the_live_alliance_object_validates() {
		$alliance = json_decode( file_get_contents( __DIR__ . '/fixtures/alliance.json' ), true );
		$this->assertSame( array(), Open_Bounty_Schema::check( $alliance, self::schema() ) );
	}

	public function test_an_own_reward_validates_and_omits_blank_optionals() {
		$o = self::own();
		$this->assertSame( array(), Open_Bounty_Schema::check( $o, self::schema() ) );
		$this->assertArrayNotHasKey( 'denomination', $o['earn'] );
		$this->assertArrayNotHasKey( 'terms_url', $o );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $o['updated_at'] );
		$this->assertSame( '0.3', $o['bounty_schema_version'] );
	}

	public function test_the_validator_rejects() {
		$fields = function ( $issues ) { return array_column( $issues, 'field' ); };
		$s      = self::schema();

		$this->assertContains( 'earn.display', $fields( Open_Bounty_Schema::check( self::own( array( 'earn_display' => '' ) ), $s ) ) );
		$this->assertContains( 'earn.display', $fields( Open_Bounty_Schema::check( self::own( array( 'earn_display' => str_repeat( 'a', 201 ) ) ), $s ) ) );
		$this->assertContains( 'program.url', $fields( Open_Bounty_Schema::check( self::own( array( 'program_url' => 'not a url' ) ), $s ) ) );
		$this->assertContains( 'earn.type', $fields( Open_Bounty_Schema::check( self::own( array( 'earn_type' => 'shares' ) ), $s ) ) );
		$this->assertContains( 'program.name', $fields( Open_Bounty_Schema::check( self::own( array( 'program_name' => '' ) ), $s ) ) );

		$extra         = self::own();
		$extra['rate'] = 0.05;
		$this->assertContains( 'rate', $fields( Open_Bounty_Schema::check( $extra, $s ) ) );

		$v1                          = self::own();
		$v1['bounty_schema_version'] = '1.0';
		$this->assertContains( 'bounty_schema_version', $fields( Open_Bounty_Schema::check( $v1, $s ) ) );
	}

	public function test_exactly_two_hundred_characters_is_allowed() {
		$this->assertSame( array(), Open_Bounty_Schema::check( self::own( array( 'earn_display' => str_repeat( 'é', 200 ) ) ), self::schema() ) );
	}

	public function test_banned_vocabulary_fails_the_save_even_when_the_schema_passes() {
		$issues = Open_Bounty_Schema::check( self::own( array( 'earn_display' => 'Earn cash back on every order.' ) ), self::schema() );
		$this->assertSame( array( 'earn.display' ), array_column( $issues, 'field' ) );
	}

	public function test_every_zero_x_version_is_accepted() {
		foreach ( array( '0.1', '0.2', '0.3', '0.4' ) as $v ) {
			$o                          = self::own();
			$o['bounty_schema_version'] = $v;
			$this->assertSame( array(), Open_Bounty_Schema::check( $o, self::schema() ), $v );
		}
	}

	public function test_nothing_is_current_until_a_save() {
		$this->assertNull( Open_Bounty_Store::current() );
	}
}
