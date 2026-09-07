<?php
/**
 * Duplicate matching across suppliers.
 *
 * The same hotel comes back from two suppliers under two different names, two
 * different property ids and slightly different coordinates. Listing both as
 * separate results makes the comparison worthless, so equivalent products are
 * clustered and presented once with a per-supplier price comparison.
 *
 * Matching is deliberately conservative. A false merge hides a genuinely
 * different property and can send a customer to the wrong hotel, which is far
 * worse than a false split — so a pair must clear BOTH a distance gate and a
 * name-similarity gate before it is merged, unless the suppliers agree on a
 * shared external id (GIATA or equivalent), which is authoritative.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Dedupe {

	/**
	 * Tokens that carry no identifying signal in a property name.
	 *
	 * @var string[]
	 */
	private static $stopwords = array(
		'hotel', 'hotels', 'the', 'and', 'resort', 'resorts', 'spa', 'inn',
		'suites', 'suite', 'apartments', 'apartment', 'apart', 'hostel',
		'guesthouse', 'guest', 'house', 'lodge', 'motel', 'by', 'at', 'of',
		'a', 'an', 'de', 'la', 'le', 'el', 'du', 'des', 'collection',
	);

	/** @var int Metres. */
	private $radius;

	/** @var float 0..1. */
	private $name_threshold;

	/**
	 * @param int|null   $radius         Max metres between two matched products.
	 * @param float|null $name_threshold Min name similarity, 0..1.
	 */
	public function __construct( $radius = null, $name_threshold = null ) {
		$settings             = gtc()->settings();
		$this->radius         = null === $radius ? (int) $settings->get( 'dedupe_radius_m', 150 ) : (int) $radius;
		$this->name_threshold = null === $name_threshold ? (float) $settings->get( 'dedupe_name_score', 0.82 ) : (float) $name_threshold;
	}

	/**
	 * Cluster offers into groups of equivalent products.
	 *
	 * @param GTC_Offer[] $offers Offers from all suppliers.
	 * @return GTC_Offer_Group[]
	 */
	public function group( array $offers ) {
		if ( ! $offers ) {
			return array();
		}

		// Step 1: collapse offers by product within each supplier first. One
		// supplier returning eight room types for one hotel is not eight hotels.
		$by_product = array();
		foreach ( $offers as $offer ) {
			$pid = $offer->get_provider_id() . '|' . $offer->product( 'property_id' );
			if ( ! isset( $by_product[ $pid ] ) ) {
				$by_product[ $pid ] = array();
			}
			$by_product[ $pid ][] = $offer;
		}

		$nodes = array_values( $by_product );
		$count = count( $nodes );

		// Step 2: union-find over product nodes.
		$parent = range( 0, $count - 1 );

		if ( ! gtc()->settings()->get( 'dedupe_enabled', 1 ) ) {
			return $this->build_groups( $nodes, $parent );
		}

		// Blocking: only compare nodes sharing a coarse geo cell (or a cell
		// edge), which keeps this linear-ish instead of O(n^2) on large sets.
		$blocks = array();
		foreach ( $nodes as $i => $group ) {
			foreach ( $this->block_keys( $group[0] ) as $key ) {
				$blocks[ $key ][] = $i;
			}
		}

		$compared = array();

		foreach ( $blocks as $members ) {
			$n = count( $members );
			for ( $a = 0; $a < $n; $a++ ) {
				for ( $b = $a + 1; $b < $n; $b++ ) {
					$i = $members[ $a ];
					$j = $members[ $b ];

					$pair = $i < $j ? "$i-$j" : "$j-$i";
					if ( isset( $compared[ $pair ] ) ) {
						continue;
					}
					$compared[ $pair ] = true;

					// Two offers from the same supplier are already collapsed;
					// never merge across the same supplier again, because a
					// supplier listing two properties 40m apart means they are
					// two properties.
					if ( $nodes[ $i ][0]->get_provider_id() === $nodes[ $j ][0]->get_provider_id() ) {
						continue;
					}

					if ( $this->is_match( $nodes[ $i ][0], $nodes[ $j ][0] ) ) {
						$this->union( $parent, $i, $j );
					}
				}
			}
		}

		return $this->build_groups( $nodes, $parent );
	}

	/**
	 * Geo cells this product falls in. Using a ~220m cell plus the eight
	 * neighbours means two properties either side of a cell boundary still meet.
	 *
	 * @param GTC_Offer $offer Offer.
	 * @return string[]
	 */
	private function block_keys( GTC_Offer $offer ) {
		$lat = $offer->product( 'lat' );
		$lng = $offer->product( 'lng' );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			// No coordinates: block on city so it is still compared with
			// something, rather than silently never matching.
			return array( 'city:' . strtolower( (string) $offer->product( 'city' ) ) );
		}

		$precision = 0.002; // ~220m of latitude.
		$li        = (int) floor( $lat / $precision );
		$gi        = (int) floor( $lng / $precision );

		$keys = array();
		for ( $dx = -1; $dx <= 1; $dx++ ) {
			for ( $dy = -1; $dy <= 1; $dy++ ) {
				$keys[] = 'g:' . ( $li + $dx ) . ':' . ( $gi + $dy );
			}
		}
		return $keys;
	}

	/**
	 * @param GTC_Offer $a Offer A.
	 * @param GTC_Offer $b Offer B.
	 * @return bool
	 */
	public function is_match( GTC_Offer $a, GTC_Offer $b ) {
		// Authoritative: a shared cross-supplier property id.
		$shared = $this->shared_external_id( $a, $b );
		if ( null !== $shared ) {
			return $shared;
		}

		$distance = $this->distance_m( $a, $b );

		// Unknown coordinates on either side: refuse to merge on name alone.
		// A "Hilton Garden Inn" in two cities would otherwise collapse.
		if ( null === $distance ) {
			return false;
		}

		if ( $distance > $this->radius ) {
			return false;
		}

		return $this->name_similarity( (string) $a->product( 'name' ), (string) $b->product( 'name' ) ) >= $this->name_threshold;
	}

	/**
	 * @param GTC_Offer $a Offer A.
	 * @param GTC_Offer $b Offer B.
	 * @return bool|null True/false when the suppliers share an id namespace,
	 *                   null when they do not and the caller should fall through.
	 */
	private function shared_external_id( GTC_Offer $a, GTC_Offer $b ) {
		$ids_a = (array) $a->product( 'external_ids', array() );
		$ids_b = (array) $b->product( 'external_ids', array() );

		$shared_namespaces = array_intersect( array_keys( $ids_a ), array_keys( $ids_b ) );

		if ( ! $shared_namespaces ) {
			return null;
		}

		foreach ( $shared_namespaces as $ns ) {
			if ( '' === (string) $ids_a[ $ns ] || '' === (string) $ids_b[ $ns ] ) {
				continue;
			}
			if ( (string) $ids_a[ $ns ] === (string) $ids_b[ $ns ] ) {
				return true;
			}
		}

		// They share a namespace and disagree within it: that is a positive
		// statement that these are different properties.
		return false;
	}

	/**
	 * Great-circle distance in metres.
	 *
	 * @param GTC_Offer $a Offer A.
	 * @param GTC_Offer $b Offer B.
	 * @return float|null Null when either side has no coordinates.
	 */
	public function distance_m( GTC_Offer $a, GTC_Offer $b ) {
		$lat1 = $a->product( 'lat' );
		$lng1 = $a->product( 'lng' );
		$lat2 = $b->product( 'lat' );
		$lng2 = $b->product( 'lng' );

		if ( ! is_numeric( $lat1 ) || ! is_numeric( $lng1 ) || ! is_numeric( $lat2 ) || ! is_numeric( $lng2 ) ) {
			return null;
		}

		$r    = 6371000.0;
		$p1   = deg2rad( (float) $lat1 );
		$p2   = deg2rad( (float) $lat2 );
		$dp   = deg2rad( (float) $lat2 - (float) $lat1 );
		$dl   = deg2rad( (float) $lng2 - (float) $lng1 );
		$sin1 = sin( $dp / 2 );
		$sin2 = sin( $dl / 2 );

		$h = $sin1 * $sin1 + cos( $p1 ) * cos( $p2 ) * $sin2 * $sin2;

		return $r * 2 * atan2( sqrt( $h ), sqrt( max( 0.0, 1 - $h ) ) );
	}

	/**
	 * Normalise a property name down to its identifying tokens.
	 *
	 * @param string $name Raw name.
	 * @return string[]
	 */
	public static function tokenise( $name ) {
		$name = (string) $name;

		if ( function_exists( 'iconv' ) ) {
			$ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $name );
			if ( false !== $ascii ) {
				$name = $ascii;
			}
		}

		$name = strtolower( $name );
		$name = str_replace( array( '&', '+' ), ' and ', $name );
		$name = preg_replace( '/[^a-z0-9 ]+/', ' ', $name );
		$name = preg_replace( '/\s+/', ' ', trim( (string) $name ) );

		if ( '' === $name ) {
			return array();
		}

		$tokens = array();
		foreach ( explode( ' ', $name ) as $token ) {
			if ( in_array( $token, self::$stopwords, true ) ) {
				continue;
			}
			if ( '' === $token ) {
				continue;
			}
			$tokens[] = $token;
		}

		// If stripping stopwords emptied the name (e.g. "The Hotel"), keep the
		// raw tokens rather than returning nothing, which would score 0 against
		// everything and silently disable matching for that property.
		if ( ! $tokens ) {
			$tokens = array_filter( explode( ' ', $name ) );
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Similarity of two property names, 0..1.
	 *
	 * Combines a token-set overlap (robust to word order and to one supplier
	 * appending a brand or a district) with a character-level ratio (robust to
	 * spelling and transliteration differences). The larger of the two wins,
	 * because each covers the other's blind spot.
	 *
	 * @param string $a Name A.
	 * @param string $b Name B.
	 * @return float
	 */
	public function name_similarity( $a, $b ) {
		$ta = self::tokenise( $a );
		$tb = self::tokenise( $b );

		if ( ! $ta || ! $tb ) {
			return 0.0;
		}

		$intersection = count( array_intersect( $ta, $tb ) );

		// Containment, not Jaccard: "marriott downtown" vs
		// "marriott downtown riverside district" should score high, and Jaccard
		// punishes the longer name for carrying extra descriptive tokens.
		$containment = $intersection / min( count( $ta ), count( $tb ) );

		$sa = implode( ' ', $ta );
		$sb = implode( ' ', $tb );

		$chars = 0.0;
		similar_text( $sa, $sb, $chars );
		$chars = $chars / 100;

		return max( $containment, $chars );
	}

	/* ------------------------------------------------------------ union-find */

	/**
	 * @param int[] $parent Parent array.
	 * @param int   $i      Node.
	 * @return int
	 */
	private function find( array &$parent, $i ) {
		while ( $parent[ $i ] !== $i ) {
			$parent[ $i ] = $parent[ $parent[ $i ] ];
			$i            = $parent[ $i ];
		}
		return $i;
	}

	/**
	 * @param int[] $parent Parent array.
	 * @param int   $i      Node A.
	 * @param int   $j      Node B.
	 */
	private function union( array &$parent, $i, $j ) {
		$ri = $this->find( $parent, $i );
		$rj = $this->find( $parent, $j );
		if ( $ri !== $rj ) {
			$parent[ $rj ] = $ri;
		}
	}

	/**
	 * @param array $nodes  Per-product offer lists.
	 * @param int[] $parent Union-find parents.
	 * @return GTC_Offer_Group[]
	 */
	private function build_groups( array $nodes, array $parent ) {
		$clusters = array();

		foreach ( $nodes as $i => $offers ) {
			$root = $this->find( $parent, $i );
			if ( ! isset( $clusters[ $root ] ) ) {
				$clusters[ $root ] = array();
			}
			foreach ( $offers as $offer ) {
				$clusters[ $root ][] = $offer;
			}
		}

		$groups = array();
		foreach ( $clusters as $offers ) {
			$groups[] = new GTC_Offer_Group( $offers );
		}

		return $groups;
	}
}
