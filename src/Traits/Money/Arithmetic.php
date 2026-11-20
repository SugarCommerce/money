<?php
/**
 * Sums on amounts: percentage(), allocate(), saving_percentage().
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Traits\Money;

/**
 * Sums on amounts: percentage(), allocate(), saving_percentage().
 *
 * Integer arithmetic with the rounding rule written once, so a total
 * always equals the sum of its lines.
 *
 * @since 1.0.0
 */
trait Arithmetic {

	/**
	 * Apply a percentage, rounding half away from zero.
	 *
	 * For discounts and tax. Kept here so the rounding rule is applied
	 * once rather than reinvented at each call site — inconsistent
	 * rounding is how an order total stops matching the sum of its lines.
	 *
	 * The arithmetic is integer. The percentage is read to six decimal
	 * places and the product is rounded exactly, because a float product
	 * misses ties: 17.5% of 180 is 31.5, which float arithmetic computes
	 * as 31.499… and rounds to 31.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $amount  Amount in the smallest currency unit.
	 * @param float $percent Percentage, e.g. 20.0 for 20%. Read to six
	 *                       decimal places.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When the percentage is not finite or
	 *                                   the result is outside integer range.
	 */
	public static function percentage( int $amount, float $percent ): int {
		if ( ! is_finite( $percent ) ) {
			throw new \InvalidArgumentException( 'A percentage must be a finite number.' );
		}

		$scaled = round( $percent * self::PERCENT_SCALE );

		// Beyond 10,000% the remainder × rate product below could pass
		// PHP_INT_MAX. Rates that size are not discounts or tax, so they
		// take the float path, which is still range-checked.
		if ( abs( $scaled ) >= self::PERCENT_SCALE * 10_000 ) {
			return self::to_int( round( $amount * $percent / 100 ) );
		}

		$rate        = (int) $scaled;
		$denominator = 100 * self::PERCENT_SCALE;

		// amount × rate ÷ denominator without overflowing the product:
		// the whole multiples of the denominator are multiplied directly,
		// and only the remainder (below 10^8) meets the rate.
		$whole     = intdiv( $amount, $denominator );
		$remainder = $amount % $denominator;
		$product   = $remainder * $rate;
		$result    = self::to_int( $whole * $rate ) + intdiv( $product, $denominator );
		$dropped   = $product % $denominator;

		if ( 2 * abs( $dropped ) >= $denominator ) {
			$result += $product < 0 ? -1 : 1;
		}

		return self::to_int( $result );
	}

	/**
	 * Decimal places of a percentage that percentage() honours, as a power
	 * of ten.
	 */
	private const PERCENT_SCALE = 1_000_000;

	/**
	 * Split an amount into shares that still sum to the original.
	 *
	 * Dividing 100 by 3 and rounding each share gives 33+33+33 = 99, and
	 * the missing penny turns up in a reconciliation months later. The
	 * remainder is distributed one unit at a time across the first
	 * shares, so the total always holds.
	 *
	 * @since 1.0.0
	 *
	 * @param int $amount Amount in the smallest currency unit.
	 * @param int $parts  How many shares.
	 *
	 * @return list<int> Shares summing exactly to `$amount`.
	 *
	 * @throws \InvalidArgumentException When asked for fewer than one share.
	 */
	public static function allocate( int $amount, int $parts ): array {
		if ( $parts < 1 ) {
			throw new \InvalidArgumentException( 'Cannot split an amount into fewer than one share.' );
		}

		$base      = intdiv( $amount, $parts );
		$remainder = $amount - ( $base * $parts );
		$extra     = abs( $remainder );
		$step      = $remainder > 0 ? 1 : -1;
		$shares    = array();

		for ( $i = 0; $i < $parts; $i++ ) {
			$shares[] = $i < $extra ? $base + $step : $base;
		}

		return $shares;
	}

	/**
	 * How much a reduction takes off, as a whole percentage.
	 *
	 * For the badge on a product card. Rounded down, so the saving is never
	 * overstated: 33.9% off reads as 33%, which is a claim the price supports.
	 *
	 * @since 1.0.0
	 *
	 * @param int $amount     What is being charged.
	 * @param int $compare_at What it cost before.
	 *
	 * @return int Nought when it is not a reduction.
	 */
	public static function saving_percentage( int $amount, int $compare_at ): int {
		if ( $compare_at <= 0 || $amount >= $compare_at ) {
			return 0;
		}

		return (int) floor( ( ( $compare_at - $amount ) / $compare_at ) * 100 );
	}

	/**
	 * Narrow to an integer, refusing anything that would not survive it.
	 *
	 * A figure past the integer range is a float, and PHP raises a
	 * `TypeError` at the return boundary rather than let it through — which
	 * reaches the user as a fatal error instead of a validation message.
	 * Rejecting it here makes the failure catchable and specific.
	 *
	 * @param int|float $value Candidate amount.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When outside integer range.
	 */
	private static function to_int( int|float $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_finite( $value ) || $value >= (float) PHP_INT_MAX || $value <= (float) PHP_INT_MIN ) {
			throw new \InvalidArgumentException( 'That amount is larger than this system can represent.' );
		}

		return (int) $value;
	}
}
