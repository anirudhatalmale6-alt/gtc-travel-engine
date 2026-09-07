<?php
/**
 * Holds the supplier adapters.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Registry {

	/** @var GTC_Provider[] */
	private $providers = array();

	/**
	 * @param GTC_Provider $provider Adapter.
	 */
	public function add( GTC_Provider $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * @param string $id Adapter id.
	 * @return GTC_Provider|null
	 */
	public function get( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Every registered adapter, ready or not — the admin screen needs these.
	 *
	 * @return GTC_Provider[]
	 */
	public function all() {
		return $this->providers;
	}

	/**
	 * Adapters that can actually serve a category right now: they declare it,
	 * they are switched on, and they have credentials.
	 *
	 * @param string $category Category slug.
	 * @return GTC_Provider[]
	 */
	public function for_category( $category ) {
		$out = array();
		foreach ( $this->providers as $id => $provider ) {
			if ( ! in_array( $category, $provider->get_categories(), true ) ) {
				continue;
			}
			if ( ! $provider->is_ready() ) {
				continue;
			}
			$out[ $id ] = $provider;
		}
		return $out;
	}
}
