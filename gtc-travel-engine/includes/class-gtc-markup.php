<?php
/**
 * Applies the site's commission to a net supplier rate.
 *
 * Markup is added as a visible price component rather than folded silently
 * into the base, so the itemised breakdown the customer sees always adds up to
 * the total they are charged, and so the same offer can be reconciled against
 * the supplier invoice later.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Markup {

	/**
	 * @param GTC_Offer          $offer   Offer carrying a net price.
	 * @param GTC_Search_Request $request Search context, for rule filters.
	 * @return GTC_Offer
	 */
	public function apply( GTC_Offer $offer, GTC_Search_Request $request ) {
		$settings = gtc()->settings();

		$type  = $settings->get( 'markup_type', 'percent' );
		$value = (float) $settings->get( 'markup_value', 0 );
		$label = (string) $settings->get( 'markup_label', __( 'Service fee', 'gtc' ) );

		/**
		 * Override the markup per offer — by category, supplier, destination,
		 * length of stay or margin band.
		 *
		 * @param array              $rule    { type, value, label }.
		 * @param GTC_Offer          $offer   Offer.
		 * @param GTC_Search_Request $request Search.
		 */
		$rule = apply_filters(
			'gtc_markup_rule',
			array(
				'type'  => $type,
				'value' => $value,
				'label' => $label,
			),
			$offer,
			$request
		);

		$value = (float) $rule['value'];

		if ( $value <= 0 ) {
			return $offer;
		}

		$price = $offer->get_price();

		// Percentage markup applies to what we are charged now, not to amounts
		// the customer will pay the property directly — we earn nothing on those.
		$basis = $price->get_total();

		$amount = 'percent' === $rule['type']
			? (int) round( $basis * ( $value / 100 ) )
			: GTC_Currency::to_minor( $value, $price->get_currency() );

		if ( $amount <= 0 ) {
			return $offer;
		}

		$price->add_component( $rule['label'], $amount, 'markup', 'now' );

		return $offer;
	}
}
