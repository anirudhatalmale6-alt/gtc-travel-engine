<?php
/**
 * Shared behaviour for the two sandbox hotel suppliers.
 *
 * These make no network calls. They exist so the platform can be built,
 * demonstrated and regression-tested before any distribution contract is
 * signed, and so the duplicate matcher has two genuinely differing views of
 * the same properties to reconcile.
 *
 * Pricing is deterministic: the same search always returns the same rate, so a
 * Look that reports "unchanged" is a real result and not luck. The two
 * scripted exceptions (a price rise and a sell-out) are declared in the
 * inventory fixture, not random, so the checkout paths that handle them can be
 * tested repeatably.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

abstract class GTC_Provider_Sandbox_Base extends GTC_Provider_Base {

	/** @var array|null */
	private static $inventory = null;

	/**
	 * Room products offered at every property, as a multiplier on the nightly
	 * net rate plus the board and refundability that go with it.
	 *
	 * @var array
	 */
	protected $room_types = array(
		array(
			'code'       => 'STD',
			'name'       => 'Standard Double Room',
			'mult'       => 1.00,
			'board'      => 'room_only',
			'refundable' => false,
		),
		array(
			'code'       => 'STD-FLEX',
			'name'       => 'Standard Double Room, flexible',
			'mult'       => 1.12,
			'board'      => 'room_only',
			'refundable' => true,
		),
		array(
			'code'       => 'STD-BB',
			'name'       => 'Standard Double Room with Breakfast',
			'mult'       => 1.18,
			'board'      => 'breakfast',
			'refundable' => true,
		),
		array(
			'code'       => 'DLX-BB',
			'name'       => 'Deluxe Room with Breakfast',
			'mult'       => 1.46,
			'board'      => 'breakfast',
			'refundable' => true,
		),
	);

	/**
	 * @return array
	 */
	protected function inventory() {
		if ( null === self::$inventory ) {
			self::$inventory = (array) require GTC_PATH . 'data/sandbox-inventory.php';
		}
		return self::$inventory;
	}

	/** @return string[] */
	public function get_categories() {
		return array( GTC_Categories::HOTELS );
	}

	/**
	 * Currency this sandbox supplier quotes in.
	 *
	 * Both sandbox suppliers quote USD, because the fixture is denominated in
	 * USD. Quoting a converted figure and labelling it EUR would be inventing
	 * an exchange rate, and the engine has no FX source until the site wires
	 * one to the gtc_fx_rate filter.
	 *
	 * @return string
	 */
	protected function quote_currency() {
		return 'USD';
	}

	/**
	 * Properties this supplier carries for the destination.
	 *
	 * @param string $destination Free-text destination.
	 * @return array
	 */
	protected function match_properties( $destination ) {
		$needle = strtolower( trim( (string) $destination ) );
		$out    = array();

		foreach ( $this->inventory() as $property ) {
			if ( ! isset( $property['suppliers'][ $this->get_id() ] ) ) {
				continue;
			}

			if ( '' !== $needle ) {
				$haystack = strtolower( $property['city'] . ' ' . $property['country'] . ' ' . $property['address'] . ' ' . $property['suppliers'][ $this->get_id() ]['name'] );
				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}

			$out[] = $property;
		}

		return $out;
	}

	/**
	 * Deterministic seasonal factor for a property on a given date, in the
	 * range 0.88 to 1.12.
	 *
	 * @param string $canonical Property key.
	 * @param string $check_in  Check-in date.
	 * @return float
	 */
	protected function seasonality( $canonical, $check_in ) {
		$seed = crc32( $canonical . '|' . $check_in );
		return 0.88 + ( ( $seed % 25 ) / 100 );
	}

	/**
	 * Build the offer list for one property.
	 *
	 * @param array              $property Fixture row.
	 * @param GTC_Search_Request $request  Search.
	 * @return GTC_Offer[]
	 */
	protected function build_offers( array $property, GTC_Search_Request $request ) {
		$supplier = $property['suppliers'][ $this->get_id() ];
		$nights   = max( 1, $request->get_nights() );
		$rooms    = $request->get_room_count();
		$guests   = $request->get_guest_count();
		$currency = $this->quote_currency();

		$season = $this->seasonality( $property['canonical'], (string) $request->get( 'check_in', '' ) );

		$offers = array();

		foreach ( $this->room_types as $room ) {
			$nightly = (int) round( $property['nightly'] * $supplier['rate_mult'] * $room['mult'] * $season );
			$base    = $nightly * $nights * $rooms;

			$price = new GTC_Price( $currency, $base );

			// VAT is included in the amount we collect.
			$price->add_component( __( 'VAT & service charge', 'gtc' ), (int) round( $base * 0.06 ), 'tax', 'now' );

			// City tax is collected by the property, so it is displayed but not
			// charged. Excluding it from the compare total would flatter this
			// supplier against one that bundles it.
			$price->add_component(
				__( 'City tax (payable at the property)', 'gtc' ),
				200 * $nights * $guests,
				'tax',
				'at_property'
			);

			$offer = new GTC_Offer(
				$this->get_id(),
				GTC_Categories::HOTELS,
				$property['canonical'] . ':' . $room['code'],
				$price
			);

			$offer->set_product(
				array(
					'name'         => $supplier['name'],
					'property_id'  => $this->get_id() . '-' . $property['canonical'],
					'lat'          => $property['lat'] + $supplier['lat_off'],
					'lng'          => $property['lng'] + $supplier['lng_off'],
					'address'      => $property['address'],
					'city'         => $property['city'],
					'country'      => $property['country'],
					'star_rating'  => $property['stars'],
					'review_score' => $property['review_score'],
					'review_count' => $property['review_count'],
					'images'       => array(),
					'amenities'    => $property['amenities'],
					'external_ids' => isset( $supplier['external_ids'] ) ? $supplier['external_ids'] : array(),
				)
			);

			$offer->set_rate(
				array(
					'name'         => $room['name'],
					'board'        => $room['board'],
					'refundable'   => $room['refundable'],
					'cancellation' => $room['refundable']
						? __( 'Free cancellation until 24 hours before check-in.', 'gtc' )
						: __( 'Non-refundable. No changes permitted after booking.', 'gtc' ),
					'inclusions'   => $this->inclusions( $room ),
					'policies'     => array(
						__( 'Check-in', 'gtc' )  => __( 'From 15:00', 'gtc' ),
						__( 'Check-out', 'gtc' ) => __( 'Until 11:00', 'gtc' ),
					),
					'rooms_left'   => ( crc32( $property['canonical'] . $room['code'] ) % 7 ) + 1,
				)
			);

			$offer->set_supplier_data(
				array(
					'canonical'  => $property['canonical'],
					'room_code'  => $room['code'],
					'rate_token' => 'sbx_' . substr( md5( $this->get_id() . $property['canonical'] . $room['code'] . $request->get_hash() ), 0, 24 ),
				)
			);

			// Referral destination. example.com is the address reserved for
			// documentation, so a demo click lands somewhere unmistakably not a
			// travel site — rather than pointing a fictional supplier's rate at
			// a real booking company's domain.
			$offer->set_deeplink(
				add_query_arg(
					array(
						'property' => $property['canonical'],
						'room'     => $room['code'],
						'checkin'  => (string) $request->get( 'check_in', '' ),
						'checkout' => (string) $request->get( 'check_out', '' ),
						'aid'      => 'demo-affiliate-id',
					),
					'https://example.com/' . $this->get_id() . '/book'
				)
			);

			$offers[] = $offer;
		}

		return $offers;
	}

	/**
	 * @param array $room Room type row.
	 * @return string[]
	 */
	protected function inclusions( array $room ) {
		$out = array( __( 'Free WiFi', 'gtc' ) );

		if ( 'breakfast' === $room['board'] ) {
			$out[] = __( 'Breakfast included', 'gtc' );
		}
		if ( $room['refundable'] ) {
			$out[] = __( 'Free cancellation', 'gtc' );
		}
		if ( 'DLX-BB' === $room['code'] ) {
			$out[] = __( 'Late check-out on request', 'gtc' );
		}

		return $out;
	}

	/**
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer[]
	 */
	public function search( GTC_Search_Request $request ) {
		if ( GTC_Categories::HOTELS !== $request->get_category() ) {
			return array();
		}

		$offers = array();

		foreach ( $this->match_properties( (string) $request->get( 'destination', '' ) ) as $property ) {
			$offers = array_merge( $offers, $this->build_offers( $property, $request ) );
		}

		return $offers;
	}

	/**
	 * @param GTC_Offer          $offer   Offer.
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer
	 * @throws GTC_Provider_Exception When the offer does not belong to this supplier.
	 */
	public function look( GTC_Offer $offer, GTC_Search_Request $request ) {
		$data      = $offer->get_supplier_data();
		$canonical = isset( $data['canonical'] ) ? $data['canonical'] : '';

		$property = null;
		foreach ( $this->inventory() as $row ) {
			if ( $row['canonical'] === $canonical && isset( $row['suppliers'][ $this->get_id() ] ) ) {
				$property = $row;
				break;
			}
		}

		if ( ! $property ) {
			throw new GTC_Provider_Exception( $this->get_id(), __( 'Rate no longer recognised by the supplier.', 'gtc' ), 'unknown_rate' );
		}

		$behaviour = isset( $property['look_behaviour'] ) ? $property['look_behaviour'] : '';

		if ( 'sold_out' === $behaviour ) {
			$gone = clone $offer;
			return $gone->mark_unavailable( __( 'This rate sold out while you were browsing.', 'gtc' ) );
		}

		// Rebuild the offer from live inventory rather than trusting anything
		// handed back to us — this is the whole purpose of the Look step.
		$rebuilt = null;
		foreach ( $this->build_offers( $property, $request ) as $candidate ) {
			if ( $candidate->get_offer_id() === $offer->get_offer_id() ) {
				$rebuilt = $candidate;
				break;
			}
		}

		if ( ! $rebuilt ) {
			$gone = clone $offer;
			return $gone->mark_unavailable( __( 'This room type is no longer available for your dates.', 'gtc' ) );
		}

		if ( 'price_up' === $behaviour ) {
			$old   = $rebuilt->get_price();
			$fresh = new GTC_Price( $old->get_currency(), (int) round( $old->get_base() * 1.09 ) );

			foreach ( $old->get_components() as $c ) {
				$fresh->add_component( $c['label'], (int) round( $c['amount'] * 1.09 ), $c['type'], $c['payable'] );
			}

			$rebuilt->set_price( $fresh );
			$rebuilt->set_rate(
				array_merge(
					$rebuilt->get_rate(),
					array( 'rooms_left' => 1 )
				)
			);
		}

		return $rebuilt;
	}

	/**
	 * @param GTC_Offer   $offer   Offer.
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 */
	public function book( GTC_Offer $offer, GTC_Booking $booking ) {
		// Prefix from the part of the id that differs between the two sandbox
		// suppliers, so a reference names which one issued it.
		$prefix = strtoupper( substr( str_replace( 'sandbox_', '', $this->get_id() ), 0, 3 ) );

		$reference = $prefix . '-' . strtoupper( substr( md5( $booking->get_idempotency_key() ), 0, 10 ) );

		return array(
			'supplier_reference' => $reference,
			'status'             => 'confirmed',
			'raw'                => array(
				'reservation_id' => $reference,
				'status'         => 'CONFIRMED',
				'rate_token'     => isset( $offer->get_supplier_data()['rate_token'] ) ? $offer->get_supplier_data()['rate_token'] : '',
				'total'          => $offer->get_price()->get_total(),
				'currency'       => $offer->get_price()->get_currency(),
			),
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 */
	public function cancel( GTC_Booking $booking ) {
		return array(
			'supplier_reference' => $booking->supplier_reference,
			'status'             => 'cancelled',
			'raw'                => array( 'status' => 'CANCELLED' ),
		);
	}
}
