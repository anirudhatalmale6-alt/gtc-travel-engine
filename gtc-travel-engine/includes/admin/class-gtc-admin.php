<?php
/**
 * Admin screens: suppliers, settings, bookings and the supplier call log.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_gtc_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_gtc_save_providers', array( $this, 'save_providers' ) );
		add_action( 'admin_post_gtc_flush_cache', array( $this, 'flush_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * @param string $hook Current admin page.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'gtc' ) ) {
			return;
		}
		wp_enqueue_style( 'gtc-admin', GTC_URL . 'assets/css/admin.css', array(), GTC_VERSION );
	}

	public function menu() {
		add_menu_page(
			__( 'Travel Engine', 'gtc' ),
			__( 'Travel Engine', 'gtc' ),
			self::CAP,
			'gtc',
			array( $this, 'page_dashboard' ),
			'dashicons-palmtree',
			56
		);

		add_submenu_page( 'gtc', __( 'Overview', 'gtc' ), __( 'Overview', 'gtc' ), self::CAP, 'gtc', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'gtc', __( 'Suppliers', 'gtc' ), __( 'Suppliers', 'gtc' ), self::CAP, 'gtc-providers', array( $this, 'page_providers' ) );
		add_submenu_page( 'gtc', __( 'Settings', 'gtc' ), __( 'Settings', 'gtc' ), self::CAP, 'gtc-settings', array( $this, 'page_settings' ) );
		add_submenu_page( 'gtc', __( 'Bookings', 'gtc' ), __( 'Bookings', 'gtc' ), self::CAP, 'gtc-bookings', array( $this, 'page_bookings' ) );
		add_submenu_page( 'gtc', __( 'Outbound clicks', 'gtc' ), __( 'Outbound clicks', 'gtc' ), self::CAP, 'gtc-clicks', array( $this, 'page_clicks' ) );
		add_submenu_page( 'gtc', __( 'Supplier log', 'gtc' ), __( 'Supplier log', 'gtc' ), self::CAP, 'gtc-log', array( $this, 'page_log' ) );
	}

	/* ------------------------------------------------------------ dashboard */

	public function page_dashboard() {
		$store    = new GTC_Booking_Store();
		$clicks   = new GTC_Click_Log();
		$referral = gtc()->settings()->is_referral_mode();

		// A referral site has no bookings and never will, so showing four
		// booking counters stuck on zero would read as a broken install.
		if ( $referral ) {
			$totals = $clicks->totals_by_provider( 30 );
			$value  = 0;
			$currency = gtc()->settings()->get( 'default_currency', 'USD' );
			foreach ( $totals as $row ) {
				$value += (int) $row['value_sent'];
			}

			$stats = array(
				__( 'Outbound clicks (30 days)', 'gtc' ) => array_sum( wp_list_pluck( $totals, 'clicks' ) ),
				__( 'Suppliers referred to', 'gtc' )     => count( $totals ),
				__( 'Value sent (30 days)', 'gtc' )      => GTC_Currency::format( $value, $currency ),
				__( 'Clicks all time', 'gtc' )           => $clicks->count(),
			);
		} else {
			$stats = array(
				__( 'Confirmed bookings', 'gtc' ) => $store->count( GTC_Booking::STATUS_CONFIRMED ),
				__( 'Quotes in progress', 'gtc' ) => $store->count( GTC_Booking::STATUS_QUOTED ) + $store->count( GTC_Booking::STATUS_REVALIDATED ),
				__( 'Needs attention', 'gtc' )    => $store->count( GTC_Booking::STATUS_SUPPLIER_ERR ),
				__( 'All records', 'gtc' )        => $store->count(),
			);
		}

		$ready = gtc()->providers()->for_category( GTC_Categories::HOTELS );
		?>
		<div class="wrap gtc-admin">
			<h1>
				<?php esc_html_e( 'Travel Engine', 'gtc' ); ?>
				<span class="gtc-pill <?php echo $referral ? '' : 'gtc-pill--good'; ?>">
					<?php echo $referral ? esc_html__( 'compare & refer out', 'gtc' ) : esc_html__( 'selling directly', 'gtc' ); ?>
				</span>
			</h1>

			<div class="gtc-admin__stats">
				<?php foreach ( $stats as $label => $value ) : ?>
					<div class="gtc-admin__stat">
						<span class="gtc-admin__stat-value"><?php echo esc_html( $value ); ?></span>
						<span class="gtc-admin__stat-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No supplier is connected, so searches will return nothing. Enable one under Suppliers.', 'gtc' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Category coverage', 'gtc' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Live suppliers', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Status', 'gtc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( GTC_Categories::all() as $slug => $meta ) : ?>
						<?php
						$live   = gtc()->providers()->for_category( $slug );
						$labels = array();
						foreach ( $live as $provider ) {
							$labels[] = $provider->get_label();
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $meta['label'] ); ?></strong></td>
							<td><?php echo $labels ? esc_html( implode( ', ', $labels ) ) : '—'; ?></td>
							<td>
								<?php if ( $live ) : ?>
									<span class="gtc-pill gtc-pill--good"><?php esc_html_e( 'Live', 'gtc' ); ?></span>
								<?php else : ?>
									<span class="gtc-pill"><?php esc_html_e( 'No supplier', 'gtc' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Shortcodes', 'gtc' ); ?></h2>
			<p><?php esc_html_e( 'Place these on the relevant pages:', 'gtc' ); ?></p>
			<ul class="gtc-admin__codes">
				<li><code>[gtc_search]</code> — <?php esc_html_e( 'search and comparison', 'gtc' ); ?></li>
				<li>
					<code>[gtc_checkout]</code> —
					<?php
					echo $referral
						? esc_html__( 'not used while the site refers out', 'gtc' )
						: esc_html__( 'checkout (set this page under Settings)', 'gtc' );
					?>
				</li>
				<li>
					<code>[gtc_confirmation]</code> —
					<?php
					echo $referral
						? esc_html__( 'not used while the site refers out', 'gtc' )
						: esc_html__( 'confirmation (set this page under Settings)', 'gtc' );
					?>
				</li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gtc_flush_cache' ); ?>
				<input type="hidden" name="action" value="gtc_flush_cache">
				<p>
					<button class="button"><?php esc_html_e( 'Clear cached searches', 'gtc' ); ?></button>
					<span class="description"><?php esc_html_e( 'Do this after changing commission or currency.', 'gtc' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ suppliers */

	public function page_providers() {
		$settings = gtc()->settings();
		$enabled  = (array) $settings->get( 'enabled_providers', array() );
		?>
		<div class="wrap gtc-admin">
			<h1><?php esc_html_e( 'Suppliers', 'gtc' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Each supplier is an adapter implementing Search, Look and Book. Credentials are issued by the supplier under a partner agreement; the engine will not call an adapter that is missing any required credential.', 'gtc' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gtc_save_providers' ); ?>
				<input type="hidden" name="action" value="gtc_save_providers">

				<?php foreach ( gtc()->providers()->all() as $id => $provider ) : ?>
					<?php
					$creds   = $settings->credentials( $id );
					$missing = method_exists( $provider, 'missing_credentials' ) ? $provider->missing_credentials() : array();
					$fields  = method_exists( $provider, 'required_credentials' ) ? $provider->required_credentials() : array();
					?>
					<div class="gtc-provider-card">
						<div class="gtc-provider-card__head">
							<label class="gtc-provider-card__toggle">
								<input type="checkbox" name="enabled[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $enabled, true ) ); ?>>
								<strong><?php echo esc_html( $provider->get_label() ); ?></strong>
							</label>

							<?php if ( $provider->is_ready() ) : ?>
								<span class="gtc-pill gtc-pill--good"><?php esc_html_e( 'Ready', 'gtc' ); ?></span>
							<?php elseif ( in_array( $id, $enabled, true ) && $missing ) : ?>
								<span class="gtc-pill gtc-pill--warn">
									<?php
									printf(
										/* translators: %s: credential names */
										esc_html__( 'Missing: %s', 'gtc' ),
										esc_html( implode( ', ', $missing ) )
									);
									?>
								</span>
							<?php else : ?>
								<span class="gtc-pill"><?php esc_html_e( 'Disabled', 'gtc' ); ?></span>
							<?php endif; ?>
						</div>

						<p class="gtc-provider-card__meta">
							<code><?php echo esc_html( $id ); ?></code>
							·
							<?php
							$labels = array();
							foreach ( $provider->get_categories() as $category ) {
								$labels[] = GTC_Categories::label( $category );
							}
							echo esc_html( implode( ', ', $labels ) );
							?>
						</p>

						<?php if ( $fields ) : ?>
							<div class="gtc-provider-card__fields">
								<?php foreach ( $fields as $field ) : ?>
									<label>
										<span><?php echo esc_html( str_replace( '_', ' ', $field ) ); ?></span>
										<input
											type="password"
											name="creds[<?php echo esc_attr( $id ); ?>][<?php echo esc_attr( $field ); ?>]"
											value="<?php echo esc_attr( isset( $creds[ $field ] ) ? $creds[ $field ] : '' ); ?>"
											autocomplete="off"
										>
									</label>
								<?php endforeach; ?>
								<label>
									<span><?php esc_html_e( 'base url (optional override)', 'gtc' ); ?></span>
									<input
										type="text"
										name="creds[<?php echo esc_attr( $id ); ?>][base_url]"
										value="<?php echo esc_attr( isset( $creds['base_url'] ) ? $creds['base_url'] : '' ); ?>"
										placeholder="<?php esc_attr_e( 'leave blank for the production endpoint', 'gtc' ); ?>"
									>
								</label>
							</div>
						<?php else : ?>
							<p class="gtc-provider-card__meta"><?php esc_html_e( 'No credentials required.', 'gtc' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save suppliers', 'gtc' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_providers() {
		check_admin_referer( 'gtc_save_providers' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not permitted.', 'gtc' ) );
		}

		$settings = gtc()->settings();

		$enabled = isset( $_POST['enabled'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['enabled'] ) ) : array();
		$settings->set( 'enabled_providers', $enabled );

		if ( isset( $_POST['creds'] ) && is_array( $_POST['creds'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
			foreach ( wp_unslash( $_POST['creds'] ) as $provider_id => $fields ) {
				$clean = array();
				foreach ( (array) $fields as $key => $value ) {
					$value = trim( sanitize_text_field( $value ) );
					if ( '' !== $value ) {
						$clean[ sanitize_key( $key ) ] = $value;
					}
				}
				$settings->set_credentials( sanitize_key( $provider_id ), $clean );
			}
		}

		// Credentials or enablement changing means the cached result sets were
		// built from a different supplier mix.
		gtc()->cache()->flush();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=gtc-providers' ) ) );
		exit;
	}

	/* ------------------------------------------------------------- settings */

	public function page_settings() {
		$settings = gtc()->settings();
		$pages    = get_pages();
		?>
		<div class="wrap gtc-admin">
			<h1><?php esc_html_e( 'Settings', 'gtc' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gtc_save_settings' ); ?>
				<input type="hidden" name="action" value="gtc_save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'What the site does', 'gtc' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px">
								<input type="radio" name="booking_mode" value="referral" <?php checked( $settings->is_referral_mode() ); ?>>
								<strong><?php esc_html_e( 'Compare and refer out', 'gtc' ); ?></strong> —
								<?php esc_html_e( 'show each supplier\'s price and send the customer to that supplier to book. No payments here. Revenue is affiliate commission.', 'gtc' ); ?>
							</label>
							<label style="display:block">
								<input type="radio" name="booking_mode" value="merchant" <?php checked( ! $settings->is_referral_mode() ); ?>>
								<strong><?php esc_html_e( 'Sell directly', 'gtc' ); ?></strong> —
								<?php esc_html_e( 'take the booking and the payment on this site. Requires a reseller agreement with each supplier.', 'gtc' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'These need different supplier agreements. Affiliate programmes give prices plus a link to the supplier\'s own checkout; merchant APIs such as Booking.com Demand and Expedia Rapid give you a net rate to resell and expect you to take the money. Commission, the checkout and the confirmation email only apply to the second option.', 'gtc' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_company_name"><?php esc_html_e( 'Company name', 'gtc' ); ?></label></th>
						<td><input class="regular-text" type="text" id="gtc_company_name" name="company_name" value="<?php echo esc_attr( $settings->get( 'company_name', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_support_email"><?php esc_html_e( 'Operations email', 'gtc' ); ?></label></th>
						<td>
							<input class="regular-text" type="email" id="gtc_support_email" name="support_email" value="<?php echo esc_attr( $settings->get( 'support_email', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Receives a copy of every confirmation and every booking that needs manual intervention.', 'gtc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_currency"><?php esc_html_e( 'Display currency', 'gtc' ); ?></label></th>
						<td><input class="small-text" type="text" id="gtc_currency" name="default_currency" maxlength="3" value="<?php echo esc_attr( $settings->get( 'default_currency', 'USD' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Commission', 'gtc' ); ?></th>
						<td>
							<select name="markup_type">
								<option value="percent" <?php selected( $settings->get( 'markup_type' ), 'percent' ); ?>><?php esc_html_e( 'Percentage', 'gtc' ); ?></option>
								<option value="fixed" <?php selected( $settings->get( 'markup_type' ), 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'gtc' ); ?></option>
							</select>
							<input class="small-text" type="number" step="0.01" min="0" name="markup_value" value="<?php echo esc_attr( $settings->get( 'markup_value', 0 ) ); ?>">
							<input class="regular-text" type="text" name="markup_label" value="<?php echo esc_attr( $settings->get( 'markup_label', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Added as a visible line on the price breakdown, so the itemised total always adds up.', 'gtc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_cache"><?php esc_html_e( 'Search cache (seconds)', 'gtc' ); ?></label></th>
						<td>
							<input class="small-text" type="number" min="0" max="900" id="gtc_cache" name="search_cache_ttl" value="<?php echo esc_attr( $settings->get( 'search_cache_ttl', 300 ) ); ?>">
							<p class="description"><?php esc_html_e( 'Only search results are cached. Look and Book always hit the supplier. Check your distribution contract for the maximum permitted display time.', 'gtc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_timeout"><?php esc_html_e( 'Supplier timeout (seconds)', 'gtc' ); ?></label></th>
						<td><input class="small-text" type="number" min="3" max="60" id="gtc_timeout" name="provider_timeout" value="<?php echo esc_attr( $settings->get( 'provider_timeout', 12 ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Duplicate matching', 'gtc' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="dedupe_enabled" value="1" <?php checked( (int) $settings->get( 'dedupe_enabled', 1 ), 1 ); ?>>
								<?php esc_html_e( 'Merge the same property across suppliers', 'gtc' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Max distance (metres)', 'gtc' ); ?>
									<input class="small-text" type="number" min="10" max="1000" name="dedupe_radius_m" value="<?php echo esc_attr( $settings->get( 'dedupe_radius_m', 150 ) ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Min name similarity (0-1)', 'gtc' ); ?>
									<input class="small-text" type="number" step="0.01" min="0.5" max="1" name="dedupe_name_score" value="<?php echo esc_attr( $settings->get( 'dedupe_name_score', 0.82 ) ); ?>">
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'Lowering either value merges more aggressively. A wrong merge hides a real property, so raise them if you see two different hotels collapsed into one.', 'gtc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_tolerance"><?php esc_html_e( 'Price change tolerance', 'gtc' ); ?></label></th>
						<td>
							<input class="small-text" type="number" min="0" id="gtc_tolerance" name="price_change_tolerance" value="<?php echo esc_attr( $settings->get( 'price_change_tolerance', 0 ) ); ?>">
							<p class="description"><?php esc_html_e( 'In minor units (cents). A revalidated price differing by less than this is treated as unchanged. Leave at 0 to surface every change.', 'gtc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_gateway"><?php esc_html_e( 'Payment gateway', 'gtc' ); ?></label></th>
						<td>
							<select id="gtc_gateway" name="payment_gateway">
								<?php foreach ( gtc()->gateways() as $gid => $gateway ) : ?>
									<option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $settings->get( 'payment_gateway' ), $gid ); ?>>
										<?php echo esc_html( $gateway->get_label() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_checkout_page"><?php esc_html_e( 'Checkout page', 'gtc' ); ?></label></th>
						<td>
							<select id="gtc_checkout_page" name="checkout_page_id">
								<option value="0"><?php esc_html_e( '— none —', 'gtc' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( (int) $settings->get( 'checkout_page_id', 0 ), $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_confirmation_page"><?php esc_html_e( 'Confirmation page', 'gtc' ); ?></label></th>
						<td>
							<select id="gtc_confirmation_page" name="confirmation_page_id">
								<option value="0"><?php esc_html_e( '— none —', 'gtc' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( (int) $settings->get( 'confirmation_page_id', 0 ), $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gtc_terms"><?php esc_html_e( 'Booking terms URL', 'gtc' ); ?></label></th>
						<td><input class="regular-text" type="url" id="gtc_terms" name="terms_url" value="<?php echo esc_attr( $settings->get( 'terms_url', '' ) ); ?>"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		check_admin_referer( 'gtc_save_settings' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not permitted.', 'gtc' ) );
		}

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.

		$values = array(
			'company_name'           => sanitize_text_field( $this->post( $post, 'company_name' ) ),
			'support_email'          => sanitize_email( $this->post( $post, 'support_email' ) ),
			'default_currency'       => strtoupper( substr( sanitize_text_field( $this->post( $post, 'default_currency', 'USD' ) ), 0, 3 ) ),
			'markup_type'            => 'fixed' === $this->post( $post, 'markup_type' ) ? 'fixed' : 'percent',
			'markup_value'           => max( 0, (float) $this->post( $post, 'markup_value', 0 ) ),
			'markup_label'           => sanitize_text_field( $this->post( $post, 'markup_label' ) ),
			'search_cache_ttl'       => max( 0, min( 900, (int) $this->post( $post, 'search_cache_ttl', 300 ) ) ),
			'provider_timeout'       => max( 3, min( 60, (int) $this->post( $post, 'provider_timeout', 12 ) ) ),
			'dedupe_enabled'         => isset( $post['dedupe_enabled'] ) ? 1 : 0,
			'dedupe_radius_m'        => max( 10, min( 1000, (int) $this->post( $post, 'dedupe_radius_m', 150 ) ) ),
			'dedupe_name_score'      => max( 0.5, min( 1.0, (float) $this->post( $post, 'dedupe_name_score', 0.82 ) ) ),
			'price_change_tolerance' => max( 0, (int) $this->post( $post, 'price_change_tolerance', 0 ) ),
			'booking_mode'           => 'merchant' === $this->post( $post, 'booking_mode' ) ? 'merchant' : 'referral',
			'payment_gateway'        => sanitize_key( $this->post( $post, 'payment_gateway', 'sandbox' ) ),
			'checkout_page_id'       => (int) $this->post( $post, 'checkout_page_id', 0 ),
			'confirmation_page_id'   => (int) $this->post( $post, 'confirmation_page_id', 0 ),
			'terms_url'              => esc_url_raw( $this->post( $post, 'terms_url' ) ),
		);

		gtc()->settings()->update( $values );

		// Commission and currency are baked into cached result sets.
		gtc()->cache()->flush();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=gtc-settings' ) ) );
		exit;
	}

	/**
	 * @param array  $post    Unslashed POST.
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private function post( array $post, $key, $default = '' ) {
		return isset( $post[ $key ] ) ? $post[ $key ] : $default;
	}

	public function flush_cache() {
		check_admin_referer( 'gtc_flush_cache' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not permitted.', 'gtc' ) );
		}

		$count = gtc()->cache()->flush();

		wp_safe_redirect( add_query_arg( 'flushed', (int) $count, admin_url( 'admin.php?page=gtc' ) ) );
		exit;
	}

	/* ------------------------------------------------------------- bookings */

	public function page_bookings() {
		$store = new GTC_Booking_Store();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$single = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		// phpcs:enable

		if ( $single ) {
			$this->render_booking( $store->get( $single ) );
			return;
		}

		$bookings = $store->query(
			array(
				'status' => $status,
				'search' => $search,
				'limit'  => 100,
			)
		);
		?>
		<div class="wrap gtc-admin">
			<h1><?php esc_html_e( 'Bookings', 'gtc' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="gtc-bookings">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Reference or property', 'gtc' ); ?>">
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'gtc' ); ?></option>
						<?php
						$statuses = array(
							GTC_Booking::STATUS_CONFIRMED    => __( 'Confirmed', 'gtc' ),
							GTC_Booking::STATUS_QUOTED       => __( 'Quoted', 'gtc' ),
							GTC_Booking::STATUS_REVALIDATED  => __( 'Revalidated', 'gtc' ),
							GTC_Booking::STATUS_AUTHORISED   => __( 'Payment authorised', 'gtc' ),
							GTC_Booking::STATUS_SUPPLIER_ERR => __( 'Needs attention', 'gtc' ),
							GTC_Booking::STATUS_CANCELLED    => __( 'Cancelled', 'gtc' ),
							GTC_Booking::STATUS_ABANDONED    => __( 'Abandoned', 'gtc' ),
						);
						foreach ( $statuses as $key => $label ) :
							?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button"><?php esc_html_e( 'Filter', 'gtc' ); ?></button>
				</p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Status', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Product', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Supplier', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Supplier ref', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Charged', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Created (UTC)', 'gtc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $bookings ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No bookings yet.', 'gtc' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $bookings as $booking ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=gtc-bookings&booking=' . $booking->id ) ); ?>">
									<code><?php echo esc_html( $booking->reference ); ?></code>
								</a>
							</td>
							<td><?php echo wp_kses_post( $this->status_pill( $booking->status ) ); ?></td>
							<td><?php echo esc_html( $booking->product_name ); ?></td>
							<td><?php echo esc_html( $booking->provider_id ); ?></td>
							<td><code><?php echo esc_html( $booking->supplier_reference ); ?></code></td>
							<td><?php echo esc_html( GTC_Currency::format( $booking->amount_charged, $booking->currency ) ); ?></td>
							<td><?php echo esc_html( $booking->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param GTC_Booking|null $booking Booking.
	 */
	private function render_booking( $booking ) {
		if ( ! $booking ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Booking not found.', 'gtc' ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap gtc-admin">
			<h1><?php echo esc_html( $booking->reference ); ?> <?php echo wp_kses_post( $this->status_pill( $booking->status ) ); ?></h1>

			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=gtc-bookings' ) ); ?>">&larr; <?php esc_html_e( 'All bookings', 'gtc' ); ?></a></p>

			<table class="widefat striped">
				<tbody>
					<?php
					$rows = array(
						__( 'Product', 'gtc' )              => $booking->product_name,
						__( 'Supplier', 'gtc' )             => $booking->provider_id,
						__( 'Supplier reference', 'gtc' )   => $booking->supplier_reference,
						__( 'Lead traveller', 'gtc' )       => $booking->get_lead_name(),
						__( 'Email', 'gtc' )                => isset( $booking->contact['email'] ) ? $booking->contact['email'] : '',
						__( 'Charged', 'gtc' )              => GTC_Currency::format( $booking->amount_charged, $booking->currency ),
						__( 'Payable at property', 'gtc' )  => GTC_Currency::format( $booking->amount_at_property, $booking->currency ),
						__( 'Gateway', 'gtc' )              => $booking->payment_gateway,
						__( 'Payment reference', 'gtc' )    => $booking->payment_reference,
						__( 'Idempotency key', 'gtc' )      => $booking->idempotency_key,
						__( 'Created (UTC)', 'gtc' )        => $booking->created_at,
						__( 'Updated (UTC)', 'gtc' )        => $booking->updated_at,
					);
					foreach ( $rows as $label => $value ) :
						?>
						<tr>
							<th style="width:220px"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( (string) $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Audit trail', 'gtc' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( (array) $booking->notes as $note ) : ?>
						<tr>
							<td style="width:200px"><?php echo esc_html( isset( $note['at'] ) ? $note['at'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $note['note'] ) ? $note['note'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	private function status_pill( $status ) {
		$class = 'gtc-pill';

		if ( GTC_Booking::STATUS_CONFIRMED === $status ) {
			$class .= ' gtc-pill--good';
		} elseif ( GTC_Booking::STATUS_SUPPLIER_ERR === $status ) {
			$class .= ' gtc-pill--bad';
		} elseif ( GTC_Booking::STATUS_AUTHORISED === $status ) {
			$class .= ' gtc-pill--warn';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( str_replace( '_', ' ', $status ) ) . '</span>';
	}

	/* --------------------------------------------------------------- clicks */

	public function page_clicks() {
		$log = new GTC_Click_Log();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filter.
		$filter = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';

		$totals = $log->totals_by_provider( 30 );
		$rows   = $log->recent(
			array(
				'provider_id' => $filter,
				'limit'       => 100,
			)
		);
		?>
		<div class="wrap gtc-admin">
			<h1><?php esc_html_e( 'Outbound clicks', 'gtc' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every time a visitor is sent to a supplier, the offer and the price they saw are recorded here. This is what a commission statement gets checked against. No personal data is stored — the visitor column is a one-way hash used only to collapse repeat clicks.', 'gtc' ); ?>
			</p>

			<h2><?php esc_html_e( 'Last 30 days by supplier', 'gtc' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Supplier', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Distinct visitors', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Value sent', 'gtc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $totals ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No outbound clicks yet.', 'gtc' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $totals as $row ) : ?>
						<?php $provider = gtc()->providers()->get( $row['provider_id'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $provider ? $provider->get_label() : $row['provider_id'] ); ?></strong></td>
							<td><?php echo esc_html( $row['clicks'] ); ?></td>
							<td><?php echo esc_html( $row['visitors'] ); ?></td>
							<td><?php echo esc_html( GTC_Currency::format( (int) $row['value_sent'], $row['currency'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent clicks', 'gtc' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When (UTC)', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Supplier', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Property', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Rate', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Price shown', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Visitor', 'gtc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Nothing recorded yet.', 'gtc' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $provider = gtc()->providers()->get( $row['provider_id'] ); ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo esc_html( $provider ? $provider->get_label() : $row['provider_id'] ); ?></td>
							<td><?php echo esc_html( $row['product_name'] ); ?></td>
							<td><?php echo esc_html( $row['rate_name'] ); ?></td>
							<td><?php echo esc_html( GTC_Currency::format( (int) $row['price_total'], $row['currency'] ) ); ?></td>
							<td><code><?php echo esc_html( substr( (string) $row['visitor_hash'], 0, 8 ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ log */

	public function page_log() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filter.
		$filter = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';

		$rows = gtc()->logger()->recent( 100, $filter );
		?>
		<div class="wrap gtc-admin">
			<h1><?php esc_html_e( 'Supplier log', 'gtc' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Credentials and traveller details are redacted before anything is written here.', 'gtc' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When (UTC)', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Supplier', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Operation', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Status', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Booking', 'gtc' ); ?></th>
						<th><?php esc_html_e( 'Error', 'gtc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Nothing logged yet.', 'gtc' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo esc_html( $row['provider_id'] ); ?></td>
							<td><?php echo esc_html( $row['operation'] ); ?></td>
							<td><?php echo esc_html( $row['http_status'] ? $row['http_status'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['duration_ms'] ? $row['duration_ms'] . 'ms' : '—' ); ?></td>
							<td><code><?php echo esc_html( $row['booking_ref'] ); ?></code></td>
							<td><?php echo esc_html( $row['error'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
