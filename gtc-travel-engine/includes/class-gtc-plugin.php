<?php
/**
 * Wiring.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Plugin {

	/** @var GTC_Plugin|null */
	private static $instance = null;

	/** @var GTC_Settings */
	private $settings;

	/** @var GTC_Provider_Registry */
	private $providers;

	/** @var GTC_Cache */
	private $cache;

	/** @var GTC_Logger */
	private $logger;

	/** @var GTC_Gateway[] */
	private $gateways = array();

	/** @var GTC_Booking_Manager */
	private $bookings;

	/**
	 * @return GTC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new GTC_Settings();
		$this->cache    = new GTC_Cache();
		$this->logger   = new GTC_Logger();

		add_action( 'plugins_loaded', array( $this, 'boot' ), 5 );
	}

	public function boot() {
		GTC_Install::maybe_upgrade();

		$this->providers = new GTC_Provider_Registry();
		$this->bookings  = new GTC_Booking_Manager();

		$this->register_providers();
		$this->register_gateways();

		new GTC_Rest();
		new GTC_Shortcodes();
		new GTC_Emails();

		if ( is_admin() ) {
			new GTC_Admin();
		}

		add_action( 'gtc_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'gtc', false, dirname( plugin_basename( GTC_FILE ) ) . '/languages' );
	}

	private function register_providers() {
		$this->providers->add( new GTC_Provider_Sandbox_Alpha() );
		$this->providers->add( new GTC_Provider_Sandbox_Beta() );
		$this->providers->add( new GTC_Provider_Booking_Demand() );
		$this->providers->add( new GTC_Provider_Expedia_Rapid() );

		/**
		 * Register additional supplier adapters.
		 *
		 * @param GTC_Provider_Registry $registry Registry.
		 */
		do_action( 'gtc_register_providers', $this->providers );
	}

	private function register_gateways() {
		$sandbox = new GTC_Gateway_Sandbox();

		$this->gateways[ $sandbox->get_id() ] = $sandbox;

		/**
		 * Register payment gateways.
		 *
		 * @param GTC_Gateway[] $gateways Gateways keyed by id.
		 */
		$this->gateways = apply_filters( 'gtc_gateways', $this->gateways );
	}

	public function maintenance() {
		$this->logger->prune( 90 );
		( new GTC_Booking_Store() )->abandon_stale_quotes( 24 );
	}

	/** @return GTC_Settings */
	public function settings() {
		return $this->settings;
	}

	/** @return GTC_Provider_Registry */
	public function providers() {
		return $this->providers;
	}

	/** @return GTC_Cache */
	public function cache() {
		return $this->cache;
	}

	/** @return GTC_Logger */
	public function logger() {
		return $this->logger;
	}

	/** @return GTC_Booking_Manager */
	public function bookings() {
		return $this->bookings;
	}

	/** @return GTC_Gateway[] */
	public function gateways() {
		return $this->gateways;
	}

	/**
	 * The gateway the site is configured to charge with.
	 *
	 * @return GTC_Gateway|null
	 */
	public function gateway() {
		$id = $this->settings->get( 'payment_gateway', 'sandbox' );
		return isset( $this->gateways[ $id ] ) ? $this->gateways[ $id ] : null;
	}
}
