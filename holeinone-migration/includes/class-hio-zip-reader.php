<?php
/**
 * Minimal ZIP reader (central directory parser, ZIP64 aware). Extraction is
 * done by the importer entry by entry, so it never needs ZipArchive and can
 * resume inside large files.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * ZIP reader.
 */
class HIO_Zip_Reader {

	/**
	 * Parse the central directory and write one JSON line per entry to $index.
	 *
	 * @param string $path  Archive.
	 * @param string $index Output file.
	 * @return array{count:int,bytes:int}
	 * @throws HIO_Exception On a damaged archive.
	 */
	public static function build_index( $path, $index ) {
		$fp = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HIO_Exception( esc_html__( 'Could not open the archive.', 'holeinone-migration' ) );
		}
		fseek( $fp, 0, SEEK_END );
		$size = ftell( $fp );
		$tail = min( $size, 65557 );
		fseek( $fp, $size - $tail );
		$buf = fread( $fp, $tail );
		$pos = strrpos( $buf, "PK\x05\x06" );
		if ( false === $pos ) {
			fclose( $fp );
			throw new HIO_Exception( esc_html__( 'The ZIP file is incomplete or damaged (no end record found). Was the upload complete?', 'holeinone-migration' ) );
		}
		$eocd      = unpack( 'Vsig/vdisk/vcddisk/vndisk/vcount/Vcdsize/Vcdoff', substr( $buf, $pos, 20 ) );
		$count     = $eocd['count'];
		$cd_size   = $eocd['cdsize'];
		$cd_offset = $eocd['cdoff'];
		$eocd_abs  = $size - $tail + $pos;

		if ( 0xFFFF === $count || 0xFFFFFFFF === $cd_size || 0xFFFFFFFF === $cd_offset ) {
			fseek( $fp, $eocd_abs - 20 );
			$loc = fread( $fp, 20 );
			if ( strlen( $loc ) === 20 && "PK\x06\x07" === substr( $loc, 0, 4 ) ) {
				$l = unpack( 'Vsig/Vdisk/Poff/Vtotal', $loc );
				fseek( $fp, $l['off'] );
				$rec = fread( $fp, 56 );
				if ( "PK\x06\x06" === substr( $rec, 0, 4 ) ) {
					$z         = unpack( 'Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pndisk/Pcount/Pcdsize/Pcdoff', $rec );
					$count     = $z['count'];
					$cd_size   = $z['cdsize'];
					$cd_offset = $z['cdoff'];
				}
			}
		}

		$out = @fopen( $index, 'wb' ); // phpcs:ignore
		if ( ! $out ) {
			fclose( $fp );
			throw new HIO_Exception( esc_html__( 'Could not write the archive index.', 'holeinone-migration' ) );
		}
		fseek( $fp, $cd_offset );
		$bytes = 0;
		$n     = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$h = fread( $fp, 46 );
			if ( strlen( $h ) < 46 || "PK\x01\x02" !== substr( $h, 0, 4 ) ) {
				fclose( $fp );
				fclose( $out );
				throw new HIO_Exception( esc_html__( 'The ZIP central directory is damaged.', 'holeinone-migration' ) );
			}
			$r     = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Voff', $h );
			$name  = $r['nlen'] > 0 ? fread( $fp, $r['nlen'] ) : '';
			$extra = $r['xlen'] > 0 ? fread( $fp, $r['xlen'] ) : '';
			if ( $r['clen'] > 0 ) {
				fseek( $fp, $r['clen'], SEEK_CUR );
			}
			$usize = $r['usize'];
			$csize = $r['csize'];
			$off   = $r['off'];
			// ZIP64 extended information.
			$p = 0;
			while ( $p + 4 <= strlen( $extra ) ) {
				$x = unpack( 'vid/vlen', substr( $extra, $p, 4 ) );
				if ( 0x0001 === $x['id'] ) {
					$data = substr( $extra, $p + 4, $x['len'] );
					$q    = 0;
					if ( 0xFFFFFFFF === $usize && $q + 8 <= strlen( $data ) ) {
						$usize = unpack( 'P', substr( $data, $q, 8 ) )[1];
						$q    += 8;
					}
					if ( 0xFFFFFFFF === $csize && $q + 8 <= strlen( $data ) ) {
						$csize = unpack( 'P', substr( $data, $q, 8 ) )[1];
						$q    += 8;
					}
					if ( 0xFFFFFFFF === $off && $q + 8 <= strlen( $data ) ) {
						$off = unpack( 'P', substr( $data, $q, 8 ) )[1];
					}
				}
				$p += 4 + $x['len'];
			}
			if ( $r['flags'] & 0x1 ) {
				fclose( $fp );
				fclose( $out );
				throw new HIO_Exception( esc_html__( 'Encrypted ZIP files are not supported.', 'holeinone-migration' ) );
			}
			$line = wp_json_encode(
				array(
					'n'   => base64_encode( $name ), // phpcs:ignore
					'm'   => $r['method'],
					'c'   => $csize,
					'u'   => $usize,
					'o'   => $off,
					'crc' => $r['crc'],
				)
			);
			fwrite( $out, $line . "\n" );
			$bytes += $usize;
			$n++;
		}
		fclose( $out );
		fclose( $fp );
		return array(
			'count' => $n,
			'bytes' => $bytes,
		);
	}

	/**
	 * Offset where the data of an entry starts.
	 *
	 * @param resource $fp     Archive handle.
	 * @param int      $offset Local header offset.
	 * @return int
	 * @throws HIO_Exception On a damaged archive.
	 */
	public static function data_offset( $fp, $offset ) {
		fseek( $fp, $offset );
		$h = fread( $fp, 30 );
		if ( strlen( $h ) < 30 || "PK\x03\x04" !== substr( $h, 0, 4 ) ) {
			throw new HIO_Exception( esc_html__( 'The ZIP file is damaged (bad local header).', 'holeinone-migration' ) );
		}
		$r = unpack( 'vnlen/vxlen', substr( $h, 26, 4 ) );
		return $offset + 30 + $r['nlen'] + $r['xlen'];
	}

	/**
	 * Read one small entry fully into memory (used for the manifest).
	 *
	 * @param string $path  Archive.
	 * @param array  $entry Index entry.
	 * @return string
	 * @throws HIO_Exception On error.
	 */
	public static function read_entry( $path, array $entry ) {
		$fp = fopen( $path, 'rb' ); // phpcs:ignore
		fseek( $fp, self::data_offset( $fp, $entry['o'] ) );
		$raw = $entry['c'] > 0 ? fread( $fp, $entry['c'] ) : '';
		fclose( $fp );
		if ( 8 === (int) $entry['m'] ) {
			$raw = gzinflate( $raw );
		} elseif ( 0 !== (int) $entry['m'] ) {
			throw new HIO_Exception( esc_html__( 'Unsupported ZIP compression method.', 'holeinone-migration' ) );
		}
		return (string) $raw;
	}
}
