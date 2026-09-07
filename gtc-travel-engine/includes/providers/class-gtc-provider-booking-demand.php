<?php
/**
 * Booking.com Demand API adapter — Search / Look / Book.
 *
 * STATUS: written against the public description of the Demand API's
 * Search-Look-Book model and wired into the engine, but NOT yet verified
 * against a live endpoint. The Demand API is issued under a partner agreement;
 * the exact request paths, field names and error envelope are fixed by the
 * version of the specification that Booking.com gives you on approval, and
 * they differ between v3.0 and v3.1. Everything version-specific in this class
 * is isolated in map_*() and endpoint() so it can be corrected in one place
 * once the specification and a sandbox key are in hand.
 *
 * Do not switch this adapter on in production until a sandbox booking has been
 * placed and cancelled end to end.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Booking_Demand extends GTC_Provider_Base {

	/** @return string */
	public function get_id() {
		return 'booking_demand';
	}

	/** @return string */
	public function get_label() {
		return __( 'Booking.com Demand API', 'gtc' );
	}

	/** @return string[] */
	public function get_categories() {
		return array( GTC_Categories::HOTELS, GTC_Categories::RENTALS );
	}

	/** @return string[] */
	public function required_credentials() {
		return array( 'affiliate_id', 'api_token' );
	}

	/**
	 * @param string $path Endpoint path.
	 * @return string
	 */
	private function endpoint( $path ) {
		$base = rtrim( $this->credential( 'base_url', 'https://demandapi.booking.com/3.1' ), '/' );
		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * @return array
	 */
	private function headers() {
		return array(
			'Authorization'  => 'Bearer ' . $this->credential( 'api_token' ),
			'X-Affiliate-Id' => $this->credential( 'affiliate_id' ),
		);
	}

	/* ----------------------------------------------------------- search */

	/**
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer[]
	 * @throws GTC_Provider_Exception On supplier error.
	 */
	public function search( GTC_Search_Request $request ) {
		$body = array(
			'checkin'         => $request->get( 'check_in' ),
			'checkout'        => $request->get( 'check_out' ),
			'city'            => $request->get( 'destination' ),
			'currency'        => $request->get_currency(),
			'guests'          => $this->map_occupancy( $request ),
			'extras'          => array( 'extra_charges', 'products' ),
			'rows'            => (int) apply_filters( 'gtc_booking_demand_rows', 100, $request ),
		);

		$data = $this->request( 'POST', $this->endpoint( '/accommodations/search' ), array(
			'headers' => $this->headers(),
			'body'    => $body,
		), 'search' );

		$rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

		if ( ! $rows ) {
			return array();
		}

		// Search returns rates keyed by accommodation id; the descriptive
		// detail (name, coordinates, rating) comes from the separate details
		// call, which is what makes matching possible at all.
		$details = $this->fetch_details( wp_list_pluck( $rows, 'id' ), $request );

		$offers = array();

		foreach ( $rows as $row ) {
			$id     = isset( $row['id'] ) ? (string) $row['id'] : '';
			$detail = isset( $details[ $id ] ) ? $details[ $id ] : array();

			foreach ( $this->extract_products( $row ) as $product ) {
				$offer = $this->map_offer( $row, $product, $detail, $request );
				if ( $offer ) {
					$offers[] = $offer;
				}
			}
		}

		return $offers;
	}

	/**
	 * @param array              $ids     Accommodation ids.
	 * @param GTC_Search_Request $request Search.
	 * @return array Keyed by accommodation id.
	 */
	private function fetch_details( array $ids, GTC_Search_Request $request ) {
		$ids = array_values( array_filter( array_map( 'strval', $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$out = array();

		// The details endpoint is batched; keep chunks small enough that one
		// slow response cannot blow the whole search timeout.
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			try {
				$data = $this->request( 'POST', $this->endpoint( '/accommodations/details' ), array(
					'headers' => $this->headers(),
					'body'    => array(
						'accommodations' => array_map( 'intval', $chunk ),
						'languages'      => array( substr( $request->get_locale(), 0, 2 ) ),
					),
				), 'search' );
			} catch ( GTC_Provider_Exception $e ) {
				// Detail enrichment failing must not lose the rates; the offers
				// are still sellable, they just compare less well.
				gtc()->logger()->log_error( $this->get_id(), 'search', 'details: ' . $e->getMessage(), $e->get_supplier_code() );
				continue;
			}

			foreach ( ( isset( $data['data'] ) ? (array) $data['data'] : array() ) as $row ) {
				if ( isset( $row['id'] ) ) {
					$out[ (string) $row['id'] ] = $row;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array $row Accommodation row from search.
	 * @return array
	 */
	private function extract_products( array $row ) {
		if ( ! empty( $row['products'] ) && is_array( $row['products'] ) ) {
			return $row['products'];
		}
		// Some responses carry a single flattened rate rather than a product
		// list; treat it as a one-product list so mapping stays uniform.
		return isset( $row['price'] ) ? array( $row ) : array();
	}

	/**
	 * @param array              $row     Accommodation row.
	 * @param array              $product Rate/product row.
	 * @param array              $detail  Details row.
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer|null
	 */
	private function map_offer( array $row, array $product, array $detail, GTC_Search_Request $request ) {
		$currency = $this->dig( $product, array( 'price', 'currency' ), $request->get_currency() );
		$gross    = $this->dig( $product, array( 'price', 'book' ), null );

		if ( null === $gross ) {
			$gross = $this->dig( $product, array( 'price', 'total' ), null );
		}

		if ( null === $gross ) {
			return null;
		}

		$price = GTC_Price::from_major( $currency, (float) $gross );

		foreach ( (array) $this->dig( $product, array( 'extra_charges' ), array() ) as $charge ) {
			$amount = isset( $charge['amount'] ) ? (float) $charge['amount'] : 0.0;
			if ( ! $amount ) {
				continue;
			}
			$price->add_component(
				isset( $charge['name'] ) ? (string) $charge['name'] : __( 'Charge', 'gtc' ),
				GTC_Currency::to_minor( $amount, $currency ),
				isset( $charge['type'] ) && 'tax' === $charge['type'] ? 'tax' : 'fee',
				! empty( $charge['included'] ) ? 'now' : 'at_property'
			);
		}

		$offer = new GTC_Offer(
			$this->get_id(),
			GTC_Categories::HOTELS,
			(string) $row['id'] . ':' . (string) $this->dig( $product, array( 'id' ), '0' ),
			$price
		);

		$offer->set_product(
			array(
				'name'         => (string) $this->dig( $detail, array( 'name' ), $this->dig( $row, array( 'name' ), '' ) ),
				'property_id'  => (string) $row['id'],
				'lat'          => $this->dig( $detail, array( 'location', 'coordinates', 'latitude' ), null ),
				'lng'          => $this->dig( $detail, array( 'location', 'coordinates', 'longitude' ), null ),
				'address'      => (string) $this->dig( $detail, array( 'location', 'address' ), '' ),
				'city'         => (string) $this->dig( $detail, array( 'location', 'city' ), '' ),
				'country'      => (string) $this->dig( $detail, array( 'location', 'country' ), '' ),
				'star_rating'  => $this->dig( $detail, array( 'accommodation_class' ), null ),
				'review_score' => $this->dig( $detail, array( 'reviews', 'score' ), null ),
				'review_count' => (int) $this->dig( $detail, array( 'reviews', 'count' ), 0 ),
				'images'       => $this->map_images( $detail ),
				'amenities'    => (array) $this->dig( $detail, array( 'facilities' ), array() ),
				// Booking.com does not publish GIATA ids; matching against
				// another supplier therefore falls back to name and geo.
				'external_ids' => array(),
			)
		);

		$offer->set_rate(
			array(
				'name'         => (string) $this->dig( $product, array( 'name' ), __( 'Room', 'gtc' ) ),
				'board'        => $this->map_board( $product ),
				'refundable'   => (bool) $this->dig( $product, array( 'cancellation', 'free_cancellation' ), false ),
				'cancellation' => (string) $this->dig( $product, array( 'cancellation', 'description' ), '' ),
				'inclusions'   => (array) $this->dig( $product, array( 'includes' ), array() ),
				'rooms_left'   => (int) $this->dig( $product, array( 'available_rooms' ), 0 ),
			)
		);

		// The block/product identifier is what the Look and Book calls key on;
		// it must survive the round trip untouched.
		$offer->set_supplier_data(
			array(
				'accommodation_id' => (string) $row['id'],
				'product_id'       => (string) $this->dig( $product, array( 'id' ), '' ),
				'block_id'         => (string) $this->dig( $product, array( 'block_id' ), '' ),
			)
		);

		return $offer;
	}

	/**
	 * @param array $detail Details row.
	 * @return array
	 */
	private function map_images( array $detail ) {
		$out = array();
		foreach ( (array) $this->dig( $detail, array( 'photos' ), array() ) as $photo ) {
			if ( ! empty( $photo['url'] ) ) {
				$out[] = esc_url_raw( $photo['url'] );
			}
		}
		return $out;
	}

	/**
	 * @param array $product Product row.
	 * @return string
	 */
	private function map_board( array $product ) {
		$meal = strtolower( (string) $this->dig( $product, array( 'meal_plan' ), '' ) );

		$map = array(
			'breakfast_included' => 'breakfast',
			'half_board'         => 'half_board',
			'full_board'         => 'full_board',
			'all_inclusive'      => 'all_inclusive',
		);

		return isset( $map[ $meal ] ) ? $map[ $meal ] : 'room_only';
	}

	/**
	 * @param GTC_Search_Request $request Search.
	 * @return array
	 */
	private function map_occupancy( GTC_Search_Request $request ) {
		$rooms  = (array) $request->get( 'occupancy', array() );
		$adults = 0;
		$ages   = array();

		foreach ( $rooms as $room ) {
			$adults += (int) $room['adults'];
			foreach ( (array) $room['children'] as $age ) {
				$ages[] = (int) $age;
			}
		}

		return array(
			'number_of_rooms' => max( 1, count( $rooms ) ),
			'number_of_adults'=> max( 1, $adults ),
			'children'        => $ages,
		);
	}

	/* ------------------------------------------------------------- look */

	/**
	 * @param GTC_Offer          $offer   Offer.
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer
	 * @throws GTC_Provider_Exception On supplier error.
	 */
	public function look( GTC_Offer $offer, GTC_Search_Request $request ) {
		$supplier = $offer->get_supplier_data();

		$data = $this->request( 'POST', $this->endpoint( '/orders/preview' ), array(
			'headers' => $this->headers(),
			'body'    => array(
				'accommodation' => (int) $supplier['accommodation_id'],
				'checkin'       => $request->get( 'check_in' ),
				'checkout'      => $request->get( 'check_out' ),
				'currency'      => $request->get_currency(),
				'guests'        => $this->map_occupancy( $request ),
				'products'      => array(
					array(
						'id'    => $supplier['product_id'],
						'block' => $supplier['block_id'],
					),
				),
			),
		), 'look' );

		$row = isset( $data['data'] ) ? (array) $data['data'] : array();

		if ( empty( $row ) || ! empty( $row['unavailable'] ) ) {
			$gone = clone $offer;
			return $gone->mark_unavailable( __( 'This rate is no longer available.', 'gtc' ) );
		}

		$currency = (string) $this->dig( $row, array( 'price', 'currency' ), $request->get_currency() );
		$total    = $this->dig( $row, array( 'price', 'book' ), $this->dig( $row, array( 'price', 'total' ), null ) );

		if ( null === $total ) {
			$gone = clone $offer;
			return $gone->mark_unavailable( __( 'The supplier did not return a bookable price.', 'gtc' ) );
		}

		$fresh = clone $offer;
		$fresh->set_price( GTC_Price::from_major( $currency, (float) $total ) );

		// The preview issues the token that Book must present.
		$fresh->set_supplier_data(
			array_merge(
				$supplier,
				array( 'order_token' => (string) $this->dig( $row, array( 'order_token' ), '' ) )
			)
		);

		return $fresh;
	}

	/* ------------------------------------------------------------- book */

	/**
	 * @param GTC_Offer   $offer   Offer.
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 * @throws GTC_Provider_Exception On supplier error.
	 */
	public function book( GTC_Offer $offer, GTC_Booking $booking ) {
		$supplier = $offer->get_supplier_data();

		if ( empty( $supplier['order_token'] ) ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				__( 'This booking was not revalidated immediately before payment.', 'gtc' ),
				'missing_order_token'
			);
		}

		$lead = isset( $booking->travellers[0] ) ? $booking->travellers[0] : array();

		$data = $this->request( 'POST', $this->endpoint( '/orders/create' ), array(
			'headers' => array_merge(
				$this->headers(),
				array( 'X-Request-Id' => $booking->get_idempotency_key() )
			),
			'body'    => array(
				'order_token' => $supplier['order_token'],
				'booker'      => array(
					'first_name' => isset( $lead['first_name'] ) ? $lead['first_name'] : '',
					'last_name'  => isset( $lead['last_name'] ) ? $lead['last_name'] : '',
					'email'      => isset( $booking->contact['email'] ) ? $booking->contact['email'] : '',
					'telephone'  => isset( $booking->contact['phone'] ) ? $booking->contact['phone'] : '',
					'country'    => isset( $booking->contact['country'] ) ? $booking->contact['country'] : '',
				),
				'guests'      => $this->map_guests( $booking ),
				'reference'   => $booking->reference,
			),
		), 'book' );

		$row = isset( $data['data'] ) ? (array) $data['data'] : array();

		$reference = (string) $this->dig( $row, array( 'reservation_id' ), $this->dig( $row, array( 'id' ), '' ) );

		if ( '' === $reference ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				__( 'The supplier accepted the request but returned no reservation id.', 'gtc' ),
				'no_reservation_id'
			);
		}

		return array(
			'supplier_reference' => $reference,
			'status'             => 'confirmed',
			'raw'                => $row,
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 */
	private function map_guests( GTC_Booking $booking ) {
		$out = array();
		foreach ( (array) $booking->travellers as $traveller ) {
			$out[] = array(
				'first_name' => isset( $traveller['first_name'] ) ? $traveller['first_name'] : '',
				'last_name'  => isset( $traveller['last_name'] ) ? $traveller['last_name'] : '',
			);
		}
		return $out;
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 * @throws GTC_Provider_Exception On supplier error.
	 */
	public function cancel( GTC_Booking $booking ) {
		$data = $this->request( 'POST', $this->endpoint( '/orders/cancel' ), array(
			'headers' => $this->headers(),
			'body'    => array( 'reservation_id' => $booking->supplier_reference ),
		), 'cancel' );

		return array(
			'supplier_reference' => $booking->supplier_reference,
			'status'             => 'cancelled',
			'raw'                => isset( $data['data'] ) ? (array) $data['data'] : array(),
		);
	}

	/**
	 * Safe nested array read.
	 *
	 * @param array $data    Array.
	 * @param array $path    Key path.
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	private function dig( array $data, array $path, $default = null ) {
		$cursor = $data;
		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return $default;
			}
			$cursor = $cursor[ $key ];
		}
		return null === $cursor ? $default : $cursor;
	}
}
