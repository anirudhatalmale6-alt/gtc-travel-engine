<?php
/**
 * A fully itemised price. The brief requires the customer to see the complete
 * price, taxes and fees before paying, so nothing here is a single "total"
 * field — every component that makes up the total is carried separately and
 * every component is displayed.
 *
 * All amounts are integer minor units (cents) to keep money out of floats.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Price {

	/** @var string ISO 4217. */
	private $currency;

	/** @var int Net rate excluding taxes and fees, in minor units. */
	private $base;

	/** @var array<int,array{label:string,amount:int,type:string,payable:string}> */
	private $components = array();

	/**
	 * @param string $currency ISO 4217 code.
	 * @param int    $base     Base amount in minor units.
	 */
	public function __construct( $currency, $base ) {
		$this->currency = strtoupper( $currency );
		$this->base     = (int) round( $base );
	}

	/**
	 * Build from major units (e.g. 149.50) — convenience for adapters whose
	 * supplier returns decimals.
	 *
	 * @param string $currency Currency code.
	 * @param float  $amount   Major units.
	 * @return GTC_Price
	 */
	public static function from_major( $currency, $amount ) {
		return new self( $currency, (int) round( ( (float) $amount ) * 100 ) );
	}

	/**
	 * Add a tax, fee, discount or markup line.
	 *
	 * @param string $label   Display label, e.g. "City tax".
	 * @param int    $amount  Minor units. Negative for discounts.
	 * @param string $type    tax|fee|markup|discount.
	 * @param string $payable now|at_property — whether the customer pays it to us
	 *                        or to the supplier on arrival. Amounts payable at the
	 *                        property are shown but excluded from the charge.
	 * @return $this
	 */
	public function add_component( $label, $amount, $type = 'fee', $payable = 'now' ) {
		$this->components[] = array(
			'label'   => $label,
			'amount'  => (int) round( $amount ),
			'type'    => $type,
			'payable' => $payable,
		);
		return $this;
	}

	/** @return string */
	public function get_currency() {
		return $this->currency;
	}

	/** @return int */
	public function get_base() {
		return $this->base;
	}

	/** @return array */
	public function get_components() {
		return $this->components;
	}

	/**
	 * Sum of components of one payable bucket.
	 *
	 * @param string $payable now|at_property.
	 * @return int
	 */
	private function sum( $payable ) {
		$n = 0;
		foreach ( $this->components as $c ) {
			if ( $c['payable'] === $payable ) {
				$n += $c['amount'];
			}
		}
		return $n;
	}

	/**
	 * What we charge the customer's card.
	 *
	 * @return int
	 */
	public function get_total() {
		return $this->base + $this->sum( 'now' );
	}

	/**
	 * What the customer will owe the supplier directly. Displayed, never charged.
	 *
	 * @return int
	 */
	public function get_payable_at_property() {
		return $this->sum( 'at_property' );
	}

	/**
	 * Total cost of the trip however it is split — this is the number the
	 * compare table must sort on, otherwise a hotel that pushes its taxes to
	 * the property looks cheaper than one that includes them.
	 *
	 * @return int
	 */
	public function get_grand_total() {
		return $this->get_total() + $this->get_payable_at_property();
	}

	/**
	 * Taxes and fees payable now, as one figure.
	 *
	 * @return int
	 */
	public function get_taxes_and_fees() {
		$n = 0;
		foreach ( $this->components as $c ) {
			if ( 'now' === $c['payable'] && in_array( $c['type'], array( 'tax', 'fee' ), true ) ) {
				$n += $c['amount'];
			}
		}
		return $n;
	}

	/**
	 * @param int $nights Nights in the stay.
	 * @return int Per-night grand total, 0 when not a stay.
	 */
	public function get_per_night( $nights ) {
		$nights = (int) $nights;
		return $nights > 0 ? (int) round( $this->get_grand_total() / $nights ) : 0;
	}

	/**
	 * Difference against another price, in minor units. Positive means this
	 * price is higher.
	 *
	 * @param GTC_Price $other Price to compare.
	 * @return int
	 */
	public function delta_against( GTC_Price $other ) {
		return $this->get_grand_total() - $other->get_grand_total();
	}

	/**
	 * @param int|null $amount Minor units, defaults to the charged total.
	 * @return string
	 */
	public function format( $amount = null ) {
		$amount = null === $amount ? $this->get_total() : (int) $amount;
		return GTC_Currency::format( $amount, $this->currency );
	}

	/** @return array */
	public function to_array() {
		return array(
			'currency'           => $this->currency,
			'base'               => $this->base,
			'components'         => $this->components,
			'total'              => $this->get_total(),
			'taxes_and_fees'     => $this->get_taxes_and_fees(),
			'payable_at_property'=> $this->get_payable_at_property(),
			'grand_total'        => $this->get_grand_total(),
		);
	}

	/**
	 * @param array $data Output of to_array().
	 * @return GTC_Price
	 */
	public static function from_array( array $data ) {
		$price = new self(
			isset( $data['currency'] ) ? $data['currency'] : 'USD',
			isset( $data['base'] ) ? $data['base'] : 0
		);
		if ( ! empty( $data['components'] ) && is_array( $data['components'] ) ) {
			foreach ( $data['components'] as $c ) {
				$price->add_component(
					isset( $c['label'] ) ? $c['label'] : '',
					isset( $c['amount'] ) ? $c['amount'] : 0,
					isset( $c['type'] ) ? $c['type'] : 'fee',
					isset( $c['payable'] ) ? $c['payable'] : 'now'
				);
			}
		}
		return $price;
	}
}
