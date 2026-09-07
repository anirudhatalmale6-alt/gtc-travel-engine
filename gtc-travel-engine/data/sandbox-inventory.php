<?php
/**
 * Sandbox inventory.
 *
 * Fictional properties used by the two sandbox adapters so the whole platform
 * — comparison, duplicate matching, revalidation, booking — can be exercised
 * before any supplier contract is in place. The property names are invented;
 * only the city names are real, so nothing here can be mistaken for a genuine
 * price for a genuine hotel.
 *
 * Each property declares how each sandbox supplier presents it: a different
 * name, slightly different coordinates and a different rate. That is what the
 * duplicate matcher has to see through.
 *
 * Deliberate cases:
 *   lis-alfama-terrace / lis-alfama-hill   60m apart, different properties.
 *                                          Must NOT merge.
 *   bcn-gothic-quarters                    Names share almost nothing but both
 *                                          suppliers publish the same GIATA id.
 *                                          Must merge on the id.
 *   dxb-marina-two-towers                  Adjacent towers, near-identical
 *                                          names, but the suppliers publish
 *                                          different GIATA ids. Must NOT merge.
 *   lis-baixa-pearl                        Sold by one supplier only.
 *   lis-graca-loft                         Price rises at Look — exercises the
 *                                          price-change path at checkout.
 *   bcn-eixample-atrium                    Sells out at Look — exercises the
 *                                          unavailable path at checkout.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

return array(

	/* ------------------------------------------------------------- Lisbon */

	array(
		'canonical'    => 'lis-azulejo',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Rua do Comercio 12',
		'lat'          => 38.70750,
		'lng'          => -9.13640,
		'stars'        => 4.0,
		'review_score' => 8.6,
		'review_count' => 1284,
		'amenities'    => array( 'Free WiFi', 'Rooftop pool', 'Spa', 'Airport shuttle', 'Restaurant' ),
		'nightly'      => 14200,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Azulejo Riverside Hotel & Spa',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array( 'giata' => '700411' ),
			),
			'sandbox_beta'  => array(
				'name'         => 'Hotel Azulejo Riverside',
				'lat_off'      => 0.00040,
				'lng_off'      => -0.00030,
				'rate_mult'    => 1.11,
				'external_ids' => array( 'giata' => '700411' ),
			),
		),
	),

	array(
		'canonical'    => 'lis-miradouro',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Largo da Graca 41',
		'lat'          => 38.71620,
		'lng'          => -9.13010,
		'stars'        => 5.0,
		'review_score' => 9.1,
		'review_count' => 2140,
		'amenities'    => array( 'Free WiFi', 'City view', 'Fitness centre', 'Bar', 'Concierge' ),
		'nightly'      => 22600,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Miradouro Grand Lisboa',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.06,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Grand Miradouro Lisboa',
				'lat_off'      => -0.00055,
				'lng_off'      => 0.00042,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),

	array(
		'canonical'    => 'lis-cais',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Cais do Sodre 8',
		'lat'          => 38.70560,
		'lng'          => -9.14520,
		'stars'        => 3.0,
		'review_score' => 8.2,
		'review_count' => 764,
		'amenities'    => array( 'Free WiFi', 'Breakfast available', '24h reception' ),
		'nightly'      => 9800,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Cais do Sodre Boutique Rooms',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Boutique Cais do Sodre',
				'lat_off'      => 0.00028,
				'lng_off'      => 0.00031,
				'rate_mult'    => 0.94,
				'external_ids' => array(),
			),
		),
	),

	// Negative control: two genuinely different properties, 60m apart.
	array(
		'canonical'    => 'lis-alfama-terrace',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Rua dos Remedios 22',
		'lat'          => 38.71180,
		'lng'          => -9.12690,
		'stars'        => 4.0,
		'review_score' => 8.8,
		'review_count' => 431,
		'amenities'    => array( 'Free WiFi', 'Terrace', 'Air conditioning' ),
		'nightly'      => 16400,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Alfama Terrace Suites',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Alfama Terrace Suites',
				'lat_off'      => 0.00020,
				'lng_off'      => 0.00018,
				'rate_mult'    => 1.05,
				'external_ids' => array(),
			),
		),
	),

	array(
		'canonical'    => 'lis-alfama-hill',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Rua dos Remedios 40',
		'lat'          => 38.71232,
		'lng'          => -9.12712,
		'stars'        => 3.0,
		'review_score' => 8.0,
		'review_count' => 198,
		'amenities'    => array( 'Free WiFi', 'Kitchenette' ),
		'nightly'      => 11900,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Alfama Hill Residences',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),

	// Sold by one supplier only.
	array(
		'canonical'    => 'lis-baixa-pearl',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Praca da Figueira 3',
		'lat'          => 38.71390,
		'lng'          => -9.13740,
		'stars'        => 4.0,
		'review_score' => 8.4,
		'review_count' => 902,
		'amenities'    => array( 'Free WiFi', 'Restaurant', 'Laundry' ),
		'nightly'      => 13100,
		'suppliers'    => array(
			'sandbox_beta' => array(
				'name'         => 'Baixa Pearl Hotel',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),

	// Price rises between Search and Look.
	array(
		'canonical'    => 'lis-graca-loft',
		'city'         => 'Lisbon',
		'country'      => 'PT',
		'address'      => 'Calcada da Graca 17',
		'lat'          => 38.71710,
		'lng'          => -9.13290,
		'stars'        => 4.0,
		'review_score' => 8.9,
		'review_count' => 356,
		'amenities'    => array( 'Free WiFi', 'City view', 'Self check-in' ),
		'nightly'      => 12700,
		'look_behaviour' => 'price_up',
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Graca Loft Apartments',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Loft Apartments Graca',
				'lat_off'      => 0.00035,
				'lng_off'      => 0.00025,
				'rate_mult'    => 1.07,
				'external_ids' => array(),
			),
		),
	),

	/* ---------------------------------------------------------- Barcelona */

	// Names share almost nothing; the shared GIATA id is authoritative.
	array(
		'canonical'    => 'bcn-gothic-quarters',
		'city'         => 'Barcelona',
		'country'      => 'ES',
		'address'      => 'Carrer dels Banys Nous 14',
		'lat'          => 41.38230,
		'lng'          => 2.17450,
		'stars'        => 4.0,
		'review_score' => 8.7,
		'review_count' => 1731,
		'amenities'    => array( 'Free WiFi', 'Rooftop bar', 'Air conditioning', 'Bicycle hire' ),
		'nightly'      => 18400,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Quarters Gothic Barcelona',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array( 'giata' => '812004' ),
			),
			'sandbox_beta'  => array(
				'name'         => 'Banys Nous Residence',
				'lat_off'      => 0.00048,
				'lng_off'      => 0.00051,
				'rate_mult'    => 1.13,
				'external_ids' => array( 'giata' => '812004' ),
			),
		),
	),

	// Sells out between Search and Look.
	array(
		'canonical'      => 'bcn-eixample-atrium',
		'city'           => 'Barcelona',
		'country'        => 'ES',
		'address'        => 'Carrer de Mallorca 220',
		'lat'            => 41.39310,
		'lng'            => 2.15870,
		'stars'          => 4.0,
		'review_score'   => 8.5,
		'review_count'   => 1188,
		'amenities'      => array( 'Free WiFi', 'Pool', 'Gym' ),
		'nightly'        => 15900,
		'look_behaviour' => 'sold_out',
		'suppliers'      => array(
			'sandbox_alpha' => array(
				'name'         => 'Eixample Atrium Hotel',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),

	array(
		'canonical'    => 'bcn-barceloneta',
		'city'         => 'Barcelona',
		'country'      => 'ES',
		'address'      => 'Passeig de Joan de Borbo 61',
		'lat'          => 41.37690,
		'lng'          => 2.19020,
		'stars'        => 3.0,
		'review_score' => 8.1,
		'review_count' => 640,
		'amenities'    => array( 'Free WiFi', 'Beachfront', 'Breakfast available' ),
		'nightly'      => 12200,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Barceloneta Sea Rooms',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.02,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Sea Rooms Barceloneta',
				'lat_off'      => -0.00033,
				'lng_off'      => 0.00027,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),

	/* -------------------------------------------------------------- Dubai */

	// Adjacent towers under near-identical names. The suppliers publish
	// different GIATA ids, which is a positive statement that they differ.
	array(
		'canonical'    => 'dxb-marina-tower-one',
		'city'         => 'Dubai',
		'country'      => 'AE',
		'address'      => 'Marina Walk, Tower One',
		'lat'          => 25.08010,
		'lng'          => 55.14020,
		'stars'        => 5.0,
		'review_score' => 9.0,
		'review_count' => 3021,
		'amenities'    => array( 'Free WiFi', 'Infinity pool', 'Spa', 'Valet parking', 'Marina view' ),
		'nightly'      => 31500,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Marina Two Towers Hotel — Tower One',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array( 'giata' => '904881' ),
			),
		),
	),

	array(
		'canonical'    => 'dxb-marina-tower-two',
		'city'         => 'Dubai',
		'country'      => 'AE',
		'address'      => 'Marina Walk, Tower Two',
		'lat'          => 25.08048,
		'lng'          => 55.14061,
		'stars'        => 5.0,
		'review_score' => 8.8,
		'review_count' => 1442,
		'amenities'    => array( 'Free WiFi', 'Infinity pool', 'Spa', 'Marina view' ),
		'nightly'      => 28900,
		'suppliers'    => array(
			'sandbox_beta' => array(
				'name'         => 'Marina Two Towers Hotel — Tower Two',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.00,
				'external_ids' => array( 'giata' => '904882' ),
			),
		),
	),

	array(
		'canonical'    => 'dxb-creek-court',
		'city'         => 'Dubai',
		'country'      => 'AE',
		'address'      => 'Baniyas Road 9',
		'lat'          => 25.26410,
		'lng'          => 55.31200,
		'stars'        => 4.0,
		'review_score' => 8.3,
		'review_count' => 877,
		'amenities'    => array( 'Free WiFi', 'Pool', 'Airport shuttle', 'Restaurant' ),
		'nightly'      => 19800,
		'suppliers'    => array(
			'sandbox_alpha' => array(
				'name'         => 'Creek Court Hotel Dubai',
				'lat_off'      => 0.0,
				'lng_off'      => 0.0,
				'rate_mult'    => 1.08,
				'external_ids' => array(),
			),
			'sandbox_beta'  => array(
				'name'         => 'Creek Court Dubai',
				'lat_off'      => 0.00025,
				'lng_off'      => -0.00041,
				'rate_mult'    => 1.00,
				'external_ids' => array(),
			),
		),
	),
);
