<?php
/**
 * Serialization-safe search & replace.
 *
 * Instead of unserialize() (which can instantiate objects and fails on
 * classes that do not exist on the new host) this walks the PHP serialize
 * format token by token, replaces inside every string, and rewrites the
 * string lengths. Nested serialized strings are handled recursively.
 * strtr() is used so already replaced text is never replaced twice.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Search & replace engine.
 */
class HOLEMI_Search_Replace {

	/**
	 * Replacement map for strtr.
	 *
	 * @var array
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param array $map search => replace.
	 */
	public function __construct( array $map ) {
		$this->map = array_filter(
			$map,
			function ( $v, $k ) {
				return '' !== (string) $k && (string) $k !== (string) $v;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Whether there is anything to replace.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->map );
	}

	/**
	 * The map.
	 *
	 * @return array
	 */
	public function map() {
		return $this->map;
	}

	/**
	 * Build the replacement map for a migration, with the common encodings
	 * URLs appear in: plain, http/https, JSON escaped, URL encoded,
	 * protocol relative.
	 *
	 * @param array $url_pairs  old URL => new URL.
	 * @param array $path_pairs old path => new path.
	 * @return array
	 */
	public static function build_map( array $url_pairs, array $path_pairs ) {
		$map = array();
		foreach ( $url_pairs as $old => $new ) {
			$old = untrailingslashit( (string) $old );
			$new = untrailingslashit( (string) $new );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$olds = array( $old );
			if ( 0 === strpos( $old, 'https://' ) ) {
				$olds[] = 'http://' . substr( $old, 8 );
			} elseif ( 0 === strpos( $old, 'http://' ) ) {
				$olds[] = 'https://' . substr( $old, 7 );
			}
			foreach ( $olds as $o ) {
				$map[ $o ]                          = $new;
				$map[ str_replace( '/', '\\/', $o ) ] = str_replace( '/', '\\/', $new );
				$map[ rawurlencode( $o ) ]          = rawurlencode( $new );
				$map[ urlencode( $o ) ]             = urlencode( $new );
			}
			// Protocol relative: //old.example.com/path.
			$o_rel = preg_replace( '#^https?:#', '', $old );
			$n_rel = preg_replace( '#^https?:#', '', $new );
			if ( 0 === strpos( $o_rel, '//' ) ) {
				$map[ $o_rel ]                          = $n_rel;
				$map[ str_replace( '/', '\\/', $o_rel ) ] = str_replace( '/', '\\/', $n_rel );
			}
		}
		foreach ( $path_pairs as $old => $new ) {
			$old = untrailingslashit( (string) $old );
			$new = untrailingslashit( (string) $new );
			if ( '' === $old || $old === $new || strlen( $old ) < 4 ) {
				continue;
			}
			$map[ $old ]                          = $new;
			$map[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}
		return $map;
	}

	/**
	 * Short substrings that every search key contains (for SQL pre-filtering).
	 *
	 * @return string[]
	 */
	public function needles() {
		$needles = array();
		foreach ( array_keys( $this->map ) as $key ) {
			$parts = preg_split( '#[/\\\\%:]+#', (string) $key, -1, PREG_SPLIT_NO_EMPTY );
			usort(
				$parts,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$best = isset( $parts[0] ) && strlen( $parts[0] ) >= 3 ? $parts[0] : (string) $key;
			// URL encoded keys like "https%3A%2F%2Fexample.com" split into "3A", "2Fexample.com".
			$best             = preg_replace( '#^(2F|3A)+#i', '', $best );
			$needles[ $best ] = true;
		}
		// Drop needles that contain another needle (the shorter one matches anyway).
		$list = array_keys( $needles );
		$out  = array();
		foreach ( $list as $a ) {
			$redundant = false;
			foreach ( $list as $b ) {
				if ( $a !== $b && '' !== $b && false !== strpos( $a, $b ) ) {
					$redundant = true;
					break;
				}
			}
			if ( ! $redundant && '' !== $a ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/**
	 * Replace in a database value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function run( $value ) {
		if ( ! is_string( $value ) || '' === $value || empty( $this->map ) ) {
			return $value;
		}
		return $this->str( $value, 0 );
	}

	/**
	 * Replace in a string that may be serialized.
	 *
	 * @param string $s     String.
	 * @param int    $depth Recursion depth.
	 * @return string
	 */
	private function str( $s, $depth ) {
		if ( $depth < 8 && self::looks_serialized( $s ) ) {
			$p   = 0;
			$out = $this->parse( $s, $p, $depth, true );
			if ( null !== $out && strlen( $s ) === $p ) {
				return $out;
			}
		}
		return strtr( $s, $this->map );
	}

	/**
	 * Quick check for serialized data.
	 *
	 * @param string $s String.
	 * @return bool
	 */
	private static function looks_serialized( $s ) {
		$l = strlen( $s );
		if ( $l < 4 ) {
			return 'N;' === $s;
		}
		if ( ':' !== $s[1] ) {
			return false;
		}
		$last = $s[ $l - 1 ];
		return false !== strpos( 'aOsibdC', $s[0] ) && ( ';' === $last || '}' === $last );
	}

	/**
	 * Parse one serialized value starting at $p and return it rebuilt.
	 *
	 * @param string $s       Input.
	 * @param int    $p       Position (by reference).
	 * @param int    $depth   Depth for nested serialized strings.
	 * @param bool   $replace Replace inside strings.
	 * @return string|null Null when the input is not valid serialized data.
	 */
	private function parse( $s, &$p, $depth, $replace ) {
		$len = strlen( $s );
		if ( $p >= $len ) {
			return null;
		}
		$t = $s[ $p ];
		switch ( $t ) {
			case 'N':
				if ( 'N;' !== substr( $s, $p, 2 ) ) {
					return null;
				}
				$p += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				if ( ! isset( $s[ $p + 1 ] ) || ':' !== $s[ $p + 1 ] ) {
					return null;
				}
				$end = strpos( $s, ';', $p );
				if ( false === $end ) {
					return null;
				}
				$tok = substr( $s, $p, $end - $p + 1 );
				$p   = $end + 1;
				return $tok;

			case 's':
			case 'E':
				if ( ! preg_match( '/\G[sE]:(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$n     = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( $start + $n + 2 > $len || '";' !== substr( $s, $start + $n, 2 ) ) {
					return null;
				}
				$content = substr( $s, $start, $n );
				$p       = $start + $n + 2;
				if ( 'E' === $t || ! $replace ) {
					return $t . ':' . $n . ':"' . $content . '";';
				}
				$new = $this->str( $content, $depth + 1 );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $p ) ) {
					return null;
				}
				$p  += strlen( $m[0] );
				$out = $m[0];
				$cnt = (int) $m[1];
				for ( $i = 0; $i < $cnt; $i++ ) {
					$k = $this->parse( $s, $p, $depth, false );
					if ( null === $k ) {
						return null;
					}
					$v = $this->parse( $s, $p, $depth, $replace );
					if ( null === $v ) {
						return null;
					}
					$out .= $k . $v;
				}
				if ( ! isset( $s[ $p ] ) || '}' !== $s[ $p ] ) {
					return null;
				}
				$p++;
				return $out . '}';

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$cn_len = (int) $m[1];
				$q      = $p + strlen( $m[0] ) + $cn_len;
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $q ) ) {
					return null;
				}
				$out = substr( $s, $p, $q - $p ) . $m2[0];
				$p   = $q + strlen( $m2[0] );
				$cnt = (int) $m2[1];
				for ( $i = 0; $i < $cnt; $i++ ) {
					$k = $this->parse( $s, $p, $depth, false );
					if ( null === $k ) {
						return null;
					}
					$v = $this->parse( $s, $p, $depth, $replace );
					if ( null === $v ) {
						return null;
					}
					$out .= $k . $v;
				}
				if ( ! isset( $s[ $p ] ) || '}' !== $s[ $p ] ) {
					return null;
				}
				$p++;
				return $out . '}';

			case 'C':
				// Custom serialized object: opaque payload, keep it untouched.
				if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$q = $p + strlen( $m[0] ) + (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $q ) ) {
					return null;
				}
				$end = $q + strlen( $m2[0] ) + (int) $m2[1];
				if ( ! isset( $s[ $end ] ) || '}' !== $s[ $end ] ) {
					return null;
				}
				$tok = substr( $s, $p, $end - $p + 1 );
				$p   = $end + 1;
				return $tok;
		}
		return null;
	}
}
