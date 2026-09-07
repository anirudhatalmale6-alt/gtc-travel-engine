<?php
/**
 * Payment gateway contract.
 *
 * The engine authorises first and captures only after the supplier confirms,
 * where the gateway supports it. That ordering is what keeps "charged but not
 * booked" out of the system; a gateway that can only charge outright must
 * declare it so the booking manager knows a failure means issuing a refund.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

interface GTC_Gateway {

	/**
	 * @return string
	 */
	public function get_id();

	/**
	 * @return string
	 */
	public function get_label();

	/**
	 * @return bool
	 */
	public function is_ready();

	/**
	 * Whether authorise/capture are separate operations.
	 *
	 * @return bool
	 */
	public function supports_authorisation();

	/**
	 * Hold funds without taking them.
	 *
	 * @param GTC_Booking $booking Booking, priced and revalidated.
	 * @param array       $payment Payment instrument details from checkout.
	 * @return array { success:bool, reference:string, error:string }
	 */
	public function authorise( GTC_Booking $booking, array $payment );

	/**
	 * Take previously held funds.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @return array { success:bool, reference:string, error:string }
	 */
	public function capture( GTC_Booking $booking );

	/**
	 * Release a hold, or refund a capture, after a supplier failure.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @param string      $reason  Reason.
	 * @return array { success:bool, reference:string, error:string }
	 */
	public function void( GTC_Booking $booking, $reason );
}
