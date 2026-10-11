<?php
/**
 * Prepared, silent access to the three bounded diagnostic tables.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores WordPress error settings even when a database operation fails.
 */
final class Database {

	/**
	 * WordPress connection; never creates an additional connection in production.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Wrap the current site's connection.
	 *
	 * @param \wpdb $wpdb WordPress database.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Resolve a fixed logical table name.
	 *
	 * @param string $key Logical table name.
	 * @return string
	 * @throws \InvalidArgumentException For an unsupported logical name.
	 */
	public function table( string $key ): string {
		if ( ! in_array( $key, array( 'domains', 'events', 'audits' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown diagnostic table.' );
		}
		return $this->wpdb->prefix . 'http_diag_' . $key;
	}

	/**
	 * Return the core options table identifier.
	 *
	 * @return string
	 */
	public function options_table(): string {
		return $this->wpdb->options;
	}

	/**
	 * Read core metadata while retaining the same silent SQL boundary.
	 *
	 * @param string $name     Owned option name.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	public function option( string $name, $fallback = false ) {
		$previous = $this->wpdb->suppress_errors( true );
		try {
			return get_option( $name, $fallback );
		} finally {
			$this->wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Save metadata, checking failed writes separately from unchanged values.
	 *
	 * @param string $name     Owned option name.
	 * @param int    $value    Required scalar metadata value.
	 * @param bool   $autoload Autoload policy.
	 * @return bool
	 */
	public function store_option( string $name, int $value, bool $autoload ): bool {
		$previous = $this->wpdb->suppress_errors( true );
		try {
			return update_option( $name, $value, $autoload ) || (string) get_option( $name ) === (string) $value;
		} finally {
			$this->wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Signal a diagnostic notice once, without autoload or repeated updates.
	 *
	 * @param string $name Owned option name.
	 * @return void
	 */
	public function signal_notice( string $name ): void {
		$previous = $this->wpdb->suppress_errors( true );
		try {
			add_option( $name, 1, '', false );
		} finally {
			$this->wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Read the inserted or LAST_INSERT_ID-selected domain id immediately.
	 *
	 * @return int
	 */
	public function inserted_id(): int {
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Decline writes while the shared connection belongs to a caller transaction.
	 *
	 * A unique savepoint probe never commits or rolls back caller data. Without
	 * an active transaction, RELEASE reports the documented missing-savepoint code.
	 *
	 * @return bool
	 * @throws \RuntimeException When transaction ownership cannot be established.
	 */
	public function outside_transaction(): bool {
		if ( 1 !== (int) $this->scalar( 'SELECT @@SESSION.autocommit' ) ) {
			return false;
		}
		$name = 'egress_eye_probe_' . bin2hex( random_bytes( 12 ) );
		$this->execute( 'SAVEPOINT %i', array( $name ) );
		try {
			$this->execute( 'RELEASE SAVEPOINT %i', array( $name ) );
			return false;
		} catch ( \RuntimeException $exception ) {
			$connection = $this->wpdb->dbh;
			if ( $connection instanceof \mysqli && 1305 === $connection->errno ) {
				$this->wpdb->last_error = '';
				return true;
			}
			throw $exception;
		}
	}

	/**
	 * Use WordPress's actual charset and collation.
	 *
	 * @return string
	 */
	public function charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Execute a prepared mutation without printing errors.
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return int
	 */
	public function execute( string $sql, array $args = array() ): int {
		return (int) $this->perform( 'query', $sql, $args );
	}

	/**
	 * Execute a scalar read without printing errors.
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return mixed
	 */
	public function scalar( string $sql, array $args = array() ) {
		return $this->perform( 'get_var', $sql, $args );
	}

	/**
	 * Execute one associative row read.
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return array<string,mixed>|null
	 */
	public function row( string $sql, array $args = array() ): ?array {
		$result = $this->perform( 'get_row', $sql, $args );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Execute an associative row collection read.
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( string $sql, array $args = array() ): array {
		$result = $this->perform( 'get_results', $sql, $args );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Hold a connection-scoped, nonblocking capacity lock until the callback ends.
	 *
	 * @template TResult
	 * @param string             $key      Fixed logical lock key.
	 * @param callable():TResult $callback Protected operation.
	 * @return TResult|null Null if another writer owns the lock.
	 */
	public function with_lock( string $key, callable $callback ) {
		$name = 'egress-eye:' . substr( hash( 'sha256', $this->wpdb->options . ':' . $key ), 0, 48 );
		if ( 1 !== (int) $this->scalar( 'SELECT GET_LOCK(%s, %d)', array( $name, 0 ) ) ) {
			return null;
		}
		try {
			return $callback();
		} finally {
			try {
				$this->scalar( 'SELECT RELEASE_LOCK(%s)', array( $name ) );
			} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Connection loss releases the advisory lock; release failure must not replace the callback result.
				// Connection loss releases its advisory locks on the server.
			}
		}
	}

	/**
	 * Run dbDelta under the same error-output boundary as normal SQL.
	 *
	 * @param array<int,string> $definitions Fixed prepared DDL statements.
	 * @return void
	 * @throws \RuntimeException If core reports a schema error.
	 */
	public function migrate( array $definitions ): void {
		$previous = $this->wpdb->suppress_errors( true );
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $definitions as $definition ) {
				dbDelta( $definition );
				if ( '' !== $this->wpdb->last_error ) {
					throw new \RuntimeException( 'Diagnostic schema operation failed.' );
				}
			}
		} finally {
			$this->wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Prepare fixed DDL with the database's trusted charset suffix.
	 *
	 * @param string $template Schema template containing one identifier placeholder.
	 * @param string $table    Logical table name.
	 * @return string
	 */
	public function schema_sql( string $template, string $table ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The template has only a %i identifier; the suffix comes directly from wpdb.
		return $this->wpdb->prepare( $template, array( $this->table( $table ) ) ) . ' ' . $this->charset_collate() . ';';
	}

	/**
	 * Dispatch only the supported reads/mutation through prepared core methods.
	 *
	 * @param string           $method Core method selector.
	 * @param string           $sql    SQL template.
	 * @param array<int,mixed> $args   Bound values.
	 * @return mixed
	 * @throws \RuntimeException On a silent database failure.
	 */
	private function perform( string $method, string $sql, array $args ) {
		if ( array() === $args ) {
			$sql   .= ' /* %s */';
			$args[] = 'egress-eye';
		}
		$wpdb     = $this->wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom diagnostic tables; block-list caching belongs to DomainRepository.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Every variable template is private to typed repository methods and prepared with all identifiers/values here.
			if ( 'query' === $method ) {
				$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			} elseif ( 'get_var' === $method ) {
				$result = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
			} elseif ( 'get_row' === $method ) {
				$result = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
			} else {
				$result = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			}
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $result || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Diagnostic database operation failed.' );
			}
			return $result;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
}
