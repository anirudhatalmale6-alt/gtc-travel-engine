<?php
/**
 * Supplier call log.
 *
 * Distribution contracts require you to be able to reproduce, per booking,
 * what was quoted and what was sent — so Look and Book calls are logged with
 * their payloads. Search is logged as a counter only; logging every search
 * body would grow faster than the site.
 *
 * Payloads are scrubbed of credentials and of traveller PII before they are
 * written, because this table gets read by support staff.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Logger {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gtc_api_log';
	}

	/**
	 * Keys whose values are replaced before anything is written.
	 *
	 * @var string[]
	 */
	private static $redact = array(
		'authorization', 'api_key', 'apikey', 'key', 'secret', 'password',
		'token', 'access_token', 'refresh_token', 'card', 'card_number',
		'cvv', 'cvc', 'expiry', 'pan', 'client_secret', 'x-api-key',
		'email', 'phone', 'passport', 'date_of_birth', 'dob',
	);

	/**
	 * @param string $provider_id Adapter id.
	 * @param string $operation   search|look|book|cancel.
	 * @param array  $request     Request payload.
	 * @param array  $response    Response payload.
	 * @param int    $status      HTTP status.
	 * @param int    $ms          Duration.
	 * @param string $booking_ref Booking reference, when tied to one.
	 */
	public function log_call( $provider_id, $operation, array $request, array $response, $status = 200, $ms = 0, $booking_ref = '' ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'provider_id' => $provider_id,
				'operation'   => $operation,
				'booking_ref' => $booking_ref,
				'http_status' => (int) $status,
				'duration_ms' => (int) $ms,
				'request'     => wp_json_encode( self::scrub( $request ) ),
				'response'    => wp_json_encode( self::scrub( $response ) ),
				'error'       => '',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param string $provider_id   Adapter id.
	 * @param string $operation     Operation.
	 * @param string $message       Error message.
	 * @param string $supplier_code Supplier error code.
	 */
	public function log_error( $provider_id, $operation, $message, $supplier_code = '' ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'provider_id' => $provider_id,
				'operation'   => $operation,
				'booking_ref' => '',
				'http_status' => 0,
				'duration_ms' => 0,
				'request'     => '',
				'response'    => '',
				'error'       => $supplier_code ? $supplier_code . ': ' . $message : $message,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recursively blank out anything that looks like a secret or like PII.
	 *
	 * @param mixed $data Payload.
	 * @return mixed
	 */
	public static function scrub( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$out = array();
		foreach ( $data as $key => $value ) {
			$needle = is_string( $key ) ? strtolower( $key ) : '';

			$hit = false;
			foreach ( self::$redact as $sensitive ) {
				if ( '' !== $needle && false !== strpos( $needle, $sensitive ) ) {
					$hit = true;
					break;
				}
			}

			if ( $hit ) {
				$out[ $key ] = '[redacted]';
				continue;
			}

			$out[ $key ] = is_array( $value ) ? self::scrub( $value ) : $value;
		}

		return $out;
	}

	/**
	 * @param int    $limit  Rows.
	 * @param string $filter Optional provider id.
	 * @return array
	 */
	public function recent( $limit = 100, $filter = '' ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( $filter ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE provider_id = %s ORDER BY id DESC LIMIT %d",
					$filter,
					$limit
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * @param int $days Retention window.
	 * @return int Rows deleted.
	 */
	public function prune( $days = 90 ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);
	}
}
