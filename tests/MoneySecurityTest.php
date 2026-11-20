<?php
/**
 * Untrusted input reaching the money helpers.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Money;
use SugarCommerce\Money\Recurring;

/**
 * A currency code routinely arrives from a query string — `?currency=`
 * on a headless checkout — and an amount arrives from a form field.
 * Neither may reach the rendered page or a fatal error.
 */
final class MoneySecurityTest extends TestCase {

	/* ─── Codes ─────────────────────────────────────────────────────── */

	#[DataProvider( 'hostile_codes' )]
	public function test_an_unrecognised_code_is_never_echoed( string $code ): void {
		$this->assertSame( '', Currencies::sanitize_code( $code ) );
		$this->assertSame( '', Currencies::symbol( $code ) );
		$this->assertSame( '', Currencies::name( $code ) );

		foreach ( array( Money::format( 100, array( 'currency' => $code ) ), Money::format( 100, array( 'currency' => $code, 'symbol' => false, 'code' => true ) ) ) as $rendered ) {
			$this->assertStringNotContainsString( '<', $rendered );
			$this->assertStringNotContainsString( '"', $rendered );
			$this->assertSame( '1.00', trim( $rendered ) );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_codes(): array {
		return array(
			'script tag'   => array( '<script>alert(1)</script>' ),
			'attribute'    => array( '" onload="alert(1)' ),
			'too short'    => array( 'US' ),
			'too long'     => array( 'USDD' ),
			'symbol'       => array( '€' ),
			'digits'       => array( '123' ),
			'null byte'    => array( "US\x00D" ),
			'entity'       => array( '&lt;b&gt;' ),
		);
	}

	/**
	 * An unknown but well-formed code is a data problem, not an attack,
	 * so it is shown rather than swallowed.
	 */
	public function test_a_plausible_unknown_code_is_kept(): void {
		$this->assertSame( 'XYZ', Currencies::sanitize_code( 'xyz' ) );
		$this->assertSame( 'XYZ', Currencies::symbol( 'XYZ' ) );
		$this->assertSame( '1.00 XYZ', Money::format( 100, array( 'currency' => 'xyz', 'symbol' => false, 'code' => true ) ) );
	}

	public function test_known_codes_are_unaffected(): void {
		$this->assertSame( '$1.00', Money::format( 100, array( 'currency' => 'usd' ) ) );
		$this->assertSame( '1.00 USD', Money::format( 100, array( 'currency' => 'USD', 'symbol' => false, 'code' => true ) ) );
		$this->assertSame( '¥1,000', Money::format( 1000, array( 'currency' => 'JPY' ) ) );
	}

	/* ─── Amounts ───────────────────────────────────────────────────── */

	/**
	 * Twenty digits in a price field overflow to a float, and PHP raises
	 * a `TypeError` at the return boundary — which reaches the user as a
	 * fatal rather than a validation message.
	 */
	#[DataProvider( 'oversized_amounts' )]
	public function test_an_unrepresentable_amount_is_refused( string $input ): void {
		$this->expectException( InvalidArgumentException::class );

		Money::parse( $input, 'USD' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function oversized_amounts(): array {
		return array(
			'21 digits'  => array( '999999999999999999999' ),
			'int max'    => array( '9223372036854775807' ),
			'negative'   => array( '-999999999999999999999' ),
			'separators' => array( '999,999,999,999,999,999,999.99' ),
		);
	}

	#[DataProvider( 'ordinary_amounts' )]
	public function test_ordinary_amounts_still_parse( string $input, int $expected ): void {
		$this->assertSame( $expected, Money::parse( $input, 'USD' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function ordinary_amounts(): array {
		return array(
			'plain'        => array( '49.99', 4999 ),
			'separators'   => array( '$1,234.56', 123456 ),
			'european'     => array( '€1.234,56', 123456 ),
			'negative'     => array( '-5.00', -500 ),
			'no fraction'  => array( '10', 1000 ),
			'nothing'      => array( 'abc', 0 ),
			'empty'        => array( '', 0 ),
			'large valid'  => array( '90000000000000', 9000000000000000 ),
		);
	}

	public function test_parsing_never_emits_a_type_error(): void {
		foreach ( array( '1e400', str_repeat( '9', 400 ), '.'. str_repeat( '9', 400 ) ) as $input ) {
			try {
				$this->assertIsInt( Money::parse( $input, 'USD' ) );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * An empty currency means the store's, not an unknown one.
	 *
	 * It used to mean neither -- the code was passed through empty and the
	 * amount rendered with no symbol at all. Now that `currency` is an option
	 * with a default, leaving it out is how a caller says "the usual one",
	 * which is the commonest call in the library.
	 */
	public function test_an_empty_currency_is_the_stores(): void {
		$this->assertSame( Money::format( 100 ), Money::format( 100, array( 'currency' => '' ) ) );
		$this->assertSame( '$1.00', Money::format( 100, array( 'currency' => '' ) ) );
	}

	/**
	 * A minus sign that is not the ASCII hyphen was stripped with the
	 * symbols, and the amount read as positive: a credit of −£5.00 became
	 * a £5.00 charge. ICU writes U+2212 for a negative in Swedish, Finnish
	 * and others, so the library's own `locale` output read back with its
	 * sign flipped.
	 *
	 * @param string $input A negative amount.
	 */
	#[DataProvider( 'unicode_minus_signs' )]
	public function test_a_unicode_minus_sign_keeps_the_amount_negative( string $input ): void {
		$this->assertSame( -500, Money::parse( $input, 'USD' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unicode_minus_signs(): array {
		return array(
			'minus sign'      => array( "\u{2212}5.00" ),
			'minus, symbol'   => array( "\u{2212}\$5.00" ),
			'en dash'         => array( "\u{2013}5.00" ),
			'figure dash'     => array( "\u{2012}5.00" ),
			'small hyphen'    => array( "\u{FE63}5.00" ),
			'fullwidth minus' => array( "\u{FF0D}5.00" ),
			'ascii'           => array( '-5.00' ),
		);
	}

	#[RequiresPhpExtension( 'intl' )]
	public function test_a_localised_negative_reads_back_negative(): void {
		$written = Money::format( -1250, array( 'currency' => 'SEK', 'locale' => 'sv' ) );

		$this->assertSame( -1250, Money::parse( $written, 'SEK' ) );
	}

	/**
	 * Digits from another script were kept by the first pass and dropped
	 * by the second, so `١٠٠` -- a hundred, typed on an Arabic keyboard --
	 * read as nought, and `1٠0` as ten. A price nobody typed, and nought
	 * reads as free. They are read as the digits they are.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_digits_from_other_scripts_read_as_digits(): void {
		$this->assertSame( 10000, Money::parse( '١٠٠', 'AED' ) );
		$this->assertSame( 10000, Money::parse( '1٠0', 'USD' ) );
		$this->assertSame( 123456, Money::parse( '१,२३४.५६', 'INR' ) );
		$this->assertSame( 1250, Money::parse( '１２．５０', 'USD' ), 'Full-width, as an IME types it.' );
	}

	/**
	 * Text that is not UTF-8 failed the pattern and read as nought -- a
	 * free price -- rather than as something that could not be read.
	 */
	public function test_text_that_is_not_utf8_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Money::parse( "\xA35.00", 'GBP' );
	}

	/* ─── Billing periods ───────────────────────────────────────────── */

	/**
	 * An interval this library has not heard of is written out as given,
	 * and it used to be written into the sprintf() format: a stored
	 * interval of `%s` threw ArgumentCountError -- a fatal error on every
	 * page that listed the subscription -- and `%1$s` printed the count.
	 */
	public function test_an_unknown_interval_is_never_read_as_a_format(): void {
		$this->assertSame( 'Every 2 %ss', Recurring::label( '%s', 2 ) );
		$this->assertSame( '2 x%1$ss', Recurring::period( 'x%1$s', 2 ) );
		$this->assertSame( '$9.99 / 3 %d%ss', Recurring::with_amount( '$9.99', '%d%s', 3, Recurring::LONG ) );
		$this->assertSame( '3 terms', Recurring::period( 'term', 3 ), 'An ordinary unknown interval is unchanged.' );
	}
}
