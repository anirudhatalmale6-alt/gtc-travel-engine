<?php
/**
 * Orchestrates quote -> revalidate -> authorise -> book -> capture -> confirm.
 *
 * The ordering here is the whole point of the class. Money is held before the
 * supplier is called and only taken once the supplier has confirmed, so the
 * two outcomes that hurt — charged without a reservation, and a reservation
 * nobody paid for — both have a defined, recorded resolution.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Booking_Manager {

	/**
	 * How stale a revalidation may be when the customer presses pay. Past this,
	 * the price is re-confirmed with the supplier again before charging.
	 */
	const REVALIDATION_MAX_AGE = 600;

	/** @var GTC_Booking_Store */
	private $store;

	public function __construct() {
		$this->store = new GTC_Booking_Store();
	}

	/**
	 * Step 1 — turn a selected offer into a quote row.
	 *
	 * @param GTC_Offer          $offer   Selected offer.
	 * @param GTC_Search_Request $request Search that produced it.
	 * @return GTC_Booking
	 */
	public function create_quote( GTC_Offer $offer, GTC_Search_Request $request ) {
		$booking = new GTC_Booking(
			array(
				'status'       => GTC_Booking::STATUS_QUOTED,
				'provider_id'  => $offer->get_provider_id(),
				'category'     => $offer->get_category(),
				'product_name' => (string) $offer->product( 'name' ),
				'search_hash'  => $request->get_hash(),
				'search'       => $request->to_array(),
				'offer_quoted' => $offer->to_array(),
				'currency'     => $offer->get_price()->get_currency(),
			)
		);

		$booking->add_note( 'Quote created from search ' . $request->get_hash() );

		return $this->store->save( $booking );
	}

	/**
	 * Step 2 — Look. Always hits the supplier; never reads the search cache.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @return GTC_Revalidation
	 */
	public function revalidate( GTC_Booking $booking ) {
		$request = $booking->get_search_request();
		$offer   = GTC_Offer::from_array( $booking->offer_quoted );

		$result = ( new GTC_Revalidator() )->revalidate( $offer, $request );

		if ( $result->is_bookable() ) {
			$final = $result->get_offer();

			$booking->offer_final        = $final->to_array();
			$booking->currency           = $final->get_price()->get_currency();
			$booking->amount_charged     = $final->get_price()->get_total();
			$booking->amount_at_property = $final->get_price()->get_payable_at_property();
			$booking->status             = GTC_Booking::STATUS_REVALIDATED;

			$booking->add_note(
				sprintf(
					'Revalidated: %s (%s)',
					$result->get_outcome(),
					GTC_Currency::format( $final->get_price()->get_total(), $final->get_price()->get_currency() )
				)
			);
		} else {
			$booking->add_note( 'Revalidation ' . $result->get_outcome() . ': ' . $result->get_message() );
		}

		$this->store->save( $booking );

		return $result;
	}

	/**
	 * Step 3 — take payment and place the reservation.
	 *
	 * @param GTC_Booking $booking          Booking, already revalidated.
	 * @param array       $travellers       Traveller details.
	 * @param array       $contact          Contact details.
	 * @param array       $payment          Payment instrument.
	 * @param int|null    $accepted_total   Total the customer saw and agreed to,
	 *                                      in minor units. Required: it is what
	 *                                      stops a price that moved between the
	 *                                      checkout render and the pay click
	 *                                      being charged silently.
	 * @return array { success:bool, booking:GTC_Booking, error:string, revalidation:GTC_Revalidation|null }
	 */
	public function complete( GTC_Booking $booking, array $travellers, array $contact, array $payment, $accepted_total = null ) {
		if ( GTC_Booking::STATUS_CONFIRMED === $booking->status ) {
			// A double-submitted form must return the existing reservation,
			// never make a second one.
			return array(
				'success'      => true,
				'booking'      => $booking,
				'error'        => '',
				'revalidation' => null,
			);
		}

		if ( $booking->is_paid() ) {
			return array(
				'success'      => false,
				'booking'      => $booking,
				'error'        => __( 'This booking has already been paid for and is being processed.', 'gtc' ),
				'revalidation' => null,
			);
		}

		$booking->travellers = $travellers;
		$booking->contact    = $contact;

		// Re-confirm with the supplier if the last Look has gone stale, so the
		// price being charged is one the supplier stood behind moments ago.
		$revalidation = null;

		if ( $this->needs_fresh_look( $booking ) ) {
			$revalidation = $this->revalidate( $booking );

			if ( ! $revalidation->is_bookable() ) {
				return array(
					'success'      => false,
					'booking'      => $booking,
					'error'        => $revalidation->get_message(),
					'revalidation' => $revalidation,
				);
			}
		}

		$offer = $booking->get_final_offer();

		if ( ! $offer ) {
			return array(
				'success'      => false,
				'booking'      => $booking,
				'error'        => __( 'This booking has not been revalidated. Please start again.', 'gtc' ),
				'revalidation' => null,
			);
		}

		$total = $offer->get_price()->get_total();

		if ( null !== $accepted_total && (int) $accepted_total !== $total ) {
			$booking->add_note(
				sprintf( 'Charge blocked: customer accepted %d, live total %d', (int) $accepted_total, $total )
			);
			$this->store->save( $booking );

			return array(
				'success'      => false,
				'booking'      => $booking,
				'error'        => __( 'The price changed while you were checking out. Please review the new total before paying.', 'gtc' ),
				'revalidation' => $revalidation,
			);
		}

		$gateway = gtc()->gateway();

		if ( ! $gateway || ! $gateway->is_ready() ) {
			return array(
				'success'      => false,
				'booking'      => $booking,
				'error'        => __( 'No payment method is available. Please contact us.', 'gtc' ),
				'revalidation' => $revalidation,
			);
		}

		$booking->payment_gateway = $gateway->get_id();

		/* ------------------------------------------------------ authorise */

		$auth = $gateway->authorise( $booking, $payment );

		if ( empty( $auth['success'] ) ) {
			$booking->add_note( 'Authorisation failed: ' . $auth['error'] );
			$this->store->save( $booking );

			return array(
				'success'      => false,
				'booking'      => $booking,
				'error'        => $auth['error'],
				'revalidation' => $revalidation,
			);
		}

		$booking->payment_reference = $auth['reference'];
		$booking->status            = GTC_Booking::STATUS_AUTHORISED;
		$booking->add_note( 'Payment authorised (' . $gateway->get_id() . ')' );

		// Persisted before the supplier call: if the request dies mid-flight,
		// the row still says money is held and no reservation exists yet.
		$this->store->save( $booking );

		/* ----------------------------------------------------------- book */

		$provider = gtc()->providers()->get( $booking->provider_id );

		if ( ! $provider ) {
			return $this->unwind( $booking, $gateway, __( 'This supplier is no longer connected.', 'gtc' ), $revalidation );
		}

		$started = microtime( true );

		try {
			$result = $provider->book( $offer, $booking );
		} catch ( GTC_Provider_Exception $e ) {
			gtc()->logger()->log_error( $booking->provider_id, 'book', $e->getMessage(), $e->get_supplier_code() );
			return $this->unwind( $booking, $gateway, $e->getMessage(), $revalidation );
		} catch ( Exception $e ) {
			gtc()->logger()->log_error( $booking->provider_id, 'book', $e->getMessage() );
			return $this->unwind( $booking, $gateway, __( 'The supplier could not complete this reservation.', 'gtc' ), $revalidation );
		}

		gtc()->logger()->log_call(
			$booking->provider_id,
			'book',
			array( 'offer_id' => $offer->get_offer_id() ),
			isset( $result['raw'] ) ? (array) $result['raw'] : array(),
			200,
			(int) round( ( microtime( true ) - $started ) * 1000 ),
			$booking->reference
		);

		if ( empty( $result['supplier_reference'] ) ) {
			return $this->unwind( $booking, $gateway, __( 'The supplier did not return a confirmation number.', 'gtc' ), $revalidation );
		}

		$booking->supplier_reference = (string) $result['supplier_reference'];
		$booking->add_note( 'Supplier confirmed: ' . $booking->supplier_reference );

		/* -------------------------------------------------------- capture */

		if ( $gateway->supports_authorisation() ) {
			$capture = $gateway->capture( $booking );

			if ( empty( $capture['success'] ) ) {
				// The reservation exists and is ours; do not cancel it out from
				// under a customer over a capture problem. Flag it for a human.
				$booking->status = GTC_Booking::STATUS_SUPPLIER_ERR;
				$booking->add_note( 'CAPTURE FAILED after supplier confirmation: ' . $capture['error'] );
				$this->store->save( $booking );

				do_action( 'gtc_booking_needs_attention', $booking, 'capture_failed' );

				return array(
					'success'      => false,
					'booking'      => $booking,
					'error'        => __( 'Your reservation is held but we could not take payment. Our team will contact you shortly.', 'gtc' ),
					'revalidation' => $revalidation,
				);
			}

			$booking->payment_reference = $capture['reference'];
		}

		$booking->status = GTC_Booking::STATUS_CONFIRMED;
		$booking->add_note( 'Payment captured and booking confirmed' );
		$this->store->save( $booking );

		do_action( 'gtc_booking_confirmed', $booking );

		return array(
			'success'      => true,
			'booking'      => $booking,
			'error'        => '',
			'revalidation' => $revalidation,
		);
	}

	/**
	 * Supplier failed after funds were held: release the hold, record why, and
	 * escalate if the release itself fails.
	 *
	 * @param GTC_Booking          $booking      Booking.
	 * @param GTC_Gateway          $gateway      Gateway.
	 * @param string               $error        Customer-facing error.
	 * @param GTC_Revalidation|null $revalidation Revalidation, if one ran.
	 * @return array
	 */
	private function unwind( GTC_Booking $booking, GTC_Gateway $gateway, $error, $revalidation ) {
		$void = $gateway->void( $booking, $error );

		$booking->status = GTC_Booking::STATUS_SUPPLIER_ERR;
		$booking->add_note( 'Supplier booking failed: ' . $error );

		if ( empty( $void['success'] ) ) {
			$booking->add_note( 'REFUND FAILED — manual refund required: ' . $void['error'] );
			do_action( 'gtc_booking_needs_attention', $booking, 'refund_failed' );
		} else {
			$booking->add_note( 'Authorisation released: ' . $void['reference'] );
		}

		$this->store->save( $booking );

		return array(
			'success'      => false,
			'booking'      => $booking,
			'error'        => $error . ' ' . __( 'You have not been charged.', 'gtc' ),
			'revalidation' => $revalidation,
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return bool
	 */
	private function needs_fresh_look( GTC_Booking $booking ) {
		if ( GTC_Booking::STATUS_REVALIDATED !== $booking->status || ! $booking->offer_final ) {
			return true;
		}

		$updated = strtotime( (string) $booking->updated_at . ' UTC' );

		if ( ! $updated ) {
			return true;
		}

		return ( time() - $updated ) > self::REVALIDATION_MAX_AGE;
	}

	/** @return GTC_Booking_Store */
	public function store() {
		return $this->store;
	}
}
