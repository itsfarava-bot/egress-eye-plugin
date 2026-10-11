<?php
/**
 * Loopback indication without automatic block policy.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Labels the site host and specified local IP forms for later UI warnings.
 */
final class LoopbackDetector {

	/**
	 * Canonical site host.
	 *
	 * @var string|null
	 */
	private $home_host;
	/**
	 * Canonical WordPress installation host, which may differ from home.
	 *
	 * @var string|null
	 */
	private $site_host;

	/**
	 * Resolve the site host once.
	 *
	 * @param HostNormalizer $normalizer Host normalizer.
	 * @param string         $home_url   Site home URL supplied by composition.
	 * @param string|null    $site_url   WordPress installation URL.
	 */
	public function __construct( HostNormalizer $normalizer, string $home_url, ?string $site_url = null ) {
		$this->home_host = $normalizer->fromUrl( $home_url );
		$this->site_host = null !== $site_url ? $normalizer->fromUrl( $site_url ) : $this->home_host;
	}

	/**
	 * Indicate loopback without changing a manual blocking decision.
	 *
	 * @param string $host Host to label.
	 * @return bool
	 */
	public function isLoopback( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );

		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		if ( '' === $host ) {
			return false;
		}

		if ( $host === $this->home_host || $host === $this->site_host || 'localhost' === $host ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return '127' === explode( '.', $host )[0];
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return inet_pton( '::1' ) === inet_pton( $host );
		}

		return false;
	}
}
