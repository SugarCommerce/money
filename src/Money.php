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
 * @since 1.0.0
 */
final class Money {

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

	/**
	 * An amount as text.
	 *
	 *     Money::format( 4999 );                                        // '$49.99'
	 *     Money::format( 1000, 'JPY' );                                 // '¥1,000'
	 *     Money::format( 4999, [ 'symbol' => false, 'code' => true ] ); // '49.99 USD'
	 *     Money::format( 2900, [ 'compact' => true ] );                 // '$29'
	 *     Money::format( 2900, [ 'currency' => 'EUR', 'locale' => 'de' ] ); // '29,00 €'
	 *     Money::format( 999, [ 'interval' => 'month' ] );              // '$9.99/mo'
	 *
	 * The options are listed in {@see Options}. A string is the currency,
	 * for the commonest call.
	 *
	 * @since 1.0.0
	 *
	 * @param int                         $amount  Amount in the smallest currency unit.
	 * @param array<string, mixed>|string $options How to write it, or the currency.
	 *
	 * @return string e.g. `$49.99`, `¥1,000`, `BD 1.500`.
	 */
	public static function format( int $amount, array|string $options = array() ): string {
		$options = Options::parse( is_string( $options ) ? array( 'currency' => $options ) : $options );

		$code    = self::currency( (string) $options['currency'] );
		$compact = (bool) $options['compact'];
		$symbol  = (bool) $options['symbol'];
		$grouped = (bool) $options['separators'];
		$locale  = trim( (string) $options['locale'] );

		$rendered = '' !== $locale ? self::localized( $amount, $code, $locale, $symbol, $grouped, $compact ) : null;

		if ( null === $rendered ) {
			$rendered = self::digits( $amount, Currencies::decimals( $code ), $grouped, $compact );

			if ( $symbol ) {
				$rendered = self::with_symbol( $rendered, $amount, $code );
			}
		}

		if ( $options['code'] ) {
			$rendered = self::with_code( $rendered, $code );
		}

		if ( '' !== trim( (string) $options['interval'] ) ) {
			$rendered .= Recurring::suffix( (string) $options['interval'], (int) $options['interval_count'] );
		}

		return $rendered;
	}

	/**
	 * The amount with its symbol in front.
	 *
	 * @param string $rendered What the amount already reads as.
	 * @param int    $amount   Amount in the smallest currency unit.
	 * @param string $code     ISO-4217 code.
	 *
	 * @return string
	 */
	private static function with_symbol( string $rendered, int $amount, string $code ): string {
		$symbol   = Currencies::symbol( $code );
		$negative = $amount < 0;

		if ( $negative ) {
			$rendered = ltrim( $rendered, '-' );
		}

		// A multi-letter symbol is a code, not a glyph, so it needs a
		// space: "BD 1.500", but "$49.99". An empty symbol means the code
		// was unrecognisable and got dropped — no leading space for it.
		$joined = match ( true ) {
			'' === $symbol             => $rendered,
			1 === mb_strlen( $symbol ) => $symbol . $rendered,
			default                    => $symbol . ' ' . $rendered,
		};

		return $negative ? '-' . $joined : $joined;
	}

	/**
	 * The rendered amount with its code after it.
	 *
	 * @param string $rendered What the amount already reads as.
	 * @param string $code     ISO-4217 code.
	 *
	 * @return string
	 */
	private static function with_code( string $rendered, string $code ): string {
		$suffix = Currencies::sanitize_code( $code );

		return '' === $suffix ? $rendered : $rendered . ' ' . $suffix;
	}

	/**
	 * The amount the way a locale writes it, through ICU.
	 *
	 * Places the symbol and separators where the locale expects them --
	 * `1 234,56 €` in French, `€1,234.56` in English. A compact whole amount
	 * loses its fraction here rather than by trimming the result, because in
	 * many locales the symbol, not the fraction, is at the end.
	 *
	 * The locale may come straight from an `Accept-Language` header, so one
	 * ICU cannot use falls back rather than throwing. The code is checked
	 * before ICU sees it, because ICU prints an unknown code as the symbol
	 * and would otherwise echo `"><` into the page.
	 *
	 * @param int    $amount  Amount in the smallest currency unit.
	 * @param string $code    ISO-4217 code.
	 * @param string $locale  Locale tag, `pt-BR` or `pt_BR`.
	 * @param bool   $symbol  Whether to show the currency.
	 * @param bool   $grouped Whether to group the thousands.
	 * @param bool   $compact Whether a whole amount drops its fraction.
	 *
	 * @return string|null Null when ICU cannot help: no ext-intl, a code
	 *                     that is not three letters, a locale it refuses.
	 */
	private static function localized( int $amount, string $code, string $locale, bool $symbol, bool $grouped, bool $compact ): ?string {
		$safe = Currencies::sanitize_code( $code );

		if ( '' === $safe || ! class_exists( \NumberFormatter::class ) ) {
			return null;
		}

		try {
			$formatter = new \NumberFormatter( str_replace( '-', '_', $locale ), $symbol ? \NumberFormatter::CURRENCY : \NumberFormatter::DECIMAL );
		} catch ( \Throwable ) {
			return null;
		}

		$places = Currencies::decimals( $code );

		// Whole amounts drop the fraction; anything else keeps every digit
		// the currency has, so 29.50 never prints as 29.5. Without a symbol
		// ICU knows nothing of the currency, so it is told the places too.
		if ( $compact && self::is_whole( $amount, $places ) ) {
			$places = 0;
		}

		if ( ! $symbol || 0 === $places ) {
			$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $places );
			$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $places );
		}

		if ( ! $grouped ) {
			$formatter->setAttribute( \NumberFormatter::GROUPING_USED, 0 );
		}

		$number = self::to_float( $amount, $code );
		$result = $symbol ? $formatter->formatCurrency( $number, $safe ) : $formatter->format( $number );

		return false === $result ? null : $result;
	}

	/**
	 * The decimal string for an amount, without any symbol.
	 *
	 * Thousands-separated unless told otherwise, with exactly as many
	 * decimal places as the currency has -- two for most, none for the yen,
	 * three for the dinars. Without separators it is what a spreadsheet, a
	 * CSV or a number input reads as a number: `1234.05`, `4900`, `1.234`.
	 * Settling on "two places or none" there is the mistake this avoids: a
	 * Kuwaiti dinar has a thousand fils, and KWD 1.234 written to two places
	 * is 1.23 -- four fils gone from every row of an export.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount     Amount in the smallest currency unit.
	 * @param string $code       ISO-4217 code.
	 * @param bool   $separators Whether to group thousands with a comma.
	 *
	 * @return string e.g. `49.99`, `1,000`, `1.500`; `1000` without separators.
	 */
	public static function decimal( int $amount, string $code, bool $separators = true ): string {
		return self::digits( $amount, Currencies::decimals( $code ), $separators, false );
	}

	/**
	 * Write minor units out as a decimal, exactly.
	 *
	 * The digits are split as a string rather than divided. Converting to
	 * float is what reintroduces the rounding error this class avoids, and
	 * abs( PHP_INT_MIN ) is itself a float, so even integer division cannot
	 * start from the absolute value. The thousands are grouped by hand for
	 * the same reason: number_format() takes a float, and above 2^53 a
	 * float has already lost the last digits.
	 *
	 * @param int  $amount  Amount in the smallest currency unit.
	 * @param int  $places  The currency's exponent.
	 * @param bool $grouped Whether to group thousands with a comma.
	 * @param bool $compact Whether a whole amount drops its fraction.
	 *
	 * @return string
	 */
	private static function digits( int $amount, int $places, bool $grouped, bool $compact ): string {
		$digits = str_pad( ltrim( (string) $amount, '-' ), $places + 1, '0', STR_PAD_LEFT );
		$major  = 0 === $places ? $digits : substr( $digits, 0, -$places );
		$minor  = 0 === $places ? '' : substr( $digits, -$places );

		$rendered = $grouped ? self::group( $major ) : $major;

		// Compact is for a price being advertised: `$49` beside a button,
		// never on a receipt, where the zeros say the number is exact.
		if ( '' !== $minor && ! ( $compact && '' === trim( $minor, '0' ) ) ) {
			$rendered .= '.' . $minor;
		}

		return $amount < 0 ? '-' . $rendered : $rendered;
	}

	/**
	 * Digits grouped in threes with a comma.
	 *
	 * @param string $digits Decimal digits, no sign.
	 *
	 * @return string
	 */
	private static function group( string $digits ): string {
		return ltrim( strrev( chunk_split( strrev( $digits ), 3, ',' ) ), ',' );
	}

	/**
	 * Whether an amount is a whole number of the major unit.
	 *
	 * @param int $amount Amount in the smallest currency unit.
	 * @param int $places The currency's exponent.
	 *
	 * @return bool
	 */
	private static function is_whole( int $amount, int $places ): bool {
		return 0 === $amount % ( 10 ** $places );
	}

	/**
	 * An amount as it should sit in a text or number input.
	 *
	 * No symbol and no separators, so it round-trips through parse()
	 * unchanged and a number input does not render it empty for the sake
	 * of a comma. Getting it wrong silently changes a price the moment
	 * somebody opens the form and saves it without touching the field.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code, or empty for the default.
	 *
	 * @return string e.g. `49.99`, `1000`, `1.500`.
	 */
	public static function input_value( int $amount, string $code = '' ): string {
		return self::decimal( $amount, self::currency( $code ), false );
	}

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

	/**
	 * Convert to a float, for a formatter that demands one.
	 *
	 * Use it to hand a value to something like `NumberFormatter` or a chart,
	 * never to store, compare, or do arithmetic with.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return float
	 */
	public static function to_float( int $amount, string $code ): float {
		return $amount / ( 10 ** Currencies::decimals( $code ) );
	}

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
