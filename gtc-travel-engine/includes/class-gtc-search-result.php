<?php
/**
 * The outcome of one fan-out: the grouped offers plus an honest account of
 * which suppliers answered, which were served from cache and which failed.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Search_Result implements JsonSerializable {

	/** @var GTC_Search_Request */
	private $request;

	/** @var GTC_Offer_Group[] */
	private $groups = array();

	/** @var int */
	private $offer_count = 0;

	/** @var array<string,array> */
	private $providers = array();

	/** @var array<string,string> */
	private $failures = array();

	/** @var string[] */
	private $notices = array();

	/**
	 * @param GTC_Search_Request $request Search.
	 */
	public function __construct( GTC_Search_Request $request ) {
		$this->request = $request;
	}

	/**
	 * @param GTC_Offer_Group[] $groups Groups.
	 */
	public function set_groups( array $groups ) {
		$this->groups = $groups;
	}

	/** @return GTC_Offer_Group[] */
	public function get_groups() {
		return $this->groups;
	}

	/**
	 * @param int $n Offer count before grouping.
	 */
	public function set_offer_count( $n ) {
		$this->offer_count = (int) $n;
	}

	/** @return int */
	public function get_offer_count() {
		return $this->offer_count;
	}

	/** @return int */
	public function get_group_count() {
		return count( $this->groups );
	}

	/**
	 * How many separate listings the dedupe pass removed from the page.
	 *
	 * @return int
	 */
	public function get_merged_count() {
		$n = 0;
		foreach ( $this->groups as $group ) {
			if ( $group->provider_count() > 1 ) {
				$n += $group->provider_count() - 1;
			}
		}
		return $n;
	}

	/**
	 * @param string $provider_id Adapter id.
	 * @param int    $count       Offers returned.
	 * @param int    $ms          Round-trip time.
	 * @param bool   $cached      Served from cache.
	 */
	public function record_provider( $provider_id, $count, $ms, $cached ) {
		$this->providers[ $provider_id ] = array(
			'offers' => (int) $count,
			'ms'     => (int) $ms,
			'cached' => (bool) $cached,
		);
	}

	/**
	 * @param string $provider_id Adapter id.
	 * @param string $message     Failure reason.
	 */
	public function record_failure( $provider_id, $message ) {
		$this->failures[ $provider_id ] = $message;
	}

	/** @return array */
	public function get_providers() {
		return $this->providers;
	}

	/** @return array */
	public function get_failures() {
		return $this->failures;
	}

	/**
	 * @param string $notice Customer-facing notice.
	 */
	public function add_notice( $notice ) {
		$this->notices[] = $notice;
	}

	/** @return string[] */
	public function get_notices() {
		return $this->notices;
	}

	/** @return GTC_Search_Request */
	public function get_request() {
		return $this->request;
	}

	/**
	 * @param int $page     1-based page.
	 * @param int $per_page Groups per page.
	 * @return GTC_Offer_Group[]
	 */
	public function page( $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );
		return array_slice( $this->groups, ( $page - 1 ) * $per_page, $per_page );
	}

	/** @return array */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(
			'search_hash'  => $this->request->get_hash(),
			'request'      => $this->request->to_array(),
			'groups'       => $this->groups,
			'group_count'  => $this->get_group_count(),
			'offer_count'  => $this->offer_count,
			'merged_count' => $this->get_merged_count(),
			'providers'    => $this->providers,
			'failures'     => $this->failures,
			'notices'      => $this->notices,
		);
	}
}
