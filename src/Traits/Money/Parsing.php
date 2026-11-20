<?php
/**
 * Reading a typed amount back: parse(), sanitize_input().
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Traits\Money;

use SugarCommerce\Money\Currencies;

/**
 * Reading a typed amount back: parse(), sanitize_input().
 *
 * What somebody typed into a price box becomes minor units here, or an
 * InvalidArgumentException when it cannot be read exactly. Nothing is
 * rounded and nothing goes through a float.
 *
 * @since 1.0.0
 */
trait Parsing {

	/**
	 * Characters that write a minus sign other than the ASCII hyphen.
	 *
	 * U+2212 is what ICU puts in front of a negative in Swedish, Finnish,
	 * Norwegian and others, so Money::format() with a `locale` writes it;
	 * the dashes are what a phone or a word processor turns a typed hyphen
	 * into.
	 *
	 * @var string[]
	 */
	private const array MINUS_SIGNS = array(
		"\u{2212}", // Minus sign.
		"\u{2012}", // Figure dash.
		"\u{2013}", // En dash.
		"\u{FE63}", // Small hyphen-minus.
		"\u{FF0D}", // Full-width hyphen-minus.
	);

	/**
	 * Separators other scripts write, as the ASCII ones they stand for.
	 *
	 * @var array<string, string>
	 */
	private const array SEPARATORS = array(
		"\u{066B}" => '.', // Arabic decimal separator.
		"\u{066C}" => ',', // Arabic thousands separator.
		"\u{FF0E}" => '.', // Full-width full stop.
		"\u{FF0C}" => ',', // Full-width comma.
	);

	/**
	 * Parse human input back to the smallest currency unit.
	 *
	 * Accepts what people actually type: symbols, spaces, thousands
	 * separators, and either decimal convention — `€1.234,56` and
	 * `$1,234.56` both mean the same amount.
	 *
	 * An amount finer than the currency's minor unit is refused, not
	 * truncated or rounded: `1.234` is not a dollar amount, and quietly
	 * charging $1.23 for it is a different price from the one typed.
	 * Trailing zeros past the exponent change nothing and are accepted —
	 * `1.230` USD is 123, `100.00` JPY is 100, `100.5` JPY is refused.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw input.
	 * @param string $code  ISO-4217 code.
	 *
	 * @return int Amount in the smallest currency unit. 0 when nothing
	 *             numeric was present.
	 *
	 * @throws \InvalidArgumentException When the amount is too large to
	 *                                   hold in an integer, or has more
	 *                                   decimal places than the currency
	 *                                   has minor units. This method
	 *                                   reads form input, so callers
	 *                                   should expect it — silently
	 *                                   clamping or rounding a money
	 *                                   value would be worse than
	 *                                   refusing it.
	 */
	public static function parse( string $input, string $code ): int {
		$decimals = Currencies::decimals( $code );

		// Every minus sign a keyboard or ICU writes is a minus. Stripped
		// with the symbols instead, −5.00 read as a charge of 5.00.
		$input = str_replace( self::MINUS_SIGNS, '-', self::in_ascii_digits( $input ) );

		// Strip everything but digits, separators and a leading sign.
		$clean    = (string) preg_replace( '/[^\d.,\-]/u', '', trim( $input ) );
		$negative = str_starts_with( $clean, '-' );
		$clean    = str_replace( '-', '', $clean );

		if ( '' === $clean ) {
			return 0;
		}

		$clean = self::normalize_separators( $clean, $decimals );
		$parts = explode( '.', $clean, 2 );

		// Digits only on both sides. The major part is never routed
		// through a float: above 2^53 a float silently drops the last
		// digits, so a large figure would parse to a different amount
		// rather than failing.
		$major = (string) preg_replace( '/\D/', '', $parts[0] );
		$tail  = (string) preg_replace( '/\D/', '', $parts[1] ?? '' );

		// Digits past the currency's exponent must all be zero. Anything
		// else is a price the currency cannot express, and truncating or
		// rounding it would charge an amount nobody typed.
		if ( '' !== trim( substr( $tail, $decimals ), '0' ) ) {
			throw new \InvalidArgumentException( 'That amount has more decimal places than the currency allows.' );
		}

		$fraction = str_pad( substr( $tail, 0, $decimals ), $decimals, '0' );
		$amount   = self::digits_to_int( $major . $fraction );

		return $negative ? -$amount : $amount;
	}

	/**
	 * Input with every digit written as an ASCII one.
	 *
	 * `١٠٠` on an Arabic keyboard and `１００` from a Japanese input method
	 * are a hundred, as `100` is. The pattern that strips the symbols keeps
	 * such digits and the one that reads the number does not, so left
	 * alone they vanished: `١٠٠` read as nought and `1٠0` as ten. Their
	 * values come from ICU; without ext-intl they are refused rather than
	 * guessed at.
	 *
	 * @param string $input Raw input.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the input is not UTF-8, or has
	 *                                   digits that cannot be read without
	 *                                   ext-intl.
	 */
	private static function in_ascii_digits( string $input ): string {
		// Text that is not UTF-8 fails every /u pattern, which used to read
		// as nothing numeric -- nought, a free price.
		if ( ! mb_check_encoding( $input, 'UTF-8' ) ) {
			throw new \InvalidArgumentException( 'That amount is not readable text.' );
		}

		$input = strtr( $input, self::SEPARATORS );

		return (string) preg_replace_callback(
			'/(?![0-9])\p{Nd}/u',
			static function ( array $digit ): string {
				if ( ! class_exists( \IntlChar::class ) ) {
					throw new \InvalidArgumentException( 'That amount is written in digits this system cannot read.' );
				}

				return (string) \IntlChar::charDigitValue( $digit[0] );
			},
			$input
		);
	}

	/**
	 * Read whatever a form sent as minor units.
	 *
	 * parse() for a value that may not be a string: an integer is taken as
	 * minor units already, and an array or a null -- a field that was not
	 * in the form -- is nought. Takes "19.99", "£19.99", "1,999.00" and
	 * "19,99" alike.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value What was typed.
	 * @param string $code  ISO-4217 code, or empty for the default.
	 *
	 * @return int Amount in the smallest currency unit.
	 *
	 * @throws \InvalidArgumentException As parse() does.
	 */
	public static function sanitize_input( mixed $value, string $code = '' ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		return self::parse( (string) $value, self::currency( $code ) );
	}

	/**
	 * Read a run of decimal digits as an integer, exactly.
	 *
	 * @param string $digits Decimal digits only; '' reads as zero.
	 *
	 * @return int Non-negative.
	 *
	 * @throws \InvalidArgumentException When the value reaches PHP_INT_MAX.
	 */
	private static function digits_to_int( string $digits ): int {
		$digits = ltrim( $digits, '0' );

		if ( '' === $digits ) {
			return 0;
		}

		$max = (string) PHP_INT_MAX;

		// At equal length a lexical comparison is a numeric one.
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) >= 0 ) ) {
			throw new \InvalidArgumentException( 'That amount is larger than this system can represent.' );
		}

		return (int) $digits;
	}

	/**
	 * Resolve mixed decimal conventions to a single dot.
	 *
	 * `1.234,56` and `1,234.56` are the same amount written for different
	 * audiences, and both arrive from real forms. The rightmost separator
	 * is the decimal one when what follows it is shorter than the
	 * currency's exponent or the two separators differ.
	 *
	 * @param string $value    Digits and separators only.
	 * @param int    $decimals Currency exponent.
	 *
	 * @return string Digits with at most one dot.
	 */
	private static function normalize_separators( string $value, int $decimals ): string {
		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		// Both present: the rightmost is the decimal separator.
		if ( false !== $last_dot && false !== $last_comma ) {
			return $last_comma > $last_dot
				? str_replace( ',', '.', str_replace( '.', '', $value ) )
				: str_replace( ',', '', $value );
		}

		// Only commas. Grouped thousands ("1,234,567") have three digits
		// after each; a single comma with a short tail is a decimal mark.
		if ( false !== $last_comma ) {
			$tail = strlen( $value ) - $last_comma - 1;

			return ( 3 === $tail && 3 !== $decimals )
				? str_replace( ',', '', $value )
				: str_replace( ',', '.', $value );
		}

		// Only dots. Several means thousands separators.
		if ( substr_count( $value, '.' ) > 1 ) {
			return str_replace( '.', '', $value );
		}

		return $value;
	}
}
