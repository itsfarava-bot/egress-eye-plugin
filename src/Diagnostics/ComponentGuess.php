<?php
/**
 * Safe probable-component value object.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribution is always a guess, including unknown results.
 */
final class ComponentGuess {

	/**
	 * Component category.
	 *
	 * @var string
	 */
	public $kind;
	/**
	 * Relative safe component key.
	 *
	 * @var string|null
	 */
	public $key;
	/**
	 * Explicit uncertainty marker.
	 *
	 * @var bool
	 */
	public $is_guess = true;

	/**
	 * Reject malformed or excessively long identifiers.
	 *
	 * @param string      $kind Component category.
	 * @param string|null $key  Relative identifier.
	 */
	public function __construct( string $kind = 'unknown', ?string $key = null ) {
		$valid      = in_array( $kind, array( 'plugin', 'mu-plugin', 'theme', 'core' ), true ) && null !== $key && strlen( $key ) <= 191 && 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $key );
		$this->kind = $valid ? $kind : 'unknown';
		$this->key  = $valid ? $key : null;
	}
}
