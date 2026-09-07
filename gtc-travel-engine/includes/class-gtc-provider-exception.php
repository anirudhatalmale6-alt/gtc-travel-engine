<?php
/**
 * Thrown by adapters. Carries the supplier's own error code so the aggregator
 * can decide between "drop this supplier from the results" and "abort".
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Exception extends Exception {

	/** @var string */
	protected $provider_id;

	/** @var string */
	protected $supplier_code;

	/** @var bool Whether retrying the same call could succeed. */
	protected $retryable;

	/**
	 * @param string $provider_id   Adapter id.
	 * @param string $message       Message.
	 * @param string $supplier_code Supplier error code.
	 * @param bool   $retryable     Retryable.
	 */
	public function __construct( $provider_id, $message, $supplier_code = '', $retryable = false ) {
		parent::__construct( $message );
		$this->provider_id   = $provider_id;
		$this->supplier_code = $supplier_code;
		$this->retryable     = (bool) $retryable;
	}

	/** @return string */
	public function get_provider_id() {
		return $this->provider_id;
	}

	/** @return string */
	public function get_supplier_code() {
		return $this->supplier_code;
	}

	/** @return bool */
	public function is_retryable() {
		return $this->retryable;
	}
}
