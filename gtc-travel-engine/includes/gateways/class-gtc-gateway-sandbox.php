<?php
/**
 * Sandbox gateway.
 *
 * Moves no money. It exists so the whole booking path — authorise, supplier
 * book, capture, confirm, and the failure branches — can be exercised end to
 * end before a real processor is connected, and so support can reproduce a
 * customer's journey without touching live payments.
 *
 * Test instruments:
 *   4111 1111 1111 1111  authorises and captures
 *   4000 0000 0000 0002  declines at authorisation
 *   4000 0000 0000 0069  authorises, then fails at capture
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Gateway_Sandbox implements GTC_Gateway {

	const DECLINE_CARD      = '4000000000000002';
	const CAPTURE_FAIL_CARD = '4000000000000069';

	/** @return string */
	public function get_id() {
		return 'sandbox';
	}

	/** @return string */
	public function get_label() {
		return __( 'Sandbox (no money moves)', 'gtc' );
	}

	/** @return bool */
	public function is_ready() {
		return true;
	}

	/** @return bool */
	public function supports_authorisation() {
		return true;
	}

	/**
	 * @param array $payment Payment details.
	 * @return string
	 */
	private function card_number( array $payment ) {
		$number = isset( $payment['card_number'] ) ? (string) $payment['card_number'] : '';
		return preg_replace( '/\D+/', '', $number );
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @param array       $payment Payment details.
	 * @return array
	 */
	public function authorise( GTC_Booking $booking, array $payment ) {
		$number = $this->card_number( $payment );

		if ( self::DECLINE_CARD === $number ) {
			return array(
				'success'   => false,
				'reference' => '',
				'error'     => __( 'Your card was declined. Please try a different card.', 'gtc' ),
			);
		}

		if ( strlen( $number ) < 12 ) {
			return array(
				'success'   => false,
				'reference' => '',
				'error'     => __( 'Please enter a valid card number.', 'gtc' ),
			);
		}

		// The card number is not stored, so the "fails at capture" behaviour has
		// to be carried on the authorisation reference — which is the only
		// thing capture() will still have to look at.
		$marker = self::CAPTURE_FAIL_CARD === $number ? 'capfail_' : '';

		return array(
			'success'   => true,
			'reference' => 'sbx_auth_' . $marker . strtolower( str_replace( '-', '', $booking->reference ) ),
			'error'     => '',
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 */
	public function capture( GTC_Booking $booking ) {
		// The capture-failure card is recognised from the authorisation
		// reference, because the card number itself is never stored.
		if ( false !== strpos( (string) $booking->payment_reference, 'capfail' ) ) {
			return array(
				'success'   => false,
				'reference' => '',
				'error'     => __( 'Capture failed at the processor.', 'gtc' ),
			);
		}

		return array(
			'success'   => true,
			'reference' => str_replace( 'auth', 'cap', (string) $booking->payment_reference ),
			'error'     => '',
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @param string      $reason  Reason.
	 * @return array
	 */
	public function void( GTC_Booking $booking, $reason ) {
		return array(
			'success'   => true,
			'reference' => str_replace( array( 'auth', 'cap' ), 'void', (string) $booking->payment_reference ),
			'error'     => '',
		);
	}
}
