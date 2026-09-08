<?php
/**
 * Sandbox supplier B.
 *
 * Carries an overlapping but not identical property set to supplier A, under
 * different names, different property ids and slightly different coordinates —
 * which is what the duplicate matcher exists to reconcile.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Provider_Sandbox_Beta extends GTC_Provider_Sandbox_Base {

	/** @return string */
	public function get_id() {
		return 'sandbox_beta';
	}

	/** @return string */
	public function get_label() {
		return __( 'Sandbox Supplier B', 'gtc' );
	}

	/**
	 * Supplier B sells a narrower room mix — a real supplier's rate plan list
	 * never matches another's, and the compare table has to cope with that.
	 *
	 * @var array
	 */
	protected $room_types = array(
		array(
			'code'       => 'STD-SAVER',
			'name'       => 'Double Room (saver rate)',
			'mult'       => 0.96,
			'board'      => 'room_only',
			'refundable' => false,
		),
		array(
			'code'       => 'STD',
			'name'       => 'Double Room',
			'mult'       => 1.09,
			'board'      => 'room_only',
			'refundable' => true,
		),
		array(
			'code'       => 'STD-BB',
			'name'       => 'Double Room with breakfast',
			'mult'       => 1.24,
			'board'      => 'breakfast',
			'refundable' => true,
		),
		array(
			'code'       => 'DLX-BB',
			'name'       => 'Superior Room, breakfast included',
			'mult'       => 1.41,
			'board'      => 'breakfast',
			'refundable' => true,
		),
	);
}
