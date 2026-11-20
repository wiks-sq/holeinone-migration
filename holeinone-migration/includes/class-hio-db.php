<?php
/**
 * Thin database layer. Uses the mysqli connection of $wpdb directly so large
 * statements are not mangled by wpdb's query checks, with a $wpdb fallback.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper.
 */
class HIO_DB {

	/**
	 * The mysqli link or null when WordPress uses another driver.
	 *
	 * @return mysqli|null
	 */
	private static function link() {
		global $wpdb;
		$h = isset( $wpdb->dbh ) ? $wpdb->dbh : null;
		return ( $h instanceof mysqli ) ? $h : null;
	}

	/**
	 * Run a query and return all rows.
	 *
	 * @param string $sql   SQL.
	 * @param bool   $assoc Associative rows instead of numeric.
	 * @return array
	 * @throws HIO_Exception On error.
	 */
	public static function rows( $sql, $assoc = false ) {
		$res = self::raw( $sql );
		if ( true === $res ) {
			return array();
		}
		if ( $res instanceof mysqli_result ) {
			$rows = $res->fetch_all( $assoc ? MYSQLI_ASSOC : MYSQLI_NUM );
			$res->free();
			return $rows;
		}
		return $res; // wpdb fallback already returns rows.
	}

	/**
	 * Run a query that returns no rows.
	 *
	 * @param string $sql SQL.
	 * @return int Affected rows.
	 * @throws HIO_Exception On error.
	 */
	public static function exec( $sql ) {
		$res = self::raw( $sql, false );
		$l   = self::link();
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
		if ( $l ) {
			return (int) $l->affected_rows;
		}
		global $wpdb;
		return (int) $wpdb->rows_affected;
	}

	/**
	 * First column of the first row, or null.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public static function value( $sql ) {
		$rows = self::rows( $sql );
		return isset( $rows[0][0] ) ? $rows[0][0] : null;
	}

	/**
	 * Execute with one automatic reconnect for "server has gone away".
	 *
	 * @param string $sql        SQL.
	 * @param bool   $want_rows  Whether rows are expected (wpdb fallback only).
	 * @return mixed
	 * @throws HIO_Exception On error.
	 */
	private static function raw( $sql, $want_rows = true ) {
		global $wpdb;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$link = self::link();
			if ( $link ) {
				$errno = 0;
				$error = '';
				try {
					$res = @$link->query( $sql ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false === $res ) {
						$errno = $link->errno;
						$error = $link->error;
					} else {
						return $res;
					}
				} catch ( Throwable $e ) {
					$errno = (int) $e->getCode();
					$error = $e->getMessage();
				}
				// 2006 = server has gone away, 2013 = lost connection.
				if ( 0 === $attempt && in_array( $errno, array( 2006, 2013 ), true ) && method_exists( $wpdb, 'check_connection' ) ) {
					$wpdb->check_connection( false );
					continue;
				}
				throw new HIO_Exception( esc_html( sprintf( 'MySQL error %d: %s', $errno, $error ) ) );
			}

			// Fallback through $wpdb (non-mysqli drivers).
			$wpdb->suppress_errors( true );
			if ( $want_rows && preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)/i', $sql ) ) {
				// $sql is built by this plugin with every value escaped (see quote()) or comes from its own dump file.
				$r = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
				if ( $wpdb->last_error ) {
					throw new HIO_Exception( esc_html( 'Database error: ' . $wpdb->last_error ) );
				}
				return is_array( $r ) ? $r : array();
			}
			$r = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( false === $r ) {
				throw new HIO_Exception( esc_html( 'Database error: ' . $wpdb->last_error ) );
			}
			return true;
		}
		throw new HIO_Exception( esc_html( 'Database connection lost.' ) );
	}

	/**
	 * Stream the rows of a query one by one without buffering the result set
	 * in memory (constant memory use, also for huge tables). No other query
	 * may run on the connection until this returns.
	 *
	 * @param string   $sql SQL.
	 * @param callable $cb  Called with each numeric row.
	 * @return int Number of rows.
	 * @throws HIO_Exception On error.
	 */
	public static function each( $sql, callable $cb ) {
		$link = self::link();
		if ( ! $link ) {
			$rows = self::rows( $sql );
			foreach ( $rows as $r ) {
				$cb( $r );
			}
			return count( $rows );
		}
		try {
			$res = @$link->query( $sql, MYSQLI_USE_RESULT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( Throwable $e ) {
			throw new HIO_Exception( esc_html( 'MySQL error: ' . $e->getMessage() ) );
		}
		if ( false === $res ) {
			throw new HIO_Exception( esc_html( sprintf( 'MySQL error %d: %s', $link->errno, $link->error ) ) );
		}
		$n = 0;
		try {
			while ( null !== ( $row = $res->fetch_row() ) && false !== $row ) { // phpcs:ignore
				$cb( $row );
				$n++;
			}
		} finally {
			$res->free();
		}
		return $n;
	}

	/**
	 * Condition "row comes after $last in primary key order", for keyset
	 * pagination over single or multi-column keys:
	 * (a > x) OR (a = x AND b > y) OR ...
	 * Unlike LIMIT offset, its cost does not grow with the position in the table.
	 *
	 * @param string[] $pk   Key columns.
	 * @param array    $last Key values of the last processed row.
	 * @return string
	 */
	public static function after( array $pk, array $last ) {
		$or = array();
		foreach ( $pk as $i => $col ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[] = HIO_Util::ident( $pk[ $j ] ) . ' = ' . self::quote( $last[ $j ] );
			}
			$and[] = HIO_Util::ident( $col ) . ' > ' . self::quote( $last[ $i ] );
			$or[]  = '(' . implode( ' AND ', $and ) . ')';
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * Quote a value as SQL literal.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function quote( $v ) {
		if ( null === $v ) {
			return 'NULL';
		}
		return "'" . HIO_Util::sql_escape( $v ) . "'";
	}

	/**
	 * Base tables of this database whose name starts with $prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	public static function tables_with_prefix( $prefix ) {
		$out = array();
		foreach ( self::rows( 'SHOW FULL TABLES' ) as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' !== strtoupper( $row[1] ) ) {
				continue;
			}
			if ( '' === $prefix || 0 === strpos( $row[0], $prefix ) ) {
				$out[] = $row[0];
			}
		}
		return $out;
	}

	/**
	 * Column info: name, type, generated?
	 *
	 * @param string $table Table.
	 * @return array[]
	 */
	public static function columns( $table ) {
		$out = array();
		foreach ( self::rows( 'SHOW COLUMNS FROM ' . HIO_Util::ident( $table ), true ) as $c ) {
			$extra = isset( $c['Extra'] ) ? strtoupper( $c['Extra'] ) : '';
			$out[] = array(
				'name'      => $c['Field'],
				'type'      => strtolower( $c['Type'] ),
				'generated' => ( false !== strpos( $extra, 'GENERATED' ) ),
			);
		}
		return $out;
	}

	/**
	 * Primary key column names in order.
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	public static function primary_key( $table ) {
		$keys = array();
		foreach ( self::rows( 'SHOW KEYS FROM ' . HIO_Util::ident( $table ) . " WHERE Key_name = 'PRIMARY'", true ) as $k ) {
			$keys[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		ksort( $keys );
		return array_values( $keys );
	}
}
