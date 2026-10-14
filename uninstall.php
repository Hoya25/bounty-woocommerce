<?php
/**
 * Removing the plugin removes what it published. After this, the store's
 * /.well-known/bounty.json is a 404 and its product pages carry nothing.
 *
 * @package OpenBounty
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'open_bounty_settings' );
delete_option( 'open_bounty_published' );
delete_transient( 'open_bounty_schema' );
delete_transient( 'open_bounty_alliance_live' );
delete_transient( 'open_bounty_healed' );
flush_rewrite_rules( false );
