<?php
/**
 * Amounts and rates from a column or an API: sanitize_amount(), sanitize_exchange_rate().
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Traits\Money;

/**
 * Amounts and rates from a column or an API: sanitize_amount(), sanitize_exchange_rate().
 *
 * For values that are already numbers of some kind, from a gateway, a
 * database column or arithmetic. What a person typed goes through
 * Parsing instead, which knows the currency.
 *
 * @since 1.0.0
 */
trait Sanitizing {

	/**
	 * An amount in minor units, from whatever it arrived as.
	 *
	 * A whole number that is never less than nought: a negative, and
	 * anything that is not a number, is 0. A sale, a fee and a refund are
	 * all positive amounts -- which of them it is belongs in a column of its
	 * own, not in a sign an unsigned column would drop.
	 *
	 * A float is rounded, not cut, because arithmetic leaves them a hair
	 * short: 19.99 * 100 is 1998.9999999999998, and that is 1999.
	 *
	 * This is not parse(). `'19.99'` here is nineteen minor units and a bit,
	 * rounded to twenty; what somebody typed into a price box goes through
	 * parse() or sanitize_input(), which know the currency.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The value, from an API, a column or arithmetic.
	 *
	 * @return int Minor units, 0 or more.
	 */
	public static function sanitize_amount( mixed $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		// Digits only is the common case off a form or a column, and the one
		// a float cannot be trusted with once the number is long.
		if ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) < 19 ) {
			return (int) $value;
		}

		$number = round( (float) $value );

		if ( is_nan( $number ) || $number <= 0 ) {
			return 0;
		}

		return $number >= PHP_INT_MAX ? PHP_INT_MAX : (int) $number;
	}

	/**
	 * An exchange rate, from whatever it arrived as.
	 *
	 * A positive number, rounded to as many places as are kept. Nought for
	 * a negative, for nought and for anything that is not a number -- which
	 * a caller can read as "not known", since no real rate is nought.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value     The rate.
	 * @param int   $precision Decimal places to keep.
	 *
	 * @return float The rate, or 0.0.
	 */
	public static function sanitize_exchange_rate( mixed $value, int $precision = 8 ): float {
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		$rate = (float) $value;

		return is_finite( $rate ) && $rate > 0 ? round( $rate, max( 0, $precision ) ) : 0.0;
	}
}
