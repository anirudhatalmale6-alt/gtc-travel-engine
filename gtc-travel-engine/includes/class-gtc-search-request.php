<?php
/**
 * A normalised search. Every adapter receives this, never raw $_GET.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Search_Request {

	/** @var string */
	private $category;

	/** @var array */
	private $params;

	/** @var string */
	private $currency;

	/** @var string */
	private $locale;

	/**
	 * @param string $category Category slug.
	 * @param array  $params   Category-specific parameters, already sanitised.
	 * @param string $currency ISO 4217 code the customer is shopping in.
	 * @param string $locale   BCP-47 locale.
	 */
	public function __construct( $category, array $params, $currency = 'USD', $locale = 'en-gb' ) {
		$this->category = $category;
		$this->params   = $params;
		$this->currency = strtoupper( $currency );
		$this->locale   = $locale;
	}

	/**
	 * Build from a raw request array, sanitising per the category's field list.
	 *
	 * @param array $raw Raw input.
	 * @return GTC_Search_Request
	 * @throws InvalidArgumentException When the category is unknown.
	 */
	public static function from_array( array $raw ) {
		$category = isset( $raw['category'] ) ? sanitize_key( $raw['category'] ) : GTC_Categories::HOTELS;

		if ( ! GTC_Categories::exists( $category ) ) {
			throw new InvalidArgumentException( 'Unknown travel category: ' . $category );
		}

		$all    = GTC_Categories::all();
		$params = array();

		foreach ( $all[ $category ]['fields'] as $field ) {
			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}
			$params[ $field ] = self::sanitize_field( $field, $raw[ $field ] );
		}

		// Occupancy is structured: rooms => [ adults, children[ages] ].
		if ( isset( $raw['occupancy'] ) && is_array( $raw['occupancy'] ) ) {
			$params['occupancy'] = self::sanitize_occupancy( $raw['occupancy'] );
		} elseif ( in_array( 'occupancy', $all[ $category ]['fields'], true ) ) {
			$params['occupancy'] = self::sanitize_occupancy( array() );
		}

		$currency = isset( $raw['currency'] ) ? sanitize_text_field( $raw['currency'] ) : gtc()->settings()->get( 'default_currency', 'USD' );
		$locale   = isset( $raw['locale'] ) ? sanitize_text_field( $raw['locale'] ) : 'en-gb';

		return new self( $category, $params, $currency, $locale );
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_field( $field, $value ) {
		$dates = array( 'check_in', 'check_out', 'depart', 'return', 'date', 'pickup_at', 'dropoff_at' );

		if ( in_array( $field, $dates, true ) ) {
			$value = sanitize_text_field( (string) $value );
			// Accept Y-m-d and Y-m-d\TH:i.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $value ) ) {
				return $value;
			}
			return '';
		}

		if ( in_array( $field, array( 'pax', 'nights', 'driver_age' ), true ) ) {
			return max( 1, absint( $value ) );
		}

		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Normalise occupancy into a predictable shape: at least one room,
	 * at least one adult, child ages clamped to 0-17.
	 *
	 * @param array $rooms Raw rooms.
	 * @return array
	 */
	private static function sanitize_occupancy( array $rooms ) {
		$out = array();

		foreach ( $rooms as $room ) {
			$adults   = isset( $room['adults'] ) ? max( 1, absint( $room['adults'] ) ) : 2;
			$children = array();
			if ( isset( $room['children'] ) && is_array( $room['children'] ) ) {
				foreach ( $room['children'] as $age ) {
					$children[] = min( 17, absint( $age ) );
				}
			}
			$out[] = array(
				'adults'   => $adults,
				'children' => $children,
			);
		}

		if ( ! $out ) {
			$out[] = array(
				'adults'   => 2,
				'children' => array(),
			);
		}

		return $out;
	}

	/** @return string */
	public function get_category() {
		return $this->category;
	}

	/**
	 * @param string $key     Parameter name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : $default;
	}

	/** @return array */
	public function all() {
		return $this->params;
	}

	/** @return string */
	public function get_currency() {
		return $this->currency;
	}

	/** @return string */
	public function get_locale() {
		return $this->locale;
	}

	/**
	 * Total guests across all rooms — used by adapters that price per person.
	 *
	 * @return int
	 */
	public function get_guest_count() {
		$rooms = $this->get( 'occupancy' );
		if ( ! is_array( $rooms ) ) {
			return (int) $this->get( 'pax', 1 );
		}
		$n = 0;
		foreach ( $rooms as $room ) {
			$n += (int) $room['adults'] + count( $room['children'] );
		}
		return max( 1, $n );
	}

	/** @return int */
	public function get_room_count() {
		$rooms = $this->get( 'occupancy' );
		return is_array( $rooms ) ? max( 1, count( $rooms ) ) : 1;
	}

	/**
	 * Length of stay in nights, 0 when the category has no stay.
	 *
	 * @return int
	 */
	public function get_nights() {
		$in  = $this->get( 'check_in' );
		$out = $this->get( 'check_out' );

		if ( ! $in || ! $out ) {
			return (int) $this->get( 'nights', 0 );
		}

		$a = strtotime( $in );
		$b = strtotime( $out );

		if ( ! $a || ! $b || $b <= $a ) {
			return 0;
		}

		return (int) round( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * Stable hash of the search. Two identical searches share a cache entry
	 * and an offer namespace; changing any parameter invalidates both.
	 *
	 * @return string
	 */
	public function get_hash() {
		$payload = array(
			'category' => $this->category,
			'params'   => $this->params,
			'currency' => $this->currency,
			'locale'   => $this->locale,
		);
		self::ksort_deep( $payload );
		return substr( hash( 'sha256', wp_json_encode( $payload ) ), 0, 32 );
	}

	/**
	 * @param array $arr Array to sort in place, recursively, by key.
	 */
	private static function ksort_deep( array &$arr ) {
		ksort( $arr );
		foreach ( $arr as &$v ) {
			if ( is_array( $v ) ) {
				self::ksort_deep( $v );
			}
		}
	}

	/** @return array */
	public function to_array() {
		return array(
			'category' => $this->category,
			'params'   => $this->params,
			'currency' => $this->currency,
			'locale'   => $this->locale,
		);
	}

	/**
	 * @param array $data Output of to_array().
	 * @return GTC_Search_Request
	 */
	public static function from_stored( array $data ) {
		return new self(
			$data['category'],
			isset( $data['params'] ) ? (array) $data['params'] : array(),
			isset( $data['currency'] ) ? $data['currency'] : 'USD',
			isset( $data['locale'] ) ? $data['locale'] : 'en-gb'
		);
	}

	/**
	 * Human summary used in headers, emails and the booking record.
	 *
	 * @return string
	 */
	public function describe() {
		switch ( $this->category ) {
			case GTC_Categories::HOTELS:
			case GTC_Categories::RENTALS:
				return sprintf(
					/* translators: 1: destination 2: check-in 3: check-out 4: nights 5: guests */
					__( '%1$s · %2$s to %3$s · %4$d nights · %5$d guests', 'gtc' ),
					$this->get( 'destination', '' ),
					$this->get( 'check_in', '' ),
					$this->get( 'check_out', '' ),
					$this->get_nights(),
					$this->get_guest_count()
				);
			case GTC_Categories::FLIGHTS:
				return sprintf(
					'%s → %s · %s',
					$this->get( 'origin', '' ),
					$this->get( 'destination', '' ),
					$this->get( 'depart', '' )
				);
			default:
				return GTC_Categories::label( $this->category );
		}
	}
}
