<?php
/**
 * A small JSON Schema (draft 2020-12 subset) evaluator for the test suite.
 *
 * The suite runs with a bare `php tests/unit.php` and no Composer
 * dependencies, so it cannot use a full validator library. This covers the
 * keywords mdf.schema.json actually uses — $ref to local $defs, type,
 * required, properties, additionalProperties, propertyNames, pattern, format
 * (uri only), min/max length, minimum, enum, const, items, min/max items,
 * uniqueItems, allOf, if/then/else and not — which is enough to validate the
 * capability document this plugin emits, and to reject obvious mistakes.
 *
 * Deliberately not a general-purpose validator: unknown keywords are ignored,
 * so it can only ever be more permissive than the real schema, never stricter.
 */

/**
 * @param mixed $data
 * @param mixed $schema
 * @return string[] Human-readable validation errors, empty when valid.
 */
function mdf_schema_validate( $data, $schema, string $path = '' ): array {
	if ( ! is_array( $schema ) ) {
		return [];
	}

	$errors = mdf_schema_errors( $data, $schema, $path === '' ? '/' : $path, $schema );

	return $errors;
}

/**
 * @param mixed $data
 * @param array $schema
 * @param string $path
 * @param array $root
 * @return string[]
 */
function mdf_schema_errors( $data, array $schema, string $path, array $root ): array {
	$errors = [];

	// $ref — local definitions only ("#/$defs/name" or "#/definitions/name").
	if ( isset( $schema['$ref'] ) && is_string( $schema['$ref'] ) ) {
		$target = mdf_schema_resolve_ref( $schema['$ref'], $root );
		if ( $target === null ) {
			return [ $path . ': unresolvable $ref ' . $schema['$ref'] ];
		}
		return mdf_schema_errors( $data, $target, $path, $root );
	}

	// allOf
	if ( isset( $schema['allOf'] ) && is_array( $schema['allOf'] ) ) {
		foreach ( $schema['allOf'] as $sub ) {
			$errors = array_merge( $errors, mdf_schema_errors( $data, $sub, $path, $root ) );
		}
	}

	// if / then / else
	if ( isset( $schema['if'] ) ) {
		$cond = mdf_schema_errors( $data, $schema['if'], $path, $root );
		if ( $cond === [] && isset( $schema['then'] ) ) {
			$errors = array_merge( $errors, mdf_schema_errors( $data, $schema['then'], $path, $root ) );
		} elseif ( $cond !== [] && isset( $schema['else'] ) ) {
			$errors = array_merge( $errors, mdf_schema_errors( $data, $schema['else'], $path, $root ) );
		}
	}

	// not
	if ( isset( $schema['not'] ) ) {
		if ( mdf_schema_errors( $data, $schema['not'], $path, $root ) === [] ) {
			$errors[] = $path . ': must not match the excluded schema';
		}
	}

	// const
	if ( array_key_exists( 'const', $schema ) && $data !== $schema['const'] ) {
		$errors[] = $path . ': must equal ' . json_encode( $schema['const'] );
	}

	// enum
	if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
		$errors[] = $path . ': must be one of ' . json_encode( $schema['enum'] );
	}

	// type
	if ( isset( $schema['type'] ) && ! mdf_schema_type_matches( $data, (string) $schema['type'] ) ) {
		return array_merge( $errors, [ $path . ': must be of type ' . $schema['type'] ] );
	}

	// Strings.
	if ( is_string( $data ) ) {
		if ( isset( $schema['minLength'] ) && mb_strlen( $data ) < (int) $schema['minLength'] ) {
			$errors[] = $path . ': shorter than minLength ' . $schema['minLength'];
		}
		if ( isset( $schema['maxLength'] ) && mb_strlen( $data ) > (int) $schema['maxLength'] ) {
			$errors[] = $path . ': longer than maxLength ' . $schema['maxLength'];
		}
		if ( isset( $schema['pattern'] ) && ! preg_match( '~' . $schema['pattern'] . '~', $data ) ) {
			$errors[] = $path . ': does not match pattern ' . $schema['pattern'];
		}
		if ( isset( $schema['format'] ) && $schema['format'] === 'uri' && ! mdf_schema_is_uri( $data ) ) {
			$errors[] = $path . ': is not a URI';
		}
	}

	// Numbers.
	if ( ( is_int( $data ) || is_float( $data ) ) && isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
		$errors[] = $path . ': below minimum ' . $schema['minimum'];
	}

	// Objects.
	if ( is_array( $data ) && ( ! $data || array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
		$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];

		foreach ( (array) ( $schema['required'] ?? [] ) as $required ) {
			if ( ! array_key_exists( $required, $data ) ) {
				$errors[] = $path . ': missing required property "' . $required . '"';
			}
		}

		foreach ( $data as $key => $value ) {
			$child_path = rtrim( $path, '/' ) . '/' . $key;

			if ( isset( $schema['propertyNames'] ) && is_array( $schema['propertyNames'] ) ) {
				$name_errors = mdf_schema_errors( (string) $key, $schema['propertyNames'], $child_path . ' (name)', $root );
				$errors       = array_merge( $errors, $name_errors );
			}

			if ( isset( $props[ $key ] ) ) {
				$errors = array_merge( $errors, mdf_schema_errors( $value, $props[ $key ], $child_path, $root ) );
				continue;
			}

			if ( array_key_exists( 'additionalProperties', $schema ) ) {
				if ( $schema['additionalProperties'] === false ) {
					$errors[] = $path . ': unexpected property "' . $key . '"';
				} elseif ( is_array( $schema['additionalProperties'] ) ) {
					$errors = array_merge( $errors, mdf_schema_errors( $value, $schema['additionalProperties'], $child_path, $root ) );
				}
			}
		}
	}

	// Arrays.
	if ( is_array( $data ) && ( ! $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
		if ( isset( $schema['minItems'] ) && count( $data ) < (int) $schema['minItems'] ) {
			$errors[] = $path . ': fewer than minItems ' . $schema['minItems'];
		}
		if ( isset( $schema['maxItems'] ) && count( $data ) > (int) $schema['maxItems'] ) {
			$errors[] = $path . ': more than maxItems ' . $schema['maxItems'];
		}
		if ( ! empty( $schema['uniqueItems'] ) ) {
			$seen = [];
			foreach ( $data as $item ) {
				$key = json_encode( $item );
				if ( isset( $seen[ $key ] ) ) {
					$errors[] = $path . ': items are not unique';
					break;
				}
				$seen[ $key ] = true;
			}
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $data as $i => $item ) {
				$errors = array_merge( $errors, mdf_schema_errors( $item, $schema['items'], rtrim( $path, '/' ) . '/' . $i, $root ) );
			}
		}
	}

	return $errors;
}

/**
 * @param mixed $data
 */
function mdf_schema_type_matches( $data, string $type ): bool {
	switch ( $type ) {
		case 'object':
			return is_array( $data );
		case 'array':
			return is_array( $data ) && ( ! $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) );
		case 'string':
			return is_string( $data );
		case 'integer':
			return is_int( $data );
		case 'number':
			return is_int( $data ) || is_float( $data );
		case 'boolean':
			return is_bool( $data );
		case 'null':
			return $data === null;
	}

	return true;
}

/**
 * Resolve a local JSON pointer ("#/$defs/x") against the root schema.
 *
 * @return array|null
 */
function mdf_schema_resolve_ref( string $ref, array $root ): ?array {
	if ( strpos( $ref, '#/' ) !== 0 ) {
		return null;
	}

	$node = $root;
	foreach ( explode( '/', substr( $ref, 2 ) ) as $segment ) {
		$segment = str_replace( [ '~1', '~0' ], [ '/', '~' ], rawurldecode( $segment ) );
		if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
			return null;
		}
		$node = $node[ $segment ];
	}

	return is_array( $node ) ? $node : null;
}

/**
 * A deliberately loose URI check (scheme + something after it).
 */
function mdf_schema_is_uri( string $value ): bool {
	return (bool) preg_match( '~^[a-zA-Z][a-zA-Z0-9+.\-]*:[^\s]+$~', $value );
}
