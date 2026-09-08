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

	/* ------------------------------------------------- like-for-like compare */

	/**
	 * Split the group into comparable classes, so supplier prices are set
	 * against each other on equivalent products rather than on whatever mix of
	 * rates each supplier happened to return.
	 *
	 * The comparison axis is room grade plus board plus refundability, never the
	 * raw room name. Suppliers name the same room differently — "Standard
	 * Double Room" against "Double Room" — so matching on the full string would
	 * compare almost nothing. Grade is extracted from the name against a fixed
	 * vocabulary; board and cancellation terms are stated explicitly by both
	 * suppliers.
	 *
	 * Grade has to be in the key. Without it a standard room and a superior
	 * room at the same board basis land in one class, and the engine reports
	 * the difference between two different rooms as a saving — a real number
	 * that means nothing. An unrecognised grade splits into its own class,
	 * which costs a comparison but never invents one.
	 *
	 * @return array<int,array>
	 */
	public function comparisons() {
		$classes = array();

		foreach ( $this->offers as $offer ) {
			$key = self::room_grade( (string) $offer->rate( 'name' ) )
				. '|' . $offer->rate( 'board' )
				. '|' . ( $offer->rate( 'refundable' ) ? '1' : '0' );

			if ( ! isset( $classes[ $key ] ) ) {
				$classes[ $key ] = array();
			}

			$provider = $offer->get_provider_id();

			// Cheapest rate per supplier within the class. The offers list is
			// already sorted by grand total, so the first one wins.
			if ( ! isset( $classes[ $key ][ $provider ] ) ) {
				$classes[ $key ][ $provider ] = $offer;
			}
		}

		$rows = array();

		foreach ( $classes as $key => $by_provider ) {
			$offers = array_values( $by_provider );

			usort(
				$offers,
				static function ( GTC_Offer $a, GTC_Offer $b ) {
					return $a->get_price()->get_grand_total() <=> $b->get_price()->get_grand_total();
				}
			);

			$totals = array();
			foreach ( $offers as $offer ) {
				$totals[] = $offer->get_price()->get_grand_total();
			}

			$rows[] = array(
				'key'            => $key,
				'label'          => $this->class_label( $offers[0] ),
				'supplier_count' => count( $offers ),
				'saving'         => count( $totals ) > 1 ? max( $totals ) - min( $totals ) : 0,
				'offers'         => $offers,
			);
		}

		// Classes both suppliers quote come first — those are the rows that are
		// actually a comparison. Within that, biggest saving first, because a
		// class where the suppliers agree on price tells the customer nothing.
		usort(
			$rows,
			static function ( array $a, array $b ) {
				if ( $a['supplier_count'] !== $b['supplier_count'] ) {
					return $b['supplier_count'] <=> $a['supplier_count'];
				}
				if ( $a['saving'] !== $b['saving'] ) {
					return $b['saving'] <=> $a['saving'];
				}
				return $a['offers'][0]->get_price()->get_grand_total() <=> $b['offers'][0]->get_price()->get_grand_total();
			}
		);

		return $rows;
	}

	/**
	 * Room grades, checked in this order so a name carrying two of them
	 * resolves the same way every time.
	 *
	 * An unqualified name ("Double Room") falls through to standard, because
	 * the base room is what a supplier lists when it does not qualify the
	 * grade — that is the one case where inferring is safer than splitting.
	 *
	 * @var string[]
	 */
	private static $grades = array(
		'presidential',
		'penthouse',
		'suite',
		'executive',
		'club',
		'deluxe',
		'luxury',
		'premium',
		'superior',
		'family',
		'studio',
		'apartment',
		'economy',
		'budget',
		'standard',
		'classic',
	);

	/**
	 * @param string $room_name Supplier's room name.
	 * @return string
	 */
	public static function room_grade( $room_name ) {
		$name = strtolower( $room_name );

		foreach ( self::$grades as $grade ) {
			if ( false !== strpos( $name, $grade ) ) {
				return $grade;
			}
		}

		return 'standard';
	}

	/**
	 * @param GTC_Offer $offer Representative offer for the class.
	 * @return string
	 */
	private function class_label( GTC_Offer $offer ) {
		$grade = self::room_grade( (string) $offer->rate( 'name' ) );
		$board = GTC_Shortcodes::board_label( $offer->rate( 'board' ) );

		$cancellation = $offer->rate( 'refundable' )
			? __( 'free cancellation', 'gtc' )
			: __( 'non-refundable', 'gtc' );

		return sprintf(
			/* translators: 1: room grade 2: board basis 3: cancellation terms */
			__( '%1$s room · %2$s · %3$s', 'gtc' ),
			ucfirst( $grade ),
			$board,
			$cancellation
		);
	}

	/**
	 * The best saving available on any single comparable class — what the
	 * customer gains by taking our cheapest supplier for the exact same
	 * product, rather than the headline saving across mismatched rates.
	 *
	 * @return int Minor units.
	 */
	public function like_for_like_saving() {
		$best = 0;

		foreach ( $this->comparisons() as $row ) {
			if ( $row['supplier_count'] > 1 && $row['saving'] > $best ) {
				$best = $row['saving'];
			}
		}

		return $best;
	}

	/** @return array */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(
			'best'           => $this->best(),
			'offers'         => $this->offers,
			'provider_count' => $this->provider_count(),
			'saving'         => $this->saving(),
			'comparisons'    => $this->comparisons(),
			'like_for_like'  => $this->like_for_like_saving(),
		);
	}
}
