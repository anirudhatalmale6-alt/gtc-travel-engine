<?php
/**
 * Sandbox supplier A.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Sandbox_Alpha extends GTC_Provider_Sandbox_Base {

	/** @return string */
	public function get_id() {
		return 'sandbox_alpha';
	}

	/** @return string */
	public function get_label() {
		return __( 'Sandbox Supplier A', 'gtc' );
	}
}
