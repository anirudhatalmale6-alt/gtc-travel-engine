<?php
/**
 * Maps GTC_* class names onto the includes/ tree.
 *
 * @package GTC
 */

defined( 'ABSPATH' ) || exit;

class GTC_Autoloader {

	/**
	 * Directories searched, in order, for class files.
	 *
	 * @var string[]
	 */
	private static $dirs = array(
		'includes/',
		'includes/contracts/',
		'includes/providers/',
		'includes/gateways/',
		'includes/admin/',
		'includes/public/',
	);

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Class name.
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'GTC_' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		foreach ( self::$dirs as $dir ) {
			$path = GTC_PATH . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
			// Interfaces live as interface-gtc-*.php.
			$iface = GTC_PATH . $dir . 'interface-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			if ( is_readable( $iface ) ) {
				require_once $iface;
				return;
			}
		}
	}
}
