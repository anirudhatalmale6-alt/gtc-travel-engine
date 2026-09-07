<?php
/**
 * Persistence for bookings.
 *
 * Bookings live in their own table rather than as a custom post type: they are
 * transactional, queried by reference and status, and must not be exposed by
 * anything that walks posts (REST, sitemaps, search, export plugins).
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Booking_Store {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gtc_bookings';
	}

	/**
	 * Insert or update. Returns the booking with its id populated.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @return GTC_Booking
	 */
	public function save( GTC_Booking $booking ) {
		global $wpdb;

		$row = $booking->to_row();

		if ( $booking->id ) {
			$wpdb->update( self::table(), $row, array( 'id' => $booking->id ) );
			return $booking;
		}

		$row['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( self::table(), $row );
		$booking->id = (int) $wpdb->insert_id;

		return $booking;
	}

	/**
	 * @param int $id Booking id.
	 * @return GTC_Booking|null
	 */
	public function get( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? GTC_Booking::from_row( $row ) : null;
	}

	/**
	 * @param string $reference Customer-facing reference.
	 * @return GTC_Booking|null
	 */
	public function get_by_reference( $reference ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $reference ),
			ARRAY_A
		);

		return $row ? GTC_Booking::from_row( $row ) : null;
	}

	/**
	 * Look a booking up by its idempotency key. Called before every supplier
	 * Book so a retried request cannot create a second reservation.
	 *
	 * @param string $key Idempotency key.
	 * @return GTC_Booking|null
	 */
	public function get_by_idempotency_key( $key ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ),
			ARRAY_A
		);

		return $row ? GTC_Booking::from_row( $row ) : null;
	}

	/**
	 * @param array $args { status, category, provider_id, search, limit, offset }.
	 * @return GTC_Booking[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'      => '',
				'category'    => '',
				'provider_id' => '',
				'search'      => '',
				'limit'       => 50,
				'offset'      => 0,
			)
		);

		$table = self::table();
		$where = array( '1=1' );
		$prep  = array();

		if ( $args['status'] ) {
			$where[] = 'status = %s';
			$prep[]  = $args['status'];
		}
		if ( $args['category'] ) {
			$where[] = 'category = %s';
			$prep[]  = $args['category'];
		}
		if ( $args['provider_id'] ) {
			$where[] = 'provider_id = %s';
			$prep[]  = $args['provider_id'];
		}
		if ( $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(reference LIKE %s OR supplier_reference LIKE %s OR product_name LIKE %s)';
			$prep[]  = $like;
			$prep[]  = $like;
			$prep[]  = $like;
		}

		$sql    = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$prep[] = max( 1, min( 500, (int) $args['limit'] ) );
		$prep[] = max( 0, (int) $args['offset'] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prep ), ARRAY_A );

		return array_map( array( 'GTC_Booking', 'from_row' ), (array) $rows );
	}

	/**
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function count( $status = '' ) {
		global $wpdb;

		$table = self::table();

		if ( $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Quotes that were never paid for. Cleared on a schedule so the table does
	 * not fill with abandoned carts.
	 *
	 * @param int $hours Age threshold.
	 * @return int Rows updated.
	 */
	public function abandon_stale_quotes( $hours = 24 ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status IN (%s, %s) AND created_at < %s",
				GTC_Booking::STATUS_ABANDONED,
				GTC_Booking::STATUS_QUOTED,
				GTC_Booking::STATUS_REVALIDATED,
				$cutoff
			)
		);
	}
}
