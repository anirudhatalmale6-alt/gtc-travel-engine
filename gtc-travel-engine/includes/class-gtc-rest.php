<?php
/**
 * REST endpoints backing the front end.
 *
 * The browser never carries supplier state. It gets a search hash and an offer
 * key; everything the supplier needs to re-price and book is recovered
 * server-side from the stored result set. That means a tampered request can
 * change which offer is selected, but cannot change its price, its rate token
 * or which supplier is called.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Rest {

	const NS = 'gtc/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'public_access' ),
			)
		);

		register_rest_route(
			self::NS,
			'/select',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'select' ),
				'permission_callback' => array( $this, 'public_access' ),
			)
		);

		register_rest_route(
			self::NS,
			'/revalidate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revalidate' ),
				'permission_callback' => array( $this, 'public_access' ),
			)
		);

		register_rest_route(
			self::NS,
			'/book',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'book' ),
				'permission_callback' => array( $this, 'public_access' ),
			)
		);
	}

	/**
	 * Anonymous shoppers are the point of the site, so these are public — but
	 * rate limited, because each search costs supplier quota.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function public_access( WP_REST_Request $request ) {
		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'gtc_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'gtc' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * @return bool
	 */
	private function is_rate_limited() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! $ip ) {
			return false;
		}

		$key   = 'gtc_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		$limit = (int) apply_filters( 'gtc_rate_limit_per_minute', 60 );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		try {
			$search = GTC_Search_Request::from_array( (array) $request->get_json_params() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'gtc_bad_request', $e->getMessage(), array( 'status' => 400 ) );
		}

		$error = $this->validate( $search );

		if ( $error ) {
			return new WP_Error( 'gtc_bad_request', $error, array( 'status' => 400 ) );
		}

		$params = (array) $request->get_json_params();

		$result = ( new GTC_Aggregator() )->search(
			$search,
			array(
				'sort'    => isset( $params['sort'] ) ? sanitize_key( $params['sort'] ) : 'price_asc',
				'filters' => isset( $params['filters'] ) ? (array) $params['filters'] : array(),
			)
		);

		$per_page = (int) gtc()->settings()->get( 'results_per_page', 20 );
		$page     = isset( $params['page'] ) ? max( 1, absint( $params['page'] ) ) : 1;

		return rest_ensure_response(
			array(
				'search_hash'  => $search->get_hash(),
				'summary'      => $search->describe(),
				'groups'       => $result->page( $page, $per_page ),
				'group_count'  => $result->get_group_count(),
				'offer_count'  => $result->get_offer_count(),
				'merged_count' => $result->get_merged_count(),
				'page'         => $page,
				'pages'        => (int) ceil( $result->get_group_count() / max( 1, $per_page ) ),
				'providers'    => $result->get_providers(),
				'failures'     => $result->get_failures(),
				'notices'      => $result->get_notices(),
				'currency'     => $search->get_currency(),
			)
		);
	}

	/**
	 * Validate the search before spending supplier quota on it.
	 *
	 * @param GTC_Search_Request $search Search.
	 * @return string Empty when valid.
	 */
	private function validate( GTC_Search_Request $search ) {
		if ( in_array( $search->get_category(), array( GTC_Categories::HOTELS, GTC_Categories::RENTALS ), true ) ) {
			$in  = $search->get( 'check_in' );
			$out = $search->get( 'check_out' );

			if ( ! $in || ! $out ) {
				return __( 'Please choose your check-in and check-out dates.', 'gtc' );
			}

			if ( strtotime( $out ) <= strtotime( $in ) ) {
				return __( 'Check-out must be after check-in.', 'gtc' );
			}

			if ( strtotime( $in ) < strtotime( gmdate( 'Y-m-d' ) ) ) {
				return __( 'Check-in cannot be in the past.', 'gtc' );
			}

			if ( $search->get_nights() > 30 ) {
				return __( 'Stays longer than 30 nights cannot be booked online.', 'gtc' );
			}
		}

		return '';
	}

	/**
	 * Select an offer: creates the quote and immediately revalidates it, so the
	 * checkout page is rendered from a live supplier price rather than a
	 * cached one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function select( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();

		$hash = isset( $params['search_hash'] ) ? sanitize_text_field( $params['search_hash'] ) : '';
		$key  = isset( $params['offer_key'] ) ? sanitize_text_field( $params['offer_key'] ) : '';

		if ( ! $hash || ! $key ) {
			return new WP_Error( 'gtc_bad_request', __( 'Missing offer reference.', 'gtc' ), array( 'status' => 400 ) );
		}

		$found = gtc()->cache()->get_from_result_set( $hash, $key );

		if ( ! $found ) {
			return new WP_Error(
				'gtc_offer_expired',
				__( 'Your search has expired. Please search again to see current prices.', 'gtc' ),
				array( 'status' => 410 )
			);
		}

		$manager = gtc()->bookings();
		$booking = $manager->create_quote( $found['offer'], $found['request'] );
		$result  = $manager->revalidate( $booking );

		return rest_ensure_response(
			array(
				'reference'    => $booking->reference,
				'token'        => $this->access_token( $booking ),
				'revalidation' => $result,
				'summary'      => $found['request']->describe(),
			)
		);
	}

	/**
	 * Re-run Look on an existing quote — used when the customer sits on the
	 * checkout page long enough for the price to go stale.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revalidate( WP_REST_Request $request ) {
		$booking = $this->authorise_booking( $request );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		return rest_ensure_response(
			array(
				'reference'    => $booking->reference,
				'revalidation' => gtc()->bookings()->revalidate( $booking ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function book( WP_REST_Request $request ) {
		$booking = $this->authorise_booking( $request );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$params = (array) $request->get_json_params();

		$travellers = $this->sanitize_travellers( isset( $params['travellers'] ) ? (array) $params['travellers'] : array() );
		$contact    = $this->sanitize_contact( isset( $params['contact'] ) ? (array) $params['contact'] : array() );

		$error = $this->validate_traveller_details( $travellers, $contact );

		if ( $error ) {
			return new WP_Error( 'gtc_bad_request', $error, array( 'status' => 400 ) );
		}

		$payment = isset( $params['payment'] ) ? (array) $params['payment'] : array();

		$accepted = isset( $params['accepted_total'] ) ? (int) $params['accepted_total'] : null;

		$result = gtc()->bookings()->complete( $booking, $travellers, $contact, $payment, $accepted );

		if ( ! $result['success'] ) {
			return rest_ensure_response(
				array(
					'success'      => false,
					'error'        => $result['error'],
					'reference'    => $result['booking']->reference,
					'status'       => $result['booking']->status,
					'revalidation' => $result['revalidation'],
				)
			);
		}

		$confirmed = $result['booking'];

		return rest_ensure_response(
			array(
				'success'            => true,
				'reference'          => $confirmed->reference,
				'supplier_reference' => $confirmed->supplier_reference,
				'status'             => $confirmed->status,
				'amount_charged'     => $confirmed->amount_charged,
				'currency'           => $confirmed->currency,
				'confirmation_url'   => GTC_Shortcodes::confirmation_url( $confirmed ),
			)
		);
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * A quote is identified by its reference plus a token derived from its
	 * idempotency key. Without the token, knowing a reference is not enough to
	 * read or pay for someone else's booking.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @return string
	 */
	public function access_token( GTC_Booking $booking ) {
		return hash_hmac( 'sha256', $booking->reference . '|' . $booking->idempotency_key, wp_salt( 'auth' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return GTC_Booking|WP_Error
	 */
	private function authorise_booking( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();

		$reference = isset( $params['reference'] ) ? sanitize_text_field( $params['reference'] ) : '';
		$token     = isset( $params['token'] ) ? sanitize_text_field( $params['token'] ) : '';

		if ( ! $reference || ! $token ) {
			return new WP_Error( 'gtc_bad_request', __( 'Missing booking reference.', 'gtc' ), array( 'status' => 400 ) );
		}

		$booking = ( new GTC_Booking_Store() )->get_by_reference( $reference );

		if ( ! $booking ) {
			return new WP_Error( 'gtc_not_found', __( 'Booking not found.', 'gtc' ), array( 'status' => 404 ) );
		}

		if ( ! hash_equals( $this->access_token( $booking ), $token ) ) {
			return new WP_Error( 'gtc_forbidden', __( 'Booking not found.', 'gtc' ), array( 'status' => 403 ) );
		}

		return $booking;
	}

	/**
	 * @param array $travellers Raw travellers.
	 * @return array
	 */
	private function sanitize_travellers( array $travellers ) {
		$out = array();

		foreach ( array_slice( $travellers, 0, 12 ) as $traveller ) {
			if ( ! is_array( $traveller ) ) {
				continue;
			}
			$out[] = array(
				'title'      => isset( $traveller['title'] ) ? sanitize_text_field( $traveller['title'] ) : '',
				'first_name' => isset( $traveller['first_name'] ) ? sanitize_text_field( $traveller['first_name'] ) : '',
				'last_name'  => isset( $traveller['last_name'] ) ? sanitize_text_field( $traveller['last_name'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * @param array $contact Raw contact.
	 * @return array
	 */
	private function sanitize_contact( array $contact ) {
		return array(
			'email'         => isset( $contact['email'] ) ? sanitize_email( $contact['email'] ) : '',
			'phone'         => isset( $contact['phone'] ) ? sanitize_text_field( $contact['phone'] ) : '',
			'phone_country' => isset( $contact['phone_country'] ) ? sanitize_text_field( $contact['phone_country'] ) : '',
			'country'       => isset( $contact['country'] ) ? sanitize_text_field( $contact['country'] ) : '',
			'special_requests' => isset( $contact['special_requests'] ) ? sanitize_textarea_field( $contact['special_requests'] ) : '',
		);
	}

	/**
	 * @param array $travellers Travellers.
	 * @param array $contact    Contact.
	 * @return string Empty when valid.
	 */
	private function validate_traveller_details( array $travellers, array $contact ) {
		if ( empty( $travellers[0]['first_name'] ) || empty( $travellers[0]['last_name'] ) ) {
			return __( 'Please enter the lead traveller\'s first and last name.', 'gtc' );
		}

		if ( empty( $contact['email'] ) || ! is_email( $contact['email'] ) ) {
			return __( 'Please enter a valid email address for your confirmation.', 'gtc' );
		}

		return '';
	}
}
