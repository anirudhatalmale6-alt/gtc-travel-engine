<?php
/**
 * The Look step: re-prices a selected offer against live inventory before the
 * customer is asked to pay.
 *
 * This is the single most important guard in the platform. A searched price is
 * a cached indication; the supplier is only bound by what it returns here. If
 * this step is skipped or its answer is ignored, the site sells at a price it
 * cannot buy at, and eats the difference on every booking.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Revalidator {

	/**
	 * @param GTC_Offer          $offer   Offer the customer selected.
	 * @param GTC_Search_Request $request Search that produced it.
	 * @return GTC_Revalidation
	 */
	public function revalidate( GTC_Offer $offer, GTC_Search_Request $request ) {
		$provider = gtc()->providers()->get( $offer->get_provider_id() );

		if ( ! $provider ) {
			return GTC_Revalidation::gone( $offer, __( 'This supplier is no longer connected.', 'gtc' ) );
		}

		$started = microtime( true );

		try {
			$fresh = $provider->look( $offer, $request );
		} catch ( GTC_Provider_Exception $e ) {
			gtc()->logger()->log_error( $offer->get_provider_id(), 'look', $e->getMessage(), $e->get_supplier_code() );
			return GTC_Revalidation::failed( $offer, $e->getMessage() );
		} catch ( Exception $e ) {
			gtc()->logger()->log_error( $offer->get_provider_id(), 'look', $e->getMessage() );
			return GTC_Revalidation::failed( $offer, __( 'We could not confirm this price with the supplier. Please try again.', 'gtc' ) );
		}

		gtc()->logger()->log_call(
			$offer->get_provider_id(),
			'look',
			array( 'offer_id' => $offer->get_offer_id() ),
			array(
				'available' => $fresh->is_available(),
				'total'     => $fresh->get_price()->get_total(),
			),
			200,
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);

		if ( ! $fresh->is_available() ) {
			return GTC_Revalidation::gone(
				$offer,
				$fresh->get_unavailable_reason() ? $fresh->get_unavailable_reason() : __( 'This rate has just sold out.', 'gtc' )
			);
		}

		// The supplier returns a net rate; our commission has to go back on
		// before the two prices are comparable. Comparing a marked-up quote
		// against a net re-price would report a "drop" on every single booking.
		( new GTC_Markup() )->apply( $fresh, $request );

		$old = $offer->get_price();
		$new = $fresh->get_price();

		// A supplier quoting in its own currency must be brought back into the
		// customer's currency before comparison, or every cross-currency offer
		// looks like a huge price change.
		if ( $new->get_currency() !== $old->get_currency() ) {
			$converted = $this->convert( $new, $old->get_currency() );

			if ( null === $converted ) {
				return GTC_Revalidation::failed(
					$fresh,
					__( 'We could not confirm this price in your currency. Please try again.', 'gtc' )
				);
			}

			$fresh->set_price( $converted );
			$new = $converted;
		}

		$delta = $new->delta_against( $old );

		$tolerance = (int) gtc()->settings()->get( 'price_change_tolerance', 0 );

		if ( abs( $delta ) <= $tolerance ) {
			return GTC_Revalidation::unchanged( $fresh );
		}

		return GTC_Revalidation::changed( $fresh, $old, $delta );
	}

	/**
	 * @param GTC_Price $price  Price to convert.
	 * @param string    $target Target currency.
	 * @return GTC_Price|null
	 */
	private function convert( GTC_Price $price, $target ) {
		$base = GTC_Currency::convert( $price->get_base(), $price->get_currency(), $target );

		if ( null === $base ) {
			return null;
		}

		$out = new GTC_Price( $target, $base );

		foreach ( $price->get_components() as $c ) {
			$amount = GTC_Currency::convert( $c['amount'], $price->get_currency(), $target );
			if ( null === $amount ) {
				return null;
			}
			$out->add_component( $c['label'], $amount, $c['type'], $c['payable'] );
		}

		return $out;
	}
}
