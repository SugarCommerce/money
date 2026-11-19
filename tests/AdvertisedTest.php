<?php
/**
 * Prices being advertised, and prices in a reader's own language.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Money;

/**
 * The `compact` and `locale` options, and the currency lookups they lean on.
 */
final class AdvertisedTest extends TestCase {

	/**
	 * Format a price for a listing.
	 *
	 * @param int    $amount Minor units.
	 * @param string $code   Currency.
	 *
	 * @return string
	 */
	private static function compact( int $amount, string $code ): string {
		return Money::format(
			$amount,
			array(
				'currency' => $code,
				'compact'  => true,
			)
		);
	}

	/* ── advertising a price ─────────────────────────────────────────── */

	/**
	 * A shop listing reads better without two zeros nobody is going to pay.
	 */
	public function test_whole_amounts_lose_their_minor_units(): void {
		$this->assertSame( '£29', self::compact( 2900, 'GBP' ) );
		$this->assertSame( '$49', self::compact( 4900, 'USD' ) );
	}

	/**
	 * Pennies are not zeros, and stay.
	 */
	public function test_an_amount_with_pennies_keeps_them(): void {
		$this->assertSame( '$49.99', self::compact( 4999, 'USD' ) );
		$this->assertSame( '£29.50', self::compact( 2950, 'GBP' ) );
		$this->assertSame( '$0.05', self::compact( 5, 'USD' ) );
	}

	/**
	 * There is nothing to drop from a yen.
	 */
	public function test_a_zero_decimal_currency_is_untouched(): void {
		$this->assertSame( Money::format( 100_000, 'JPY' ), self::compact( 100_000, 'JPY' ) );
	}

	/**
	 * All three of a dinar's places are counted.
	 */
	public function test_a_three_decimal_currency_counts_all_three(): void {
		$this->assertSame( 'BD 2', self::compact( 2000, 'BHD' ) );
		$this->assertSame( 'BD 1.500', self::compact( 1500, 'BHD' ) );
	}

	/**
	 * Compact is about the fraction, nothing else.
	 */
	public function test_the_rest_of_the_format_survives(): void {
		$this->assertSame( '$1,234', self::compact( 123_400, 'USD' ) );
		$this->assertSame( '-£29', self::compact( -2900, 'GBP' ) );
		$this->assertSame( '$0', self::compact( 0, 'USD' ) );
		$this->assertSame(
			'$29/mo',
			Money::format(
				2900,
				array(
					'compact'  => true,
					'interval' => 'month',
				)
			),
			'The interval is not mistaken for the fraction.'
		);
		$this->assertSame(
			'29 USD',
			Money::format(
				2900,
				array(
					'compact' => true,
					'symbol'  => false,
					'code'    => true,
				)
			)
		);
	}

	/**
	 * A string in place of options is the currency.
	 */
	public function test_a_string_is_the_currency(): void {
		$this->assertSame( Money::format( 1500, array( 'currency' => 'BHD' ) ), Money::format( 1500, 'BHD' ) );
	}

	/* ── in a reader's language ──────────────────────────────────────── */

	/**
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
	 */
	public static function localised_compact(): array {
		return array(
			'a whole euro price in German'     => array( 2900, 'EUR', 'de', "29\u{a0}€" ),
			'euro cents in German'             => array( 2950, 'EUR', 'de', "29,50\u{a0}€" ),
			'a hyphenated tag'                 => array( 2900, 'BRL', 'pt-BR', "R$\u{a0}29" ),
			'an ICU tag'                       => array( 2900, 'BRL', 'pt_BR', "R$\u{a0}29" ),
			'a lower-case code'                => array( 2900, 'eur', 'de', "29\u{a0}€" ),
			'a negative amount'                => array( -2900, 'EUR', 'de', "-29\u{a0}€" ),
			'a three-decimal currency in full' => array( 1500, 'BHD', 'en', "BHD\u{a0}1.500" ),
		);
	}

	/**
	 * An advertised price reads the way its locale writes one.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $code     Currency.
	 * @param string $locale   Locale.
	 * @param string $expected What it reads as.
	 */
	#[DataProvider( 'localised_compact' )]
	#[RequiresPhpExtension( 'intl' )]
	public function test_an_advertised_price_reads_the_way_its_locale_writes_one( int $amount, string $code, string $locale, string $expected ): void {
		$this->assertSame(
			$expected,
			Money::format(
				$amount,
				array(
					'currency' => $code,
					'locale'   => $locale,
					'compact'  => true,
				)
			)
		);
	}

	/**
	 * Trimming the localised receipt format cannot produce this: in German
	 * the fraction is in the middle.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_only_whole_amounts_lose_their_fraction(): void {
		$receipt = Money::format(
			2900,
			array(
				'currency' => 'EUR',
				'locale'   => 'de',
			)
		);

		$this->assertSame( "29,00\u{a0}€", $receipt );
		$this->assertStringNotContainsString( ',00', Money::format( 2900, array( 'currency' => 'EUR', 'locale' => 'de', 'compact' => true ) ) );
	}

	/**
	 * The locale decides where everything goes.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_localized_formatting_follows_the_locale(): void {
		$english = Money::format( 123456, array( 'currency' => 'EUR', 'locale' => 'en_US' ) );
		$french  = Money::format( 123456, array( 'currency' => 'EUR', 'locale' => 'fr_FR' ) );

		$this->assertSame( '€1,234.56', $english );
		$this->assertNotSame( $english, $french );
		$this->assertStringContainsString( '234,56', $french );
	}

	/**
	 * The other options still apply in a locale.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_the_other_options_apply_in_a_locale(): void {
		$this->assertSame( '1.234,56', Money::format( 123456, array( 'currency' => 'EUR', 'locale' => 'de', 'symbol' => false ) ) );
		$this->assertSame( '1,500', Money::format( 1500, array( 'currency' => 'BHD', 'locale' => 'de', 'symbol' => false ) ), 'Every place the currency has, with a German decimal comma.' );
		$this->assertSame( '1234,56', Money::format( 123456, array( 'currency' => 'EUR', 'locale' => 'de', 'symbol' => false, 'separators' => false ) ) );
		$this->assertSame( "1.234,56\u{a0}€ EUR", Money::format( 123456, array( 'currency' => 'EUR', 'locale' => 'de', 'code' => true ) ) );
		$this->assertSame( "9,99\u{a0}€/mo", Money::format( 999, array( 'currency' => 'EUR', 'locale' => 'de', 'interval' => 'month' ) ) );
	}

	/**
	 * A zero-decimal currency keeps its digits.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_a_zero_decimal_currency_keeps_its_digits(): void {
		$this->assertStringContainsString( '100,000', Money::format( 100_000, array( 'currency' => 'JPY', 'locale' => 'ja', 'compact' => true ) ) );
	}

	/**
	 * No locale is this library's own format.
	 */
	public function test_no_locale_is_the_plain_format(): void {
		$this->assertSame( '€29', Money::format( 2900, array( 'currency' => 'EUR', 'locale' => '  ', 'compact' => true ) ) );
	}

	/* ── currency lookups ────────────────────────────────────────────── */

	/**
	 * Each currency has a locale to fall back on.
	 */
	public function test_each_currency_has_a_locale(): void {
		$this->assertSame( 'ja_JP', Currencies::locale( 'jpy' ) );
		$this->assertSame( 'de_DE', Currencies::locale( 'EUR' ) );
		$this->assertSame( 'en_US', Currencies::locale( 'ZZZ' ) );
	}

	/**
	 * A country gives the currency a shopper there pays in.
	 */
	public function test_currencies_resolve_by_country(): void {
		$this->assertSame( array( 'JPY' ), Currencies::for_country( 'JP' ) );
		$this->assertSame( array( 'GBP' ), Currencies::for_country( ' gb ' ) );
		$this->assertSame( array( 'EUR' ), Currencies::for_country( 'EU' ) );
		$this->assertSame( array(), Currencies::for_country( 'ZZ' ) );
		$this->assertSame( array(), Currencies::for_country( '' ) );
	}

	/**
	 * The euro area is found by member state, not only under `EU`.
	 *
	 * The issuer column files the euro under the union, so before the
	 * spending map Germany had no currency at all.
	 */
	public function test_a_euro_area_country_pays_in_euro(): void {
		$this->assertSame( array( 'EUR' ), Currencies::for_country( 'DE' ) );
		$this->assertSame( 'EUR', Currencies::paid_in( 'ie' ) );
		$this->assertSame( 'EUR', Currencies::paid_in( 'HR' ) );
		$this->assertSame( 'EUR', Currencies::paid_in( 'ME' ) );
	}

	/**
	 * Bulgaria has paid in euro since 1 January 2026; the lev stays in the
	 * dataset so an old order still formats.
	 */
	public function test_bulgaria_pays_in_euro_and_the_lev_is_kept(): void {
		$this->assertSame( array( 'EUR' ), Currencies::for_country( 'BG' ) );
		$this->assertTrue( Currencies::supports( 'BGN' ) );
		$this->assertSame( 'BG', Currencies::country( 'BGN' ) );
	}

	/**
	 * EU members outside the euro keep their own money.
	 */
	public function test_eu_members_outside_the_euro_keep_their_currency(): void {
		$this->assertSame( 'SEK', Currencies::paid_in( 'SE' ) );
		$this->assertSame( 'PLN', Currencies::paid_in( 'PL' ) );
		$this->assertSame( 'CZK', Currencies::paid_in( 'cz' ) );
		$this->assertSame( 'DKK', Currencies::paid_in( 'DK' ) );
		$this->assertSame( 'HUF', Currencies::paid_in( 'HU' ) );
		$this->assertSame( 'RON', Currencies::paid_in( 'RO' ) );
	}

	/**
	 * A shared currency is found from every country that spends it.
	 */
	public function test_shared_currencies_resolve_from_each_country(): void {
		$this->assertSame( 'XOF', Currencies::paid_in( 'CI' ) );
		$this->assertSame( 'XAF', Currencies::paid_in( 'GA' ) );
		$this->assertSame( 'XCD', Currencies::paid_in( 'LC' ) );
		$this->assertSame( 'USD', Currencies::paid_in( 'EC' ) );
		$this->assertSame( 'AUD', Currencies::paid_in( 'KI' ) );
		$this->assertSame( 'CHF', Currencies::paid_in( 'LI' ) );
	}

	/**
	 * Null means "use your default": the country's money is not one the
	 * dataset carries, or it moved off a retired one.
	 */
	public function test_a_country_without_a_listed_currency_is_null(): void {
		$this->assertNull( Currencies::paid_in( 'AQ' ) );
		$this->assertNull( Currencies::paid_in( 'CW' ) );
		$this->assertNull( Currencies::paid_in( 'SL' ) );
		$this->assertNull( Currencies::paid_in( 'nonsense' ) );
	}

	/**
	 * The two sets that are not two-decimal can be asked about directly.
	 */
	public function test_the_zero_and_three_decimal_sets(): void {
		$zero  = array_filter( Currencies::codes(), static fn( string $c ): bool => Currencies::is_zero_decimal( $c ) );
		$three = array_filter( Currencies::codes(), static fn( string $c ): bool => Currencies::is_three_decimal( $c ) );

		$this->assertCount( 15, $zero );
		$this->assertCount( 5, $three );
		$this->assertTrue( Currencies::is_zero_decimal( 'krw' ) );
		$this->assertFalse( Currencies::is_zero_decimal( 'USD' ) );
		$this->assertTrue( Currencies::is_three_decimal( 'KWD' ) );
		$this->assertFalse( Currencies::is_three_decimal( 'ZZZ' ), 'An unknown currency is two-decimal.' );
	}
}
