<?php
/**
 * Schema and activation.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Install {

	const DB_VERSION = '4';

	public static function activate() {
		self::create_tables();

		if ( false === get_option( GTC_Settings::OPTION, false ) ) {
			add_option( GTC_Settings::OPTION, GTC_Settings::defaults(), '', true );
		}

		self::create_pages();

		if ( ! wp_next_scheduled( 'gtc_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gtc_daily_maintenance' );
		}

		update_option( 'gtc_db_version', self::DB_VERSION, true );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'gtc_daily_maintenance' );
		flush_rewrite_rules();
	}

	/**
	 * Runs dbDelta on every load where the stored version is behind, so a
	 * plugin updated by file copy still gets its schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'gtc_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'gtc_db_version', self::DB_VERSION, true );
	}

	/**
	 * The three customer-facing pages. Checkout and confirmation are addressed
	 * by id in settings, so they are created once and then left alone — an
	 * activation must never overwrite a page the site has since edited.
	 */
	private static function create_pages() {
		$settings = new GTC_Settings();

		$pages = array(
			'search_page_id'       => array(
				'title'   => __( 'Search & compare', 'gtc' ),
				'slug'    => 'travel-search',
				'content' => '[gtc_search]',
			),
			'checkout_page_id'     => array(
				'title'   => __( 'Checkout', 'gtc' ),
				'slug'    => 'travel-checkout',
				'content' => '[gtc_checkout]',
			),
			'confirmation_page_id' => array(
				'title'   => __( 'Booking confirmed', 'gtc' ),
				'slug'    => 'travel-confirmation',
				'content' => '[gtc_confirmation]',
			),
		);

		foreach ( $pages as $setting => $page ) {
			$existing = (int) $settings->get( $setting, 0 );

			if ( $existing && get_post( $existing ) ) {
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( $id && ! is_wp_error( $id ) ) {
				$settings->set( $setting, (int) $id );
			}
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$b       = GTC_Booking_Store::table();
		$l       = GTC_Logger::table();

		$sql = "CREATE TABLE {$b} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reference varchar(32) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'quoted',
			provider_id varchar(64) NOT NULL DEFAULT '',
			category varchar(32) NOT NULL DEFAULT '',
			supplier_reference varchar(128) NOT NULL DEFAULT '',
			product_name varchar(255) NOT NULL DEFAULT '',
			search_hash varchar(32) NOT NULL DEFAULT '',
			search longtext NULL,
			offer_quoted longtext NULL,
			offer_final longtext NULL,
			travellers longtext NULL,
			contact longtext NULL,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			amount_charged bigint(20) NOT NULL DEFAULT 0,
			amount_at_property bigint(20) NOT NULL DEFAULT 0,
			payment_gateway varchar(64) NOT NULL DEFAULT '',
			payment_reference varchar(191) NOT NULL DEFAULT '',
			idempotency_key varchar(64) NOT NULL DEFAULT '',
			notes longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status (status),
			KEY provider_id (provider_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		$sql = "CREATE TABLE {$l} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			provider_id varchar(64) NOT NULL DEFAULT '',
			operation varchar(32) NOT NULL DEFAULT '',
			booking_ref varchar(32) NOT NULL DEFAULT '',
			http_status int(11) NOT NULL DEFAULT 0,
			duration_ms int(11) NOT NULL DEFAULT 0,
			request longtext NULL,
			response longtext NULL,
			error text NULL,
			PRIMARY KEY  (id),
			KEY provider_id (provider_id),
			KEY booking_ref (booking_ref),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
