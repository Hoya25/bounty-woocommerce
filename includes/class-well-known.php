<?php
/**
 * Carrier (a): /.well-known/bounty.json — the canonical one.
 *
 * Served through a rewrite rule that is registered on EVERY request (init), so
 * any flush — Settings → Permalinks → Save, another plugin, a migration —
 * rebuilds the rules with this one in them. A flush cannot drop what is
 * re-registered before it runs. If the stored rules ever lack it anyway (rules
 * flushed while this plugin was inactive), they are rebuilt once on the next
 * admin request.
 *
 * Also emits <link rel="bounty"> in wp_head pointing at the same URL, for agents
 * that start from the home page (spec §4 step 2) and for sites installed in a
 * subdirectory, where /.well-known/ at the domain root is not WordPress's to
 * serve.
 *
 * Nothing saved → a 404, exactly as if this plugin were not installed.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Well_Known {

	const QUERY_VAR = 'open_bounty_well_known';
	const RULE      = '^\.well-known/bounty\.json$';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'heal' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'head_link' ), 1 );
	}

	public static function register() {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function activate() {
		self::register();
		flush_rewrite_rules( false );
	}

	public static function deactivate() {
		flush_rewrite_rules( false );
	}

	/** Rebuilds the rules once if they were flushed while this rule was missing. */
	public static function heal() {
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && ! isset( $rules[ self::RULE ] ) && ! get_transient( 'open_bounty_healed' ) ) {
			set_transient( 'open_bounty_healed', 1, HOUR_IN_SECONDS );
			flush_rewrite_rules( false );
		}
	}

	/** A trailing-slash redirect would turn a valid fetch into a 301. */
	public static function no_canonical_redirect( $redirect, $requested ) {
		return false !== strpos( (string) $requested, self::path_suffix() ) ? false : $redirect;
	}

	private static function path_suffix() {
		return '.well-known/bounty.json';
	}

	public static function url() {
		return home_url( '/' . self::path_suffix() );
	}

	/** @param WP $wp */
	public static function serve( $wp ) {
		$matched = ! empty( $wp->query_vars[ self::QUERY_VAR ] )
			|| self::path_suffix() === trim( (string) $wp->request, '/' );
		if ( ! $matched ) {
			return;
		}

		$body = Open_Bounty_Render::well_known_body( Open_Bounty_Store::current() );
		if ( null === $body ) {
			// Nothing published: the honest answer is that nothing is here.
			$wp->query_vars = array( 'error' => '404' );
			return;
		}

		$published = Open_Bounty_Store::published();
		$schema    = $published && ! empty( $published['schema_id'] ) ? $published['schema_id'] : Open_Bounty_Schema::SCHEMA_URL;

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Link: <' . esc_url_raw( $schema ) . '>; rel="describedby"' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON body, gated in Open_Bounty_Render.
		}
		exit;
	}

	public static function head_link() {
		if ( null === Open_Bounty_Render::well_known_body( Open_Bounty_Store::current() ) ) {
			return;
		}
		echo Open_Bounty_Render::head_link( self::url() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in head_link().
	}
}
