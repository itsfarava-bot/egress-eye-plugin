<?php
/**
 * Storage schema and long-host policy regression tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Persistence;

use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HostNormalizer;
use EgressEye\Persistence\SchemaManager;
use EgressEye\Tests\Support\InMemoryDomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Checks portable DDL constraints without substituting a fake database.
 */
final class SchemaDefinitionTest extends TestCase {

	/**
	 * Indexed text must fit a 767-byte utf8mb4 key budget.
	 *
	 * @return void
	 */
	public function test_schema_varchars_are_at_most_191_and_primary_keys_are_portable(): void {
		$definitions = SchemaManager::definition_templates();
		self::assertCount( 3, $definitions );

		foreach ( $definitions as $sql ) {
			self::assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
			self::assertStringNotContainsString( 'FOREIGN KEY', $sql );
			self::assertStringNotContainsString( 'ENGINE=', $sql );
			self::assertStringNotContainsString( '0000-00-00', $sql );
			preg_match_all( '/varchar\((\d+)\)/i', $sql, $matches );

			foreach ( $matches[1] as $length ) {
				self::assertLessThanOrEqual( 191, (int) $length );
			}
		}
	}

	/**
	 * A valid DNS host of length 200 is ignored before a block lookup.
	 *
	 * @return void
	 */
	public function test_200_character_host_is_not_normalized_or_blocked(): void {
		$host                      = str_repeat( 'a', 63 ) . '.' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.dddddddd';
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( $host );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );

		self::assertSame( 200, strlen( $host ) );
		self::assertNull( ( new HostNormalizer() )->fromUrl( 'https://' . $host . '/' ) );
		self::assertFalse( $guard->filter( false, array(), 'https://' . $host . '/' ) );
		self::assertSame( 0, $repository->read_calls );
	}
}
