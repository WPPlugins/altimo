<?php
/**
 * The single generic, serialization-safe URL replacement engine (D-01/D-02/D-03).
 *
 * Replacing URLs with a raw str_replace() inside a PHP-serialized string
 * desynchronizes the s:N: length prefixes as soon as the replacement changes
 * the string length, after which unserialize() fails and the stored layout is
 * lost. This engine instead unpacks the value, replaces only scalar string
 * leaves inside the typed data tree, and reserializes -- so length prefixes are
 * always regenerated correctly.
 *
 * Every builder adapter routes through here; there is deliberately no
 * builder-specific replacement logic anywhere else.
 *
 * @package Image_AI_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format-aware typed replacement for serialized, JSON-decoded and plain values.
 */
class Image_AI_SEO_Serialization_Safe_Replacer {

	/**
	 * Recursion guard.
	 *
	 * PHP-serialized data from WordPress postmeta/options does not legitimately
	 * nest deeper than a handful of levels (builder trees rarely exceed ~15).
	 * A hard cap keeps pathological or corrupted input from exhausting the
	 * call stack.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 50;

	/**
	 * Replaces URLs inside a raw postmeta/option value without corrupting it.
	 *
	 * @param mixed $raw_value   The raw value exactly as stored in the database.
	 * @param array $url_mapping Map of old_url => new_url.
	 * @return array{0:mixed,1:bool} [ possibly updated raw value, whether it changed ].
	 */
	public static function replace( $raw_value, $url_mapping ) {
		if ( ! is_string( $raw_value ) || empty( $url_mapping ) ) {
			return array( $raw_value, false );
		}

		$changed = false;

		if ( is_serialized( $raw_value ) ) {
			// allowed_classes=false so that untrusted-origin postmeta can never
			// instantiate a real builder class (object injection). Unknown
			// classes come back as __PHP_Incomplete_Class stand-ins, which are
			// handled explicitly in recursive_replace().
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			$data = @unserialize( $raw_value, array( 'allowed_classes' => false ) );

			if ( false === $data && 'b:0;' !== $raw_value ) {
				// is_serialized() said yes but unserialize() still failed: the
				// value was already corrupted before we touched it. D-03 --
				// detect and report, never repair.
				return array( $raw_value, false );
			}

			$replaced = self::recursive_replace( $data, $url_mapping, 0, new SplObjectStorage(), $changed );

			// The change verdict comes from the leaves, NOT from comparing the
			// re-serialized bytes against the input. serialize() does not
			// round-trip byte-identically: float precision drifts,
			// __PHP_Incomplete_Class stand-ins lose their original private and
			// protected property-name mangling, and reference markers (R:/r:)
			// are flattened into duplicated values by the unserialize/serialize
			// pair. Inferring "changed" from those bytes made every no-op pass
			// look like a hit -- triggering a real $wpdb->update(), an
			// incremented statistic, a cache flush, and, worst of all, a
			// permanent rewrite of the customer's stored builder data even
			// though no URL had matched.
			if ( ! $changed ) {
				return array( $raw_value, false );
			}

			return array( serialize( $replaced ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		$new_raw = self::replace_scalar_string( $raw_value, $url_mapping, $changed );

		return array( $new_raw, $changed );
	}

	/**
	 * Runs the same typed replacement over an already decoded tree.
	 *
	 * Used by the Elementor branch (D-02): the caller json_decode()s the raw
	 * meta, hands the tree in here, and re-encodes the result itself so that
	 * escaped slashes are preserved.
	 *
	 * @param mixed $decoded     Decoded data tree.
	 * @param array $url_mapping Map of old_url => new_url.
	 * @param bool  $changed     Out-parameter: true when a leaf actually changed.
	 * @return mixed The tree with all string leaves replaced.
	 */
	public static function replace_decoded_tree( $decoded, $url_mapping, &$changed = false ) {
		return self::recursive_replace( $decoded, $url_mapping, 0, new SplObjectStorage(), $changed );
	}

	/**
	 * Replaces a single string leaf.
	 *
	 * A leaf may itself be a nested serialized blob (legacy double-encoded
	 * widget data is a documented real-world case), so that is tried first;
	 * only a plain string falls through to str_replace().
	 *
	 * @param string $value       The string leaf.
	 * @param array  $url_mapping Map of old_url => new_url.
	 * @param bool   $changed     Out-parameter, set to true when this leaf changed.
	 *                            Never reset to false -- it accumulates across the tree.
	 * @return string The replaced string.
	 */
	private static function replace_scalar_string( $value, $url_mapping, &$changed ) {
		if ( is_serialized( $value ) ) {
			list( $nested, $nested_changed ) = self::replace( $value, $url_mapping );

			if ( $nested_changed ) {
				$changed = true;

				return $nested;
			}

			return $value;
		}

		$replaced = $value;

		foreach ( $url_mapping as $old_url => $new_url ) {
			$replaced = str_replace( $old_url, $new_url, $replaced );
		}

		if ( $replaced !== $value ) {
			$changed = true;
		}

		return $replaced;
	}

	/**
	 * Walks the decoded data tree and replaces string leaves only.
	 *
	 * @param mixed            $data        Current node.
	 * @param array            $url_mapping Map of old_url => new_url.
	 * @param int              $depth       Current recursion depth.
	 * @param SplObjectStorage $visited     Objects already visited (cycle guard).
	 * @param bool             $changed     Out-parameter, set to true as soon as any
	 *                                      leaf anywhere in the tree changed.
	 * @return mixed The node with its string leaves replaced.
	 */
	private static function recursive_replace( $data, $url_mapping, $depth, SplObjectStorage $visited, &$changed ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			return self::replace_scalar_string( $data, $url_mapping, $changed );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				// Only values are reassigned; the key itself is never touched,
				// so integer and string key types survive untouched.
				$data[ $key ] = self::recursive_replace( $value, $url_mapping, $depth + 1, $visited, $changed );
			}

			return $data;
		}

		if ( is_object( $data ) ) {
			// Cycle guard: serialized object graphs may self-reference.
			if ( $visited->contains( $data ) ) {
				return $data;
			}

			$visited->attach( $data );

			if ( '__PHP_Incomplete_Class' === get_class( $data ) ) {
				return self::replace_incomplete_object( $data, $url_mapping, $depth, $visited, $changed );
			}

			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = self::recursive_replace( $value, $url_mapping, $depth + 1, $visited, $changed );
			}

			return $data;
		}

		// int, float, bool, null, resource: nothing to replace.
		return $data;
	}

	/**
	 * Replaces inside an object whose class was not loaded during unserialize().
	 *
	 * PHP refuses both reads and writes of properties on an incomplete object
	 * ("The script tried to modify a property on an incomplete object"), so the
	 * properties are collected, replaced, and a fresh instance is rebuilt from a
	 * correctly constructed serialized representation. Lengths are computed, not
	 * patched -- the same guarantee the rest of this class provides.
	 *
	 * When nothing changed the original object is returned untouched, which
	 * keeps a no-op pass byte-identical.
	 *
	 * @param object           $data        The incomplete object.
	 * @param array            $url_mapping Map of old_url => new_url.
	 * @param int              $depth       Current recursion depth.
	 * @param SplObjectStorage $visited     Objects already visited (cycle guard).
	 * @param bool             $changed     Out-parameter, set to true when the rebuild
	 *                                      actually replaced something.
	 * @return object Either the untouched original or a rebuilt equivalent.
	 */
	private static function replace_incomplete_object( $data, $url_mapping, $depth, SplObjectStorage $visited, &$changed ) {
		$vars       = get_object_vars( $data );
		$class_name = isset( $vars['__PHP_Incomplete_Class_Name'] ) ? $vars['__PHP_Incomplete_Class_Name'] : '';

		unset( $vars['__PHP_Incomplete_Class_Name'] );

		if ( ! is_string( $class_name ) || '' === $class_name ) {
			return $data;
		}

		$replaced      = array();
		$local_changed = false;

		foreach ( $vars as $key => $value ) {
			$new_value        = self::recursive_replace( $value, $url_mapping, $depth + 1, $visited, $local_changed );
			$replaced[ $key ] = $new_value;
		}

		if ( ! $local_changed ) {
			return $data;
		}

		$changed = true;

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$raw = 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . count( $replaced ) . ':{';

		foreach ( $replaced as $key => $value ) {
			$raw .= serialize( (string) $key ) . serialize( $value );
		}

		$raw .= '}';
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		$rebuilt = @unserialize( $raw, array( 'allowed_classes' => false ) );

		if ( false === $rebuilt || ! is_object( $rebuilt ) ) {
			return $data;
		}

		return $rebuilt;
	}
}
