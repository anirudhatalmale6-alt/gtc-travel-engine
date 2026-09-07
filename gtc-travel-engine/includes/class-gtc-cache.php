<?php
/**
 * Search-result cache.
 *
 * Only Search is cached. Look and Book are never cached — a cached price is
 * exactly the thing revalidation exists to catch. Most distribution contracts
 * also cap how long a searched price may be displayed, so the TTL is a setting
 * and is clamped to a ceiling.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Cache {

	const PREFIX  = 'gtc_s_';
	const MAX_TTL = 900;

	/**
	 * @return int
	 */
	private function ttl() {
		$ttl = (int) gtc()->settings()->get( 'search_cache_ttl', 300 );
		return max( 0, min( self::MAX_TTL, $ttl ) );
	}

	/**
	 * @param GTC_Search_Request $request Search.
	 * @param string             $provider_id Adapter id.
	 * @return string
	 */
	private function key( GTC_Search_Request $request, $provider_id ) {
		return self::PREFIX . $provider_id . '_' . $request->get_hash();
	}

	/**
	 * @param GTC_Search_Request $request     Search.
	 * @param string             $provider_id Adapter id.
	 * @return GTC_Offer[]|null Null on miss.
	 */
	public function get_offers( GTC_Search_Request $request, $provider_id ) {
		if ( ! $this->ttl() ) {
			return null;
		}

		$raw = get_transient( $this->key( $request, $provider_id ) );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$offers = array();
		foreach ( $raw as $item ) {
			$offers[] = GTC_Offer::from_array( $item );
		}
		return $offers;
	}

	/**
	 * @param GTC_Search_Request $request     Search.
	 * @param string             $provider_id Adapter id.
	 * @param GTC_Offer[]        $offers      Offers to store.
	 */
	public function set_offers( GTC_Search_Request $request, $provider_id, array $offers ) {
		$ttl = $this->ttl();
		if ( ! $ttl ) {
			return;
		}

		$raw = array();
		foreach ( $offers as $offer ) {
			$raw[] = $offer->to_array();
		}

		set_transient( $this->key( $request, $provider_id ), $raw, $ttl );
	}

	/**
	 * Store the assembled result set so the offer the customer clicks can be
	 * recovered server-side, with its supplier_data intact, without trusting
	 * anything the browser sends back.
	 *
	 * @param GTC_Search_Request $request Search.
	 * @param GTC_Offer[]        $offers  Flat list of offers.
	 */
	public function set_result_set( GTC_Search_Request $request, array $offers ) {
		$map = array();
		foreach ( $offers as $offer ) {
			$map[ $offer->get_key() ] = $offer->to_array();
		}

		set_transient(
			self::PREFIX . 'rs_' . $request->get_hash(),
			array(
				'request' => $request->to_array(),
				'offers'  => $map,
			),
			max( 900, $this->ttl() * 3 )
		);
	}

	/**
	 * Recover one offer, and the search that produced it, from a result set.
	 *
	 * @param string $search_hash Search hash.
	 * @param string $offer_key   Offer key.
	 * @return array{offer:GTC_Offer,request:GTC_Search_Request}|null
	 */
	public function get_from_result_set( $search_hash, $offer_key ) {
		$stored = get_transient( self::PREFIX . 'rs_' . $search_hash );

		if ( ! is_array( $stored ) || empty( $stored['offers'][ $offer_key ] ) ) {
			return null;
		}

		return array(
			'offer'   => GTC_Offer::from_array( $stored['offers'][ $offer_key ] ),
			'request' => GTC_Search_Request::from_stored( $stored['request'] ),
		);
	}

	/**
	 * Drop every cached search. Used after a settings change that alters
	 * pricing — a stale markup is a mispriced result page.
	 *
	 * @return int Rows removed.
	 */
	public function flush() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';

		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		$n = 0;
		foreach ( (array) $names as $name ) {
			if ( delete_transient( substr( $name, strlen( '_transient_' ) ) ) ) {
				$n++;
			}
		}

		return $n;
	}
}
