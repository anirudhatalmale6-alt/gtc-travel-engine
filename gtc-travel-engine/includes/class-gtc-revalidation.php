<?php
/**
 * The verdict of a Look call.
 *
 * A price rise is never applied silently: the checkout must show the old
 * price, the new price and the difference, and the customer must accept it
 * before the payment step becomes available.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Revalidation implements JsonSerializable {

	const UNCHANGED = 'unchanged';
	const CHANGED   = 'changed';
	const GONE      = 'gone';
	const FAILED    = 'failed';

	/** @var string */
	private $outcome;

	/** @var GTC_Offer */
	private $offer;

	/** @var GTC_Price|null Price as originally quoted. */
	private $previous;

	/** @var int Minor units; positive means the price went up. */
	private $delta = 0;

	/** @var string */
	private $message = '';

	/**
	 * @param string         $outcome  Outcome constant.
	 * @param GTC_Offer      $offer    Offer.
	 * @param GTC_Price|null $previous Previous price.
	 * @param int            $delta    Difference.
	 * @param string         $message  Customer-facing message.
	 */
	private function __construct( $outcome, GTC_Offer $offer, GTC_Price $previous = null, $delta = 0, $message = '' ) {
		$this->outcome  = $outcome;
		$this->offer    = $offer;
		$this->previous = $previous;
		$this->delta    = (int) $delta;
		$this->message  = $message;
	}

	/**
	 * @param GTC_Offer $offer Offer.
	 * @return GTC_Revalidation
	 */
	public static function unchanged( GTC_Offer $offer ) {
		return new self( self::UNCHANGED, $offer, null, 0, __( 'Price and availability confirmed.', 'gtc' ) );
	}

	/**
	 * @param GTC_Offer $offer    Re-priced offer.
	 * @param GTC_Price $previous Previously quoted price.
	 * @param int       $delta    Difference in minor units.
	 * @return GTC_Revalidation
	 */
	public static function changed( GTC_Offer $offer, GTC_Price $previous, $delta ) {
		$message = $delta > 0
			? __( 'The supplier has increased this price since your search.', 'gtc' )
			: __( 'Good news — this price has dropped since your search.', 'gtc' );

		return new self( self::CHANGED, $offer, $previous, $delta, $message );
	}

	/**
	 * @param GTC_Offer $offer  Offer.
	 * @param string    $reason Reason.
	 * @return GTC_Revalidation
	 */
	public static function gone( GTC_Offer $offer, $reason ) {
		return new self( self::GONE, $offer, null, 0, $reason );
	}

	/**
	 * @param GTC_Offer $offer   Offer.
	 * @param string    $message Message.
	 * @return GTC_Revalidation
	 */
	public static function failed( GTC_Offer $offer, $message ) {
		return new self( self::FAILED, $offer, null, 0, $message );
	}

	/** @return string */
	public function get_outcome() {
		return $this->outcome;
	}

	/** @return GTC_Offer */
	public function get_offer() {
		return $this->offer;
	}

	/** @return GTC_Price|null */
	public function get_previous_price() {
		return $this->previous;
	}

	/** @return int */
	public function get_delta() {
		return $this->delta;
	}

	/** @return string */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Whether the customer may proceed to payment on this outcome. A changed
	 * price is bookable, but only after explicit re-acceptance in the UI.
	 *
	 * @return bool
	 */
	public function is_bookable() {
		return in_array( $this->outcome, array( self::UNCHANGED, self::CHANGED ), true );
	}

	/** @return bool */
	public function requires_acceptance() {
		return self::CHANGED === $this->outcome && $this->delta > 0;
	}

	/** @return array */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(
			'outcome'             => $this->outcome,
			'message'             => $this->message,
			'offer'               => $this->offer,
			'previous_price'      => $this->previous ? $this->previous->to_array() : null,
			'delta'               => $this->delta,
			'delta_formatted'     => $this->previous ? GTC_Currency::format( abs( $this->delta ), $this->offer->get_price()->get_currency() ) : null,
			'bookable'            => $this->is_bookable(),
			'requires_acceptance' => $this->requires_acceptance(),
		);
	}
}
