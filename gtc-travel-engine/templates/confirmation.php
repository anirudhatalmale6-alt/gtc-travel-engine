<?php
/**
 * Confirmation.
 *
 * @package GTC
 * @var GTC_Booking             $booking Booking.
 * @var GTC_Offer|null          $offer   Offer as booked.
 * @var GTC_Search_Request|null $search  Search.
 */

defined( 'ABSPATH' ) || exit;

$gtc_confirmed = GTC_Booking::STATUS_CONFIRMED === $booking->status;
$gtc_provider  = gtc()->providers()->get( $booking->provider_id );
?>
<div class="gtc gtc-confirmation">

	<?php if ( $gtc_confirmed ) : ?>

		<div class="gtc-confirmation__hero">
			<span class="gtc-confirmation__tick" aria-hidden="true">✓</span>
			<h1><?php esc_html_e( 'Your booking is confirmed', 'gtc' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: email address */
					esc_html__( 'We have sent the details to %s.', 'gtc' ),
					'<strong>' . esc_html( isset( $booking->contact['email'] ) ? $booking->contact['email'] : '' ) . '</strong>'
				);
				?>
			</p>
		</div>

		<div class="gtc-refs">
			<div>
				<span class="gtc-refs__label"><?php esc_html_e( 'Your reference', 'gtc' ); ?></span>
				<code class="gtc-refs__value"><?php echo esc_html( $booking->reference ); ?></code>
			</div>
			<div>
				<span class="gtc-refs__label"><?php esc_html_e( 'Supplier confirmation', 'gtc' ); ?></span>
				<code class="gtc-refs__value"><?php echo esc_html( $booking->supplier_reference ); ?></code>
			</div>
		</div>

	<?php elseif ( GTC_Booking::STATUS_SUPPLIER_ERR === $booking->status ) : ?>

		<div class="gtc-confirmation__hero gtc-confirmation__hero--warn">
			<h1><?php esc_html_e( 'This booking needs our attention', 'gtc' ); ?></h1>
			<p><?php esc_html_e( 'Something went wrong between payment and the supplier. Our team has been alerted and will contact you. Please quote the reference below.', 'gtc' ); ?></p>
			<code class="gtc-refs__value"><?php echo esc_html( $booking->reference ); ?></code>
		</div>

	<?php else : ?>

		<div class="gtc-confirmation__hero gtc-confirmation__hero--warn">
			<h1><?php esc_html_e( 'This booking is not confirmed', 'gtc' ); ?></h1>
			<p><?php esc_html_e( 'No reservation has been placed and no payment has been taken.', 'gtc' ); ?></p>
		</div>

	<?php endif; ?>

	<?php if ( $offer ) : ?>
		<?php $gtc_price = $offer->get_price(); ?>

		<div class="gtc-confirmation__body">
			<h2><?php echo esc_html( $offer->product( 'name' ) ); ?></h2>
			<p class="gtc-summary__address"><?php echo esc_html( trim( $offer->product( 'address' ) . ', ' . $offer->product( 'city' ), ', ' ) ); ?></p>

			<dl class="gtc-summary__facts">
				<?php if ( $search ) : ?>
					<div>
						<dt><?php esc_html_e( 'Dates', 'gtc' ); ?></dt>
						<dd><?php echo esc_html( $search->get( 'check_in' ) . ' → ' . $search->get( 'check_out' ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Guests', 'gtc' ); ?></dt>
						<dd><?php echo esc_html( $search->get_guest_count() ); ?></dd>
					</div>
				<?php endif; ?>
				<div>
					<dt><?php esc_html_e( 'Lead traveller', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( $booking->get_lead_name() ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Room', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( $offer->rate( 'name' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Supplied by', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( $gtc_provider ? $gtc_provider->get_label() : $booking->provider_id ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Paid', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( GTC_Currency::format( $booking->amount_charged, $booking->currency ) ); ?></dd>
				</div>
				<?php if ( $booking->amount_at_property > 0 ) : ?>
					<div>
						<dt><?php esc_html_e( 'Payable at the property', 'gtc' ); ?></dt>
						<dd><?php echo esc_html( GTC_Currency::format( $booking->amount_at_property, $booking->currency ) ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>

			<div class="gtc-policy <?php echo $offer->rate( 'refundable' ) ? 'is-refundable' : 'is-nonref'; ?>">
				<strong><?php echo $offer->rate( 'refundable' ) ? esc_html__( 'Free cancellation', 'gtc' ) : esc_html__( 'Non-refundable', 'gtc' ); ?></strong>
				<span><?php echo esc_html( $offer->rate( 'cancellation' ) ); ?></span>
			</div>

			<?php unset( $gtc_price ); ?>
		</div>
	<?php endif; ?>
</div>
