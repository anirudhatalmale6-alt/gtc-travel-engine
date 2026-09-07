<?php
/**
 * Expedia Rapid adapter — Shop / Price Check / Book.
 *
 * STATUS: written against Rapid's published shape (a shop response whose links
 * carry the price-check href, and an itinerary PUT keyed on an affiliate
 * reference id) and wired into the engine, but NOT yet verified against a live
 * endpoint. Rapid is issued under a partner agreement; field names and the
 * exact link relations are fixed by the version of the specification you are
 * given, and Rapid explicitly requires clients to follow the href returned in
 * the response rather than constructing URLs — which this adapter does.
 *
 * Do not switch this adapter on in production until a test booking has been
 * placed and cancelled end to end in the Rapid test environment.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Expedia_Rapid extends GTC_Provider_Base {

	/** @return string */
	public function get_id() {
		return 'expedia_rapid';
	}

	/** @return string */
	public function get_label() {
		return __( 'Expedia Rapid API', 'gtc' );
	}

	/** @return string[] */
	public function get_categories() {
		return array( GTC_Categories::HOTELS, GTC_Categories::RENTALS );
	}

	/** @return string[] */
	public function required_credentials() {
		return array( 'api_key', 'shared_secret' );
	}

	/**
	 * @param string $path Path.
	 * @return string
	 */
	private function endpoint( $path ) {
		$base = rtrim( $this->credential( 'base_url', 'https://api.ean.com/v3' ), '/' );
		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Rapid signs each request with SHA-512 of key + secret + unix timestamp.
	 * The timestamp must be current, so this is computed per call rather than
	 * cached.
	 *
	 * @return array
	 */
	private function headers() {
		$key       = $this->credential( 'api_key' );
		$secret    = $this->credential( 'shared_secret' );
		$timestamp = time();
		$signature = hash( 'sha512', $key . $secret . $timestamp );

		return array(
			'Authorization' => sprintf( 'EAN APIKey=%s,Signature=%s,timestamp=%d', $key, $signature, $timestamp ),
			'Customer-Ip'   => $this->customer_ip(),
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Rapid requires the end customer's IP, not the server's — it is used for
	 * point-of-sale and fraud checks, and sending the server address for every
	 * request will get results skewed or blocked.
	 *
	 * @return string
	 */
	private function customer_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$value = trim( explode( ',', $value )[0] );
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '0.0.0.0';
	}

	/* ----------------------------------------------------------- search */

	/**
	 * @param GTC_Search_Request $request Search.
	 * @return GTC_Offer[]
	 * @throws GTC_Provider_Exception On supplier error.
	 */
	public function search( GTC_Search_Request $request ) {
		$property_ids = $this->resolve_property_ids( $request );

		if ( ! $property_ids ) {
			return array();
		}

		$query = array(
			'checkin'       => (string) $request->get( 'check_in' ),
			'checkout'      => (string) $request->get( 'check_out' ),
			'currency'      => $request->get_currency(),
			'country_code'  => $this->credential( 'point_of_sale_country', 'US' ),
			'language'      => $request->get_locale(),
			'rate_plan_count' => 4,
			'sales_channel' => 'website',
			'sales_environment' => 'hotel_only',
		);

		// Rapid takes repeated property_id and occupancy parameters, which
		// add_query_arg cannot express, so they are appended by hand.
		$url = add_query_arg( array_map( 'rawurlencode', $query ), $this->endpoint( '/properties/availability' ) );

		foreach ( $property_ids as $id ) {
			$url .= '&property_id=' . rawurlencode( $id );
		}

		foreach ( $this->map_occupancy( $request ) as $occupancy ) {
			$url .= '&occupancy=' . rawurlencode( $occupancy );
		}

		$data = $this->request( 'GET', $url, array( 'headers' => $this->headers() ), 'search' );

		$content = $this->content_for( $property_ids );

		$offers = array();

		foreach ( (array) $data as $property ) {
			if ( ! is_array( $property ) || empty( $property['property_id'] ) ) {
				continue;
			}

			$meta = isset( $content[ $property['property_id'] ] ) ? $content[ $property['property_id'] ] : array();

			foreach ( (array) ( isset( $property['rooms'] ) ? $property['rooms'] : array() ) as $room ) {
				foreach ( (array) ( isset( $room['rates'] ) ? $room['rates'] : array() ) as $rate ) {
					$offer = $this->map_offer( $property, $room, $rate, $meta, $request );
					if ( $offer ) {
						$offers[] = $offer;
					}
				}
			}
		}

		return $offers;
	}

	/**
	 * Rapid prices a list of property ids; it does not take a free-text
	 * destination. Turning "Lisbon" into a property list is a mapping the site
	 * owns, built from Rapid's own property content feed.
	 *
	 * Until that content feed has been ingested there is nothing to price, and
	 * this adapter returns nothing rather than guessing an id.
	 *
	 * @param GTC_Search_Request $request Search.
	 * @return string[]
	 */
	private function resolve_property_ids( GTC_Search_Request $request ) {
		/**
		 * Supply the Rapid property ids for a destination.
		 *
		 * @param string[]           $ids     Property ids.
		 * @param GTC_Search_Request $request Search.
		 */
		$ids = apply_filters( 'gtc_rapid_property_ids', array(), $request );

		return array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
	}

	/**
	 * Descriptive content for a property list, from the site's ingested copy of
	 * Rapid's content feed. Without it the offers are still sellable but carry
	 * no name or coordinates, so they cannot be matched against another
	 * supplier.
	 *
	 * @param string[] $ids Property ids.
	 * @return array
	 */
	private function content_for( array $ids ) {
		/**
		 * Supply property content keyed by Rapid property id.
		 *
		 * @param array    $content Content rows.
		 * @param string[] $ids     Property ids.
		 */
		return (array) apply_filters( 'gtc_rapid_property_content', array(), $ids );
	}

	/**
	 * Rapid expresses occupancy as "adults-childAge,childAge" per room.
	 *
	 * @param GTC_Search_Request $request Search.
	 * @return string[]
	 */
	private function map_occupancy( GTC_Search_Request $request ) {
		$out = array();

		foreach ( (array) $request->get( 'occupancy', array() ) as $room ) {
			$value = (string) (int) $room['adults'];

			if ( ! empty( $room['children'] ) ) {
				$value .= '-' . implode( ',', array_map( 'intval', $room['children'] ) );
			}

			$out[] = $value;
		}

		return $out ? $out : array( '2' );
	}

	/**
	 * @param array              $property Property row.
	 * @param array              $room     Room row.
	 * @param array              $rate     Rate row.
	 * @param array              $meta     Content row.
	 * @param GTC_Search_Request $request  Search.
	 * @return GTC_Offer|null
	 */
	private function map_offer( array $property, array $room, array $rate, array $meta, GTC_Search_Request $request ) {
		$totals = $this->dig( $rate, array( 'occupancy_pricing' ), array() );

		if ( ! is_array( $totals ) || ! $totals ) {
			return null;
		}

		// One rate carries a price per occupancy string; the bookable total is
		// the sum across the rooms searched.
		$currency  = $request->get_currency();
		$inclusive = 0;
		$exclusive = 0;

		foreach ( $totals as $occupancy_price ) {
			$currency   = (string) $this->dig( $occupancy_price, array( 'totals', 'inclusive', 'request_currency', 'currency' ), $currency );
			$inclusive += GTC_Currency::to_minor(
				(float) $this->dig( $occupancy_price, array( 'totals', 'inclusive', 'request_currency', 'value' ), 0 ),
				$currency
			);
			$exclusive += GTC_Currency::to_minor(
				(float) $this->dig( $occupancy_price, array( 'totals', 'exclusive', 'request_currency', 'value' ), 0 ),
				$currency
			);
		}

		if ( $inclusive <= 0 ) {
			return null;
		}

		// Rapid reports an exclusive (net) total and an inclusive total; the
		// difference is tax and fees, and it has to be shown as such rather
		// than folded into the headline rate.
		$base = $exclusive > 0 ? $exclusive : $inclusive;

		$price = new GTC_Price( $currency, $base );

		if ( $exclusive > 0 && $inclusive > $exclusive ) {
			$price->add_component( __( 'Taxes & fees', 'gtc' ), $inclusive - $exclusive, 'tax', 'now' );
		}

		foreach ( (array) $this->dig( $rate, array( 'fees', 'mandatory_fee' ), array() ) as $fee ) {
			$amount = GTC_Currency::to_minor( (float) $this->dig( $fee, array( 'request_currency', 'value' ), 0 ), $currency );
			if ( $amount > 0 ) {
				$price->add_component( __( 'Resort fee (payable at the property)', 'gtc' ), $amount, 'fee', 'at_property' );
			}
		}

		$offer = new GTC_Offer(
			$this->get_id(),
			GTC_Categories::HOTELS,
			(string) $property['property_id'] . ':' . (string) $this->dig( $room, array( 'id' ), '' ) . ':' . (string) $this->dig( $rate, array( 'id' ), '' ),
			$price
		);

		$offer->set_product(
			array(
				'name'         => (string) $this->dig( $meta, array( 'name' ), '' ),
				'property_id'  => (string) $property['property_id'],
				'lat'          => $this->dig( $meta, array( 'location', 'coordinates', 'latitude' ), null ),
				'lng'          => $this->dig( $meta, array( 'location', 'coordinates', 'longitude' ), null ),
				'address'      => (string) $this->dig( $meta, array( 'address', 'line_1' ), '' ),
				'city'         => (string) $this->dig( $meta, array( 'address', 'city' ), '' ),
				'country'      => (string) $this->dig( $meta, array( 'address', 'country_code' ), '' ),
				'star_rating'  => $this->dig( $meta, array( 'ratings', 'property', 'rating' ), null ),
				'review_score' => $this->dig( $meta, array( 'ratings', 'guest', 'overall' ), null ),
				'review_count' => (int) $this->dig( $meta, array( 'ratings', 'guest', 'count' ), 0 ),
				'images'       => array(),
				'amenities'    => array(),
				// Rapid publishes a GIATA id in its content feed where one
				// exists; when present it is the strongest signal the matcher
				// has, so it is carried through verbatim.
				'external_ids' => array_filter(
					array( 'giata' => (string) $this->dig( $meta, array( 'giata_id' ), '' ) )
				),
			)
		);

		$offer->set_rate(
			array(
				'name'         => (string) $this->dig( $room, array( 'room_name' ), __( 'Room', 'gtc' ) ),
				'board'        => $this->map_board( $rate ),
				'refundable'   => (bool) $this->dig( $rate, array( 'refundable' ), false ),
				'cancellation' => $this->map_cancellation( $rate ),
				'inclusions'   => (array) $this->dig( $rate, array( 'amenities' ), array() ),
				'rooms_left'   => (int) $this->dig( $rate, array( 'available_rooms' ), 0 ),
			)
		);

		// Rapid returns the price-check href in the rate's links. Following it
		// is mandatory: the URL is signed and short-lived, and building one by
		// hand is both unsupported and fragile.
		$offer->set_supplier_data(
			array(
				'price_check_href' => (string) $this->dig( $rate, array( 'links', 'price_check', 'href' ), '' ),
				'property_id'      => (string) $property['property_id'],
				'room_id'          => (string) $this->dig( $room, array( 'id' ), '' ),
				'rate_id'          => (string) $this->dig( $rate, array( 'id' ), '' ),
			)
		);

		return $offer;
	}

	/**
	 * @param array $rate Rate row.
	 * @return string
	 */
	private function map_board( array $rate ) {
		$amenities = (array) $this->dig( $rate, array( 'amenities' ), array() );

		foreach ( $amenities as $amenity ) {
			$name = strtolower( is_array( $amenity ) && isset( $amenity['name'] ) ? $amenity['name'] : (string) $amenity );
			if ( false !== strpos( $name, 'all inclusive' ) ) {
				return 'all_inclusive';
			}
			if ( false !== strpos( $name, 'breakfast' ) ) {
				return 'breakfast';
			}
		}

		return 'room_only';
	}

	/**
	 * @param array $rate Rate row.
	 * @return string
	 */
	private function map_cancellation( array $rate ) {
		$penalties = (array) $this->dig( $rate, array( 'cancel_penalties' ), array() );

		if ( ! $penalties ) {
			return (bool) $this->dig( $rate, array( 'refundable' ), false )
				? __( 'Free cancellation.', 'gtc' )
				: __( 'Non-refundable.', 'gtc' );
		}

		$lines = array();

		foreach ( $penalties as $penalty ) {
			$start = (string) $this->dig( $penalty, array( 'start' ), '' );
			$end   = (string) $this->dig( $penalty, array( 'end' ), '' );

			$amount = $this->dig( $penalty, array( 'amount' ), null );
			$nights = $this->dig( $penalty, array( 'nights' ), null );
			$percent = $this->dig( $penalty, array( 'percent' ), null );

			if ( null !== $amount ) {
				$charge = $amount;
			} elseif ( null !== $nights ) {
				/* translators: %s: number of nights */
				$charge = sprintf( __( '%s nights', 'gtc' ), $nights );
			} elseif ( null !== $percent ) {
				$charge = $percent . '%';
			} else {
				continue;
			}

			$lines[] = sprintf(
				/* translators: 1: start date 2: end date 3: penalty */
				__( 'Cancel between %1$s and %2$s: %3$s charged.', 'gtc' ),
				$start,
				$end,
				$charge
			);
		}

		return implode( ' ', $lines );
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

		if ( empty( $supplier['price_check_href'] ) ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				__( 'This rate did not carry a price-check link.', 'gtc' ),
				'missing_price_check'
			);
		}

		$url = $this->absolute( $supplier['price_check_href'] );

		$data = $this->request( 'GET', $url, array( 'headers' => $this->headers() ), 'look' );

		$status = strtolower( (string) $this->dig( $data, array( 'status' ), '' ) );

		if ( 'available' !== $status ) {
			$gone = clone $offer;
			return $gone->mark_unavailable(
				'price_changed' === $status
					? __( 'The supplier has repriced this room.', 'gtc' )
					: __( 'This rate is no longer available.', 'gtc' )
			);
		}

		$currency  = $request->get_currency();
		$inclusive = 0;
		$exclusive = 0;

		foreach ( (array) $this->dig( $data, array( 'occupancy_pricing' ), array() ) as $occupancy_price ) {
			$currency   = (string) $this->dig( $occupancy_price, array( 'totals', 'inclusive', 'request_currency', 'currency' ), $currency );
			$inclusive += GTC_Currency::to_minor( (float) $this->dig( $occupancy_price, array( 'totals', 'inclusive', 'request_currency', 'value' ), 0 ), $currency );
			$exclusive += GTC_Currency::to_minor( (float) $this->dig( $occupancy_price, array( 'totals', 'exclusive', 'request_currency', 'value' ), 0 ), $currency );
		}

		if ( $inclusive <= 0 ) {
			$gone = clone $offer;
			return $gone->mark_unavailable( __( 'The supplier did not return a bookable price.', 'gtc' ) );
		}

		$base  = $exclusive > 0 ? $exclusive : $inclusive;
		$price = new GTC_Price( $currency, $base );

		if ( $exclusive > 0 && $inclusive > $exclusive ) {
			$price->add_component( __( 'Taxes & fees', 'gtc' ), $inclusive - $exclusive, 'tax', 'now' );
		}

		// Carry the at-property fees from the original offer forward: price
		// check reports what we are charged, not what the hotel collects, and
		// dropping them here would quietly shrink the displayed trip cost.
		foreach ( $offer->get_price()->get_components() as $component ) {
			if ( 'at_property' === $component['payable'] ) {
				$price->add_component( $component['label'], $component['amount'], $component['type'], 'at_property' );
			}
		}

		$fresh = clone $offer;
		$fresh->set_price( $price );

		$fresh->set_supplier_data(
			array_merge(
				$supplier,
				array( 'book_href' => (string) $this->dig( $data, array( 'links', 'book', 'href' ), '' ) )
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

		if ( empty( $supplier['book_href'] ) ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				__( 'This booking was not revalidated immediately before payment.', 'gtc' ),
				'missing_book_link'
			);
		}

		$lead = isset( $booking->travellers[0] ) ? $booking->travellers[0] : array();

		$body = array(
			'affiliate_reference_id' => substr( $booking->reference, 0, 28 ),
			'hold'                   => false,
			'email'                  => isset( $booking->contact['email'] ) ? $booking->contact['email'] : '',
			'phone'                  => array(
				'country_code' => isset( $booking->contact['phone_country'] ) ? $booking->contact['phone_country'] : '',
				'number'       => isset( $booking->contact['phone'] ) ? $booking->contact['phone'] : '',
			),
			'rooms'                  => $this->map_rooms( $booking ),
		);

		unset( $lead );

		$data = $this->request( 'POST', $this->absolute( $supplier['book_href'] ), array(
			'headers' => array_merge(
				$this->headers(),
				// Rapid deduplicates on the affiliate reference id; sending the
				// same one twice returns the original itinerary rather than
				// creating a second booking.
				array( 'Transaction-Id' => $booking->get_idempotency_key() )
			),
			'body'    => $body,
		), 'book' );

		$itinerary = (string) $this->dig( $data, array( 'itinerary_id' ), '' );

		if ( '' === $itinerary ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				__( 'The supplier accepted the request but returned no itinerary id.', 'gtc' ),
				'no_itinerary_id'
			);
		}

		return array(
			'supplier_reference' => $itinerary,
			'status'             => 'confirmed',
			'raw'                => $data,
		);
	}

	/**
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 */
	private function map_rooms( GTC_Booking $booking ) {
		$rooms = array();

		foreach ( (array) $booking->travellers as $traveller ) {
			$rooms[] = array(
				'given_name'  => isset( $traveller['first_name'] ) ? $traveller['first_name'] : '',
				'family_name' => isset( $traveller['last_name'] ) ? $traveller['last_name'] : '',
				'smoking'     => false,
			);
		}

		return $rooms ? array( $rooms[0] ) : array();
	}

	/**
	 * Rapid's links are sometimes relative to the API host.
	 *
	 * @param string $href Link.
	 * @return string
	 */
	private function absolute( $href ) {
		if ( 0 === strpos( $href, 'http' ) ) {
			return $href;
		}

		$base = wp_parse_url( $this->endpoint( '/' ) );

		return $base['scheme'] . '://' . $base['host'] . '/' . ltrim( $href, '/' );
	}

	/**
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
