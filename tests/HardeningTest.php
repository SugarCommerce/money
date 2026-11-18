<?php
/**
 * Hostile codes and locales, and amounts at the edge of the integer.
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
use SugarCommerce\Money\Rate;

/**
 * What the money library hardened itself against before it moved here.
 *
 * Each case is a bug that shipped once: a code echoed into a page, an
 * exception from ICU, a price parsed through a float, a tie rounded the
 * wrong way.
 */
final class HardeningTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_codes(): array {
		return array(
			'script tag' => array( '<script>alert(1)</script>' ),
			'attribute'  => array( '" onload="alert(1)' ),
			'too short'  => array( 'US' ),
			'symbol'     => array( '€' ),
			'null byte'  => array( "US\x00D" ),
			'newline'    => array( "USD\n" ),

			// Three characters is the shape ICU takes for a code, and it
			// prints one it does not know, upper-cased, as the symbol.
			'three, tag'   => array( '<b>' ),
			'three, quote' => array( '"x"' ),
		);
	}

	/**
	 * ICU prints an unknown currency code as the symbol, so a hostile code
	 * would reach the page through the localised format.
	 *
	 * @param string $code The code.
	 */
	#[RequiresPhpExtension( 'intl' )]
	#[DataProvider( 'hostile_codes' )]
	public function test_a_localised_format_never_echoes_a_hostile_code( string $code ): void {
		foreach ( array( false, true ) as $compact ) {
			$rendered = Money::format(
				100,
				array(
					'currency' => $code,
					'locale'   => 'en_US',
					'compact'  => $compact,
				)
			);

			$this->assertStringNotContainsString( '<', $rendered );
			$this->assertStringNotContainsString( '"', $rendered );
			$this->assertStringNotContainsString( "\n", $rendered );
		}
	}

	/**
	 * A newline never survives into a code.
	 *
	 * A code goes into headers and CSV cells as well as pages. Around it, it
	 * is whitespace and trimmed; inside it, the code is refused.
	 */
	public function test_a_newline_never_survives_into_a_code(): void {
		$this->assertSame( 'USD', Currencies::sanitize_code( " usd\n" ) );
		$this->assertSame( '', Currencies::sanitize_code( "US\nD" ) );
		$this->assertSame( '', Currencies::sanitize_code( "USD\nX" ) );
	}

	/**
	 * The reason is written to be shown to the shopper, and the code is
	 * usually theirs.
	 */
	public function test_the_unsupported_currency_message_never_echoes_markup(): void {
		$reason = (string) Money::why_not_chargeable( 100, '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<', $reason );
		$this->assertSame( 'That is not a supported currency.', $reason );
		$this->assertSame( 'XYZ is not a supported currency.', Money::why_not_chargeable( 100, 'xyz' ) );
	}

	/**
	 * A locale from an Accept-Language header that ICU refuses fell
	 * through as an uncaught IntlException.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_an_unusable_locale_falls_back_rather_than_throwing(): void {
		$this->assertSame( '$1.00', Money::format( 100, array( 'currency' => 'USD', 'locale' => str_repeat( 'x', 200 ) ) ) );
	}

	/**
	 * Above 2^53 the major part was read through a float and came back as
	 * a different, plausible amount instead of failing.
	 */
	public function test_large_amounts_parse_exactly(): void {
		$this->assertSame( 9007199254740993123, Money::parse( '90071992547409931.23', 'USD' ) );
		$this->assertSame( PHP_INT_MAX - 1, Money::parse( (string) ( PHP_INT_MAX - 1 ), 'JPY' ) );
		$this->assertSame( 1234, Money::parse( '1,234.00', 'JPY' ) );
	}

	/**
	 * PHP_INT_MAX itself is refused: it is where an overflow lands, so it
	 * cannot be told apart from one.
	 */
	public function test_the_largest_integer_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Money::parse( (string) PHP_INT_MAX, 'JPY' );
	}

	/**
	 * A dinar has three places, so `1,234` typed for one is a decimal comma,
	 * not a thousands separator -- for a dollar it is the other way round.
	 */
	public function test_a_comma_before_three_digits_depends_on_the_currency(): void {
		$this->assertSame( 1234, Money::parse( '1,234', 'BHD' ) );
		$this->assertSame( 123400, Money::parse( '1,234', 'USD' ) );
		$this->assertSame( 1234, Money::parse( '1,234', 'JPY' ) );
	}

	/**
	 * And they format exactly: number_format() takes a float, which had
	 * already rounded the last digits away.
	 */
	public function test_large_amounts_format_exactly(): void {
		$this->assertSame( '¥9,223,372,036,854,775,807', Money::format( PHP_INT_MAX, 'JPY' ) );
		$this->assertSame( '92,233,720,368,547,758.07', Money::decimal( PHP_INT_MAX, 'USD' ) );
		$this->assertSame( '9007199254740993', Money::decimal( 9007199254740993, 'JPY', false ) );
		$this->assertSame( 9007199254740993, Money::parse( Money::input_value( 9007199254740993, 'JPY' ), 'JPY' ) );
	}

	/**
	 * A fraction finer than the currency's minor unit was truncated —
	 * `1.234` USD became 123 — and a zero-decimal currency rounded half
	 * up. Either way the stored price was not the one typed.
	 *
	 * @param string $input What was typed.
	 * @param string $code  The currency.
	 */
	#[DataProvider( 'excess_decimals' )]
	public function test_an_amount_finer_than_the_minor_unit_is_refused( string $input, string $code ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'more decimal places than the currency allows' );

		Money::parse( $input, $code );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function excess_decimals(): array {
		return array(
			'two decimal, three given'   => array( '1.234', 'USD' ),
			'two decimal, rounds up'     => array( '49.999', 'USD' ),
			'two decimal, negative'      => array( '-1.234', 'USD' ),
			'two decimal, european'      => array( '1.234,567', 'EUR' ),
			'two decimal, nonzero after' => array( '1.2301', 'USD' ),
			'zero decimal, half'         => array( '100.5', 'JPY' ),
			'zero decimal, under half'   => array( '1234.49', 'JPY' ),
			'zero decimal, grouped'      => array( '1,234.5', 'JPY' ),
			'zero decimal, trailing'     => array( '100.01', 'JPY' ),
			'three decimal, four given'  => array( '1.5001', 'BHD' ),
		);
	}

	/**
	 * Zeros past the exponent do not change the amount, so they are not
	 * a reason to refuse it.
	 */
	public function test_trailing_zeros_past_the_minor_unit_are_accepted(): void {
		$this->assertSame( 123, Money::parse( '1.230', 'USD' ) );
		$this->assertSame( 123, Money::parse( '1.23000', 'USD' ) );
		$this->assertSame( 100, Money::parse( '100.00', 'JPY' ) );
		$this->assertSame( 100, Money::parse( '100.', 'JPY' ) );
		$this->assertSame( 1500, Money::parse( '1.5000', 'BHD' ) );
		$this->assertSame( 4990, Money::parse( '49.9', 'USD' ) );
	}

	/**
	 * abs( PHP_INT_MIN ) is a float, so the old integer division threw a
	 * TypeError formatting the smallest amount there is.
	 */
	public function test_the_smallest_integer_formats_rather_than_throwing(): void {
		$this->assertSame( '-$92,233,720,368,547,758.08', Money::format( PHP_INT_MIN, 'USD' ) );
		$this->assertSame( '-BD 0.005', Money::format( -5, 'BHD' ) );
	}

	/**
	 * 17.5% of 180 is exactly 31.5. Float arithmetic made it 31.499… and
	 * rounded it down, so "half up" was not.
	 */
	public function test_percentages_round_exact_ties_up(): void {
		$this->assertSame( 32, Money::percentage( 180, 17.5 ) );
		$this->assertSame( -32, Money::percentage( -180, 17.5 ) );
		$this->assertSame( 89, Money::percentage( 1000, 8.875 ) );
		$this->assertSame( 92233720368547758, Money::percentage( PHP_INT_MAX, 1.0 ) );
	}

	/**
	 * A discount rounds the way every other percentage does.
	 */
	public function test_a_percentage_discount_rounds_exact_ties_up(): void {
		$this->assertSame( 32, Rate::applied_to( 180, 17.5, 'percent' ) );
		$this->assertSame( 180, Rate::applied_to( 180, 250, 'percent' ), 'Never more than the amount.' );
		$this->assertSame( 0, Rate::applied_to( 180, -5, 'percent' ), 'Never negative.' );
		$this->assertSame( 0, Rate::applied_to( 180, NAN, 'percent' ), 'Not a number takes nothing off.' );
	}

	/**
	 * An overflowing or non-finite percentage used to warn and return 0 —
	 * a free order rather than an error.
	 *
	 * @param int   $amount  The amount.
	 * @param float $percent The percentage.
	 */
	#[DataProvider( 'unrepresentable_percentages' )]
	public function test_an_unrepresentable_percentage_is_refused( int $amount, float $percent ): void {
		$this->expectException( InvalidArgumentException::class );

		Money::percentage( $amount, $percent );
	}

	/**
	 * @return array<string, array{0: int, 1: float}>
	 */
	public static function unrepresentable_percentages(): array {
		return array(
			'overflow'     => array( PHP_INT_MAX, 200.0 ),
			'huge rate'    => array( PHP_INT_MAX, 1e9 ),
			'not a number' => array( 100, NAN ),
			'infinite'     => array( 100, INF ),
		);
	}

	/**
	 * Rounding to an increment stays in integers.
	 */
	public function test_rounding_to_a_valid_increment_is_exact_for_large_amounts(): void {
		$this->assertSame( 9007199254741000, Money::round_to_valid( 9007199254740993, 'ISK' ) );
		$this->assertSame( -600, Money::round_to_valid( -550, 'ISK' ) );
		$this->assertSame( -500, Money::round_to_valid( -549, 'ISK' ) );
		$this->assertSame( 1240, Money::round_to_valid( 1235, 'BHD' ), 'A tie goes away from zero.' );
	}
}
