<?php
/**
 * The contract every supplier adapter implements.
 *
 * Adding a supplier means writing one class against this interface and
 * registering it. Nothing else in the engine changes.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

interface GTC_Provider {

	/**
	 * Machine id, e.g. 'booking_demand'. Stable — it is stored on bookings.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label shown in the compare table and admin.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Categories this adapter can serve, from GTC_Categories.
	 *
	 * @return string[]
	 */
	public function get_categories();

	/**
	 * True when credentials are present and the adapter is enabled.
	 *
	 * @return bool
	 */
	public function is_ready();

	/**
	 * SEARCH — cacheable, indicative pricing, many results.
	 *
	 * @param GTC_Search_Request $request Normalised search.
	 * @return GTC_Offer[]
	 * @throws GTC_Provider_Exception On transport or supplier error.
	 */
	public function search( GTC_Search_Request $request );

	/**
	 * LOOK — never cached. Re-prices a single offer against live inventory
	 * and returns the authoritative, bookable price and policies.
	 *
	 * @param GTC_Offer          $offer   The offer the customer selected.
	 * @param GTC_Search_Request $request The search that produced it.
	 * @return GTC_Offer Re-priced offer, or the same offer with is_available() false.
	 * @throws GTC_Provider_Exception
	 */
	public function look( GTC_Offer $offer, GTC_Search_Request $request );

	/**
	 * BOOK — creates the reservation with the supplier. Must be idempotent
	 * on $booking->get_idempotency_key().
	 *
	 * @param GTC_Offer   $offer   The looked-up (revalidated) offer.
	 * @param GTC_Booking $booking Booking holding traveller and payment context.
	 * @return array {
	 *     @type string $supplier_reference Supplier's confirmation number.
	 *     @type string $status             confirmed|pending|failed.
	 *     @type array  $raw                Raw supplier payload for the audit log.
	 * }
	 * @throws GTC_Provider_Exception
	 */
	public function book( GTC_Offer $offer, GTC_Booking $booking );

	/**
	 * Cancel a reservation, where the supplier supports it.
	 *
	 * @param GTC_Booking $booking Booking to cancel.
	 * @return array
	 * @throws GTC_Provider_Exception
	 */
	public function cancel( GTC_Booking $booking );
}
