<?php
/**
 * Exact decimal arithmetic helpers.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Performs fixed-scale decimal operations without converting values to float.
 */
final class Balance_Beacon_Decimal {
	const SCALE = 8;

	/** Add two decimal strings. */
	public static function add( $left, $right, $scale = self::SCALE ) {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( self::normalize( $left ), self::normalize( $right ), $scale );
		}

		$left  = self::parts( $left, $scale );
		$right = self::parts( $right, $scale );

		if ( $left['negative'] === $right['negative'] ) {
			$digits   = self::add_digits( $left['digits'], $right['digits'] );
			$negative = $left['negative'];
		} else {
			$comparison = self::compare_digits( $left['digits'], $right['digits'] );
			if ( 0 === $comparison ) {
				return self::format_digits( '0', false, $scale );
			}
			$larger   = $comparison > 0 ? $left : $right;
			$smaller  = $comparison > 0 ? $right : $left;
			$digits   = self::subtract_digits( $larger['digits'], $smaller['digits'] );
			$negative = $larger['negative'];
		}

		return self::format_digits( $digits, $negative, $scale );
	}

	/** Subtract one decimal string from another. */
	public static function subtract( $left, $right, $scale = self::SCALE ) {
		if ( function_exists( 'bcsub' ) ) {
			return bcsub( self::normalize( $left ), self::normalize( $right ), $scale );
		}

		$right = self::normalize( $right );
		$right = '-' === $right[0] ? substr( $right, 1 ) : '-' . $right;

		return self::add( $left, $right, $scale );
	}

	/** Normalize a database decimal value to a plain decimal string. */
	public static function normalize( $value ) {
		$value = null === $value || '' === $value ? '0' : trim( (string) $value );

		if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid decimal value.' );
		}

		return $value;
	}

	/** Convert a decimal string to sign and arbitrary-length scaled digits. */
	private static function parts( $value, $scale ) {
		$value    = self::normalize( $value );
		$negative = '-' === $value[0];
		$value    = ltrim( $value, '+-' );
		$parts    = explode( '.', $value, 2 );
		$fraction = isset( $parts[1] ) ? $parts[1] : '';
		$fraction = substr( str_pad( $fraction, $scale, '0' ), 0, $scale );
		$digits   = ltrim( $parts[0] . $fraction, '0' );

		return array( 'negative' => $negative, 'digits' => '' === $digits ? '0' : $digits );
	}

	/** Format arbitrary-length scaled digits as a decimal string. */
	private static function format_digits( $digits, $negative, $scale ) {
		$digits = str_pad( ltrim( $digits, '0' ) ?: '0', $scale + 1, '0', STR_PAD_LEFT );
		$result   = substr( $digits, 0, -$scale ) . '.' . substr( $digits, -$scale );

		return $negative && '' !== trim( $digits, '0' ) ? '-' . $result : $result;
	}

	/** Add two unsigned integer strings. */
	private static function add_digits( $left, $right ) {
		$left = strrev( $left );
		$right = strrev( $right );
		$carry = 0;
		$out = '';
		$length = max( strlen( $left ), strlen( $right ) );
		for ( $i = 0; $i < $length; $i++ ) {
			$sum = ( $i < strlen( $left ) ? (int) $left[ $i ] : 0 ) + ( $i < strlen( $right ) ? (int) $right[ $i ] : 0 ) + $carry;
			$out .= (string) ( $sum % 10 );
			$carry = intdiv( $sum, 10 );
		}
		if ( $carry ) $out .= (string) $carry;
		return strrev( $out );
	}

	/** Subtract the smaller unsigned integer string from the larger one. */
	private static function subtract_digits( $larger, $smaller ) {
		$larger = strrev( $larger );
		$smaller = strrev( $smaller );
		$borrow = 0;
		$out = '';
		for ( $i = 0, $length = strlen( $larger ); $i < $length; $i++ ) {
			$digit = (int) $larger[ $i ] - $borrow - ( $i < strlen( $smaller ) ? (int) $smaller[ $i ] : 0 );
			if ( $digit < 0 ) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
			$out .= (string) $digit;
		}
		return ltrim( strrev( $out ), '0' ) ?: '0';
	}

	/** Compare two unsigned integer strings. */
	private static function compare_digits( $left, $right ) {
		$left = ltrim( $left, '0' ) ?: '0';
		$right = ltrim( $right, '0' ) ?: '0';
		if ( strlen( $left ) !== strlen( $right ) ) return strlen( $left ) < strlen( $right ) ? -1 : 1;
		return strcmp( $left, $right );
	}
}
