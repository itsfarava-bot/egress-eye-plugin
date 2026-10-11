<?php
/**
 * Storage value object; deliberately has no URL, headers, body or arguments.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit component values pass through; this class performs no attribution.
 */
final class SlowRequest {

	/**
	 * Canonical host.
	 *
	 * @var string
	 */
	public $host;
	/**
	 * Elapsed microseconds.
	 *
	 * @var int
	 */
	public $duration_us;
	/**
	 * UTC occurrence time.
	 *
	 * @var string
	 */
	public $occurred_at;
	/**
	 * HTTP status.
	 *
	 * @var int|null
	 */
	public $response_code;
	/**
	 * Sanitized error identifier.
	 *
	 * @var string|null
	 */
	public $error_code;
	/**
	 * Provided component category.
	 *
	 * @var string|null
	 */
	public $component_kind;
	/**
	 * Provided component identifier.
	 *
	 * @var string|null
	 */
	public $component_key;
	/**
	 * Provided loopback marker.
	 *
	 * @var bool
	 */
	public $is_loopback;

	/**
	 * Internal identity of the latest atomic aggregate write for this sample.
	 *
	 * @var string|null
	 */
	private $sample_key;

	/**
	 * Build a bounded diagnostic sample from already selected values.
	 *
	 * @param string      $host           Host only.
	 * @param int         $duration_us    Duration in microseconds.
	 * @param int         $unix_time      UTC Unix timestamp.
	 * @param int|null    $response_code  HTTP status.
	 * @param string|null $error_code     Error identifier, never its message.
	 * @param string|null $component_kind Explicit category.
	 * @param string|null $component_key  Explicit component key.
	 * @param bool        $is_loopback    Explicit loopback marker.
	 */
	public function __construct( string $host, int $duration_us, int $unix_time, ?int $response_code = null, ?string $error_code = null, ?string $component_kind = null, ?string $component_key = null, bool $is_loopback = false ) {
		$this->host           = $host;
		$this->duration_us    = $duration_us;
		$this->occurred_at    = gmdate( 'Y-m-d H:i:s', $unix_time );
		$this->response_code  = null !== $response_code && $response_code > 0 && $response_code <= 65535 ? $response_code : null;
		$this->error_code     = $this->identifier( $error_code, 100 );
		$this->component_kind = $this->identifier( $component_kind, 20 );
		$this->component_key  = $this->component_identifier( $component_key );
		$this->is_loopback    = $is_loopback;
	}

	/**
	 * Give each aggregate write a fresh internal identity, including DTO reuse.
	 *
	 * @return void
	 */
	public function begin_recording(): void {
		$this->sample_key = bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Return only a bounded hex identity for internal persistence.
	 *
	 * @return string|null
	 */
	public function sample_key(): ?string {
		return null !== $this->sample_key && 1 === preg_match( '/^[a-f0-9]{32}$/D', $this->sample_key ) ? $this->sample_key : null;
	}

	/**
	 * Keep only explicitly supplied safe keys, never arbitrary messages or paths.
	 *
	 * @param string|null $value Supplied identifier.
	 * @param int         $limit Maximum ASCII length.
	 * @return string|null
	 */
	private function identifier( ?string $value, int $limit ): ?string {
		return null !== $value && strlen( $value ) <= $limit && preg_match( '/^[a-zA-Z0-9_-]+$/D', $value ) ? $value : null;
	}

	/**
	 * Preserve a supplied relative component key without accepting URLs or absolute paths.
	 *
	 * @param string|null $value Explicit recorder component key.
	 * @return string|null
	 */
	private function component_identifier( ?string $value ): ?string {
		return null !== $value && strlen( $value ) <= 191 && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.\/-]*$/D', $value ) ? $value : null;
	}
}
