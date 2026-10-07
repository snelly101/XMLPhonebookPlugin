<?php
/**
 * Small database helpers (transactions, error surfacing).
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction helpers around $wpdb.
 */
final class Db {

	/**
	 * Nesting depth of begin() calls.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Whether the outermost unit of work should be a savepoint instead of a
	 * real transaction. Used when caller code (for example the WordPress test
	 * suite) already holds an open transaction, because START TRANSACTION
	 * would implicitly commit it.
	 *
	 * @return bool
	 */
	private static function nested_only() {
		return (bool) apply_filters( 'spb_db_in_outer_transaction', false );
	}

	/**
	 * Start a transaction (or a savepoint when nested).
	 */
	public static function begin() {
		global $wpdb;
		if ( 0 === self::$depth && ! self::nested_only() ) {
			$wpdb->query( 'START TRANSACTION' );
		} else {
			$wpdb->query( 'SAVEPOINT spb_sp_' . ( self::$depth + 1 ) );
		}
		++self::$depth;
	}

	/**
	 * Commit (or release the savepoint when nested).
	 */
	public static function commit() {
		global $wpdb;
		if ( self::$depth <= 0 ) {
			return;
		}
		if ( 1 === self::$depth && ! self::nested_only() ) {
			$wpdb->query( 'COMMIT' );
		} else {
			$wpdb->query( 'RELEASE SAVEPOINT spb_sp_' . self::$depth );
		}
		--self::$depth;
	}

	/**
	 * Roll back (to the savepoint when nested).
	 */
	public static function rollback() {
		global $wpdb;
		if ( self::$depth <= 0 ) {
			return;
		}
		if ( 1 === self::$depth && ! self::nested_only() ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT spb_sp_' . self::$depth );
		}
		--self::$depth;
	}

	/**
	 * Throw if the last query produced a database error.
	 *
	 * @param string $context Description for the exception.
	 * @throws \RuntimeException On database error.
	 */
	public static function check( $context = 'query' ) {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			$message = $wpdb->last_error;
			$wpdb->last_error = '';
			throw new \RuntimeException( $context . ': ' . $message );
		}
	}

	/**
	 * Current GMT timestamp in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Run a callback inside a transaction, rolling back on any exception.
	 *
	 * @param callable $callback Work to do; return value is passed through.
	 * @return mixed
	 * @throws \Throwable Re-throws after rollback.
	 */
	public static function transaction( callable $callback ) {
		global $wpdb;
		$wpdb->last_error = '';
		self::begin();
		try {
			$result = $callback();
			self::commit();
			return $result;
		} catch ( \Throwable $e ) {
			self::rollback();
			throw $e;
		}
	}
}
