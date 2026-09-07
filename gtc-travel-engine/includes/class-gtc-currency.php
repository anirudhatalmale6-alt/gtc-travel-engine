<?php
/**
 * Currency formatting and conversion.
 *
 * Conversion deliberately has no built-in rate source: shipping hard-coded or
 * scraped rates would misprice real bookings. A rate provider is injected via
 * the gtc_fx_rate filter (ECB feed, the supplier's own multi-currency
 * response, or your treasury rate). Until one is wired, conversion is refused
 * rather than guessed, and offers are shown in the currency the supplier
 * quoted.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Currency {

	/**
	 * Minor-unit exponent per currency. Anything not listed is assumed 2.
	 *
	 * @var array<string,int>
	 */
	private static $exponents = array(
		'JPY' => 0,
		'KRW' => 0,
		'VND' => 0,
		'CLP' => 0,
		'ISK' => 0,
		'HUF' => 0,
		'TWD' => 0,
		'BHD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * @var array<string,string>
	 */
	private static $symbols = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'INR' => '₹',
		'AED' => 'AED ',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'JPY' => '¥',
		'SGD' => 'S$',
		'CHF' => 'CHF ',
		'ZAR' => 'R',
	);

	/**
	 * @param string $currency ISO 4217.
	 * @return int
	 */
	public static function exponent( $currency ) {
		$currency = strtoupper( $currency );
		return isset( self::$exponents[ $currency ] ) ? self::$exponents[ $currency ] : 2;
	}

	/**
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217.
	 * @return string
	 */
	public static function format( $minor, $currency ) {
		$currency = strtoupper( $currency );
		$exp      = self::exponent( $currency );
		$divisor  = pow( 10, $exp );
		$major    = $minor / $divisor;
		$symbol   = isset( self::$symbols[ $currency ] ) ? self::$symbols[ $currency ] : $currency . ' ';
		$negative = $major < 0;
		$body     = number_format( abs( $major ), $exp, '.', ',' );

		return ( $negative ? '-' : '' ) . $symbol . $body;
	}

	/**
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217.
	 * @return float Major units.
	 */
	public static function to_major( $minor, $currency ) {
		return $minor / pow( 10, self::exponent( $currency ) );
	}

	/**
	 * @param float  $major    Major units.
	 * @param string $currency ISO 4217.
	 * @return int Minor units.
	 */
	public static function to_minor( $major, $currency ) {
		return (int) round( ( (float) $major ) * pow( 10, self::exponent( $currency ) ) );
	}

	/**
	 * Convert between currencies using an injected rate.
	 *
	 * @param int    $minor Amount in minor units of $from.
	 * @param string $from  Source currency.
	 * @param string $to    Target currency.
	 * @return int|null Minor units of $to, or null when no rate source answered.
	 */
	public static function convert( $minor, $from, $to ) {
		$from = strtoupper( $from );
		$to   = strtoupper( $to );

		if ( $from === $to ) {
			return (int) $minor;
		}

		/**
		 * Supply an FX rate. Return a float (units of $to per 1 unit of $from)
		 * or null to decline.
		 *
		 * @param float|null $rate Rate.
		 * @param string     $from Source currency.
		 * @param string     $to   Target currency.
		 */
		$rate = apply_filters( 'gtc_fx_rate', null, $from, $to );

		if ( ! is_numeric( $rate ) || $rate <= 0 ) {
			return null;
		}

		$major_from = self::to_major( $minor, $from );
		return self::to_minor( $major_from * (float) $rate, $to );
	}
}
