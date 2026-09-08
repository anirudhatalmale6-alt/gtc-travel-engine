<?php
/**
 * Outbound click log.
 *
 * In referral mode the money arrives later, as affiliate commission, and it
 * arrives as a statement from the supplier weeks after the fact. Without a
 * record of what was sent where, that statement cannot be checked — so every
 * outbound click is recorded with the offer and the price as displayed at the
 * moment the customer left.
 *
 * No personal data is stored. The IP is kept only as a salted hash, purely so
 * repeat clicks from one visitor can be collapsed when reconciling; it cannot
 * be reversed to an address.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Click_Log {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gtc_clicks';
	}

	/**
	 * @param GTC_Offer          $offer   Offer clicked.
	 * @param GTC_Search_Request $request Search that produced it.
	 * @return int Insert id.
	 */
	public function record( GTC_Offer $offer, GTC_Search_Request $request ) {
		global $wpdb;

		$price = $offer->get_price();

		$wpdb->insert(
			self::table(),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'provider_id'  => $offer->get_provider_id(),
				'category'     => $offer->get_category(),
				'search_hash'  => $request->get_hash(),
				'offer_key'    => $offer->get_key(),
				'product_name' => (string) $offer->product( 'name' ),
				'rate_name'    => (string) $offer->rate( 'name' ),
				'currency'     => $price->get_currency(),
				'price_total'  => $price->get_total(),
				'grand_total'  => $price->get_grand_total(),
				'visitor_hash' => $this->visitor_hash(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Salted with the site's own auth salt, so the hashes are meaningless
	 * outside this install and cannot be matched against a rainbow table of
	 * the (small) IPv4 space.
	 *
	 * @return string
	 */
	private function visitor_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return '';
		}

		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * @param array $args { provider_id, limit, offset }.
	 * @return array
	 */
	public function recent( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'provider_id' => '',
				'limit'       => 100,
				'offset'      => 0,
			)
		);

		$table = self::table();
		$limit = max( 1, min( 500, (int) $args['limit'] ) );

		if ( $args['provider_id'] ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE provider_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$args['provider_id'],
					$limit,
					max( 0, (int) $args['offset'] )
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				max( 0, (int) $args['offset'] )
			),
			ARRAY_A
		);
	}

	/**
	 * Clicks and the value sent, per supplier, over a window — the figures you
	 * check a commission statement against.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public function totals_by_provider( $days = 30 ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider_id,
				        COUNT(*) AS clicks,
				        COUNT(DISTINCT visitor_hash) AS visitors,
				        SUM(grand_total) AS value_sent,
				        currency
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY provider_id, currency
				 ORDER BY clicks DESC",
				$cutoff
			),
			ARRAY_A
		);
	}

	/** @return int */
	public function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
