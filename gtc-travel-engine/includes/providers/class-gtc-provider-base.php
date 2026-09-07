<?php
/**
 * Shared adapter plumbing: credentials, HTTP with timeout and retry, and
 * consistent error translation.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

abstract class GTC_Provider_Base implements GTC_Provider {

	/**
	 * Credential keys this adapter cannot work without. The admin screen reads
	 * this to render the form and to say why an adapter is not ready.
	 *
	 * @return string[]
	 */
	public function required_credentials() {
		return array();
	}

	/**
	 * @param string $key     Credential key.
	 * @param string $default Fallback.
	 * @return string
	 */
	protected function credential( $key, $default = '' ) {
		$creds = gtc()->settings()->credentials( $this->get_id() );
		return isset( $creds[ $key ] ) && '' !== $creds[ $key ] ? (string) $creds[ $key ] : $default;
	}

	/**
	 * Ready means: switched on in settings, and every required credential
	 * present. Adapters that need nothing (the sandbox ones) only need the
	 * switch.
	 *
	 * @return bool
	 */
	public function is_ready() {
		if ( ! gtc()->settings()->is_provider_enabled( $this->get_id() ) ) {
			return false;
		}

		foreach ( $this->required_credentials() as $key ) {
			if ( '' === $this->credential( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Which required credentials are still missing — shown in admin so an
	 * adapter is never just silently absent from results.
	 *
	 * @return string[]
	 */
	public function missing_credentials() {
		$missing = array();
		foreach ( $this->required_credentials() as $key ) {
			if ( '' === $this->credential( $key ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * HTTP call with logging, timeout and one retry on transport failure.
	 *
	 * @param string $method    HTTP method.
	 * @param string $url       Absolute URL.
	 * @param array  $args      { headers, body, query }.
	 * @param string $operation search|look|book|cancel, for the log.
	 * @return array Decoded JSON body.
	 * @throws GTC_Provider_Exception On transport failure or a non-2xx status.
	 */
	protected function request( $method, $url, array $args = array(), $operation = 'search' ) {
		$args = wp_parse_args(
			$args,
			array(
				'headers' => array(),
				'body'    => null,
				'query'   => array(),
			)
		);

		if ( $args['query'] ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		$timeout = (int) gtc()->settings()->get( 'provider_timeout', 12 );

		$http = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'GTC-Travel-Engine/' . GTC_VERSION . '; ' . home_url(),
				),
				$args['headers']
			),
		);

		if ( null !== $args['body'] ) {
			$http['body'] = is_string( $args['body'] ) ? $args['body'] : wp_json_encode( $args['body'] );
		}

		$started  = microtime( true );
		$response = wp_remote_request( $url, $http );

		if ( is_wp_error( $response ) ) {
			// One retry: a single timeout on a search is usually transient, and
			// dropping the supplier for it costs a comparison.
			$response = wp_remote_request( $url, $http );
		}

		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				$response->get_error_message(),
				'transport',
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$data = array( 'raw' => $body );
		}

		gtc()->logger()->log_call(
			$this->get_id(),
			$operation,
			array(
				'url'    => $url,
				'method' => $http['method'],
				'body'   => isset( $http['body'] ) ? json_decode( $http['body'], true ) : null,
			),
			$data,
			$status,
			$ms
		);

		if ( $status < 200 || $status >= 300 ) {
			throw new GTC_Provider_Exception(
				$this->get_id(),
				$this->extract_error( $data, $status ),
				isset( $data['error_code'] ) ? (string) $data['error_code'] : (string) $status,
				in_array( $status, array( 429, 502, 503, 504 ), true )
			);
		}

		return $data;
	}

	/**
	 * Pull a human message out of whatever error envelope the supplier used.
	 *
	 * @param array $data   Decoded body.
	 * @param int   $status HTTP status.
	 * @return string
	 */
	protected function extract_error( array $data, $status ) {
		foreach ( array( 'message', 'error_description', 'detail', 'error' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}

		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$first = reset( $data['errors'] );
			if ( is_array( $first ) && ! empty( $first['message'] ) ) {
				return (string) $first['message'];
			}
			if ( is_string( $first ) ) {
				return $first;
			}
		}

		return sprintf(
			/* translators: %d: HTTP status */
			__( 'Supplier returned HTTP %d.', 'gtc' ),
			$status
		);
	}

	/**
	 * Default: suppliers that cannot cancel programmatically say so rather
	 * than pretending the call worked.
	 *
	 * @param GTC_Booking $booking Booking.
	 * @return array
	 * @throws GTC_Provider_Exception Always, unless overridden.
	 */
	public function cancel( GTC_Booking $booking ) {
		throw new GTC_Provider_Exception(
			$this->get_id(),
			__( 'This supplier does not support cancellation through the API. Cancel it in the supplier extranet.', 'gtc' ),
			'not_supported'
		);
	}
}
