<?php
/**
 * One product, every offer for it, from every supplier that has it.
 *
 * This is what the results page actually renders: a single card per hotel with
 * a supplier price comparison inside it, rather than the same hotel four times.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Offer_Group implements JsonSerializable {

	/** @var GTC_Offer[] */
	private $offers;

	/**
	 * @param GTC_Offer[] $offers Offers for one product.
	 */
	public function __construct( array $offers ) {
		$this->offers = array_values( $offers );

		usort(
			$this->offers,
			static function ( GTC_Offer $a, GTC_Offer $b ) {
				return $a->get_price()->get_grand_total() <=> $b->get_price()->get_grand_total();
			}
		);
	}

	/**
	 * The offer shown as the headline: the cheapest on grand total, so a
	 * supplier cannot win the card by deferring its taxes to the property.
	 *
	 * @return GTC_Offer
	 */
	public function best() {
		return $this->offers[0];
	}

	/** @return GTC_Offer[] */
	public function all() {
		return $this->offers;
	}

	/**
	 * Cheapest offer per supplier — the comparison rows on the card.
	 *
	 * @return GTC_Offer[] Keyed by provider id.
	 */
	public function by_provider() {
		$out = array();
		foreach ( $this->offers as $offer ) {
			$pid = $offer->get_provider_id();
			if ( ! isset( $out[ $pid ] ) ) {
				$out[ $pid ] = $offer;
			}
		}
		return $out;
	}

	/** @return int */
	public function provider_count() {
		return count( $this->by_provider() );
	}

	/**
	 * How much the customer saves by taking our headline offer instead of the
	 * dearest supplier for the same product. Zero when only one supplier has it.
	 *
	 * @return int Minor units.
	 */
	public function saving() {
		$per_provider = $this->by_provider();

		if ( count( $per_provider ) < 2 ) {
			return 0;
		}

		$totals = array();
		foreach ( $per_provider as $offer ) {
			$totals[] = $offer->get_price()->get_grand_total();
		}

		return max( $totals ) - min( $totals );
	}

	/**
	 * @return string Product name from the headline offer.
	 */
	public function get_name() {
		return (string) $this->best()->product( 'name' );
	}

	/** @return array */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(
			'best'           => $this->best(),
			'offers'         => $this->offers,
			'provider_count' => $this->provider_count(),
			'saving'         => $this->saving(),
		);
	}
}
