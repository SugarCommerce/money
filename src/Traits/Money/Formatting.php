<?php
/**
 * Writing an amount for a person: format(), decimal(), input_value().
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Traits\Money;

use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Options;
use SugarCommerce\Money\Recurring;

/**
 * Writing an amount for a person: format(), decimal(), input_value().
 *
 * Every way an integer amount becomes text, and nothing else. The digits
 * are split as a string, never divided, so an amount past 2^53 reads
 * exactly; ICU is used only when a locale is asked for.
 *
 * @since 1.0.0
 */
trait Formatting {

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
}
