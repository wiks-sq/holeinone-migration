<?php
/**
 * Streaming tar parser with a serializable state, so extraction can stop
 * after any gzip member and resume in the next request.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handler contract for parsed entries.
 */
interface HIO_Tar_Handler {

	/**
	 * A new entry starts.
	 *
	 * @param string $name  Entry name.
	 * @param string $type  Type flag ('0' file, '5' dir, ...).
	 * @param int    $size  Data size.
	 * @param int    $mtime Modification time.
	 * @return bool Whether the handler wants the data.
	 */
	public function entry_start( $name, $type, $size, $mtime );

	/**
	 * Data of the current entry.
	 *
	 * @param string $data Bytes.
	 */
	public function entry_data( $data );

	/**
	 * Current entry is complete.
	 */
	public function entry_end();
}

/**
 * Tar stream parser.
 */
class HIO_Tar_Parser {

	/**
	 * Parser state (persisted between requests).
	 *
	 * @var array
	 */
	public $st;

	/**
	 * Handler.
	 *
	 * @var HIO_Tar_Handler
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @param HIO_Tar_Handler $handler Handler.
	 * @param array           $state   Saved state from export_state().
	 */
	public function __construct( HIO_Tar_Handler $handler, array $state = array() ) {
		$this->handler = $handler;
		$this->st      = array_merge(
			array(
				'mode'      => 'header',
				'need'      => 512,
				'buf'       => '',
				'long'      => null,
				'long_len'  => 0,
				'remaining' => 0,
				'accept'    => false,
				'end'       => false,
			),
			$state
		);
		$this->st['buf']  = base64_decode( (string) $this->st['buf'] ); // phpcs:ignore
		$this->st['long'] = null === $this->st['long'] ? null : base64_decode( $this->st['long'] ); // phpcs:ignore
	}

	/**
	 * State to persist.
	 *
	 * @return array
	 */
	public function export_state() {
		$s         = $this->st;
		$s['buf']  = base64_encode( $s['buf'] ); // phpcs:ignore
		$s['long'] = null === $s['long'] ? null : base64_encode( $s['long'] ); // phpcs:ignore
		return $s;
	}

	/**
	 * Whether the end-of-archive marker was seen.
	 *
	 * @return bool
	 */
	public function ended() {
		return ! empty( $this->st['end'] );
	}

	/**
	 * Parse a numeric header field (octal or GNU base-256).
	 *
	 * @param string $f Field.
	 * @return int
	 */
	private static function num( $f ) {
		if ( '' !== $f && ( ord( $f[0] ) & 0x80 ) ) {
			$n = 0;
			for ( $i = 1, $l = strlen( $f ); $i < $l; $i++ ) {
				$n = ( $n << 8 ) | ord( $f[ $i ] );
			}
			return $n;
		}
		$f = trim( $f, " \0" );
		return '' === $f ? 0 : (int) octdec( $f );
	}

	/**
	 * Feed decompressed bytes.
	 *
	 * @param string $data Bytes.
	 * @throws HIO_Exception On a corrupt header.
	 */
	public function feed( $data ) {
		$off = 0;
		$len = strlen( $data );
		$st  = &$this->st;

		while ( $off < $len && ! $st['end'] ) {
			switch ( $st['mode'] ) {
				case 'header':
				case 'longname':
					$take       = min( $st['need'] - strlen( $st['buf'] ), $len - $off );
					$st['buf'] .= substr( $data, $off, $take );
					$off       += $take;
					if ( strlen( $st['buf'] ) < $st['need'] ) {
						break;
					}
					$block     = $st['buf'];
					$st['buf'] = '';
					if ( 'longname' === $st['mode'] ) {
						$st['long'] = rtrim( substr( $block, 0, $st['long_len'] ), "\0" );
						$st['mode'] = 'header';
						$st['need'] = 512;
						break;
					}
					$this->header( $block );
					break;

				case 'data':
					$take = min( $st['remaining'], $len - $off );
					if ( $st['accept'] && $take > 0 ) {
						$this->handler->entry_data( substr( $data, $off, $take ) );
					}
					$off             += $take;
					$st['remaining'] -= $take;
					if ( 0 === $st['remaining'] ) {
						if ( $st['accept'] ) {
							$this->handler->entry_end();
						}
						$st['mode']      = 'skip';
						$st['remaining'] = $st['pad'];
						if ( 0 === $st['remaining'] ) {
							$st['mode'] = 'header';
							$st['need'] = 512;
						}
					}
					break;

				case 'skip':
					$take             = min( $st['remaining'], $len - $off );
					$off             += $take;
					$st['remaining'] -= $take;
					if ( 0 === $st['remaining'] ) {
						$st['mode'] = 'header';
						$st['need'] = 512;
					}
					break;

				default:
					return;
			}
		}
	}

	/**
	 * Handle one header block.
	 *
	 * @param string $h 512 bytes.
	 * @throws HIO_Exception On checksum mismatch.
	 */
	private function header( $h ) {
		$st = &$this->st;
		if ( str_repeat( "\0", 512 ) === $h ) {
			$st['end'] = true;
			return;
		}
		$stored = self::num( substr( $h, 148, 8 ) );
		$sum    = 0;
		$tmp    = substr_replace( $h, '        ', 148, 8 );
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $tmp[ $i ] );
		}
		if ( $sum !== $stored ) {
			throw new HIO_Exception( esc_html__( 'The archive is damaged (bad tar header checksum). Please create and upload the archive again.', 'hole-in-one-migration' ) );
		}
		$name  = rtrim( substr( $h, 0, 100 ), "\0" );
		$size  = self::num( substr( $h, 124, 12 ) );
		$mtime = self::num( substr( $h, 136, 12 ) );
		$type  = $h[156];
		$magic = substr( $h, 257, 6 );
		if ( "ustar\0" === $magic ) {
			$prefix = rtrim( substr( $h, 345, 155 ), "\0" );
			if ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
		}
		$pad = ( 512 - $size % 512 ) % 512;

		if ( 'L' === $type ) {
			$st['mode']     = 'longname';
			$st['need']     = $size + $pad;
			$st['long_len'] = $size;
			return;
		}
		if ( null !== $st['long'] ) {
			$name       = $st['long'];
			$st['long'] = null;
		}
		if ( "\0" === $type ) {
			$type = '0';
		}
		if ( in_array( $type, array( 'x', 'g', 'K' ), true ) ) {
			// Extended headers we do not need: skip their data.
			$st['accept'] = false;
		} else {
			$st['accept'] = (bool) $this->handler->entry_start( $name, $type, $size, $mtime );
		}
		$st['pad'] = $pad;
		if ( $size > 0 ) {
			$st['mode']      = 'data';
			$st['remaining'] = $size;
		} else {
			if ( $st['accept'] ) {
				$this->handler->entry_end();
			}
			$st['mode'] = 'header';
			$st['need'] = 512;
		}
	}
}
