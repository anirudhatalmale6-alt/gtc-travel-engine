<?php
/**
 * Front-end entry points.
 *
 * Three shortcodes, one per step: search and compare, checkout, confirmation.
 * They render into any theme, which is the point of building this as a plugin
 * rather than a theme — the client's managed WordPress can keep whatever
 * design it already has.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Shortcodes {

	public function __construct() {
		add_shortcode( 'gtc_search', array( $this, 'search' ) );
		add_shortcode( 'gtc_checkout', array( $this, 'checkout' ) );
		add_shortcode( 'gtc_confirmation', array( $this, 'confirmation' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		$this->register();
	}

	/**
	 * Register the handles. Idempotent, and called from the shortcodes as well
	 * as from wp_enqueue_scripts — block themes render the post content while
	 * building the head, which means a shortcode can run BEFORE
	 * wp_enqueue_scripts has fired at all. Enqueuing an unregistered handle
	 * still puts the file on the page, but wp_localize_script silently refuses
	 * to attach data to it, so the script would load with nothing to configure
	 * it and the whole front end would sit there inert.
	 */
	private function register() {
		if ( wp_script_is( 'gtc', 'registered' ) ) {
			return;
		}

		wp_register_style( 'gtc', GTC_URL . 'assets/css/gtc.css', array(), GTC_VERSION );
		wp_register_script( 'gtc', GTC_URL . 'assets/js/gtc.js', array(), GTC_VERSION, true );
	}

	/**
	 * Enqueue and configure the front-end assets. Safe to call twice.
	 */
	private function enqueue() {
		$this->register();

		if ( wp_script_is( 'gtc', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( 'gtc' );
		wp_enqueue_script( 'gtc' );
		$this->localise( 'gtc' );
	}

	/**
	 * @param string $handle Script handle to localise.
	 */
	private function localise( $handle ) {
		return wp_localize_script(
			$handle,
			'GTC',
			array(
				'root'       => esc_url_raw( rest_url( GTC_Rest::NS ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'checkout'   => esc_url_raw( self::page_url( 'checkout_page_id' ) ),
				'currency'   => gtc()->settings()->get( 'default_currency', 'USD' ),
				'categories' => GTC_Categories::available(),
				// Display names, so the comparison names the supplier the
				// customer would recognise rather than an internal adapter id.
				'providers'  => $this->provider_labels(),
				'i18n'       => array(
					'searching'   => __( 'Searching suppliers…', 'gtc' ),
					'noResults'   => __( 'No availability found for these dates.', 'gtc' ),
					'error'       => __( 'Something went wrong. Please try again.', 'gtc' ),
					'from'        => __( 'from', 'gtc' ),
					'perNight'    => __( 'per night', 'gtc' ),
					'total'       => __( 'total', 'gtc' ),
					'select'      => __( 'Select', 'gtc' ),
					'suppliers'   => __( 'suppliers', 'gtc' ),
					'youSave'     => __( 'Save', 'gtc' ),
					'atProperty'  => __( 'payable at the property', 'gtc' ),
				),
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function provider_labels() {
		$labels = array();

		foreach ( gtc()->providers()->all() as $id => $provider ) {
			$labels[ $id ] = $provider->get_label();
		}

		return $labels;
	}

	/* ------------------------------------------------------------- search */

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function search( $atts ) {
		$atts = shortcode_atts(
			array(
				'category'    => GTC_Categories::HOTELS,
				'destination' => '',
			),
			$atts,
			'gtc_search'
		);

		$this->enqueue();

		ob_start();
		include GTC_PATH . 'templates/search.php';
		return ob_get_clean();
	}

	/* ----------------------------------------------------------- checkout */

	/**
	 * @return string
	 */
	public function checkout() {
		$this->enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page load, authorised by the HMAC token below.
		$reference = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable

		$booking = $reference ? ( new GTC_Booking_Store() )->get_by_reference( $reference ) : null;

		if ( ! $booking || ! hash_equals( ( new GTC_Rest() )->access_token( $booking ), $token ) ) {
			return $this->notice( __( 'We could not find that booking. Please start a new search.', 'gtc' ) );
		}

		if ( GTC_Booking::STATUS_CONFIRMED === $booking->status ) {
			wp_safe_redirect( self::confirmation_url( $booking ) );
			exit;
		}

		$offer = $booking->get_final_offer();

		if ( ! $offer ) {
			return $this->notice( __( 'This offer is no longer available. Please start a new search.', 'gtc' ) );
		}

		$search = $booking->get_search_request();

		ob_start();
		include GTC_PATH . 'templates/checkout.php';
		return ob_get_clean();
	}

	/* ------------------------------------------------------- confirmation */

	/**
	 * @return string
	 */
	public function confirmation() {
		$this->enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page load, authorised by the HMAC token below.
		$reference = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable

		$booking = $reference ? ( new GTC_Booking_Store() )->get_by_reference( $reference ) : null;

		if ( ! $booking || ! hash_equals( ( new GTC_Rest() )->access_token( $booking ), $token ) ) {
			return $this->notice( __( 'We could not find that booking.', 'gtc' ) );
		}

		$offer  = $booking->get_final_offer();
		$search = $booking->get_search_request();

		ob_start();
		include GTC_PATH . 'templates/confirmation.php';
		return ob_get_clean();
	}

	/* -------------------------------------------------------------- utils */

	/**
	 * @param string $message Message.
	 * @return string
	 */
	private function notice( $message ) {
		return '<div class="gtc gtc-notice gtc-notice--empty">' . esc_html( $message ) . '</div>';
	}

	/**
	 * @param string $setting Settings key holding a page id.
	 * @return string
	 */
	public static function page_url( $setting ) {
		$id = (int) gtc()->settings()->get( $setting, 0 );
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return string
	 */
	public static function checkout_url( GTC_Booking $booking ) {
		return add_query_arg(
			array(
				'ref'   => rawurlencode( $booking->reference ),
				'token' => ( new GTC_Rest() )->access_token( $booking ),
			),
			self::page_url( 'checkout_page_id' )
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return string
	 */
	public static function confirmation_url( GTC_Booking $booking ) {
		return add_query_arg(
			array(
				'ref'   => rawurlencode( $booking->reference ),
				'token' => ( new GTC_Rest() )->access_token( $booking ),
			),
			self::page_url( 'confirmation_page_id' )
		);
	}

	/**
	 * @param string $board Board code.
	 * @return string
	 */
	public static function board_label( $board ) {
		$map = array(
			'room_only'     => __( 'Room only', 'gtc' ),
			'breakfast'     => __( 'Breakfast included', 'gtc' ),
			'half_board'    => __( 'Half board', 'gtc' ),
			'full_board'    => __( 'Full board', 'gtc' ),
			'all_inclusive' => __( 'All inclusive', 'gtc' ),
		);

		return isset( $map[ $board ] ) ? $map[ $board ] : $board;
	}
}
