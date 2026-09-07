<?php
/**
 * Fans a search out to every ready supplier, then merges the answers.
 *
 * A supplier that errors or times out is dropped from this search and reported
 * in the result's meta — never allowed to fail the whole page. A comparison
 * site that goes blank because one of six APIs is having a bad afternoon is
 * worse than one that shows five suppliers and says so.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Aggregator {

	/**
	 * @param GTC_Search_Request $request Normalised search.
	 * @param array              $args    { sort, filters, page }.
	 * @return GTC_Search_Result
	 */
	public function search( GTC_Search_Request $request, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'sort'    => 'price_asc',
				'filters' => array(),
				'page'    => 1,
			)
		);

		$providers = gtc()->providers()->for_category( $request->get_category() );

		$result = new GTC_Search_Result( $request );

		if ( ! $providers ) {
			$result->add_notice(
				sprintf(
					/* translators: %s: category label */
					__( 'No supplier is currently connected for %s.', 'gtc' ),
					GTC_Categories::label( $request->get_category() )
				)
			);
			return $result;
		}

		$cache  = gtc()->cache();
		$markup = new GTC_Markup();
		$offers = array();

		foreach ( $providers as $id => $provider ) {
			$started = microtime( true );
			$cached  = $cache->get_offers( $request, $id );

			if ( null !== $cached ) {
				$result->record_provider( $id, count( $cached ), 0, true );
				$offers = array_merge( $offers, $cached );
				continue;
			}

			try {
				$found = $provider->search( $request );
			} catch ( GTC_Provider_Exception $e ) {
				gtc()->logger()->log_error( $id, 'search', $e->getMessage(), $e->get_supplier_code() );
				$result->record_failure( $id, $e->getMessage() );
				continue;
			} catch ( Exception $e ) {
				gtc()->logger()->log_error( $id, 'search', $e->getMessage() );
				$result->record_failure( $id, __( 'Supplier unavailable.', 'gtc' ) );
				continue;
			}

			$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			$cache->set_offers( $request, $id, $found );
			$result->record_provider( $id, count( $found ), $ms, false );

			$offers = array_merge( $offers, $found );
		}

		// Markup is applied after caching so the cache holds net supplier rates.
		// Changing commission then re-prices instantly instead of waiting for
		// every cached search to expire at the old margin.
		foreach ( $offers as $offer ) {
			$markup->apply( $offer, $request );
		}

		// Normalise every offer into the currency the customer is shopping in,
		// or drop it: a card showing EUR next to USD is not a comparison.
		$offers = $this->reconcile_currency( $offers, $request, $result );

		$offers = $this->apply_filters( $offers, $args['filters'] );

		// The result set is stored before pagination so Look can recover any
		// offer the customer clicks, including ones on later pages.
		$cache->set_result_set( $request, $offers );

		$groups = ( new GTC_Dedupe() )->group( $offers );
		$groups = $this->sort_groups( $groups, $args['sort'], $request );

		$result->set_groups( $groups );
		$result->set_offer_count( count( $offers ) );

		return $result;
	}

	/**
	 * @param GTC_Offer[]        $offers  Offers.
	 * @param GTC_Search_Request $request Search.
	 * @param GTC_Search_Result  $result  Result, for notices.
	 * @return GTC_Offer[]
	 */
	private function reconcile_currency( array $offers, GTC_Search_Request $request, GTC_Search_Result $result ) {
		$target  = $request->get_currency();
		$kept    = array();
		$dropped = 0;

		foreach ( $offers as $offer ) {
			$price = $offer->get_price();

			if ( $price->get_currency() === $target ) {
				$kept[] = $offer;
				continue;
			}

			$converted = $this->convert_price( $price, $target );

			if ( null === $converted ) {
				$dropped++;
				continue;
			}

			$offer->set_price( $converted );
			$kept[] = $offer;
		}

		if ( $dropped > 0 ) {
			$result->add_notice(
				sprintf(
					/* translators: 1: count 2: currency */
					_n(
						'%1$d offer was hidden because it could not be converted to %2$s.',
						'%1$d offers were hidden because they could not be converted to %2$s.',
						$dropped,
						'gtc'
					),
					$dropped,
					$target
				)
			);
		}

		return $kept;
	}

	/**
	 * @param GTC_Price $price  Source price.
	 * @param string    $target Target currency.
	 * @return GTC_Price|null Null when no FX rate is available.
	 */
	private function convert_price( GTC_Price $price, $target ) {
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

	/**
	 * @param GTC_Offer[] $offers  Offers.
	 * @param array       $filters { max_price, min_stars, board, refundable_only, providers }.
	 * @return GTC_Offer[]
	 */
	private function apply_filters( array $offers, array $filters ) {
		if ( ! $filters ) {
			return $offers;
		}

		return array_values(
			array_filter(
				$offers,
				static function ( GTC_Offer $offer ) use ( $filters ) {
					if ( ! empty( $filters['max_price'] ) && $offer->get_price()->get_grand_total() > (int) $filters['max_price'] ) {
						return false;
					}
					if ( ! empty( $filters['min_stars'] ) && (float) $offer->product( 'star_rating', 0 ) < (float) $filters['min_stars'] ) {
						return false;
					}
					if ( ! empty( $filters['refundable_only'] ) && ! $offer->rate( 'refundable' ) ) {
						return false;
					}
					if ( ! empty( $filters['board'] ) && $offer->rate( 'board' ) !== $filters['board'] ) {
						return false;
					}
					if ( ! empty( $filters['providers'] ) && ! in_array( $offer->get_provider_id(), (array) $filters['providers'], true ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * @param GTC_Offer_Group[]  $groups  Groups.
	 * @param string             $sort    Sort key.
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer_Group[]
	 */
	private function sort_groups( array $groups, $sort, GTC_Search_Request $request ) {
		$nights = max( 1, $request->get_nights() );

		$comparators = array(
			'price_asc'   => static function ( GTC_Offer_Group $a, GTC_Offer_Group $b ) {
				return $a->best()->get_price()->get_grand_total() <=> $b->best()->get_price()->get_grand_total();
			},
			'price_desc'  => static function ( GTC_Offer_Group $a, GTC_Offer_Group $b ) {
				return $b->best()->get_price()->get_grand_total() <=> $a->best()->get_price()->get_grand_total();
			},
			'rating_desc' => static function ( GTC_Offer_Group $a, GTC_Offer_Group $b ) {
				return (float) $b->best()->product( 'review_score', 0 ) <=> (float) $a->best()->product( 'review_score', 0 );
			},
			'stars_desc'  => static function ( GTC_Offer_Group $a, GTC_Offer_Group $b ) {
				return (float) $b->best()->product( 'star_rating', 0 ) <=> (float) $a->best()->product( 'star_rating', 0 );
			},
			'saving_desc' => static function ( GTC_Offer_Group $a, GTC_Offer_Group $b ) {
				return $b->saving() <=> $a->saving();
			},
		);

		$fn = isset( $comparators[ $sort ] ) ? $comparators[ $sort ] : $comparators['price_asc'];

		usort( $groups, $fn );

		unset( $nights );

		return $groups;
	}
}
