<?php
/**
 * Settings store.
 *
 * Supplier credentials are kept in their own option, separate from display
 * settings, so the display option can be exported, autoloaded and dumped in a
 * support bundle without ever carrying a key.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Settings {

	const OPTION       = 'gtc_settings';
	const OPTION_CREDS = 'gtc_credentials';

	/** @var array|null */
	private $cache = null;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_currency'     => 'USD',
			'search_cache_ttl'     => 300,
			'provider_timeout'     => 12,
			'enabled_providers'    => array(),
			'markup_type'          => 'percent',
			'markup_value'         => 0,
			'markup_label'         => __( 'Service fee', 'gtc' ),
			'dedupe_enabled'       => 1,
			'dedupe_radius_m'      => 150,
			'dedupe_name_score'    => 0.82,
			'results_per_page'     => 20,
			'price_change_tolerance' => 0,
			// referral = compare and send the customer to the supplier's own
			// site; merchant = take the booking and the money here. The two
			// need completely different supplier agreements, so this is the
			// switch the whole platform hangs off.
			'booking_mode'         => 'referral',
			'payment_gateway'      => 'sandbox',
			'company_name'         => '',
			'support_email'        => '',
			'terms_url'            => '',
			'search_page_id'       => 0,
			'checkout_page_id'     => 0,
			'confirmation_page_id' => 0,
		);
	}

	/**
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		if ( ! array_key_exists( $key, $all ) || '' === $all[ $key ] ) {
			return null === $default ? ( isset( self::defaults()[ $key ] ) ? self::defaults()[ $key ] : null ) : $default;
		}
		return $all[ $key ];
	}

	/**
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$all         = $this->all();
		$all[ $key ] = $value;
		$this->cache = $all;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * @param array $values Key/value pairs to merge.
	 */
	public function update( array $values ) {
		$all         = array_merge( $this->all(), $values );
		$this->cache = $all;
		update_option( self::OPTION, $all, true );
	}

	/* ------------------------------------------------------------ credentials */

	/**
	 * Credentials for one adapter.
	 *
	 * @param string $provider_id Adapter id.
	 * @return array
	 */
	public function credentials( $provider_id ) {
		$all = get_option( self::OPTION_CREDS, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $provider_id ] ) ) {
			return array();
		}

		/**
		 * Lets a site keep keys out of the database entirely — return them from
		 * wp-config constants, an env var or a secrets manager instead.
		 *
		 * @param array  $creds       Stored credentials.
		 * @param string $provider_id Adapter id.
		 */
		return apply_filters( 'gtc_provider_credentials', (array) $all[ $provider_id ], $provider_id );
	}

	/**
	 * @param string $provider_id Adapter id.
	 * @param array  $creds       Credentials.
	 */
	public function set_credentials( $provider_id, array $creds ) {
		$all = get_option( self::OPTION_CREDS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $provider_id ] = $creds;
		update_option( self::OPTION_CREDS, $all, false );
	}

	/**
	 * True when the site refers customers out rather than selling to them.
	 *
	 * @return bool
	 */
	public function is_referral_mode() {
		return 'merchant' !== $this->get( 'booking_mode', 'referral' );
	}

	/**
	 * @param string $provider_id Adapter id.
	 * @return bool
	 */
	public function is_provider_enabled( $provider_id ) {
		$enabled = (array) $this->get( 'enabled_providers', array() );
		return in_array( $provider_id, $enabled, true );
	}
}
