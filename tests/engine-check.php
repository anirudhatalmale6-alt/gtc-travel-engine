<?php
/**
 * Engine self-check.
 *
 * Runs the whole path — fan-out, normalisation, duplicate matching,
 * revalidation, payment, supplier book, confirmation — against the two sandbox
 * suppliers and asserts the outcomes that actually matter.
 *
 * Every duplicate-matching assertion is paired: one that must merge and one
 * that must NOT. A matcher that merged everything would pass the first kind
 * alone, so the negative controls are what give the positives meaning.
 *
 * Run with:  php wp-cli.phar eval-file tests/engine-check.php --path=site
 */

defined( 'ABSPATH' ) || die( 'Run through wp-cli eval-file.' );

/**
 * Counters live on a static rather than in a global, because wp-cli's
 * eval-file runs this body inside a function scope — a `global $pass` in the
 * helper and a bare `$pass` out here would be two different variables, and the
 * summary would report zero however many assertions ran.
 */
class GTC_Check {

	public static $pass = 0;
	public static $fail = 0;

	/**
	 * @param string $label  What is being asserted.
	 * @param bool   $actual Result.
	 * @param string $detail Extra context printed either way.
	 */
	public static function assert( $label, $actual, $detail = '' ) {
		if ( $actual ) {
			self::$pass++;
			echo "  PASS  " . $label . ( $detail ? "  [{$detail}]" : '' ) . "\n";
		} else {
			self::$fail++;
			echo "  FAIL  " . $label . ( $detail ? "  [{$detail}]" : '' ) . "\n";
		}
	}
}

/**
 * @param string $label  What is being asserted.
 * @param bool   $actual Result.
 * @param string $detail Extra context.
 */
function gtc_check( $label, $actual, $detail = '' ) {
	GTC_Check::assert( $label, $actual, $detail );
}

/**
 * @param GTC_Offer_Group[] $groups Groups.
 * @param string            $needle Substring of a product name.
 * @return GTC_Offer_Group|null
 */
function gtc_find_group( array $groups, $needle ) {
	foreach ( $groups as $group ) {
		foreach ( $group->all() as $offer ) {
			if ( false !== stripos( (string) $offer->product( 'name' ), $needle ) ) {
				return $group;
			}
		}
	}
	return null;
}

echo "\n=== GTC engine self-check ===\n\n";

/* ------------------------------------------------------------- setup */

gtc()->settings()->update(
	array(
		'enabled_providers' => array( 'sandbox_alpha', 'sandbox_beta' ),
		'markup_type'       => 'percent',
		'markup_value'      => 8,
		'markup_label'      => 'Service fee',
		'search_cache_ttl'  => 0, // Cache off: every assertion must reflect a live call.
	)
);

gtc()->cache()->flush();

$check_in  = gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) );
$check_out = gmdate( 'Y-m-d', time() + ( 33 * DAY_IN_SECONDS ) );

$request = new GTC_Search_Request(
	GTC_Categories::HOTELS,
	array(
		'destination' => 'Lisbon',
		'check_in'    => $check_in,
		'check_out'   => $check_out,
		'occupancy'   => array( array( 'adults' => 2, 'children' => array() ) ),
	),
	'USD'
);

echo "Search: Lisbon, {$check_in} → {$check_out}, 2 adults, 3 nights\n\n";

$result = ( new GTC_Aggregator() )->search( $request );
$groups = $result->get_groups();

echo "-- fan-out --\n";

gtc_check(
	'both sandbox suppliers answered',
	2 === count( $result->get_providers() ),
	implode( ', ', array_keys( $result->get_providers() ) )
);

gtc_check( 'no supplier failed', 0 === count( $result->get_failures() ) );
gtc_check( 'offers were returned', $result->get_offer_count() > 0, $result->get_offer_count() . ' rates' );

echo "\n-- duplicate matching --\n";

// POSITIVE: different names, ~50m apart, both suppliers. Must be one card.
$azulejo = gtc_find_group( $groups, 'Azulejo' );
gtc_check(
	'"Azulejo Riverside Hotel & Spa" and "Hotel Azulejo Riverside" merged',
	$azulejo && 2 === $azulejo->provider_count(),
	$azulejo ? $azulejo->provider_count() . ' suppliers on the card' : 'group not found'
);

// POSITIVE: word order reversed.
$miradouro = gtc_find_group( $groups, 'Miradouro' );
gtc_check(
	'"Miradouro Grand Lisboa" and "Grand Miradouro Lisboa" merged',
	$miradouro && 2 === $miradouro->provider_count(),
	$miradouro ? $miradouro->provider_count() . ' suppliers' : 'group not found'
);

// NEGATIVE: two genuinely different properties 60m apart. Must stay separate.
$terrace = gtc_find_group( $groups, 'Alfama Terrace' );
$hill    = gtc_find_group( $groups, 'Alfama Hill' );

gtc_check(
	'"Alfama Terrace Suites" and "Alfama Hill Residences" NOT merged',
	$terrace && $hill && $terrace !== $hill,
	$terrace && $hill ? 'separate cards' : 'one or both missing'
);

// The dedupe count must be a real number, not a claim.
gtc_check(
	'merged count is reported',
	$result->get_merged_count() > 0,
	$result->get_merged_count() . ' duplicate listings removed'
);

gtc_check(
	'grouping reduced the card count below the raw property count',
	$result->get_group_count() < $result->get_offer_count(),
	$result->get_group_count() . ' cards from ' . $result->get_offer_count() . ' rates'
);

echo "\n-- duplicate matching across cities (GIATA) --\n";

$bcn = ( new GTC_Aggregator() )->search(
	new GTC_Search_Request(
		GTC_Categories::HOTELS,
		array(
			'destination' => 'Barcelona',
			'check_in'    => $check_in,
			'check_out'   => $check_out,
			'occupancy'   => array( array( 'adults' => 2, 'children' => array() ) ),
		),
		'USD'
	)
);

// POSITIVE: names share nothing, but both suppliers publish the same GIATA id.
$gothic = gtc_find_group( $bcn->get_groups(), 'Quarters Gothic' );
gtc_check(
	'"Quarters Gothic Barcelona" and "Banys Nous Residence" merged on a shared GIATA id',
	$gothic && 2 === $gothic->provider_count(),
	$gothic ? $gothic->provider_count() . ' suppliers' : 'group not found'
);

gtc_check(
	'dissimilar names alone would not have merged them',
	( new GTC_Dedupe() )->name_similarity( 'Quarters Gothic Barcelona', 'Banys Nous Residence' ) < 0.82,
	sprintf( 'name similarity %.2f', ( new GTC_Dedupe() )->name_similarity( 'Quarters Gothic Barcelona', 'Banys Nous Residence' ) )
);

$dxb = ( new GTC_Aggregator() )->search(
	new GTC_Search_Request(
		GTC_Categories::HOTELS,
		array(
			'destination' => 'Dubai',
			'check_in'    => $check_in,
			'check_out'   => $check_out,
			'occupancy'   => array( array( 'adults' => 2, 'children' => array() ) ),
		),
		'USD'
	)
);

// NEGATIVE: near-identical names, 60m apart, but the GIATA ids disagree.
$tower_one = gtc_find_group( $dxb->get_groups(), 'Tower One' );
$tower_two = gtc_find_group( $dxb->get_groups(), 'Tower Two' );

gtc_check(
	'adjacent towers with near-identical names NOT merged (GIATA ids disagree)',
	$tower_one && $tower_two && $tower_one !== $tower_two,
	$tower_one && $tower_two ? 'separate cards' : 'one or both missing'
);

gtc_check(
	'their names alone WOULD have merged them',
	( new GTC_Dedupe() )->name_similarity(
		'Marina Two Towers Hotel — Tower One',
		'Marina Two Towers Hotel — Tower Two'
	) >= 0.82,
	sprintf(
		'name similarity %.2f',
		( new GTC_Dedupe() )->name_similarity(
			'Marina Two Towers Hotel — Tower One',
			'Marina Two Towers Hotel — Tower Two'
		)
	)
);

echo "\n-- like-for-like supplier comparison --\n";

$compare_group = $azulejo ? $azulejo : ( $miradouro ? $miradouro : null );

if ( $compare_group ) {
	$rows = $compare_group->comparisons();

	gtc_check(
		'the card is split into comparable classes',
		count( $rows ) > 0,
		count( $rows ) . ' classes'
	);

	$multi = 0;
	foreach ( $rows as $row ) {
		if ( $row['supplier_count'] > 1 ) {
			$multi++;
		}
	}

	gtc_check(
		'at least one class holds both suppliers',
		$multi > 0,
		$multi . ' comparable classes'
	);

	// A class must never hold the same supplier twice, or the "comparison"
	// is one supplier's two rate plans sitting next to each other.
	$one_per_supplier = true;
	foreach ( $rows as $row ) {
		$seen = array();
		foreach ( $row['offers'] as $offer ) {
			if ( isset( $seen[ $offer->get_provider_id() ] ) ) {
				$one_per_supplier = false;
			}
			$seen[ $offer->get_provider_id() ] = true;
		}
	}
	gtc_check( 'each class holds at most one rate per supplier', $one_per_supplier );

	// The point of the class key: everything inside a class must be the same
	// product on all three axes.
	$homogeneous = true;
	$mismatch    = '';
	foreach ( $rows as $row ) {
		$signature = null;
		foreach ( $row['offers'] as $offer ) {
			$this_sig = GTC_Offer_Group::room_grade( (string) $offer->rate( 'name' ) )
				. '|' . $offer->rate( 'board' )
				. '|' . ( $offer->rate( 'refundable' ) ? '1' : '0' );

			if ( null === $signature ) {
				$signature = $this_sig;
			} elseif ( $signature !== $this_sig ) {
				$homogeneous = false;
				$mismatch    = $signature . ' vs ' . $this_sig;
			}
		}
	}
	gtc_check(
		'every class is one grade, one board basis, one cancellation policy',
		$homogeneous,
		$homogeneous ? 'consistent' : $mismatch
	);

	// Negative control for the grade axis. Without grade in the key these two
	// land in the same class and their price gap is reported as a saving.
	gtc_check(
		'a superior room is not compared against a standard room',
		GTC_Offer_Group::room_grade( 'Superior Room, breakfast included' )
			!== GTC_Offer_Group::room_grade( 'Standard Double Room with Breakfast' ),
		'superior vs standard'
	);

	// Positive control for the same axis: differently-worded base rooms must
	// still meet, or the grade key would simply disable comparison.
	gtc_check(
		'differently worded base rooms still compare',
		GTC_Offer_Group::room_grade( 'Double Room' )
			=== GTC_Offer_Group::room_grade( 'Standard Double Room' ),
		'both resolve to standard'
	);

	// The headline badge must come from a multi-supplier class only.
	$max_multi_saving = 0;
	foreach ( $rows as $row ) {
		if ( $row['supplier_count'] > 1 && $row['saving'] > $max_multi_saving ) {
			$max_multi_saving = $row['saving'];
		}
	}

	gtc_check(
		'the advertised saving is the best like-for-like gap, not the spread across all rates',
		$compare_group->like_for_like_saving() === $max_multi_saving,
		GTC_Currency::format( $compare_group->like_for_like_saving(), 'USD' )
			. ' like-for-like vs ' . GTC_Currency::format( $compare_group->saving(), 'USD' ) . ' across all rates'
	);

	// The two figures measure different things and neither bounds the other:
	// saving() pairs each supplier's cheapest rate whatever it is, so it can
	// be the gap between a non-refundable room-only rate and a refundable one.
	// The like-for-like figure is the largest gap on a single product, which
	// may well be bigger. What matters is that the advertised number always
	// comes from one class — asserted above — not that it is the smaller one.
	echo '  note  like-for-like ' . GTC_Currency::format( $compare_group->like_for_like_saving(), 'USD' )
		. ', cheapest-vs-cheapest ' . GTC_Currency::format( $compare_group->saving(), 'USD' )
		. " — different pairings, neither bounds the other\n";

	// Nothing to compare must advertise nothing. This is the guard that stops
	// a single-supplier card carrying a "Save $X" badge built from that one
	// supplier's own rate spread.
	$solo = gtc_find_group( $groups, 'Alfama Hill' );

	if ( $solo ) {
		gtc_check(
			'a single-supplier card advertises no saving',
			1 === $solo->provider_count() && 0 === $solo->like_for_like_saving(),
			$solo->provider_count() . ' supplier, badge ' . $solo->like_for_like_saving()
		);
	} else {
		gtc_check( 'single-supplier fixture found', false, 'Alfama Hill missing' );
	}
} else {
	gtc_check( 'a multi-supplier group was found to compare', false );
}

echo "\n-- pricing --\n";

$best = $azulejo ? $azulejo->best() : $groups[0]->best();
$price = $best->get_price();

$components = array();
foreach ( $price->get_components() as $component ) {
	$components[ $component['type'] ] = true;
}

gtc_check( 'the headline offer carries a tax line', isset( $components['tax'] ) );
gtc_check( 'the headline offer carries the commission as a visible line', isset( $components['markup'] ) );

$sum = $price->get_base();
foreach ( $price->get_components() as $component ) {
	if ( 'now' === $component['payable'] ) {
		$sum += $component['amount'];
	}
}

gtc_check(
	'the itemised lines add up to the charged total',
	$sum === $price->get_total(),
	$sum . ' vs ' . $price->get_total()
);

gtc_check(
	'amounts payable at the property are excluded from the charge',
	$price->get_payable_at_property() > 0 && $price->get_grand_total() > $price->get_total(),
	'charge ' . $price->format() . ', at property ' . $price->format( $price->get_payable_at_property() )
);

// The compare table must rank on grand total, or a supplier that defers its
// taxes to the property wins the card while costing the customer more.
$sorted = true;
$prev   = -1;
foreach ( $groups as $group ) {
	$total = $group->best()->get_price()->get_grand_total();
	if ( $prev >= 0 && $total < $prev ) {
		$sorted = false;
		break;
	}
	$prev = $total;
}
gtc_check( 'cards are ordered by cheapest grand total', $sorted );

echo "\n-- revalidation (Look) --\n";

$manager = gtc()->bookings();

// Unchanged: deterministic sandbox pricing means a stable rate must re-price
// identically. If this ever fails, the engine is comparing against the wrong
// baseline (net vs marked-up, or across currencies).
$booking = $manager->create_quote( $best, $request );
$reval   = $manager->revalidate( $booking );

gtc_check(
	'a stable rate revalidates as unchanged',
	GTC_Revalidation::UNCHANGED === $reval->get_outcome(),
	$reval->get_outcome() . ' / delta ' . $reval->get_delta()
);

gtc_check( 'the unchanged rate is bookable', $reval->is_bookable() );

// Price rise.
$loft = gtc_find_group( $groups, 'Graca Loft' );
$loft_offer = $loft ? $loft->best() : null;

if ( $loft_offer ) {
	$loft_booking = $manager->create_quote( $loft_offer, $request );
	$loft_reval   = $manager->revalidate( $loft_booking );

	gtc_check(
		'a supplier price rise is detected at Look',
		GTC_Revalidation::CHANGED === $loft_reval->get_outcome() && $loft_reval->get_delta() > 0,
		$loft_reval->get_outcome() . ' / +' . $loft_reval->get_delta()
	);

	gtc_check(
		'a price rise requires explicit customer acceptance',
		$loft_reval->requires_acceptance()
	);
} else {
	gtc_check( 'price-rise fixture found', false, 'Graca Loft missing from results' );
}

// Sell-out.
$atrium = gtc_find_group( $bcn->get_groups(), 'Eixample Atrium' );

if ( $atrium ) {
	$atrium_booking = $manager->create_quote( $atrium->best(), $bcn->get_request() );
	$atrium_reval   = $manager->revalidate( $atrium_booking );

	gtc_check(
		'a sold-out rate is caught before payment',
		GTC_Revalidation::GONE === $atrium_reval->get_outcome(),
		$atrium_reval->get_outcome()
	);

	gtc_check( 'a sold-out rate is not bookable', ! $atrium_reval->is_bookable() );
} else {
	gtc_check( 'sell-out fixture found', false, 'Eixample Atrium missing from results' );
}

echo "\n-- booking --\n";

$travellers = array( array( 'title' => 'Ms', 'first_name' => 'Test', 'last_name' => 'Traveller' ) );
$contact    = array( 'email' => 'traveller@example.com', 'phone' => '+351000000000', 'country' => 'PT' );

$paid = $manager->complete(
	$booking,
	$travellers,
	$contact,
	array( 'card_number' => '4111111111111111' ),
	$booking->get_final_offer()->get_price()->get_total()
);

gtc_check( 'a valid card books successfully', ! empty( $paid['success'] ), $paid['error'] );
gtc_check(
	'the booking is confirmed and carries a supplier reference',
	GTC_Booking::STATUS_CONFIRMED === $paid['booking']->status && '' !== $paid['booking']->supplier_reference,
	$paid['booking']->status . ' / ' . $paid['booking']->supplier_reference
);

gtc_check(
	'the amount recorded equals the revalidated total',
	$paid['booking']->amount_charged === $booking->get_final_offer()->get_price()->get_total(),
	GTC_Currency::format( $paid['booking']->amount_charged, $paid['booking']->currency )
);

// Charging a price the customer never saw is the failure this guard exists for.
$guard_booking = $manager->create_quote( $best, $request );
$manager->revalidate( $guard_booking );

$guard = $manager->complete(
	$guard_booking,
	$travellers,
	$contact,
	array( 'card_number' => '4111111111111111' ),
	1 // A total the customer never agreed to.
);

gtc_check(
	'a total the customer did not accept is refused',
	empty( $guard['success'] ) && GTC_Booking::STATUS_CONFIRMED !== $guard['booking']->status,
	$guard['booking']->status
);

gtc_check(
	'the refused booking took no payment',
	'' === $guard['booking']->payment_reference && '' === $guard['booking']->supplier_reference
);

// Declined card.
$declined_booking = $manager->create_quote( $best, $request );
$manager->revalidate( $declined_booking );

$declined = $manager->complete(
	$declined_booking,
	$travellers,
	$contact,
	array( 'card_number' => '4000000000000002' ),
	$declined_booking->get_final_offer()->get_price()->get_total()
);

gtc_check( 'a declined card does not book', empty( $declined['success'] ) );
gtc_check(
	'a declined card leaves no supplier reservation',
	'' === $declined['booking']->supplier_reference,
	$declined['booking']->status
);

// Double submit.
$again = $manager->complete( $paid['booking'], $travellers, $contact, array( 'card_number' => '4111111111111111' ), null );

gtc_check(
	're-submitting a confirmed booking returns the same reservation, not a second one',
	! empty( $again['success'] ) && $again['booking']->supplier_reference === $paid['booking']->supplier_reference,
	$again['booking']->supplier_reference
);

echo "\n-- audit --\n";

$log_rows = gtc()->logger()->recent( 50 );

$operations = array();
foreach ( $log_rows as $row ) {
	$operations[ $row['operation'] ] = true;
}

gtc_check( 'Look calls are logged', isset( $operations['look'] ) );
gtc_check( 'Book calls are logged', isset( $operations['book'] ) );

$has_note = false;
foreach ( (array) $paid['booking']->notes as $note ) {
	if ( false !== strpos( $note['note'], 'Supplier confirmed' ) ) {
		$has_note = true;
	}
}
gtc_check( 'the booking carries its own audit trail', $has_note );

echo "\n=== " . GTC_Check::$pass . ' passed, ' . GTC_Check::$fail . " failed ===\n\n";

if ( GTC_Check::$fail > 0 ) {
	exit( 1 );
}
