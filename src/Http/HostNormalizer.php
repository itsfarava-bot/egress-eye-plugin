<?php
/**
 * Exact HTTP host normalization without DNS or network access.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a canonical host key or null for unsupported or invalid input.
 */
final class HostNormalizer {

	/**
	 * Parse and validate the initial HTTP API destination host.
	 *
	 * @param string $url Original URL.
	 * @return string|null
	 */
	public function fromUrl( string $url ): ?string {
		if ( '' === $url || preg_match( '/[\\x00-\\x20\\x7f]/', $url ) ) {
			return null;
		}

		try {
			$parts = wp_parse_url( $url );

			if (
				! is_array( $parts )
				|| ! isset( $parts['scheme'], $parts['host'] )
				|| ! is_string( $parts['scheme'] )
				|| ! is_string( $parts['host'] )
				|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			) {
				return null;
			}

			$host = strtolower( $parts['host'] );

			if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
				$host = substr( $host, 1, -1 );

				if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
					return null;
				}
			}

			if ( '.' === substr( $host, -1 ) ) {
				$host = substr( $host, 0, -1 );
			}

			if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
				$packed = inet_pton( $host );

				if ( false === $packed ) {
					return null;
				}

				$canonical = inet_ntop( $packed );

				return false !== $canonical ? strtolower( $canonical ) : null;
			}

			if ( false !== strpos( $host, ':' ) || preg_match( '/^[0-9.]+$/D', $host ) ) {
				return null;
			}

			if ( preg_match( '/[^\\x00-\\x7f]/', $host ) ) {
				if ( ! function_exists( 'idn_to_ascii' ) || ! defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
					return null;
				}

				$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );

				if ( false === $ascii ) {
					return null;
				}

				$host = strtolower( $ascii );
			}

			if ( '' === $host || strlen( $host ) > 191 ) {
				return null;
			}

			foreach ( explode( '.', $host ) as $label ) {
				if ( strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label ) ) {
					return null;
				}
			}

			return $host;
		} catch ( \Throwable $exception ) {
			return null;
		}
	}
}
