<?php
/**
 * Transactional email.
 *
 * Sent through wp_mail so the site's existing SMTP plugin handles delivery.
 * Booking confirmations are the legal record of the sale, so the message
 * carries both references and the exact amounts charged and payable later.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Emails {

	public function __construct() {
		add_action( 'gtc_booking_confirmed', array( $this, 'send_confirmation' ) );
		add_action( 'gtc_booking_needs_attention', array( $this, 'alert_operator' ), 10, 2 );
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 */
	public function send_confirmation( GTC_Booking $booking ) {
		$to = isset( $booking->contact['email'] ) ? $booking->contact['email'] : '';

		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$company = gtc()->settings()->get( 'company_name', get_bloginfo( 'name' ) );

		$subject = sprintf(
			/* translators: 1: company 2: reference */
			__( '%1$s — booking confirmed (%2$s)', 'gtc' ),
			$company,
			$booking->reference
		);

		$body = $this->render_confirmation( $booking );

		wp_mail(
			$to,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		$operator = gtc()->settings()->get( 'support_email', '' );

		if ( $operator && is_email( $operator ) ) {
			wp_mail(
				$operator,
				sprintf(
					/* translators: 1: reference 2: product */
					__( 'New booking %1$s — %2$s', 'gtc' ),
					$booking->reference,
					$booking->product_name
				),
				$body,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}
	}

	/**
	 * A booking that took money but could not be resolved automatically. This
	 * needs a human, so it is mailed rather than left in a log.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @param string      $reason  refund_failed|capture_failed.
	 */
	public function alert_operator( GTC_Booking $booking, $reason ) {
		$operator = gtc()->settings()->get( 'support_email', get_option( 'admin_email' ) );

		if ( ! $operator || ! is_email( $operator ) ) {
			return;
		}

		$lines = array(
			sprintf( __( 'Booking %s needs manual intervention.', 'gtc' ), $booking->reference ),
			sprintf( __( 'Reason: %s', 'gtc' ), $reason ),
			sprintf( __( 'Status: %s', 'gtc' ), $booking->status ),
			sprintf( __( 'Supplier: %s', 'gtc' ), $booking->provider_id ),
			sprintf( __( 'Supplier reference: %s', 'gtc' ), $booking->supplier_reference ? $booking->supplier_reference : __( 'none', 'gtc' ) ),
			sprintf( __( 'Payment reference: %s', 'gtc' ), $booking->payment_reference ),
			sprintf( __( 'Amount: %s', 'gtc' ), GTC_Currency::format( $booking->amount_charged, $booking->currency ) ),
			sprintf( __( 'Customer: %s', 'gtc' ), isset( $booking->contact['email'] ) ? $booking->contact['email'] : '' ),
			'',
			admin_url( 'admin.php?page=gtc-bookings&booking=' . $booking->id ),
		);

		wp_mail(
			$operator,
			sprintf(
				/* translators: %s: reference */
				__( '[ACTION REQUIRED] Booking %s', 'gtc' ),
				$booking->reference
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return string
	 */
	private function render_confirmation( GTC_Booking $booking ) {
		$offer  = $booking->get_final_offer();
		$search = $booking->get_search_request();

		$rows = array(
			__( 'Booking reference', 'gtc' )       => $booking->reference,
			__( 'Supplier confirmation', 'gtc' )   => $booking->supplier_reference,
			__( 'Lead traveller', 'gtc' )          => $booking->get_lead_name(),
		);

		if ( $offer ) {
			$rows[ __( 'Property', 'gtc' ) ] = $offer->product( 'name' );
			$rows[ __( 'Address', 'gtc' ) ]  = trim( $offer->product( 'address' ) . ', ' . $offer->product( 'city' ), ', ' );
			$rows[ __( 'Room', 'gtc' ) ]     = $offer->rate( 'name' );
			$rows[ __( 'Board', 'gtc' ) ]    = GTC_Shortcodes::board_label( $offer->rate( 'board' ) );
		}

		if ( $search ) {
			$rows[ __( 'Check in', 'gtc' ) ]  = (string) $search->get( 'check_in' );
			$rows[ __( 'Check out', 'gtc' ) ] = (string) $search->get( 'check_out' );
			$rows[ __( 'Guests', 'gtc' ) ]    = (string) $search->get_guest_count();
		}

		$rows[ __( 'Paid now', 'gtc' ) ] = GTC_Currency::format( $booking->amount_charged, $booking->currency );

		if ( $booking->amount_at_property > 0 ) {
			$rows[ __( 'Payable at the property', 'gtc' ) ] = GTC_Currency::format( $booking->amount_at_property, $booking->currency );
		}

		$html = '<div style="font-family:Helvetica,Arial,sans-serif;color:#10212b;max-width:600px">';
		$html .= '<h2 style="margin:0 0 4px">' . esc_html__( 'Your booking is confirmed', 'gtc' ) . '</h2>';
		$html .= '<p style="margin:0 0 20px;color:#4a5c67">' . esc_html__( 'Please keep this email — the property may ask for the supplier confirmation number at check-in.', 'gtc' ) . '</p>';
		$html .= '<table style="width:100%;border-collapse:collapse;font-size:14px">';

		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$html .= '<tr>';
			$html .= '<th align="left" style="padding:8px 12px 8px 0;border-bottom:1px solid #dde5e9;color:#4a5c67;font-weight:400">' . esc_html( $label ) . '</th>';
			$html .= '<td align="right" style="padding:8px 0;border-bottom:1px solid #dde5e9;font-weight:600">' . esc_html( $value ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';

		if ( $offer && $offer->rate( 'cancellation' ) ) {
			$html .= '<p style="margin:20px 0 0;padding:12px 14px;background:#f4f7f8;border-radius:8px;font-size:13px">';
			$html .= '<strong>' . esc_html__( 'Cancellation policy', 'gtc' ) . '</strong><br>';
			$html .= esc_html( $offer->rate( 'cancellation' ) );
			$html .= '</p>';
		}

		$html .= '</div>';

		return $html;
	}
}
