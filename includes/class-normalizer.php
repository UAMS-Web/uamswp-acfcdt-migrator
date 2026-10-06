<?php

namespace UAMSWP_ACFCDT_Migrator;

/**
 * Turns raw (unformatted) ACF values into a canonical shape so values read from the custom table and
 * values read from postmeta can be compared fairly.
 *
 * What is treated as "the same":
 *  - null, '', [] (and false for non true_false fields) are all "empty" (null)
 *  - ints/floats/bools vs their string forms (custom tables JSON-encode ints, postmeta stores strings)
 *  - repeater / group / flexible rows keyed by field key vs field name
 *  - associative key order
 *
 * Everything else (text content, whitespace, list order, backslashes) must match exactly.
 */
class Normalizer {

	const COMPLEX = [ 'repeater', 'flexible_content', 'group', 'clone' ];

	/** Fields whose value is a post/term/user/attachment ID or list of IDs. */
	const ID_TYPES = [ 'taxonomy', 'relationship', 'post_object', 'gallery', 'user', 'image', 'file' ];

	/**
	 * @param array $field ACF field array
	 * @param mixed $v     raw value from acf_get_value()
	 *
	 * @return mixed|null
	 */
	public static function value( array $field, $v ) {
		$type = isset( $field['type'] ) ? $field['type'] : '';

		if ( $v === null || $v === '' || $v === [] ) {
			return null;
		}
		if ( $v === false ) {
			return $type === 'true_false' ? '0' : null;
		}
		if ( $v === true ) {
			return '1';
		}
		if ( is_object( $v ) ) {
			$v = json_decode( wp_json_encode( $v ), true );
		}

		// ID fields: ID 0 / '' never refers to anything (ACF drops it on save) and array keys carry no
		// meaning (lists can come back with gaps such as {0,1,3}). Compare the IDs only.
		if ( in_array( $type, self::ID_TYPES, true ) ) {
			$out = [];
			foreach ( is_array( $v ) ? $v : [ $v ] as $item ) {
				$n = self::generic( $item );
				if ( $n === null || $n === '0' ) {
					continue;
				}
				$out[] = $n;
			}
			if ( ! $out ) {
				return null;
			}

			return is_array( $v ) ? $out : $out[0];
		}

		if ( ! is_array( $v ) ) {
			$s = (string) $v;
			// An empty repeater / flexible field can surface as its row count of 0.
			if ( ( $type === 'repeater' || $type === 'flexible_content' ) && $s === '0' ) {
				return null;
			}

			return $s;
		}

		switch ( $type ) {
			case 'repeater':
				$subs = isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : [];
				$out  = [];
				foreach ( $v as $row ) {
					$out[] = self::row( $subs, $row );
				}

				return $out ?: null;

			case 'flexible_content':
				$layouts = [];
				foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : [] ) as $layout ) {
					if ( isset( $layout['name'] ) ) {
						$layouts[ $layout['name'] ] = isset( $layout['sub_fields'] ) ? (array) $layout['sub_fields'] : [];
					}
				}
				$out = [];
				foreach ( $v as $row ) {
					$name  = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
					$subs  = isset( $layouts[ $name ] ) ? $layouts[ $name ] : [];
					$norm  = self::row( $subs, $row );
					$out[] = is_array( $norm ) ? $norm + [ 'acf_fc_layout' => $name ] : $norm;
				}

				return $out ?: null;

			case 'group':
			case 'clone':
				if ( ! empty( $field['sub_fields'] ) ) {
					$norm = self::row( (array) $field['sub_fields'], $v );

					return $norm ?: null;
				}

				return self::generic( $v );

			default:
				return self::generic( $v );
		}
	}

	/** Normalize one repeater/group row: map keys (field_xxx or name) to names, drop empties, sort. */
	private static function row( array $subs, $row ) {
		if ( ! is_array( $row ) ) {
			return self::generic( $row );
		}
		$by_key  = [];
		$by_name = [];
		foreach ( $subs as $sub ) {
			if ( isset( $sub['key'] ) ) {
				$by_key[ $sub['key'] ] = $sub;
			}
			if ( isset( $sub['name'] ) && $sub['name'] !== '' ) {
				$by_name[ $sub['name'] ] = $sub;
			}
		}

		$out = [];
		foreach ( $row as $k => $val ) {
			if ( $k === 'acf_fc_layout' ) {
				continue;
			}
			$sub = isset( $by_key[ $k ] ) ? $by_key[ $k ] : ( isset( $by_name[ $k ] ) ? $by_name[ $k ] : null );
			if ( $sub ) {
				$name = $sub['name'];
				$norm = self::value( $sub, $val );
			} else {
				$name = (string) $k;
				$norm = self::generic( $val );
			}
			if ( $norm !== null ) {
				$out[ $name ] = $norm;
			}
		}
		ksort( $out );

		return $out; // an all-empty row stays [] (row count still matters)
	}

	public static function generic( $v ) {
		if ( $v === null || $v === '' || $v === [] || $v === false ) {
			return null;
		}
		if ( $v === true ) {
			return '1';
		}
		if ( is_object( $v ) ) {
			$v = json_decode( wp_json_encode( $v ), true );
		}
		if ( ! is_array( $v ) ) {
			return (string) $v;
		}

		if ( self::is_list( $v ) ) {
			$out = [];
			foreach ( $v as $item ) {
				$out[] = self::generic( $item );
			}

			return $out;
		}

		$out = [];
		foreach ( $v as $k => $item ) {
			$n = self::generic( $item );
			if ( $n !== null ) {
				$out[ (string) $k ] = $n;
			}
		}
		ksort( $out );

		return $out ?: null;
	}

	private static function is_list( array $a ) {
		$i = 0;
		foreach ( $a as $k => $_ ) {
			if ( $k !== $i ++ ) {
				return false;
			}
		}

		return true;
	}

	/** Stable JSON used for equality checks and file output. */
	public static function json( $normalized ) {
		return wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function same( $a, $b ) {
		return self::json( $a ) === self::json( $b );
	}

	/**
	 * True if an ID field holds something that isn't an ID (e.g. a corrupted string like "10522]").
	 * Those are reported for manual review instead of being written.
	 */
	public static function has_invalid_ids( array $field, $normalized ) {
		if ( $normalized === null || ! in_array( isset( $field['type'] ) ? $field['type'] : '', self::ID_TYPES, true ) ) {
			return false;
		}
		foreach ( is_array( $normalized ) ? $normalized : [ $normalized ] as $id ) {
			if ( ! is_string( $id ) || ! ctype_digit( $id ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_complex( array $field ) {
		return in_array( isset( $field['type'] ) ? $field['type'] : '', self::COMPLEX, true );
	}
}
