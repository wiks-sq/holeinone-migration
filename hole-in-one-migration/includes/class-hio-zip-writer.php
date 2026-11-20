<?php
/**
 * Resumable ZIP writer (with ZIP64 for archives and files over 4 GB).
 *
 * PHP's ZipArchive rewrites the whole archive every time it is closed, which
 * makes chunked writing slower and slower until it times out. This writer
 * appends only: small files are deflated in one go, large files are stored
 * and streamed over as many requests as needed. The central directory is
 * collected in a side file and appended at the very end.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * ZIP writer.
 */
class HIO_Zip_Writer {

	const MAX32 = 0xFFFFFFFF;

	/**
	 * Archive handle.
	 *
	 * @var resource
	 */
	private $fp;

	/**
	 * Central directory side file handle.
	 *
	 * @var resource
	 */
	private $cd;

	/**
	 * State.
	 *
	 * @var array
	 */
	private $st;

	/**
	 * Open (or resume).
	 *
	 * @param string $path    Archive path.
	 * @param string $cd_path Side file for central directory records.
	 * @param array  $state   Saved state.
	 * @throws HIO_Exception If files cannot be opened.
	 */
	public function __construct( $path, $cd_path, array $state ) {
		$this->st = array_merge(
			array(
				'size'    => 0,
				'cd_size' => 0,
				'count'   => 0,
				'entry'   => null,
			),
			$state
		);
		$this->fp = @fopen( $path, 'c+b' ); // phpcs:ignore
		$this->cd = @fopen( $cd_path, 'c+b' ); // phpcs:ignore
		if ( ! $this->fp || ! $this->cd ) {
			throw new HIO_Exception( esc_html__( 'Could not open the archive for writing.', 'hole-in-one-migration' ) );
		}
		ftruncate( $this->fp, (int) $this->st['size'] );
		fseek( $this->fp, 0, SEEK_END );
		ftruncate( $this->cd, (int) $this->st['cd_size'] );
		fseek( $this->cd, 0, SEEK_END );
	}

	/**
	 * DOS date/time.
	 *
	 * @param int $t Unix time.
	 * @return int[] [time, date]
	 */
	private static function dos_time( $t ) {
		$d = getdate( $t > 315532800 ? $t : 315532800 );
		return array(
			( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ),
			( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'],
		);
	}

	/**
	 * Write a local file header.
	 *
	 * @param string $name   Name.
	 * @param int    $method 0 store, 8 deflate.
	 * @param int    $crc    CRC32.
	 * @param int    $csize  Compressed size.
	 * @param int    $usize  Uncompressed size.
	 * @param int    $mtime  Modification time.
	 * @return int Offset of the header.
	 */
	private function local_header( $name, $method, $crc, $csize, $usize, $mtime ) {
		$offset = ftell( $this->fp );
		list( $t, $d ) = self::dos_time( $mtime );
		$z64   = ( $usize >= self::MAX32 || $csize >= self::MAX32 );
		$extra = $z64 ? pack( 'vvPP', 0x0001, 16, $usize, $csize ) : '';
		$h     = pack(
			'VvvvvvVVVvv',
			0x04034b50,
			$z64 ? 45 : 20,
			0x0800,
			$method,
			$t,
			$d,
			$crc,
			$z64 ? self::MAX32 : $csize,
			$z64 ? self::MAX32 : $usize,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra;
		HIO_Util::fwrite_all( $this->fp, $h );
		return $offset;
	}

	/**
	 * Append the central directory record of an entry to the side file.
	 *
	 * @param string $name   Name.
	 * @param int    $method Method.
	 * @param int    $crc    CRC.
	 * @param int    $csize  Compressed size.
	 * @param int    $usize  Uncompressed size.
	 * @param int    $mtime  Time.
	 * @param int    $offset Local header offset.
	 * @param bool   $dir    Directory entry.
	 */
	private function central( $name, $method, $crc, $csize, $usize, $mtime, $offset, $dir ) {
		list( $t, $d ) = self::dos_time( $mtime );
		$extra = '';
		if ( $usize >= self::MAX32 ) {
			$extra .= pack( 'P', $usize );
		}
		if ( $csize >= self::MAX32 ) {
			$extra .= pack( 'P', $csize );
		}
		if ( $offset >= self::MAX32 ) {
			$extra .= pack( 'P', $offset );
		}
		if ( '' !== $extra ) {
			$extra = pack( 'vv', 0x0001, strlen( $extra ) ) . $extra;
		}
		$mode = $dir ? ( 040755 << 16 ) | 0x10 : ( 0100644 << 16 );
		$rec  = pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			( 3 << 8 ) | 45,
			'' !== $extra ? 45 : 20,
			0x0800,
			$method,
			$t,
			$d,
			$crc,
			min( $csize, self::MAX32 ),
			min( $usize, self::MAX32 ),
			strlen( $name ),
			strlen( $extra ),
			0,
			0,
			0,
			$mode,
			min( $offset, self::MAX32 )
		) . $name . $extra;
		HIO_Util::fwrite_all( $this->cd, $rec );
		$this->st['cd_size'] += strlen( $rec );
		$this->st['count']++;
	}

	/**
	 * Add a directory.
	 *
	 * @param string $name  Name.
	 * @param int    $mtime Time.
	 */
	public function add_dir( $name, $mtime ) {
		$name = rtrim( $name, '/' ) . '/';
		$off  = $this->local_header( $name, 0, 0, 0, 0, $mtime );
		$this->central( $name, 0, 0, 0, 0, $mtime, $off, true );
	}

	/**
	 * Add a file from memory (deflated when that helps).
	 *
	 * @param string $name  Name.
	 * @param string $data  Content.
	 * @param int    $mtime Time.
	 */
	public function add_small( $name, $data, $mtime ) {
		$crc    = crc32( $data );
		$method = 0;
		$body   = $data;
		if ( function_exists( 'gzdeflate' ) && strlen( $data ) > 64 ) {
			$c = gzdeflate( $data, 6 );
			if ( false !== $c && strlen( $c ) < strlen( $data ) ) {
				$method = 8;
				$body   = $c;
			}
		}
		$off = $this->local_header( $name, $method, $crc, strlen( $body ), strlen( $data ), $mtime );
		HIO_Util::fwrite_all( $this->fp, $body );
		$this->central( $name, $method, $crc, strlen( $body ), strlen( $data ), $mtime, $off, false );
	}

	/**
	 * Begin a large stored file.
	 *
	 * @param string $name  Name.
	 * @param int    $size  Exact size.
	 * @param int    $mtime Time.
	 */
	public function begin_entry( $name, $size, $mtime ) {
		$off               = $this->local_header( $name, 0, 0, $size, $size, $mtime );
		$this->st['entry'] = array(
			'name'    => base64_encode( $name ), // phpcs:ignore
			'size'    => $size,
			'written' => 0,
			'crc'     => 0,
			'off'     => $off,
			'mtime'   => $mtime,
		);
	}

	/**
	 * Stream data of the current large file.
	 *
	 * @param string $data Bytes.
	 */
	public function write( $data ) {
		$e    = &$this->st['entry'];
		$left = $e['size'] - $e['written'];
		if ( strlen( $data ) > $left ) {
			$data = substr( $data, 0, $left );
		}
		if ( '' === $data ) {
			return;
		}
		HIO_Util::fwrite_all( $this->fp, $data );
		$e['crc']      = HIO_Util::crc32_combine( $e['crc'], crc32( $data ), strlen( $data ) );
		$e['written'] += strlen( $data );
	}

	/**
	 * Finish the current large file: pad if it shrank, patch the CRC.
	 */
	public function end_entry() {
		$e = $this->st['entry'];
		while ( $e['written'] < $e['size'] ) {
			$this->write( str_repeat( "\0", (int) min( 1048576, $e['size'] - $e['written'] ) ) );
			$e = $this->st['entry'];
		}
		fseek( $this->fp, $e['off'] + 14 );
		HIO_Util::fwrite_all( $this->fp, pack( 'V', $e['crc'] ) );
		fseek( $this->fp, 0, SEEK_END );
		$name = base64_decode( $e['name'] ); // phpcs:ignore
		$this->central( $name, 0, $e['crc'], $e['size'], $e['size'], $e['mtime'], $e['off'], false );
		$this->st['entry'] = null;
	}

	/**
	 * Append central directory and end records.
	 */
	public function finish() {
		$cd_offset = ftell( $this->fp );
		fflush( $this->cd );
		rewind( $this->cd );
		while ( ! feof( $this->cd ) ) {
			$buf = fread( $this->cd, 1048576 );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			HIO_Util::fwrite_all( $this->fp, $buf );
		}
		$cd_size = $this->st['cd_size'];
		$count   = $this->st['count'];
		if ( $count >= 0xFFFF || $cd_offset >= self::MAX32 || $cd_size >= self::MAX32 ) {
			$z64_off = ftell( $this->fp );
			HIO_Util::fwrite_all(
				$this->fp,
				pack( 'VPvvVVPPPP', 0x06064b50, 44, ( 3 << 8 ) | 45, 45, 0, 0, $count, $count, $cd_size, $cd_offset )
			);
			HIO_Util::fwrite_all( $this->fp, pack( 'VVPV', 0x07064b50, 0, $z64_off, 1 ) );
		}
		HIO_Util::fwrite_all(
			$this->fp,
			pack(
				'VvvvvVVv',
				0x06054b50,
				0,
				0,
				min( $count, 0xFFFF ),
				min( $count, 0xFFFF ),
				min( $cd_size, self::MAX32 ),
				min( $cd_offset, self::MAX32 ),
				0
			)
		);
	}

	/**
	 * Close and return state.
	 *
	 * @return array
	 */
	public function close() {
		fflush( $this->fp );
		fseek( $this->fp, 0, SEEK_END );
		$this->st['size'] = ftell( $this->fp );
		fclose( $this->fp );
		fflush( $this->cd );
		fclose( $this->cd );
		return $this->st;
	}
}
