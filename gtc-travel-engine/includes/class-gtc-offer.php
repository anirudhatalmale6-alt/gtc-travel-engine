<?php
/**
 * A normalised, comparable offer.
 *
 * Every adapter maps its supplier's response into this shape, which is why the
 * compare table, the dedupe matcher, the checkout and the booking record can
 * all stay supplier-agnostic. Supplier-specific data that only that adapter
 * understands (rate tokens, session ids, room keys) rides along in
 * $supplier_data and is handed straight back to the adapter at Look and Book.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Offer implements JsonSerializable {

	/** @var string Adapter id that produced this offer. */
	private $provider_id;

	/** @var string Category slug. */
	private $category;

	/** @var string Supplier's own offer/rate id. */
	private $offer_id;

	/** @var GTC_Price */
	private $price;

	/** @var array Product identity: name, geo, address, rating, images. */
	private $product = array();

	/** @var array Rate detail: room/fare name, board, refundability, policies. */
	private $rate = array();

	/** @var array Opaque adapter payload, round-tripped to Look and Book. */
	private $supplier_data = array();

	/**
	 * Where the customer completes this booking when the site refers out
	 * instead of selling. Empty for merchant APIs, which have no such page.
	 *
	 * @var string
	 */
	private $deeplink = '';

	/** @var bool */
	private $available = true;

	/** @var string|null Set when Look reports the offer is gone. */
	private $unavailable_reason = null;

	/**
	 * @param string    $provider_id Adapter id.
	 * @param string    $category    Category slug.
	 * @param string    $offer_id    Supplier offer id.
	 * @param GTC_Price $price       Itemised price.
	 */
	public function __construct( $provider_id, $category, $offer_id, GTC_Price $price ) {
		$this->provider_id = $provider_id;
		$this->category    = $category;
		$this->offer_id    = (string) $offer_id;
		$this->price       = $price;
	}

	/* ---------------------------------------------------------------- product */

	/**
	 * @param array $product {
	 *     @type string $name        Display name.
	 *     @type string $property_id Supplier's property id.
	 *     @type float  $lat
	 *     @type float  $lng
	 *     @type string $address
	 *     @type string $city
	 *     @type string $country     ISO 3166-1 alpha-2.
	 *     @type float  $star_rating
	 *     @type float  $review_score
	 *     @type int    $review_count
	 *     @type array  $images
	 *     @type array  $amenities
	 *     @type array  $external_ids Cross-supplier ids (giata, chain codes).
	 * }
	 * @return $this
	 */
	public function set_product( array $product ) {
		$this->product = wp_parse_args(
			$product,
			array(
				'name'         => '',
				'property_id'  => '',
				'lat'          => null,
				'lng'          => null,
				'address'      => '',
				'city'         => '',
				'country'      => '',
				'star_rating'  => null,
				'review_score' => null,
				'review_count' => 0,
				'images'       => array(),
				'amenities'    => array(),
				'external_ids' => array(),
			)
		);
		return $this;
	}

	/**
	 * @param string $key     Product key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function product( $key, $default = null ) {
		return array_key_exists( $key, $this->product ) ? $this->product[ $key ] : $default;
	}

	/** @return array */
	public function get_product() {
		return $this->product;
	}

	/* ------------------------------------------------------------------- rate */

	/**
	 * @param array $rate {
	 *     @type string $name          Room or fare name.
	 *     @type string $board         room_only|breakfast|half_board|all_inclusive.
	 *     @type bool   $refundable
	 *     @type string $cancellation  Human policy text.
	 *     @type array  $inclusions    Bullet list shown before payment.
	 *     @type array  $policies      Key/value policy pairs.
	 *     @type int    $rooms_left    Urgency signal, 0 when unknown.
	 *     @type bool   $pay_at_property
	 * }
	 * @return $this
	 */
	public function set_rate( array $rate ) {
		$this->rate = wp_parse_args(
			$rate,
			array(
				'name'            => '',
				'board'           => 'room_only',
				'refundable'      => false,
				'cancellation'    => '',
				'inclusions'      => array(),
				'policies'        => array(),
				'rooms_left'      => 0,
				'pay_at_property' => false,
			)
		);
		return $this;
	}

	/**
	 * @param string $key     Rate key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function rate( $key, $default = null ) {
		return array_key_exists( $key, $this->rate ) ? $this->rate[ $key ] : $default;
	}

	/** @return array */
	public function get_rate() {
		return $this->rate;
	}

	/* --------------------------------------------------------------- accessors */

	/** @return string */
	public function get_provider_id() {
		return $this->provider_id;
	}

	/** @return string */
	public function get_category() {
		return $this->category;
	}

	/** @return string */
	public function get_offer_id() {
		return $this->offer_id;
	}

	/** @return GTC_Price */
	public function get_price() {
		return $this->price;
	}

	/**
	 * @param GTC_Price $price Replacement price (used by Look and by markup).
	 * @return $this
	 */
	public function set_price( GTC_Price $price ) {
		$this->price = $price;
		return $this;
	}

	/**
	 * @param array $data Adapter payload.
	 * @return $this
	 */
	public function set_supplier_data( array $data ) {
		$this->supplier_data = $data;
		return $this;
	}

	/** @return array */
	public function get_supplier_data() {
		return $this->supplier_data;
	}

	/**
	 * @param string $url Supplier's own booking page for this offer.
	 * @return $this
	 */
	public function set_deeplink( $url ) {
		$this->deeplink = esc_url_raw( (string) $url );
		return $this;
	}

	/**
	 * Where the customer finishes the booking when the site refers out. Empty
	 * for merchant APIs — those exist so the site sells the room itself, and
	 * they publish no customer-facing page to send anyone to.
	 *
	 * @return string
	 */
	public function get_deeplink() {
		return $this->deeplink;
	}

	/** @return bool */
	public function has_deeplink() {
		return '' !== $this->deeplink;
	}

	/** @return bool */
	public function is_available() {
		return $this->available;
	}

	/**
	 * @param string $reason Why the offer can no longer be sold.
	 * @return $this
	 */
	public function mark_unavailable( $reason ) {
		$this->available          = false;
		$this->unavailable_reason = $reason;
		return $this;
	}

	/** @return string|null */
	public function get_unavailable_reason() {
		return $this->unavailable_reason;
	}

	/**
	 * Identity of this exact offer within a search. Two offers from different
	 * suppliers never collide because the provider id is part of the key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->provider_id . ':' . md5( $this->offer_id . '|' . $this->product( 'property_id' ) );
	}

	/* -------------------------------------------------------- serialisation */

	/** @return array */
	public function to_array() {
		return array(
			'provider_id'   => $this->provider_id,
			'category'      => $this->category,
			'offer_id'      => $this->offer_id,
			'price'         => $this->price->to_array(),
			'product'       => $this->product,
			'rate'          => $this->rate,
			'supplier_data' => $this->supplier_data,
			'deeplink'      => $this->deeplink,
			'available'     => $this->available,
		);
	}

	/**
	 * @param array $data Output of to_array().
	 * @return GTC_Offer
	 */
	public static function from_array( array $data ) {
		$offer = new self(
			$data['provider_id'],
			$data['category'],
			$data['offer_id'],
			GTC_Price::from_array( isset( $data['price'] ) ? (array) $data['price'] : array() )
		);
		$offer->set_product( isset( $data['product'] ) ? (array) $data['product'] : array() );
		$offer->set_rate( isset( $data['rate'] ) ? (array) $data['rate'] : array() );
		$offer->set_supplier_data( isset( $data['supplier_data'] ) ? (array) $data['supplier_data'] : array() );
		if ( ! empty( $data['deeplink'] ) ) {
			$offer->set_deeplink( $data['deeplink'] );
		}
		if ( isset( $data['available'] ) && ! $data['available'] ) {
			$offer->mark_unavailable( '' );
		}
		return $offer;
	}

	/**
	 * What is safe to send to the browser.
	 *
	 * supplier_data holds rate tokens and never leaves the server. The deeplink
	 * is withheld too — it carries the affiliate identifier, and outbound
	 * traffic goes through a logged redirect so clicks can be reconciled
	 * against commission. The browser gets a boolean instead.
	 *
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		$out = $this->to_array();
		unset( $out['supplier_data'] );
		unset( $out['deeplink'] );
		$out['has_deeplink'] = $this->has_deeplink();
		return $out;
	}
}
