<?php
/**
 * Whether a processor will take an amount: is_chargeable() and friends.
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
 * Whether a processor will take an amount: is_chargeable() and friends.
 *
 * The rules that end in an API rejection rather than a rounding
 * difference: minimum and maximum charges, and the currencies that only
 * move in steps of ten or a hundred. to_int() comes from Arithmetic.
 *
 * @since 1.0.0
 */
trait Charging {

	/**
	 * Currencies that must be charged as whole major units.
	 *
	 * ISK and UGX both moved to zero decimals, but Stripe still requires
	 * them expressed as two-decimal values with `00` in the minor
	 * position — 5 ISK is sent as 500, and fractions are rejected.
	 *
	 * @var string[]
	 */
	private const HUNDRED_MULTIPLE = array( 'isk', 'ugx' );

	/**
	 * Currencies that must be whole major units when paying *out*.
	 *
	 * HUF and TWD are zero-decimal for payouts and two-decimal for charges.
	 * Stripe is explicit about it: "Stripe treats HUF as a zero-decimal
	 * currency for payouts, even though you can charge two-decimal amounts."
	 *
	 * They are kept apart from the charge rule because conflating them
	 * refuses a charge Stripe would have taken -- a HUF 10.45 order is
	 * perfectly chargeable and would have been rejected by this library
	 * before it ever reached the API.
	 *
	 * @var string[]
	 */
	private const HUNDRED_MULTIPLE_PAYOUT = array( 'huf', 'twd' );

	/**
	 * Whether an amount is one the processor will accept.
	 *
	 * Two rules, both of which produce an API rejection rather than a
	 * rounding difference, and neither of which is implied by the decimal
	 * exponent:
	 *
	 * **Three-decimal currencies settle in hundredths.** BHD, JOD, KWD,
	 * OMR and TND have an exponent of 3, but Stripe settles them to two
	 * places, so the amount must be a multiple of ten. BHD 1.234 is
	 * refused.
	 *
	 * **Two currencies must be whole major units.** ISK and UGX moved to
	 * zero decimals but are still expressed with `00` in the minor
	 * position, so a charge needs a multiple of 100 -- Stripe says you
	 * cannot charge fractions of either.
	 *
	 * HUF and TWD are deliberately *not* in that group. They are
	 * zero-decimal for payouts and two-decimal for charges, and treating
	 * them as charge-restricted refuses a HUF 10.45 order that Stripe
	 * would have taken. See is_valid_payout().
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return bool
	 */
	public static function is_valid_amount( int $amount, string $code ): bool {
		return 0 === $amount % self::increment( $code );
	}

	/**
	 * Whether an amount is one the processor will pay out.
	 *
	 * A different question from is_valid_amount(), and only for HUF and TWD,
	 * where the two answers differ: a HUF 10.45 charge is fine and a HUF 10.45
	 * manual payout is refused, because the payout amount must be evenly
	 * divisible by 100.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return bool
	 */
	public static function is_valid_payout( int $amount, string $code ): bool {
		if ( ! self::is_valid_amount( $amount, $code ) ) {
			return false;
		}

		return in_array( strtolower( trim( $code ) ), self::HUNDRED_MULTIPLE_PAYOUT, true )
			? 0 === $amount % 100
			: true;
	}

	/**
	 * Why an amount cannot be charged, or null when it can.
	 *
	 * Combines the three ways an amount is rejected at the API rather
	 * than by your own validation: below the minimum, above the maximum,
	 * or not a permitted increment.
	 *
	 * The minimum is the one that bites in practice — a 0.30 USD charge
	 * looks perfectly reasonable and is refused, because Stripe will not
	 * let its own fee exceed the payment.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string|null Null when chargeable, else why not.
	 */
	public static function why_not_chargeable( int $amount, string $code ): ?string {
		// The code is usually caller-supplied and the message is meant to
		// be shown, so an unrecognised one is echoed only when it is three
		// plain letters.
		if ( ! Currencies::supports( $code ) ) {
			$safe = Currencies::sanitize_code( $code );

			return '' === $safe ? 'That is not a supported currency.' : $safe . ' is not a supported currency.';
		}

		$upper = strtoupper( trim( $code ) );

		if ( $amount < 0 ) {
			return 'Amount cannot be negative.';
		}

		$minimum = Currencies::minimum_charge( $code );

		// Zero is allowed: subscriptions use it for trials and full
		// coupons, and Stripe accepts it.
		if ( 0 !== $amount && $minimum > 0 && $amount < $minimum ) {
			return 'Minimum charge for ' . $upper . ' is ' . self::format( $minimum, $upper ) . '.';
		}

		if ( $amount > Currencies::maximum_charge( $code ) ) {
			return 'Maximum charge for ' . $upper . ' is ' . self::format( Currencies::maximum_charge( $code ), $upper ) . '.';
		}

		if ( ! self::is_valid_amount( $amount, $code ) ) {
			return $upper . ' amounts must be a multiple of ' . self::increment( $code ) . '.';
		}

		return null;
	}

	/**
	 * Whether an amount can be charged.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return bool
	 */
	public static function is_chargeable( int $amount, string $code ): bool {
		return null === self::why_not_chargeable( $amount, $code );
	}

	/**
	 * Round an amount to something the processor will accept.
	 *
	 * Rounds half away from zero, in integer arithmetic so an amount
	 * beyond 2^53 is not nudged by float rounding.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When rounding up would pass the
	 *                                   integer range.
	 */
	public static function round_to_valid( int $amount, string $code ): int {
		$increment = self::increment( $code );
		$remainder = $amount % $increment;

		if ( 0 === $remainder ) {
			return $amount;
		}

		$down = $amount - $remainder;

		if ( 2 * abs( $remainder ) < $increment ) {
			return $down;
		}

		return self::to_int( $down + ( $amount < 0 ? -$increment : $increment ) );
	}

	/**
	 * The smallest amount step a currency permits.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO-4217 code.
	 *
	 * @return int 1, 10, or 100.
	 */
	public static function increment( string $code ): int {
		if ( in_array( strtolower( trim( $code ) ), self::HUNDRED_MULTIPLE, true ) ) {
			return 100;
		}

		return Currencies::is_three_decimal( $code ) ? 10 : 1;
	}
}
