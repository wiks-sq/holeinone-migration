<?php
/**
 * Resumable .tar.gz writer.
 *
 * The archive is a standard GNU tar stream compressed as a sequence of gzip
 * members. A multi-member gzip file is part of the gzip standard (RFC 1952)
 * and is read transparently by tar, 7-Zip, WinRAR, macOS Archive Utility, etc.
 *
 * Because every request closes its gzip member, the file on disk is always
 * valid up to the last checkpoint. If a request dies halfway, the next one
 * truncates the file back to the checkpoint size and carries on.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tar.gz writer.
 */
class HIO_Tar_Writer {

	/**
	 * Start a new gzip member after this many uncompressed bytes. This keeps
	 * every member small, which lets the importer checkpoint between members.
	 */
	const MEMBER_CAP = 8388608;

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $fp;

	/**
	 * Deflate context of the current member.
	 *
	 * @var DeflateContext|resource|null
	 */
	private $ctx = null;

	/**
	 * Uncompressed bytes in the current member.
	 *
	 * @var int
	 */
	private $member_raw = 0;

	/**
	 * Persistent state.
	 *
	 * @var array
	 */
	private $st;

	/**
	 * Open (or resume) an archive.
	 *
	 * @param string $path  Archive path.
	 * @param array  $state Saved state.
	 * @throws HIO_Exception If the file cannot be opened.
	 */
	public function __construct( $path, array $state ) {
		$this->st = array_merge(
			array(
				'size'      => 0,
				'remaining' => 0,
				'pad'       => 0,
			),
			$state
		);
		if ( ! function_exists( 'deflate_init' ) ) {
			throw new HIO_Exception( __( 'The PHP zlib extension is missing. Choose the ZIP format in Settings or ask your host to enable zlib.', 'holeinone-migration' ) );
		}
		$this->fp = @fopen( $path, 'c+b' ); // phpcs:ignore
		if ( ! $this->fp ) {
			throw new HIO_Exception( __( 'Could not open the archive for writing.', 'holeinone-migration' ) );
		}
		ftruncate( $this->fp, (int) $this->st['size'] );
		fseek( $this->fp, 0, SEEK_END );
	}

	/**
	 * Write uncompressed tar bytes.
	 *
	 * @param string $data Bytes.
	 */
	private function raw( $data ) {
		if ( '' === $data ) {
			return;
		}
		if ( null === $this->ctx ) {
			$this->ctx = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
		}
		$out = deflate_add( $this->ctx, $data, ZLIB_NO_FLUSH );
		if ( '' !== $out ) {
			HIO_Util::fwrite_all( $this->fp, $out );
		}
		$this->member_raw += strlen( $data );
		if ( $this->member_raw >= self::MEMBER_CAP ) {
			$this->end_member();
		}
	}

	/**
	 * Finish the current gzip member.
	 */
	private function end_member() {
		if ( null !== $this->ctx ) {
			HIO_Util::fwrite_all( $this->fp, deflate_add( $this->ctx, '', ZLIB_FINISH ) );
			$this->ctx        = null;
			$this->member_raw = 0;
		}
	}

	/**
	 * Octal field.
	 *
	 * @param int $n   Number.
	 * @param int $len Field length.
	 * @return string
	 */
	private static function oct( $n, $len ) {
		return str_pad( decoct( max( 0, (int) $n ) ), $len - 1, '0', STR_PAD_LEFT ) . "\0";
	}

	/**
	 * Build one 512 byte header block.
	 *
	 * @param string $name  Name (max 100 bytes used).
	 * @param int    $size  Size.
	 * @param int    $mtime Modification time.
	 * @param string $type  Type flag.
	 * @return string
	 */
	private static function block( $name, $size, $mtime, $type ) {
		if ( $size < 8589934592 ) {
			$size_field = self::oct( $size, 12 );
		} else {
			// GNU base-256 encoding for files of 8 GB and more.
			$size_field = "\x80\0\0\0" . pack( 'J', $size );
		}
		$mtime = ( $mtime > 0 && $mtime < 8589934591 ) ? $mtime : time();
		$h     = str_pad( substr( $name, 0, 100 ), 100, "\0" )
			. self::oct( '5' === $type ? 0755 : 0644, 8 )
			. self::oct( 0, 8 )
			. self::oct( 0, 8 )
			. $size_field
			. self::oct( $mtime, 12 )
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar  \0"
			. str_pad( 'www', 32, "\0" )
			. str_pad( 'www', 32, "\0" )
			. str_repeat( "\0", 8 )
			. str_repeat( "\0", 8 )
			. str_repeat( "\0", 155 );
		$h   = str_pad( $h, 512, "\0" );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/**
	 * Write entry header(s), using a GNU long name entry when needed.
	 *
	 * @param string $name  Name.
	 * @param int    $size  Size.
	 * @param int    $mtime Modification time.
	 * @param string $type  Type flag.
	 */
	private function header( $name, $size, $mtime, $type ) {
		if ( strlen( $name ) > 100 ) {
			$long = $name . "\0";
			$this->raw( self::block( '././@LongLink', strlen( $long ), 0, 'L' ) );
			$this->raw( $long . str_repeat( "\0", ( 512 - strlen( $long ) % 512 ) % 512 ) );
		}
		$this->raw( self::block( $name, $size, $mtime, $type ) );
	}

	/**
	 * Add a directory entry.
	 *
	 * @param string $name  Name (without trailing slash).
	 * @param int    $mtime Modification time.
	 */
	public function add_dir( $name, $mtime ) {
		$this->header( rtrim( $name, '/' ) . '/', 0, $mtime, '5' );
	}

	/**
	 * Add a file whose content is in memory.
	 *
	 * @param string $name  Name.
	 * @param string $data  Content.
	 * @param int    $mtime Modification time.
	 */
	public function add_small( $name, $data, $mtime ) {
		$len = strlen( $data );
		$this->header( $name, $len, $mtime, '0' );
		$this->raw( $data );
		$this->raw( str_repeat( "\0", ( 512 - $len % 512 ) % 512 ) );
	}

	/**
	 * Begin a large file that will be streamed with write().
	 *
	 * @param string $name  Name.
	 * @param int    $size  Exact size that will be written.
	 * @param int    $mtime Modification time.
	 */
	public function begin_entry( $name, $size, $mtime ) {
		$this->header( $name, $size, $mtime, '0' );
		$this->st['remaining'] = $size;
		$this->st['pad']       = ( 512 - $size % 512 ) % 512;
	}

	/**
	 * Write content of the current large entry.
	 *
	 * @param string $data Bytes (never more than what remains).
	 */
	public function write( $data ) {
		if ( strlen( $data ) > $this->st['remaining'] ) {
			$data = substr( $data, 0, $this->st['remaining'] );
		}
		$this->raw( $data );
		$this->st['remaining'] -= strlen( $data );
	}

	/**
	 * Close the current large entry. Pads with zeros if the file shrank.
	 */
	public function end_entry() {
		while ( $this->st['remaining'] > 0 ) {
			$n = min( 1048576, $this->st['remaining'] );
			$this->raw( str_repeat( "\0", $n ) );
			$this->st['remaining'] -= $n;
		}
		$this->raw( str_repeat( "\0", $this->st['pad'] ) );
		$this->st['pad'] = 0;
	}

	/**
	 * Write the end-of-archive marker.
	 */
	public function finish() {
		$this->raw( str_repeat( "\0", 1024 ) );
	}

	/**
	 * Close and return state to persist.
	 *
	 * @return array
	 */
	public function close() {
		$this->end_member();
		fflush( $this->fp );
		fseek( $this->fp, 0, SEEK_END );
		$this->st['size'] = ftell( $this->fp );
		fclose( $this->fp );
		return $this->st;
	}
}
