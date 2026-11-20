<?php
/**
 * Integer-minor-unit money formatting and parsing.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money;

use SugarCommerce\Money\Traits\Money\Arithmetic;
use SugarCommerce\Money\Traits\Money\Charging;
use SugarCommerce\Money\Traits\Money\Formatting;
use SugarCommerce\Money\Traits\Money\Parsing;
use SugarCommerce\Money\Traits\Money\Sanitizing;

/**
 * Class Money
 *
 * Formats and parses amounts held as integers in the smallest currency
 * unit — the representation Stripe, PayPal, Adyen and every sane
 * accounting system use.
 *
 * **Money is never a float.** `0.1 + 0.2` is not `0.3` in binary
 * floating point, and a rounding error in a total is a discrepancy
 * somebody eventually has to reconcile by hand. Amounts stay integer
 * from the database to the payment processor; this class exists only to
 * render them for humans and read them back.
 *
 * **Dividing by 100 is wrong for twenty currencies.** The exponent is 0
 * for fifteen (JPY, KRW, VND, the CFA francs) and 3 for five (BHD, JOD,
 * KWD, OMR, TND). A ¥1,000 order divided by 100 displays as ¥10.00, and
 * BHD 1.000 displays as BD 1000 — the first undercharges by two orders
 * of magnitude and the second overcharges by three. Everything here
 * consults {@see Currencies::decimals()} instead.
 *
 * Typical use:
 *
 *   Money::format( 4999 );                              // '$49.99'
 *   Money::format( 1000, 'JPY' );                       // '¥1,000'
 *   Money::format( 2900, [ 'currency' => 'GBP', 'compact' => true ] ); // '£29'
 *   Money::parse( '49.99', 'USD' );                     // 4999
 *
 * Each job is a trait under Traits/Money: Formatting, Parsing, Charging,
 * Arithmetic and Sanitizing. This class holds the default currency they
 * all fall back on.
 *
 * @since 1.0.0
 */
final class Money {

	use Arithmetic;
	use Charging;
	use Formatting;
	use Parsing;
	use Sanitizing;

	/**
	 * The currency to use when none is given: a code, or a closure that
	 * answers with one.
	 *
	 * Static, and so one per copy of this library. A plugin that bundles it
	 * under its own prefix has its own copy and its own default, which is
	 * the point: two shops on one site do not share a currency by accident.
	 *
	 * @var string|\Closure|null
	 */
	private static string|\Closure|null $default_currency = null;

	/**
	 * Say which currency an amount is in when nobody says.
	 *
	 * A code for a shop with one currency; a closure for one whose answer
	 * depends on the request, or that wants its own filter on it:
	 *
	 *     Money::set_default_currency( 'GBP' );
	 *     Money::set_default_currency( fn() => apply_filters( 'myshop_currency', get_option( 'myshop_currency', 'USD' ) ) );
	 *
	 * Asked every time an amount is formatted without a currency, so a
	 * closure sees the request it is formatting for.
	 *
	 * @since 1.0.0
	 *
	 * @param string|\Closure|null $currency A code, a closure returning one,
	 *                                       or null for US dollars.
	 *
	 * @return void
	 */
	public static function set_default_currency( string|\Closure|null $currency ): void {
		self::$default_currency = $currency;
	}

	/**
	 * The currency to format in.
	 *
	 * The code given, or the default when it is empty. A default that is
	 * not a currency this library knows is US dollars instead: an unknown
	 * code would format every price on the site with no symbol and two
	 * decimals, silently.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO-4217 code, or empty for the default.
	 *
	 * @return string
	 */
	public static function currency( string $code = '' ): string {
		if ( '' !== $code ) {
			return $code;
		}

		$default = self::$default_currency instanceof \Closure
			? ( self::$default_currency )()
			: self::$default_currency;

		$default = is_string( $default ) ? $default : '';

		return Currencies::supports( $default ) ? strtoupper( trim( $default ) ) : 'USD';
	}

	/**
	 * An amount as it was asked for: minor units, or money for a person.
	 *
	 * The shape every record's money getter has -- `get_total( true )` for
	 * "£12.00", `get_total()` for 1200 -- in one place. An amount that is
	 * not known yet is null, or a dash when formatted: never a nought,
	 * which would read as "free".
	 *
	 * @since 1.0.0
	 *
	 * @param int|null      $amount    Minor units, or null when not known.
	 * @param string        $code      ISO-4217 code, or empty for the default.
	 * @param bool          $formatted Money for a person rather than minor units.
	 * @param string        $unknown   What a formatted unknown amount reads as.
	 * @param callable|null $format    The plugin's own formatter, `( int, string ): string`,
	 *                                 so its filters still apply; format() otherwise.
	 *
	 * @return int|string|null
	 */
	public static function value( ?int $amount, string $code, bool $formatted = false, string $unknown = "\u{2014}", ?callable $format = null ): int|string|null {
		if ( null === $amount ) {
			return $formatted ? $unknown : null;
		}

		if ( ! $formatted ) {
			return $amount;
		}

		return null !== $format
			? (string) $format( $amount, $code )
			: self::format( $amount, $code );
	}
}
