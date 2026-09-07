<?php
/**
 * The category registry. Categories are modules: the engine, cache, dedupe,
 * checkout and booking store are all category-agnostic, so switching a
 * category on is a matter of having an adapter that declares it.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Categories {

	const HOTELS     = 'hotels';
	const FLIGHTS    = 'flights';
	const RENTALS    = 'vacation_rentals';
	const CARS       = 'car_rentals';
	const TRANSFERS  = 'airport_transfers';
	const ACTIVITIES = 'activities';
	const INSURANCE  = 'travel_insurance';
	const CRUISES    = 'cruises';

	/**
	 * All categories the platform models, with the search fields each needs.
	 *
	 * @return array
	 */
	public static function all() {
		$categories = array(
			self::HOTELS     => array(
				'label'  => __( 'Hotels & accommodation', 'gtc' ),
				'fields' => array( 'destination', 'check_in', 'check_out', 'occupancy' ),
				'icon'   => 'building',
			),
			self::FLIGHTS    => array(
				'label'  => __( 'Flights', 'gtc' ),
				'fields' => array( 'origin', 'destination', 'depart', 'return', 'pax', 'cabin' ),
				'icon'   => 'plane',
			),
			self::RENTALS    => array(
				'label'  => __( 'Vacation rentals', 'gtc' ),
				'fields' => array( 'destination', 'check_in', 'check_out', 'occupancy' ),
				'icon'   => 'home',
			),
			self::CARS       => array(
				'label'  => __( 'Car rentals', 'gtc' ),
				'fields' => array( 'pickup', 'dropoff', 'pickup_at', 'dropoff_at', 'driver_age' ),
				'icon'   => 'car',
			),
			self::TRANSFERS  => array(
				'label'  => __( 'Airport transfers', 'gtc' ),
				'fields' => array( 'pickup', 'dropoff', 'pickup_at', 'pax' ),
				'icon'   => 'shuttle',
			),
			self::ACTIVITIES => array(
				'label'  => __( 'Tours, attractions & activities', 'gtc' ),
				'fields' => array( 'destination', 'date', 'pax' ),
				'icon'   => 'ticket',
			),
			self::INSURANCE  => array(
				'label'  => __( 'Travel insurance', 'gtc' ),
				'fields' => array( 'residency', 'destination', 'depart', 'return', 'ages' ),
				'icon'   => 'shield',
			),
			self::CRUISES    => array(
				'label'  => __( 'Cruises', 'gtc' ),
				'fields' => array( 'region', 'depart_month', 'nights', 'occupancy' ),
				'icon'   => 'ship',
			),
		);

		return apply_filters( 'gtc_categories', $categories );
	}

	/**
	 * Categories that have at least one ready adapter behind them.
	 *
	 * @return array
	 */
	public static function available() {
		$out = array();
		foreach ( self::all() as $slug => $meta ) {
			if ( gtc()->providers()->for_category( $slug ) ) {
				$out[ $slug ] = $meta;
			}
		}
		return $out;
	}

	/**
	 * @param string $slug Category slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return isset( self::all()[ $slug ] );
	}

	/**
	 * @param string $slug Category slug.
	 * @return string
	 */
	public static function label( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ]['label'] : $slug;
	}
}
