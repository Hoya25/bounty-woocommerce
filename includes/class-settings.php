<?php
/**
 * WooCommerce → Bounty.
 *
 * Two choices: the NCTR Alliance's object, fetched live, or the merchant's own
 * reward, described in five fields. Either way the object is validated against
 * the published schema (and the disclosure gate) BEFORE anything is saved, and
 * the page says exactly which schema and object version it was checked against.
 * A save that fails writes nothing.
 *
 * @package OpenBounty
 */

defined( 'ABSPATH' ) || exit;

final class Open_Bounty_Settings {

	const SLUG = 'open-bounty';
	const CAP  = 'manage_woocommerce';

	/** @var array|null Result of this request's form submission, shown on the page. */
	private static $result = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'plugin_action_links_' . plugin_basename( OPEN_BOUNTY_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function menu() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Bounty', 'open-bounty' ),
			__( 'Bounty', 'open-bounty' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		add_action( 'load-' . $hook, array( __CLASS__, 'handle' ) );
	}

	public static function action_link( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'open-bounty' ) ) );
		return $links;
	}

	private static function page_url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Runs before any output, on this page only. */
	public static function handle() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change this.', 'open-bounty' ) );
		}
		check_admin_referer( 'open_bounty_save' );

		if ( isset( $_POST['open_bounty_unpublish'] ) ) {
			Open_Bounty_Store::unpublish();
			self::$result = array( 'unpublished' => true );
			return;
		}

		$s = self::read_form();

		$schema = Open_Bounty_Schema::get();
		if ( is_wp_error( $schema ) ) {
			self::$result = array(
				'ok'     => false,
				'form'   => $s,
				'issues' => array( array( 'field' => 'schema', 'message' => $schema->get_error_message() . ' Nothing was published. Try again in a minute.' ) ),
			);
			return;
		}

		$issues = array();
		$provenance = null;
		$providers = self::providers();
		if ( isset( $providers[ $s['mode'] ] ) ) {
			// Read live at save. The sentence comes from the rule, never the form.
			$p    = $providers[ $s['mode'] ];
			$read = call_user_func( array( $p['class'], 'read' ), home_url( '/' ) );
			if ( ! $read['ok'] ) {
				$issues[] = array(
					'field'   => $p['label'],
					'message' => $read['message'],
				);
				$object = null;
			} else {
				$object     = Open_Bounty_Smile::build_object( $read['rule'], $s['loyalty_noun'], get_bloginfo( 'name' ), home_url( '/' ) );
				$provenance = Open_Bounty_Smile::provenance( $read['rule'], $p['label'] );
			}
		} elseif ( 'alliance' === $s['mode'] ) {
			$object = Open_Bounty_Schema::fetch_json( Open_Bounty_Schema::ALLIANCE_URL );
			if ( is_wp_error( $object ) ) {
				$issues[] = array( 'field' => 'NCTR Alliance', 'message' => $object->get_error_message() );
				$object   = null;
			} else {
				$object = Open_Bounty_Store::store_copy( $object );
			}
			if ( ! $s['alliance_confirmed'] ) {
				$issues[] = array(
					'field'   => 'confirmation',
					'message' => __( 'Confirm that purchases at this store earn through the NCTR Alliance. Publishing the alliance\'s sentence on a store that is not connected would tell shoppers something untrue.', 'open-bounty' ),
				);
			}
		} else {
			$object = Open_Bounty_Store::build_own( $s );
		}

		if ( is_array( $object ) ) {
			$issues = array_merge( $issues, Open_Bounty_Schema::check( $object, $schema ) );
		}

		$validated = array(
			'schema_id'      => isset( $schema['$id'] ) ? (string) $schema['$id'] : Open_Bounty_Schema::SCHEMA_URL,
			'object_version' => is_array( $object ) && isset( $object['bounty_schema_version'] ) ? (string) $object['bounty_schema_version'] : 'unknown',
		);

		if ( $issues ) {
			self::$result = array(
				'ok'        => false,
				'form'      => $s,
				'issues'    => $issues,
				'validated' => $validated,
			);
			return;
		}

		Open_Bounty_Store::save( $s, $object, $schema );
		self::$result = array(
			'ok'         => true,
			'validated'  => $validated,
			'provenance' => $provenance,
		);
	}

	/**
	 * Loyalty programs whose live earning rule can be read and composed into
	 * the sentence. Each note says plainly how the provider stands on WooCommerce.
	 */
	private static function providers() {
		return array(
			'smile' => array(
				'class' => 'Open_Bounty_Smile',
				'label' => 'Smile.io',
				'note'  => __( 'Note: Smile.io runs on Shopify and BigCommerce and has no WooCommerce integration, so on most WooCommerce stores there will be no program to read.', 'open-bounty' ),
			),
			'yotpo' => array(
				'class' => 'Open_Bounty_Yotpo',
				'label' => 'Yotpo Loyalty',
				'note'  => __( 'Note: Yotpo says it does not currently support new WooCommerce accounts and doesn’t list WooCommerce as a supported platform. Existing or custom installs that load Yotpo’s loyalty script on your pages can still be read.', 'open-bounty' ),
			),
			'loyaltylion' => array(
				'class' => 'Open_Bounty_LoyaltyLion',
				'label' => 'LoyaltyLion',
				'note'  => __( 'Note: LoyaltyLion lists Shopify, Adobe Commerce and custom platforms as install targets; WooCommerce isn’t one of them. A custom install that loads LoyaltyLion’s script on your pages can be read.', 'open-bounty' ),
			),
		);
	}

	private static function read_form() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle().
		$text = function ( $key ) {
			return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
		};
		$url  = function ( $key ) {
			return isset( $_POST[ $key ] ) ? trim( esc_url_raw( wp_unslash( $_POST[ $key ] ), array( 'http', 'https' ) ) ) : '';
		};
		$type = $text( 'earn_type' );

		$s = array(
			'mode'               => in_array( $text( 'mode' ), array_merge( array( 'own' ), array_keys( self::providers() ) ), true ) ? $text( 'mode' ) : 'alliance',
			'alliance_confirmed' => ! empty( $_POST['alliance_confirmed'] ),
			'program_name'       => $text( 'program_name' ),
			'program_url'        => $url( 'program_url' ),
			// The sentence is published verbatim, so it is stored verbatim: only
			// tags and line breaks are stripped, nothing is rewritten.
			'earn_display'       => isset( $_POST['earn_display'] ) ? trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( wp_unslash( $_POST['earn_display'] ) ) ) ) : '',
			'earn_type'          => in_array( $type, Open_Bounty_Store::EARN_TYPES, true ) ? $type : 'other',
			'denomination'       => $text( 'denomination' ),
			'terms_url'          => $url( 'terms_url' ),
			'show_block'         => ! empty( $_POST['show_block'] ),
			'loyalty_noun'       => 'Customers' === $text( 'noun_' . $text( 'mode' ) ) ? 'Customers' : 'Members',
		);
		// phpcs:enable
		return $s;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$r         = self::$result;
		$s         = $r && isset( $r['form'] ) ? $r['form'] : Open_Bounty_Store::settings();
		$published = Open_Bounty_Store::published();
		$current   = Open_Bounty_Store::current();
		$own       = 'own' === $s['mode'];
		$providers = self::providers();
		$loyalty   = isset( $providers[ $s['mode'] ] );
		?>
		<div class="wrap open-bounty-admin">
			<h1><?php esc_html_e( 'Bounty', 'open-bounty' ); ?></h1>
			<p class="description" style="font-size:14px;max-width:46em">
				<?php esc_html_e( 'Declare what a purchase pays the shopper, in a form AI shopping agents can read. This publishes one small Open Bounty Schema object for your store, and a line on each product page.', 'open-bounty' ); ?>
			</p>

			<?php self::render_result( $r ); ?>
			<?php self::render_status( $published, $current ); ?>

			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
				<?php wp_nonce_field( 'open_bounty_save' ); ?>

				<h2><?php esc_html_e( 'Which program funds your bounty?', 'open-bounty' ); ?></h2>
				<fieldset class="open-bounty-choice">
					<label style="display:block;margin:0 0 .6em">
						<input type="radio" name="mode" value="alliance" <?php checked( ! $own && ! $loyalty ); ?> />
						<strong><?php esc_html_e( 'NCTR Alliance', 'open-bounty' ); ?></strong>
						— <?php esc_html_e( 'fetched live from the alliance, so its sentence stays current without you editing anything.', 'open-bounty' ); ?>
					</label>
					<label style="display:block">
						<input type="radio" name="mode" value="own" <?php checked( $own ); ?> />
						<strong><?php esc_html_e( 'Describe your own reward', 'open-bounty' ); ?></strong>
						— <?php esc_html_e( 'any rewards program: points, store credit, a gift, a cash reward.', 'open-bounty' ); ?>
					</label>
					<?php foreach ( $providers as $key => $p ) : ?>
						<label style="display:block;margin-top:.6em">
							<input type="radio" name="mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['mode'], $key ); ?> />
							<strong><?php echo esc_html( sprintf( /* translators: %s: provider */ __( 'Use my %s program', 'open-bounty' ), $p['label'] ) ); ?></strong>
							— <?php echo esc_html( sprintf( /* translators: %s: provider */ __( 'reads your points program\'s earning rule directly from %s.', 'open-bounty' ), $p['label'] ) ); ?>
						</label>
					<?php endforeach; ?>
					<?php // Okendo Loyalty is a Shopify app with no WooCommerce version: there is no program to read, so it is a note, not a choice. ?>
					<p class="description open-bounty-unavailable" style="margin-top:.8em;max-width:46em">
						<?php esc_html_e( 'Okendo Loyalty: not available here. Okendo runs only on Shopify and has no WooCommerce version, so there is no Okendo program on a WooCommerce store to read.', 'open-bounty' ); ?>
					</p>
				</fieldset>

				<table class="form-table" role="presentation" data-mode="alliance">
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirm', 'open-bounty' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="alliance_confirmed" value="1" <?php checked( ! empty( $s['alliance_confirmed'] ) ); ?> />
								<?php esc_html_e( 'Purchases at this store earn through the NCTR Alliance.', 'open-bounty' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only tick this if your store is connected to the alliance. The alliance\'s sentence is a promise to shoppers; it has to be true here.', 'open-bounty' ); ?></p>
						</td>
					</tr>
				</table>

				<?php foreach ( $providers as $key => $p ) : ?>
				<table class="form-table" role="presentation" data-mode="<?php echo esc_attr( $key ); ?>">
					<tr>
						<th scope="row"><label for="ob-noun-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Who earns?', 'open-bounty' ); ?></label></th>
						<td>
							<select id="ob-noun-<?php echo esc_attr( $key ); ?>" name="noun_<?php echo esc_attr( $key ); ?>">
								<option value="Members" <?php selected( $s['loyalty_noun'], 'Members' ); ?>><?php esc_html_e( 'Members', 'open-bounty' ); ?></option>
								<option value="Customers" <?php selected( $s['loyalty_noun'], 'Customers' ); ?>><?php esc_html_e( 'Customers', 'open-bounty' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: provider */ __( 'The rate and the points name come from your live %1$s rule and can’t be typed. If %1$s can’t be read, nothing is published.', 'open-bounty' ), $p['label'] ) ); ?></p>
							<p class="description"><?php echo esc_html( $p['note'] ); ?></p>
						</td>
					</tr>
				</table>
				<?php endforeach; ?>

				<table class="form-table" role="presentation" data-mode="own">
					<tr>
						<th scope="row"><label for="ob-program-name"><?php esc_html_e( 'Program name', 'open-bounty' ); ?></label></th>
						<td><input id="ob-program-name" name="program_name" type="text" class="regular-text" value="<?php echo esc_attr( $s['program_name'] ); ?>" placeholder="<?php esc_attr_e( 'Example Rewards', 'open-bounty' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ob-program-url"><?php esc_html_e( 'Program URL', 'open-bounty' ); ?></label></th>
						<td><input id="ob-program-url" name="program_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['program_url'] ); ?>" placeholder="https://" />
						<p class="description"><?php esc_html_e( 'Where a shopper can read about the program.', 'open-bounty' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ob-earn-display"><?php esc_html_e( 'What a purchase earns', 'open-bounty' ); ?></label></th>
						<td>
							<textarea id="ob-earn-display" name="earn_display" rows="2" class="large-text" maxlength="200" style="max-width:36em"><?php echo esc_textarea( $s['earn_display'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One plain sentence, up to 200 characters. Agents show it to shoppers word for word, so say only what every qualifying purchase actually pays today.', 'open-bounty' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ob-earn-type"><?php esc_html_e( 'Paid as', 'open-bounty' ); ?></label></th>
						<td>
							<select id="ob-earn-type" name="earn_type">
								<?php
								$labels = array(
									'points' => __( 'Points', 'open-bounty' ),
									'token'  => __( 'Token', 'open-bounty' ),
									'cash'   => __( 'Cash', 'open-bounty' ),
									'other'  => __( 'Other (credit, a gift, …)', 'open-bounty' ),
								);
								foreach ( $labels as $value => $label ) {
									printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $s['earn_type'], $value, false ), esc_html( $label ) );
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ob-denomination"><?php esc_html_e( 'Denomination', 'open-bounty' ); ?></label> <span class="description">(<?php esc_html_e( 'optional', 'open-bounty' ); ?>)</span></th>
						<td><input id="ob-denomination" name="denomination" type="text" class="regular-text" value="<?php echo esc_attr( $s['denomination'] ); ?>" placeholder="<?php esc_attr_e( 'points, USD, store credit', 'open-bounty' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ob-terms-url"><?php esc_html_e( 'Terms URL', 'open-bounty' ); ?></label> <span class="description">(<?php esc_html_e( 'optional', 'open-bounty' ); ?>)</span></th>
						<td><input id="ob-terms-url" name="terms_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['terms_url'] ); ?>" placeholder="https://" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Product pages', 'open-bounty' ); ?></h2>
				<label>
					<input type="checkbox" name="show_block" value="1" <?php checked( ! empty( $s['show_block'] ) ); ?> />
					<?php esc_html_e( 'Show “Rewards on this purchase” under the price', 'open-bounty' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'The structured data agents read is added to every product page either way.', 'open-bounty' ); ?></p>

				<p class="submit" style="display:flex;gap:1em;align-items:center;flex-wrap:wrap">
					<?php submit_button( __( 'Validate and publish', 'open-bounty' ), 'primary', 'open_bounty_save', false ); ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: schema URL */
							esc_html__( 'Checked against %s before anything is saved.', 'open-bounty' ),
							'<a href="' . esc_url( Open_Bounty_Schema::SCHEMA_URL ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '~^https://~', '', Open_Bounty_Schema::SCHEMA_URL ) ) . '</a>'
						);
						?>
					</span>
				</p>
				<?php if ( $published ) : ?>
					<p><?php submit_button( __( 'Stop publishing', 'open-bounty' ), 'link-delete', 'open_bounty_unpublish', false ); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<script>
		( function () {
			var radios = document.querySelectorAll( '.open-bounty-admin input[name="mode"]' );
			function sync() {
				var mode = document.querySelector( '.open-bounty-admin input[name="mode"]:checked' ).value;
				document.querySelectorAll( '.open-bounty-admin table[data-mode]' ).forEach( function ( t ) {
					t.style.display = t.getAttribute( 'data-mode' ) === mode ? '' : 'none';
				} );
			}
			radios.forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
			sync();
		} )();
		</script>
		<?php
	}

	private static function render_result( $r ) {
		if ( ! $r ) {
			return;
		}
		if ( ! empty( $r['unpublished'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Stopped publishing. /.well-known/bounty.json now returns 404 and product pages carry nothing.', 'open-bounty' ) . '</p></div>';
			return;
		}
		if ( $r['ok'] ) {
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Published.', 'open-bounty' ) . '</strong> ';
			echo esc_html( self::validated_line( $r['validated'] ) ) . '</p>';
			if ( ! empty( $r['provenance'] ) ) {
				echo '<p>' . esc_html( $r['provenance'] ) . '</p>';
			}
			echo '</div>';
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Nothing was published.', 'open-bounty' ) . '</strong>';
		// Only say what was validated when an object actually existed to validate.
		if ( ! empty( $r['validated'] ) && 'unknown' !== $r['validated']['object_version'] ) {
			echo ' ' . esc_html( self::validated_line( $r['validated'] ) );
		}
		echo '</p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $r['issues'] as $i ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( $i['field'] ), esc_html( $i['message'] ) );
		}
		echo '</ul></div>';
	}

	private static function validated_line( $v ) {
		return sprintf(
			/* translators: 1: schema id, 2: object version */
			__( 'Validated against %1$s · object version %2$s', 'open-bounty' ),
			preg_replace( '~^https://~', '', $v['schema_id'] ),
			$v['object_version']
		);
	}

	private static function render_status( $published, $current ) {
		echo '<div class="card" style="max-width:46em;padding:1em 1.4em">';
		if ( ! $published || ! is_array( $current ) ) {
			echo '<h2 style="margin-top:.2em">' . esc_html__( 'Nothing is published yet', 'open-bounty' ) . '</h2>';
			echo '<p>' . esc_html__( 'Until you publish, /.well-known/bounty.json returns 404 and product pages carry nothing. Agents treat the store as unknown, never as “no reward”.', 'open-bounty' ) . '</p>';
			echo '</div>';
			return;
		}
		$url = Open_Bounty_Well_Known::url();
		echo '<h2 style="margin-top:.2em">' . esc_html__( 'What agents read now', 'open-bounty' ) . '</h2>';
		echo '<blockquote style="font-size:15px;margin:0 0 .6em;padding-left:1em;border-left:3px solid #c3c4c7">“' . esc_html( $current['earn']['display'] ) . '”</blockquote>';
		echo '<p class="description" style="margin-top:0">' . esc_html__( 'Shown to shoppers verbatim.', 'open-bounty' ) . '</p>';
		echo '<table class="widefat striped" style="border:0;margin:.6em 0"><tbody>';
		$rows = array(
			__( 'Program', 'open-bounty' )        => $current['program']['name'],
			__( 'Paid as', 'open-bounty' )        => isset( $current['earn']['type'] ) ? $current['earn']['type'] : '—',
			__( 'Denomination', 'open-bounty' )   => isset( $current['earn']['denomination'] ) ? $current['earn']['denomination'] : '—',
			__( 'Status', 'open-bounty' )         => ! empty( $current['active'] ) ? __( 'bounty active', 'open-bounty' ) : __( 'not currently offering a bounty', 'open-bounty' ),
			__( 'Last updated', 'open-bounty' )   => $current['updated_at'],
		);
		// v0.5 rewards, when the program's data gave a truthful block.
		$rw = isset( $current['rewards'] ) && is_array( $current['rewards'] ) ? $current['rewards'] : array();
		if ( ! empty( $rw['value'] ) ) {
			$rows[ __( 'What points are worth', 'open-bounty' ) ] = $rw['value'];
		}
		if ( ! empty( $rw['ladder']['rungs'] ) ) {
			$unit = isset( $rw['ladder']['unit'] ) ? $rw['ladder']['unit'] : '';
			$rows[ __( 'Tiers', 'open-bounty' ) ] = implode(
				' · ',
				array_map(
					function ( $r ) use ( $unit ) {
						return $r['rung'] . ' (' . $r['threshold'] . ' ' . $unit . ')';
					},
					$rw['ladder']['rungs']
				)
			);
		}
		if ( ! empty( $rw['perks'] ) ) {
			$rows[ __( 'Entry-tier perks', 'open-bounty' ) ] = implode( ' · ', array_column( $rw['perks'], 'name' ) );
		}
		foreach ( $rows as $k => $v ) {
			printf( '<tr><th style="width:11em">%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( $v ) );
		}
		echo '</tbody></table>';
		printf(
			'<p>%s <a href="%s" target="_blank" rel="noopener"><code>%s</code></a></p>',
			esc_html__( 'Published at', 'open-bounty' ),
			esc_url( $url ),
			esc_html( $url )
		);
		echo '<p class="description">' . esc_html(
			self::validated_line(
				array(
					'schema_id'      => $published['schema_id'],
					'object_version' => isset( $current['bounty_schema_version'] ) ? $current['bounty_schema_version'] : $published['object_version'],
				)
			)
		) . '</p>';
		echo '</div>';
	}
}
