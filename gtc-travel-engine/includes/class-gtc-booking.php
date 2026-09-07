<?php
/**
 * A booking record.
 *
 * The lifecycle is deliberately explicit, because the failure that costs real
 * money is "customer charged, supplier never booked". Every transition is
 * persisted before the next call is made, so an interrupted request always
 * leaves a row that says exactly how far it got.
 *
 *   quoted -> revalidated -> payment_authorised -> confirmed
 *                                              \-> supplier_failed (refund due)
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Booking {

	const STATUS_QUOTED       = 'quoted';
	const STATUS_REVALIDATED  = 'revalidated';
	const STATUS_AUTHORISED   = 'payment_authorised';
	const STATUS_CONFIRMED    = 'confirmed';
	const STATUS_SUPPLIER_ERR = 'supplier_failed';
	const STATUS_CANCELLED    = 'cancelled';
	const STATUS_ABANDONED    = 'abandoned';

	/** @var int */
	public $id = 0;

	/** @var string Customer-facing reference. */
	public $reference = '';

	/** @var string */
	public $status = self::STATUS_QUOTED;

	/** @var string */
	public $provider_id = '';

	/** @var string */
	public $category = '';

	/** @var string Supplier confirmation number. */
	public $supplier_reference = '';

	/** @var string */
	public $product_name = '';

	/** @var string */
	public $search_hash = '';

	/** @var array Request snapshot. */
	public $search = array();

	/** @var array Offer snapshot as quoted at search time. */
	public $offer_quoted = array();

	/** @var array Offer snapshot as revalidated at Look. */
	public $offer_final = array();

	/** @var array Lead traveller and companions. */
	public $travellers = array();

	/** @var array Contact details. */
	public $contact = array();

	/** @var string */
	public $currency = 'USD';

	/** @var int Minor units actually charged. */
	public $amount_charged = 0;

	/** @var int Minor units payable at the property. */
	public $amount_at_property = 0;

	/** @var string */
	public $payment_gateway = '';

	/** @var string */
	public $payment_reference = '';

	/** @var string */
	public $idempotency_key = '';

	/** @var string */
	public $created_at = '';

	/** @var string */
	public $updated_at = '';

	/** @var array Free-form audit notes. */
	public $notes = array();

	/**
	 * @param array $data Row or partial data.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}

		if ( ! $this->reference ) {
			$this->reference = self::generate_reference();
		}

		if ( ! $this->idempotency_key ) {
			$this->idempotency_key = wp_generate_uuid4();
		}
	}

	/**
	 * Human reference: readable over the phone, no ambiguous characters,
	 * enough entropy that it cannot be guessed or enumerated.
	 *
	 * @return string
	 */
	public static function generate_reference() {
		$alphabet = 'ACDEFGHJKLMNPQRTUVWXY3479';
		$out      = '';
		for ( $i = 0; $i < 9; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return substr( $out, 0, 3 ) . '-' . substr( $out, 3, 3 ) . '-' . substr( $out, 6, 3 );
	}

	/** @return string */
	public function get_idempotency_key() {
		return $this->idempotency_key;
	}

	/**
	 * @param string $note Audit note.
	 */
	public function add_note( $note ) {
		$this->notes[] = array(
			'at'   => current_time( 'mysql', true ),
			'note' => $note,
		);
	}

	/** @return GTC_Offer|null */
	public function get_final_offer() {
		return $this->offer_final ? GTC_Offer::from_array( $this->offer_final ) : null;
	}

	/** @return GTC_Search_Request|null */
	public function get_search_request() {
		return $this->search ? GTC_Search_Request::from_stored( $this->search ) : null;
	}

	/**
	 * @return string Lead traveller's display name.
	 */
	public function get_lead_name() {
		if ( empty( $this->travellers[0] ) ) {
			return '';
		}
		$t = $this->travellers[0];
		return trim( ( isset( $t['first_name'] ) ? $t['first_name'] : '' ) . ' ' . ( isset( $t['last_name'] ) ? $t['last_name'] : '' ) );
	}

	/**
	 * True once money has moved. Anything after this point that fails needs a
	 * refund, not just an error message.
	 *
	 * @return bool
	 */
	public function is_paid() {
		return in_array(
			$this->status,
			array( self::STATUS_AUTHORISED, self::STATUS_CONFIRMED, self::STATUS_SUPPLIER_ERR ),
			true
		);
	}

	/** @return array */
	public function to_row() {
		return array(
			'reference'          => $this->reference,
			'status'             => $this->status,
			'provider_id'        => $this->provider_id,
			'category'           => $this->category,
			'supplier_reference' => $this->supplier_reference,
			'product_name'       => $this->product_name,
			'search_hash'        => $this->search_hash,
			'search'             => wp_json_encode( $this->search ),
			'offer_quoted'       => wp_json_encode( $this->offer_quoted ),
			'offer_final'        => wp_json_encode( $this->offer_final ),
			'travellers'         => wp_json_encode( $this->travellers ),
			'contact'            => wp_json_encode( $this->contact ),
			'currency'           => $this->currency,
			'amount_charged'     => (int) $this->amount_charged,
			'amount_at_property' => (int) $this->amount_at_property,
			'payment_gateway'    => $this->payment_gateway,
			'payment_reference'  => $this->payment_reference,
			'idempotency_key'    => $this->idempotency_key,
			'notes'              => wp_json_encode( $this->notes ),
			'updated_at'         => current_time( 'mysql', true ),
		);
	}

	/**
	 * @param array $row Database row.
	 * @return GTC_Booking
	 */
	public static function from_row( array $row ) {
		foreach ( array( 'search', 'offer_quoted', 'offer_final', 'travellers', 'contact', 'notes' ) as $json ) {
			if ( isset( $row[ $json ] ) ) {
				$decoded       = json_decode( (string) $row[ $json ], true );
				$row[ $json ] = is_array( $decoded ) ? $decoded : array();
			}
		}
		return new self( $row );
	}
}
