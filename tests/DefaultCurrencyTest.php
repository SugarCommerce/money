<?php
/**
 * The default currency, and the calls that lean on it.
 *
 * @package SugarCommerce\Money
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Money;
use SugarCommerce\Money\Rate;
use SugarCommerce\Money\Recurring;
use SugarCommerce\Money\Render;

/**
 * The surface a plugin actually calls.
 *
 * The old library answered "which currency?" with a global function and a
 * global filter, which two plugins bundling it would both have declared. A
 * default set on the class belongs to the copy that set it, so each plugin's
 * prefixed copy keeps its own.
 */
final class DefaultCurrencyTest extends TestCase {

	/**
	 * Start from no default.
	 */
	protected function setUp(): void {
		Money::set_default_currency( null );
	}

	/**
	 * And leave none behind for the next suite.
	 */
	protected function tearDown(): void {
		Money::set_default_currency( null );
	}

	/**
	 * With no currency given, the default is used.
	 */
	public function test_the_default_currency_is_the_fallback(): void {
		$this->assertSame( 'USD', Money::currency() );
		$this->assertSame( '$49.99', Money::format( 4999 ) );

		Money::set_default_currency( 'gbp' );

		$this->assertSame( 'GBP', Money::currency() );
		$this->assertSame( '£49.99', Money::format( 4999 ) );
		$this->assertSame( '£49.99', Money::value( 4999, '', true ) );
	}

	/**
	 * A closure is asked every time, so it can answer for the request.
	 */
	public function test_a_closure_is_asked_each_time(): void {
		$asked = 0;
		$codes = array( 'EUR', 'JPY' );

		Money::set_default_currency(
			static function () use ( &$asked, $codes ): string {
				return $codes[ $asked++ ];
			}
		);

		$this->assertSame( '€10.00', Money::format( 1000 ) );
		$this->assertSame( '¥1,000', Money::format( 1000 ) );
		$this->assertSame( 2, $asked );
	}

	/**
	 * A currency given explicitly beats the default.
	 */
	public function test_an_explicit_currency_wins(): void {
		Money::set_default_currency( 'GBP' );

		$this->assertSame( '¥1,000', Money::format( 1000, array( 'currency' => 'JPY' ) ) );
		$this->assertSame( '¥1,000', Money::format( 1000, 'JPY' ) );
	}

	/**
	 * A default that is nonsense does not break every price on the site.
	 *
	 * The failure it guards is silent: an unsupported code formats with no
	 * symbol and two decimals, so a yen price would read "1,000.00" and
	 * nothing would report it.
	 *
	 * @param mixed $default What the closure answered.
	 */
	#[DataProvider( 'nonsense_defaults' )]
	public function test_an_unsupported_default_falls_back( mixed $default ): void {
		Money::set_default_currency( static fn() => $default );

		$this->assertSame( 'USD', Money::currency() );
		$this->assertSame( '$49.99', Money::format( 4999 ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonsense_defaults(): array {
		return array(
			'unknown code' => array( 'ZZZ' ),
			'markup'       => array( '<b>' ),
			'empty'        => array( '' ),
			'not a string' => array( 42 ),
			'null'         => array( null ),
		);
	}

	/**
	 * Each call that takes an optional currency uses the default.
	 */
	public function test_each_call_uses_the_default(): void {
		Money::set_default_currency( 'JPY' );

		$this->assertSame( '1000', Money::input_value( 1000 ) );
		$this->assertSame( 1000, Money::sanitize_input( '1,000' ) );
		$this->assertSame( '¥1,000', Rate::format( 1000, 'flat' ) );
		$this->assertStringContainsString( '¥1,000', Render::price( 1000 ) );
		$this->assertSame( '¥', Currencies::symbol( Money::currency() ) );
	}

	/**
	 * Each helper the old global functions were is still here.
	 */
	public function test_the_old_helpers_are_methods(): void {
		// The code replaces the symbol rather than joining it: "49.99 USD" is
		// how an amount is written when the currency has to be stated, and
		// "$49.99 USD" says it twice.
		$this->assertSame( '49.99 USD', Money::format( 4999, array( 'symbol' => false, 'code' => true ) ) );
		$this->assertTrue( Currencies::supports( 'gbp' ) );
		$this->assertFalse( Currencies::supports( 'ZZZ' ) );

		// Keyed by upper-case code, which is what a select control stores.
		$this->assertArrayHasKey( 'USD', Currencies::options() );
		$this->assertSame( 49.99, Money::to_float( 4999, 'USD' ) );
		$this->assertStringContainsString( '49.99', Render::price( 4999 ) );
	}

	/**
	 * What a person types comes back as minor units.
	 *
	 * @param mixed $typed  What arrived.
	 * @param int   $expect The amount it means.
	 */
	#[DataProvider( 'typedProvider' )]
	public function test_what_a_person_types_is_read( mixed $typed, int $expect ): void {
		$this->assertSame( $expect, Money::sanitize_input( $typed ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function typedProvider(): array {
		return array(
			'a plain amount'  => array( '19.99', 1999 ),
			'with a symbol'   => array( '$19.99', 1999 ),
			'with separators' => array( '1,999.00', 199900 ),
			'already an int'  => array( 1999, 1999 ),
			'a float'         => array( 19.99, 1999 ),
			'nothing'         => array( '', 0 ),
			'nonsense'        => array( 'abc', 0 ),
			'an array'        => array( array( 1 ), 0 ),
			'null'            => array( null, 0 ),
		);
	}

	/**
	 * A price nobody can be charged is refused, not rounded.
	 */
	public function test_a_typed_price_finer_than_a_penny_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::sanitize_input( '19.999', 'USD' );
	}

	/**
	 * An amount round-trips through the input value and back.
	 *
	 * The pair that has to agree: what goes into a text field must come back
	 * out as the same amount, or editing a price without touching it changes
	 * it.
	 */
	public function test_an_amount_round_trips_through_an_input(): void {
		foreach ( array( 'USD', 'JPY', 'BHD', 'KWD' ) as $code ) {
			foreach ( array( 0, 1, 1999, 100000, -4999 ) as $amount ) {
				$this->assertSame(
					$amount,
					Money::sanitize_input( Money::input_value( $amount, $code ), $code ),
					sprintf( '%d %s did not survive the round trip.', $amount, $code )
				);
			}
		}
	}

	/**
	 * A subscription price says how often it is charged.
	 *
	 * @param int    $count    How many intervals.
	 * @param string $interval The interval.
	 * @param string $expect   What it should read.
	 */
	#[DataProvider( 'recurringProvider' )]
	public function test_a_subscription_price_names_its_period( int $count, string $interval, string $expect ): void {
		$this->assertSame( $expect, Money::format( 999, array( 'interval' => $interval, 'interval_count' => $count ) ) );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function recurringProvider(): array {
		return array(
			'monthly'        => array( 1, 'month', '$9.99/mo' ),
			'yearly'         => array( 1, 'year', '$9.99/yr' ),
			'weekly'         => array( 1, 'week', '$9.99/wk' ),
			'daily'          => array( 1, 'day', '$9.99/day' ),

			// Past one, the short form stops reading correctly: "/3mo" is not
			// something anybody parses on the first attempt.
			'every 3 months' => array( 3, 'month', '$9.99 every 3 months' ),
			'every 2 years'  => array( 2, 'year', '$9.99 every 2 years' ),
			'every 6 weeks'  => array( 6, 'week', '$9.99 every 6 weeks' ),
		);
	}

	/**
	 * No interval is a plain price, not a broken one.
	 */
	public function test_no_interval_is_a_plain_price(): void {
		$this->assertSame( '$9.99', Money::format( 999, array( 'interval' => '' ) ) );
		$this->assertSame( '$9.99', Money::format( 999, array( 'currency' => 'USD', 'interval' => '   ' ) ) );
	}

	/**
	 * A count of nought is not a billing period.
	 *
	 * "every 0 months" is worse than a plain price, and a nought reaches here
	 * from a gateway that left the field unset.
	 */
	public function test_a_zero_count_reads_as_one(): void {
		$this->assertSame( '$9.99/mo', Money::format( 999, array( 'interval' => 'month', 'interval_count' => 0 ) ) );
		$this->assertSame( '$9.99/mo', Money::format( 999, array( 'interval' => 'month', 'interval_count' => -3 ) ) );
	}

	/**
	 * An interval this library has not heard of is passed through.
	 *
	 * A gateway may add one before this does. Showing "/quarter" is better
	 * than refusing to render the price.
	 */
	public function test_an_unknown_interval_is_passed_through(): void {
		$this->assertSame( '$9.99/quarter', Money::format( 999, array( 'interval' => 'quarter' ) ) );
		$this->assertSame( '$9.99 every 2 quarters', Money::format( 999, array( 'interval' => 'quarter', 'interval_count' => 2 ) ) );
	}

	/**
	 * A recurring price obeys the currency's decimals like any other.
	 */
	public function test_a_recurring_price_obeys_the_currency(): void {
		$this->assertSame( '¥1,000/mo', Money::format( 1000, array( 'currency' => 'JPY', 'interval' => 'month', 'interval_count' => 1 ) ) );
	}

	/**
	 * The intervals come back for a dropdown.
	 */
	public function test_the_intervals_are_offered(): void {
		$this->assertSame( array( 'day', 'week', 'month', 'year' ), array_keys( Recurring::options() ) );
		$this->assertSame( Recurring::INTERVALS, array_keys( Recurring::options() ) );
	}

	/**
	 * A rate formats either way.
	 */
	public function test_a_rate_formats_either_way(): void {
		$this->assertSame( '20%', Rate::format( 20, 'percent' ) );
		$this->assertSame( '$0.20', Rate::format( 20, 'flat' ) );
	}

	/**
	 * A sale price renders with both amounts.
	 */
	public function test_a_sale_price_renders_both_amounts(): void {
		$html = Render::price( 1999, array( 'compare_at' => 2999 ) );

		$this->assertStringContainsString( '$29.99', $html );
		$this->assertStringContainsString( '$19.99', $html );
		$this->assertSame( 33, Money::saving_percentage( 1999, 2999 ) );
	}
}
