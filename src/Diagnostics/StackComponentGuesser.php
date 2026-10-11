<?php
/**
 * Bounded path attribution using WordPress path conventions.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\ComponentGuesser;

/**
 * Ambiguous source paths remain unknown rather than claiming a culprit.
 */
final class StackComponentGuesser implements ComponentGuesser {

	/**
	 * Normalized recognized roots.
	 *
	 * @var array<string,string>
	 */
	private $roots;
	/**
	 * Normalized installation root.
	 *
	 * @var string
	 */
	private $core_root;
	/**
	 * Normalized own plugin root.
	 *
	 * @var string
	 */
	private $own_root;

	/**
	 * Permit literal roots in unit fixtures and actual WordPress defaults.
	 *
	 * @param string|null $plugins Plugin root.
	 * @param string|null $mu      Must-use root.
	 * @param string|null $themes  Theme root.
	 * @param string|null $core    Installation root.
	 * @param string|null $own     Own directory.
	 */
	public function __construct( ?string $plugins = null, ?string $mu = null, ?string $themes = null, ?string $core = null, ?string $own = null ) {
		$this->roots = array(
			'plugin'    => wp_normalize_path( null !== $plugins ? $plugins : WP_PLUGIN_DIR ),
			'mu-plugin' => wp_normalize_path( null !== $mu ? $mu : WPMU_PLUGIN_DIR ),
			'theme'     => wp_normalize_path( null !== $themes ? $themes : get_theme_root() ),
		);
		uasort(
			$this->roots,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);
		$this->core_root = wp_normalize_path( null !== $core ? $core : ABSPATH );
		$this->own_root  = wp_normalize_path( null !== $own ? $own : dirname( __DIR__, 2 ) );
	}

	/**
	 * Map at most thirty frames and discard every raw stack after returning.
	 *
	 * @param array<int,array<string,mixed>> $trace Argument-free metadata.
	 * @return ComponentGuess
	 */
	public function guess( array $trace ): ComponentGuess {
		$candidates   = array();
		$direct       = array();
		$core_seen    = false;
		$unattributed = false;
		$own          = dirname( $this->logical_path( rtrim( $this->own_root, '/' ) . '/.egress-eye-source' ) );
		foreach ( array_slice( $trace, 0, 30 ) as $frame ) {
			if ( ! isset( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}
			$file = $this->logical_path( wp_normalize_path( $frame['file'] ) );
			if ( $this->self_frame( $file, $own ) ) {
				continue;
			}
			$recognized = false;
			foreach ( $this->roots as $kind => $root ) {
				$relative = $this->relative( $file, $root );
				if ( null === $relative ) {
					continue;
				}
				$recognized = true;
				$key        = explode( '/', $relative )[0];
				if ( $this->windows_path( $file ) ) {
					$key = strtolower( $key );
				}
				$guess = new ComponentGuess( $kind, $key );
				if ( 'unknown' !== $guess->kind ) {
					$candidates[ $kind . ':' . $key ] = $guess;
					if ( $this->request_call_site( $frame ) ) {
						$direct[ $kind . ':' . $key ] = $guess;
					}
				} else {
					$unattributed = true;
				}
				break;
			}
			$core_relative = $this->relative( $file, $this->core_root );
			if ( ! $recognized && null !== $core_relative && 1 === preg_match( '#^wp-(includes|admin)/#i', $core_relative ) && ! $this->http_frame( $core_relative ) ) {
				$core_seen = true;
				if ( $this->request_call_site( $frame ) ) {
					// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stable machine key.
					$direct['core'] = new ComponentGuess( 'core', 'wordpress' );
				}
			}
		}
		if ( $unattributed ) {
			return new ComponentGuess();
		}
		if ( ! empty( $direct ) ) {
			return 1 === count( $direct ) ? array_values( $direct )[0] : new ComponentGuess();
		}
		if ( 1 === count( $candidates ) ) {
			return array_values( $candidates )[0];
		}
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stable machine key, not a display label.
		return empty( $candidates ) && $core_seen ? new ComponentGuess( 'core', 'wordpress' ) : new ComponentGuess();
	}

	/**
	 * Named HTTP API frames identify their caller file rather than the dispatcher.
	 *
	 * @param array<string,mixed> $frame Argument-free call metadata.
	 * @return bool
	 */
	private function request_call_site( array $frame ): bool {
		if ( ! isset( $frame['function'] ) || ! is_string( $frame['function'] ) || isset( $frame['class'] ) ) {
			return false;
		}
		return in_array( strtolower( ltrim( $frame['function'], '\\' ) ), array( 'wp_remote_request', 'wp_remote_get', 'wp_remote_post', 'wp_remote_head' ), true );
	}

	/**
	 * Resolve registered symlinks with WordPress's plugin basename mapping.
	 *
	 * @param string $file Normalized physical filename.
	 * @return string
	 */
	private function logical_path( string $file ): string {
		global $wp_plugin_paths;
		if ( ! is_array( $wp_plugin_paths ) ) {
			return $file;
		}
		$paths = array_filter( $wp_plugin_paths, 'is_string' );
		uasort(
			$paths,
			static function ( string $left, string $right ): int {
				return strlen( wp_normalize_path( $right ) ) <=> strlen( wp_normalize_path( $left ) );
			}
		);
		foreach ( $paths as $logical => $physical ) {
			if ( ! is_string( $logical ) || null === $this->relative( $file, wp_normalize_path( $physical ) ) ) {
				continue;
			}
			$physical = wp_normalize_path( $physical );
			// Ignore a checkout-parent mapping for a nested WordPress content root.
			foreach ( array_merge( array_values( $this->roots ), array( $this->core_root ) ) as $root ) {
				if ( strlen( $physical ) < strlen( $root ) && null !== $this->relative( $root . '/.root', $physical ) && null !== $this->relative( $file, $root ) ) {
					continue 2;
				}
			}
			$suffix = $this->relative( $file, $physical );
			$mapped = wp_normalize_path( $logical ) . '/' . $suffix;
			foreach ( array( 'plugin', 'mu-plugin' ) as $kind ) {
				$relative = $this->relative( $mapped, $this->roots[ $kind ] );
				if ( null !== $relative ) {
					$basename = plugin_basename( rtrim( $physical, '/' ) . '/' . $suffix );
					// Overlapping artificial checkout mappings may rewrite the already mapped path again.
					return rtrim( $this->roots[ $kind ], '/' ) . '/' . ( $basename === $relative ? $basename : $relative );
				}
			}
			return $mapped;
		}
		return $file;
	}

	/**
	 * Exclude our source while preserving any nested WordPress installation roots.
	 *
	 * @param string $file Logical filename.
	 * @param string $own  Logical own directory.
	 * @return bool
	 */
	private function self_frame( string $file, string $own ): bool {
		if ( null === $this->relative( $file, $own ) ) {
			return false;
		}
		foreach ( array_merge( array_values( $this->roots ), array( $this->core_root ) ) as $root ) {
			if ( strlen( $own ) < strlen( $root ) && null !== $this->relative( $root . '/.root', $own ) && null !== $this->relative( $file, $root ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Recognize drive-letter paths and normalized Windows network shares.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	private function windows_path( string $path ): bool {
		return 1 === preg_match( '#^(?:[a-z]:|//[^/]+/[^/]+)#i', $path );
	}

	/**
	 * Match directory boundaries; Windows drive paths are case-insensitive.
	 *
	 * @param string $file Normalized filename.
	 * @param string $root Normalized directory.
	 * @return string|null
	 */
	private function relative( string $file, string $root ): ?string {
		$prefix  = rtrim( $root, '/' ) . '/';
		$windows = $this->windows_path( $prefix );
		$matches = $windows ? 0 === strncasecmp( $file, $prefix, strlen( $prefix ) ) : 0 === strncmp( $file, $prefix, strlen( $prefix ) );
		return $matches ? substr( $file, strlen( $prefix ) ) : null;
	}

	/**
	 * Framework dispatch and HTTP transport frames do not establish ownership.
	 *
	 * @param string $relative Core-relative filename.
	 * @return bool
	 */
	private function http_frame( string $relative ): bool {
		return 1 === preg_match( '#^wp-includes/(?:http(?:/|\.php)|class-(?:wp-http[^/]*|http|wp-hook)\.php|Requests/|plugin\.php)#i', $relative );
	}
}
