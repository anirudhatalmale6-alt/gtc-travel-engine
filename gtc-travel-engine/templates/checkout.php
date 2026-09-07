<?php
/**
 * Checkout: the revalidated price, the full breakdown, then payment.
 *
 * @package GTC
 * @var GTC_Booking        $booking Booking.
 * @var GTC_Offer          $offer   Revalidated offer.
 * @var GTC_Search_Request $search  Original search.
 */

defined( 'ABSPATH' ) || exit;

$gtc_price    = $offer->get_price();
$gtc_currency = $gtc_price->get_currency();
$gtc_token    = ( new GTC_Rest() )->access_token( $booking );
$gtc_provider = gtc()->providers()->get( $booking->provider_id );
$gtc_nights   = $search ? $search->get_nights() : 0;
?>
<div class="gtc gtc-checkout"
	data-gtc-checkout
	data-reference="<?php echo esc_attr( $booking->reference ); ?>"
	data-token="<?php echo esc_attr( $gtc_token ); ?>"
	data-total="<?php echo esc_attr( $gtc_price->get_total() ); ?>">

	<div class="gtc-checkout__main">

		<ol class="gtc-steps">
			<li class="is-done"><?php esc_html_e( 'Compare', 'gtc' ); ?></li>
			<li class="is-current"><?php esc_html_e( 'Confirm & pay', 'gtc' ); ?></li>
			<li><?php esc_html_e( 'Confirmation', 'gtc' ); ?></li>
		</ol>

		<div class="gtc-revalidated" data-gtc-revalidation-notice>
			<span class="gtc-revalidated__tick" aria-hidden="true">✓</span>
			<div>
				<strong><?php esc_html_e( 'Price and availability confirmed with the supplier', 'gtc' ); ?></strong>
				<span><?php esc_html_e( 'We re-checked this rate live before showing you this page. If it changes before you pay, we will tell you here first.', 'gtc' ); ?></span>
			</div>
		</div>

		<form class="gtc-form" data-gtc-checkout-form>

			<fieldset class="gtc-fieldset">
				<legend><?php esc_html_e( 'Lead traveller', 'gtc' ); ?></legend>
				<p class="gtc-fieldset__hint"><?php esc_html_e( 'Enter names exactly as they appear on the passport or ID used at check-in.', 'gtc' ); ?></p>

				<div class="gtc-grid">
					<label class="gtc-field gtc-field--narrow">
						<span class="gtc-field__label"><?php esc_html_e( 'Title', 'gtc' ); ?></span>
						<select name="title">
							<option value="Mr"><?php esc_html_e( 'Mr', 'gtc' ); ?></option>
							<option value="Ms"><?php esc_html_e( 'Ms', 'gtc' ); ?></option>
							<option value="Mrs"><?php esc_html_e( 'Mrs', 'gtc' ); ?></option>
							<option value="Mx"><?php esc_html_e( 'Mx', 'gtc' ); ?></option>
							<option value="Dr"><?php esc_html_e( 'Dr', 'gtc' ); ?></option>
						</select>
					</label>

					<label class="gtc-field">
						<span class="gtc-field__label"><?php esc_html_e( 'First name', 'gtc' ); ?></span>
						<input type="text" name="first_name" autocomplete="given-name" required>
					</label>

					<label class="gtc-field">
						<span class="gtc-field__label"><?php esc_html_e( 'Last name', 'gtc' ); ?></span>
						<input type="text" name="last_name" autocomplete="family-name" required>
					</label>
				</div>
			</fieldset>

			<fieldset class="gtc-fieldset">
				<legend><?php esc_html_e( 'Contact details', 'gtc' ); ?></legend>
				<p class="gtc-fieldset__hint"><?php esc_html_e( 'Your confirmation and any supplier updates go here.', 'gtc' ); ?></p>

				<div class="gtc-grid">
					<label class="gtc-field">
						<span class="gtc-field__label"><?php esc_html_e( 'Email', 'gtc' ); ?></span>
						<input type="email" name="email" autocomplete="email" required>
					</label>

					<label class="gtc-field">
						<span class="gtc-field__label"><?php esc_html_e( 'Phone', 'gtc' ); ?></span>
						<input type="tel" name="phone" autocomplete="tel">
					</label>

					<label class="gtc-field gtc-field--narrow">
						<span class="gtc-field__label"><?php esc_html_e( 'Country', 'gtc' ); ?></span>
						<input type="text" name="country" autocomplete="country-name" placeholder="<?php esc_attr_e( 'e.g. United Kingdom', 'gtc' ); ?>">
					</label>
				</div>

				<label class="gtc-field">
					<span class="gtc-field__label"><?php esc_html_e( 'Special requests (optional)', 'gtc' ); ?></span>
					<textarea name="special_requests" rows="3" placeholder="<?php esc_attr_e( 'Passed to the property. Not guaranteed.', 'gtc' ); ?>"></textarea>
				</label>
			</fieldset>

			<fieldset class="gtc-fieldset">
				<legend><?php esc_html_e( 'Payment', 'gtc' ); ?></legend>

				<?php if ( 'sandbox' === gtc()->settings()->get( 'payment_gateway', 'sandbox' ) ) : ?>
					<div class="gtc-sandbox-banner">
						<strong><?php esc_html_e( 'Sandbox mode — no money will be taken.', 'gtc' ); ?></strong>
						<span><?php esc_html_e( 'Use 4111 1111 1111 1111 to succeed, or 4000 0000 0000 0002 to see a declined card.', 'gtc' ); ?></span>
					</div>
				<?php endif; ?>

				<div class="gtc-grid">
					<label class="gtc-field gtc-field--wide">
						<span class="gtc-field__label"><?php esc_html_e( 'Card number', 'gtc' ); ?></span>
						<input type="text" name="card_number" inputmode="numeric" autocomplete="off" placeholder="4111 1111 1111 1111" required>
					</label>

					<label class="gtc-field gtc-field--narrow">
						<span class="gtc-field__label"><?php esc_html_e( 'Expiry', 'gtc' ); ?></span>
						<input type="text" name="card_expiry" placeholder="MM/YY" autocomplete="off" required>
					</label>

					<label class="gtc-field gtc-field--narrow">
						<span class="gtc-field__label"><?php esc_html_e( 'CVC', 'gtc' ); ?></span>
						<input type="text" name="card_cvc" inputmode="numeric" autocomplete="off" placeholder="123" required>
					</label>
				</div>
			</fieldset>

			<label class="gtc-consent">
				<input type="checkbox" name="terms" required>
				<span>
					<?php
					$gtc_terms = gtc()->settings()->get( 'terms_url', '' );
					if ( $gtc_terms ) {
						printf(
							/* translators: %s: terms link */
							esc_html__( 'I accept the %s and the supplier cancellation policy shown above.', 'gtc' ),
							'<a href="' . esc_url( $gtc_terms ) . '" target="_blank" rel="noopener">' . esc_html__( 'booking terms', 'gtc' ) . '</a>'
						);
					} else {
						esc_html_e( 'I accept the booking terms and the supplier cancellation policy shown above.', 'gtc' );
					}
					?>
				</span>
			</label>

			<div class="gtc-checkout__error" data-gtc-error hidden></div>

			<button type="submit" class="gtc-btn gtc-btn--primary gtc-btn--large" data-gtc-pay>
				<?php
				printf(
					/* translators: %s: amount */
					esc_html__( 'Pay %s and confirm', 'gtc' ),
					esc_html( $gtc_price->format() )
				);
				?>
			</button>

			<p class="gtc-checkout__reassurance">
				<?php esc_html_e( 'We hold the payment, place the reservation with the supplier, and only take the money once the supplier confirms.', 'gtc' ); ?>
			</p>
		</form>
	</div>

	<aside class="gtc-summary">
		<div class="gtc-summary__card">
			<h2 class="gtc-summary__title"><?php echo esc_html( $offer->product( 'name' ) ); ?></h2>

			<p class="gtc-summary__address">
				<?php echo esc_html( trim( $offer->product( 'address' ) . ', ' . $offer->product( 'city' ), ', ' ) ); ?>
			</p>

			<dl class="gtc-summary__facts">
				<?php if ( $search ) : ?>
					<div>
						<dt><?php esc_html_e( 'Dates', 'gtc' ); ?></dt>
						<dd>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: check-in 2: check-out 3: nights */
									_n( '%1$s → %2$s (%3$d night)', '%1$s → %2$s (%3$d nights)', max( 1, $gtc_nights ), 'gtc' ),
									(string) $search->get( 'check_in' ),
									(string) $search->get( 'check_out' ),
									$gtc_nights
								)
							);
							?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Guests', 'gtc' ); ?></dt>
						<dd>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: rooms 2: guests */
									__( '%1$d room, %2$d guests', 'gtc' ),
									$search->get_room_count(),
									$search->get_guest_count()
								)
							);
							?>
						</dd>
					</div>
				<?php endif; ?>
				<div>
					<dt><?php esc_html_e( 'Room', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( $offer->rate( 'name' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Board', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( GTC_Shortcodes::board_label( $offer->rate( 'board' ) ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Supplied by', 'gtc' ); ?></dt>
					<dd><?php echo esc_html( $gtc_provider ? $gtc_provider->get_label() : $booking->provider_id ); ?></dd>
				</div>
			</dl>

			<?php if ( $offer->rate( 'inclusions' ) ) : ?>
				<ul class="gtc-summary__inclusions">
					<?php foreach ( (array) $offer->rate( 'inclusions' ) as $gtc_inclusion ) : ?>
						<li><?php echo esc_html( $gtc_inclusion ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="gtc-policy <?php echo $offer->rate( 'refundable' ) ? 'is-refundable' : 'is-nonref'; ?>">
				<strong><?php echo $offer->rate( 'refundable' ) ? esc_html__( 'Free cancellation', 'gtc' ) : esc_html__( 'Non-refundable', 'gtc' ); ?></strong>
				<span><?php echo esc_html( $offer->rate( 'cancellation' ) ); ?></span>
			</div>

			<table class="gtc-breakdown">
				<caption class="screen-reader-text"><?php esc_html_e( 'Full price breakdown', 'gtc' ); ?></caption>
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Room rate', 'gtc' ); ?></th>
						<td><?php echo esc_html( $gtc_price->format( $gtc_price->get_base() ) ); ?></td>
					</tr>
					<?php foreach ( $gtc_price->get_components() as $gtc_component ) : ?>
						<?php if ( 'now' !== $gtc_component['payable'] ) { continue; } ?>
						<tr>
							<th scope="row"><?php echo esc_html( $gtc_component['label'] ); ?></th>
							<td><?php echo esc_html( $gtc_price->format( $gtc_component['amount'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr class="gtc-breakdown__total">
						<th scope="row"><?php esc_html_e( 'Total charged now', 'gtc' ); ?></th>
						<td data-gtc-total><?php echo esc_html( $gtc_price->format() ); ?></td>
					</tr>
					<?php if ( $gtc_price->get_payable_at_property() > 0 ) : ?>
						<?php foreach ( $gtc_price->get_components() as $gtc_component ) : ?>
							<?php if ( 'at_property' !== $gtc_component['payable'] ) { continue; } ?>
							<tr class="gtc-breakdown__later">
								<th scope="row"><?php echo esc_html( $gtc_component['label'] ); ?></th>
								<td><?php echo esc_html( $gtc_price->format( $gtc_component['amount'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr class="gtc-breakdown__later gtc-breakdown__later--total">
							<th scope="row"><?php esc_html_e( 'Payable at the property', 'gtc' ); ?></th>
							<td><?php echo esc_html( $gtc_price->format( $gtc_price->get_payable_at_property() ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="gtc-summary__ref">
				<?php
				printf(
					/* translators: %s: booking reference */
					esc_html__( 'Reference %s', 'gtc' ),
					'<code>' . esc_html( $booking->reference ) . '</code>'
				);
				?>
			</p>
		</div>
	</aside>
</div>
