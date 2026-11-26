<?php
/**
 * Thin database layer.
 *
 * Every query that contains a value goes through $wpdb->prepare(): callers
 * pass a query template with placeholders (%i identifier, %s string, %d
 * number) and the values separately. Nothing is ever put into a query by
 * string concatenation.
 *
 * The prepared query runs on the mysqli connection of $wpdb when there is
 * one, so a huge table can be read row by row without buffering it (each())
 * and errors become exceptions, with $wpdb as the fallback for other drivers.
 *
 * The one exception is run_dump_statement(): it runs a statement of the SQL
 * dump in the archive (the migration dump exception, see there).
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper.
 */
class HOLEMI_DB {

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
	 * Fill a query template with $wpdb->prepare(), for running it on the
	 * mysqli link. prepare() hides every "%" of the result behind a
	 * placeholder string that only $wpdb->query() removes again, so it is
	 * removed here (otherwise values such as "100%" or LIKE patterns would
	 * reach the server mangled).
	 *
	 * @param string $query Query template with placeholders.
	 * @param array  $args  Values for the placeholders (at least one).
	 * @return string
	 * @throws HOLEMI_Exception When the template and values do not match.
	 */
	private static function prepare( $query, array $args ) {
		global $wpdb;
		$sql = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is a placeholder template written in this plugin's code; every value is in $args.
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new HOLEMI_Exception( esc_html( 'Could not prepare a database query.' ) );
		}
		return $wpdb->remove_placeholder_escape( $sql );
	}

	/**
	 * Run a query and return all rows.
	 *
	 * @param string $query Query template with placeholders (%i, %s, %d).
	 * @param array  $args  Values for the placeholders (at least one).
	 * @param bool   $assoc Associative rows instead of numeric.
	 * @return array
	 * @throws HOLEMI_Exception On error.
	 */
	public static function rows( $query, array $args, $assoc = false ) {
		global $wpdb;
		$link = self::link();
		if ( ! $link ) {
			// Other database drivers: through $wpdb.
			$wpdb->suppress_errors( true );
			$r = $wpdb->get_results( $wpdb->prepare( $query, $args ), $assoc ? ARRAY_A : ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is a placeholder template written in this plugin's code (never data); every value is in $args and goes through prepare(). A migration has to read the tables directly, and caching would serve stale rows.
			if ( $wpdb->last_error ) {
				throw new HOLEMI_Exception( esc_html( 'Database error: ' . $wpdb->last_error ) );
			}
			return is_array( $r ) ? $r : array();
		}
		$res = self::run( $link, self::prepare( $query, $args ) );
		if ( $res instanceof mysqli_result ) {
			$rows = $res->fetch_all( $assoc ? MYSQLI_ASSOC : MYSQLI_NUM );
			$res->free();
			return $rows;
		}
		return array();
	}

	/**
	 * Run a query that returns no rows.
	 *
	 * @param string $query Query template with placeholders (%i, %s, %d).
	 * @param array  $args  Values for the placeholders (at least one).
	 * @return int Affected rows.
	 * @throws HOLEMI_Exception On error.
	 */
	public static function exec( $query, array $args ) {
		global $wpdb;
		$link = self::link();
		if ( ! $link ) {
			$wpdb->suppress_errors( true );
			$r = $wpdb->query( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is a placeholder template written in this plugin's code (never data); every value is in $args and goes through prepare(). A migration has to change the tables directly.
			if ( false === $r ) {
				throw new HOLEMI_Exception( esc_html( 'Database error: ' . $wpdb->last_error ) );
			}
			return (int) $r;
		}
		$res = self::run( $link, self::prepare( $query, $args ) );
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
		$link = self::link(); // run() may have reconnected.
		return $link ? (int) $link->affected_rows : 0;
	}

	/**
	 * First column of the first row, or null.
	 *
	 * @param string $query Query template with placeholders (%i, %s, %d).
	 * @param array  $args  Values for the placeholders (at least one).
	 * @return string|null
	 * @throws HOLEMI_Exception On error.
	 */
	public static function value( $query, array $args ) {
		$rows = self::rows( $query, $args );
		return isset( $rows[0][0] ) ? $rows[0][0] : null;
	}

	/**
	 * Start a transaction (no SQL text involved).
	 *
	 * @throws HOLEMI_Exception On error.
	 */
	public static function begin() {
		global $wpdb;
		$link = self::link();
		if ( $link ) {
			if ( ! $link->begin_transaction() ) {
				throw new HOLEMI_Exception( esc_html( 'Could not start a database transaction: ' . $link->error ) );
			}
			return;
		}
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Commit the transaction.
	 *
	 * @throws HOLEMI_Exception On error.
	 */
	public static function commit() {
		global $wpdb;
		$link = self::link();
		if ( $link ) {
			if ( ! $link->commit() ) {
				throw new HOLEMI_Exception( esc_html( 'Could not commit a database transaction: ' . $link->error ) );
			}
			return;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Run one statement of the archive's SQL dump.
	 *
	 * This is the migration dump exception: the statement is one line of the
	 * dump file in the archive (DROP TABLE, CREATE TABLE or a multi-row INSERT,
	 * written by HOLEMI_Exporter::dump() like mysqldump does), aimed at this
	 * import's temporary tables. It is complete SQL with its values already
	 * encoded, so it cannot be split into a template and values for
	 * $wpdb->prepare(). Only HOLEMI_Importer::db_import() calls this, and only
	 * after checking that the statement is one of those three kinds.
	 *
	 * @param string $statement One SQL statement from the dump file.
	 * @return void
	 * @throws HOLEMI_Exception On error.
	 */
	public static function run_dump_statement( $statement ) {
		global $wpdb;
		$link = self::link();
		if ( ! $link ) {
			$wpdb->suppress_errors( true );
			$r = $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration dump exception, see the comment above.
			if ( false === $r ) {
				throw new HOLEMI_Exception( esc_html( 'Database error: ' . $wpdb->last_error ) );
			}
			return;
		}
		$res = self::run( $link, $statement );
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
	}

	/**
	 * Execute on the mysqli link, with one automatic reconnect for "server
	 * has gone away". $sql is the output of prepare() or a dump statement
	 * (run_dump_statement()); nothing else reaches this method.
	 *
	 * @param mysqli $link Link.
	 * @param string $sql  Prepared SQL.
	 * @return mysqli_result|true
	 * @throws HOLEMI_Exception On error.
	 */
	private static function run( mysqli $link, $sql ) {
		global $wpdb;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$errno = 0;
			$error = '';
			try {
				$res = @$link->query( $sql ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $res ) {
					return $res;
				}
				$errno = $link->errno;
				$error = $link->error;
			} catch ( Throwable $e ) {
				$errno = (int) $e->getCode();
				$error = $e->getMessage();
			}
			// 2006 = server has gone away, 2013 = lost connection.
			if ( 0 === $attempt && in_array( $errno, array( 2006, 2013 ), true ) && method_exists( $wpdb, 'check_connection' ) ) {
				$wpdb->check_connection( false );
				$link = self::link();
				if ( $link ) {
					continue;
				}
			}
			throw new HOLEMI_Exception( esc_html( sprintf( 'MySQL error %d: %s', $errno, $error ) ) );
		}
		throw new HOLEMI_Exception( esc_html( 'Database connection lost.' ) );
	}

	/**
	 * Stream the rows of a query one by one without buffering the result set
	 * in memory (constant memory use, also for huge tables). No other query
	 * may run on the connection until this returns.
	 *
	 * @param string   $query Query template with placeholders (%i, %s, %d).
	 * @param array    $args  Values for the placeholders (at least one).
	 * @param callable $cb    Called with each numeric row.
	 * @return int Number of rows.
	 * @throws HOLEMI_Exception On error.
	 */
	public static function each( $query, array $args, callable $cb ) {
		$link = self::link();
		if ( ! $link ) {
			$rows = self::rows( $query, $args );
			foreach ( $rows as $r ) {
				$cb( $r );
			}
			return count( $rows );
		}
		$sql = self::prepare( $query, $args );
		try {
			$res = @$link->query( $sql, MYSQLI_USE_RESULT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( Throwable $e ) {
			throw new HOLEMI_Exception( esc_html( 'MySQL error: ' . $e->getMessage() ) );
		}
		if ( false === $res ) {
			throw new HOLEMI_Exception( esc_html( sprintf( 'MySQL error %d: %s', $link->errno, $link->error ) ) );
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
	 * Placeholders for a list of identifiers: "%i,%i,%i".
	 *
	 * @param int    $n   Number of identifiers.
	 * @param string $sep Separator.
	 * @return string
	 */
	public static function idents( $n, $sep = ',' ) {
		return implode( $sep, array_fill( 0, max( 1, (int) $n ), '%i' ) );
	}

	/**
	 * Condition "row comes after $last in primary key order", for keyset
	 * pagination over single or multi-column keys:
	 * (a > x) OR (a = x AND b > y) OR ...
	 * Unlike LIMIT offset, its cost does not grow with the position in the
	 * table. Returns a template piece with placeholders and its values, to be
	 * added to a query template and its values.
	 *
	 * @param string[] $pk   Key columns.
	 * @param array    $last Key values of the last processed row.
	 * @return array array( string $template, array $args ).
	 */
	public static function after( array $pk, array $last ) {
		$pk   = array_values( $pk );
		$last = array_values( $last );
		$or   = array();
		$args = array();
		foreach ( $pk as $i => $col ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[]  = '%i = %s';
				$args[] = $pk[ $j ];
				$args[] = (string) $last[ $j ];
			}
			$and[]  = '%i > %s';
			$args[] = $col;
			$args[] = (string) $last[ $i ];
			$or[]   = '(' . implode( ' AND ', $and ) . ')';
		}
		return array( '(' . implode( ' OR ', $or ) . ')', $args );
	}

	/**
	 * Base tables of this database whose name starts with $prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	public static function tables_with_prefix( $prefix ) {
		$out = array();
		foreach ( self::rows( 'SHOW FULL TABLES WHERE Table_type = %s', array( 'BASE TABLE' ) ) as $row ) {
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
		foreach ( self::rows( 'SHOW COLUMNS FROM %i', array( $table ), true ) as $c ) {
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
		foreach ( self::rows( 'SHOW KEYS FROM %i WHERE Key_name = %s', array( $table, 'PRIMARY' ), true ) as $k ) {
			$keys[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		ksort( $keys );
		return array_values( $keys );
	}

	/**
	 * Status rows (SHOW TABLE STATUS) of the tables whose name starts with
	 * $prefix, by table name.
	 *
	 * @param string $prefix Prefix.
	 * @return array[]
	 */
	public static function table_status( $prefix ) {
		global $wpdb;
		$out = array();
		foreach ( self::rows( 'SHOW TABLE STATUS LIKE %s', array( $wpdb->esc_like( $prefix ) . '%' ), true ) as $r ) {
			$out[ $r['Name'] ] = $r;
		}
		return $out;
	}
}
