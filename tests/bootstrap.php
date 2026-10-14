<?php
/**
 * Minimal WordPress stand-ins, enough to load the plugin's pure classes and
 * render their output outside WordPress. Behaviour mirrors core where it
 * matters to the assertions (escaping, JSON encoding); nothing here is clever.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'OPEN_BOUNTY_FILE', dirname( __DIR__ ) . '/open-bounty.php' );
define( 'OPEN_BOUNTY_VERSION', 'test' );

$GLOBALS['ob_options']    = array();
$GLOBALS['ob_transients'] = array();

function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function __( $t ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $t ) { return esc_html( $t ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $u ) { return esc_html( $u ); }
function esc_url_raw( $u ) { return (string) $u; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ob_options'] ) ? $GLOBALS['ob_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['ob_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['ob_options'][ $k ] ); return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['ob_transients'] ) ? $GLOBALS['ob_transients'][ $k ] : false; }
function set_transient( $k, $v ) { $GLOBALS['ob_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['ob_transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public function __construct( public $code = '', public $message = '' ) {} public function get_error_message() { return $this->message; } }

require dirname( __DIR__ ) . '/includes/class-gate.php';
require dirname( __DIR__ ) . '/includes/class-schema.php';
require dirname( __DIR__ ) . '/includes/class-store.php';
require dirname( __DIR__ ) . '/includes/class-render.php';
require dirname( __DIR__ ) . '/includes/class-smile.php';
