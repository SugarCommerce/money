<?php
/**
 * Decimal strings, option labels and recurring labels.
 *
 * @package SugarCommerce\Money
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Money;
use SugarCommerce\Money\Recurring;
use PHPUnit\Framework\TestCase;

/**
 * What a spreadsheet, a select and a billing column are given to read.
 */
final class LabelsTest extends TestCase {

	/**
	 * A dinar has three decimal places and keeps all three. Written to two
	 * it is a different amount.
	 */
	public function test_decimal_keeps_every_place_a_currency_has(): void {
		$this->assertSame( '1.234', Money::decimal( 1234, 'KWD', false ) );
		$this->assertSame( '1.234', Money::decimal( 1234, 'kwd' ) );
		$this->assertSame( '0.005', Money::decimal( 5, 'BHD', false ) );
		$this->assertSame( '49.00', Money::decimal( 4900, 'GBP', false ) );
		$this->assertSame( '4900', Money::decimal( 4900, 'JPY', false ) );
	}

	/**
	 * Separators are on unless turned off, and off means none anywhere.
	 */
	public function test_decimal_without_separators(): void {
		$this->assertSame( '1,234.05', Money::decimal( 123405, 'EUR' ) );
		$this->assertSame( '1234.05', Money::decimal( 123405, 'EUR', false ) );
		$this->assertSame( '1,000,000', Money::decimal( 1000000, 'JPY' ) );
		$this->assertSame( '1000000', Money::decimal( 1000000, 'JPY', false ) );
		$this->assertSame( '1234567.890', Money::decimal( 1234567890, 'KWD', false ) );
		$this->assertSame( '-1234.50', Money::decimal( -123450, 'USD', false ) );
		$this->assertSame( '0.00', Money::decimal( 0, 'USD', false ) );
	}

	/**
	 * format() without separators is decimal() without them.
	 */
	public function test_format_agrees_with_decimal(): void {
		$options = [
			'currency'   => 'KWD',
			'symbol'     => false,
			'separators' => false,
		];

		$this->assertSame( '1234.567', Money::format( 1234567, $options ) );
		$this->assertSame( Money::decimal( 1234567, 'KWD', false ), Money::format( 1234567, $options ) );
	}

	/**
	 * What sits in an input, and reads back as the same amount.
	 */
	public function test_input_value(): void {
		$this->assertSame( '1234.50', Money::input_value( 123450, 'USD' ) );
		$this->assertSame( '1000', Money::input_value( 1000, 'JPY' ) );
		$this->assertSame( '1.500', Money::input_value( 1500, 'BHD' ) );
		$this->assertSame( '1234.50', Money::input_value( 123450 ), 'No code is the store\'s currency.' );
		$this->assertSame( 123450, Money::parse( Money::input_value( 123450, 'USD' ), 'USD' ) );
	}

	/**
	 * The default label is what it always was.
	 */
	public function test_options_default_label_is_unchanged(): void {
		$options = Currencies::options();

		$this->assertSame( 'USD - US Dollar ($)', $options['USD'] );
		$this->assertSame( $options, Currencies::options( '' ) );
		$this->assertSame( $options, Currencies::options( Currencies::LABEL ) );
	}

	/**
	 * A caller's template is filled in, and the list is in the order of the
	 * labels it made.
	 */
	public function test_options_take_a_label_template(): void {
		$options = Currencies::options( '{name} ({code})' );

		$this->assertSame( 'US Dollar (USD)', $options['USD'] );
		$this->assertSame( 'Japanese Yen (JPY)', $options['JPY'] );
		$this->assertCount( count( Currencies::all() ), $options );

		$sorted = $options;
		asort( $sorted );

		$this->assertSame( array_keys( $sorted ), array_keys( $options ) );
		$this->assertNotSame( array_keys( Currencies::options() ), array_keys( $options ) );

		$this->assertSame( '$', Currencies::options( '{symbol}' )['USD'] );
	}

	/**
	 * How a price bills, as a label.
	 */
	public function test_recurring_label(): void {
		$this->assertSame( 'Every month', Recurring::label( 'month' ) );
		$this->assertSame( 'Every month', Recurring::label( ' Month ', 1 ) );
		$this->assertSame( 'Every 3 months', Recurring::label( 'month', 3 ) );
		$this->assertSame( 'Every year', Recurring::label( 'year' ) );
		$this->assertSame( 'Every 2 weeks', Recurring::label( 'week', 2 ) );
		$this->assertSame( 'Every day', Recurring::label( 'day', 0 ) );
		$this->assertSame( 'Every 90 days', Recurring::label( 'day', 90 ) );
		$this->assertSame( '', Recurring::label( '' ) );

		// One the library has not heard of is passed through.
		$this->assertSame( 'Every fortnight', Recurring::label( 'fortnight' ) );
		$this->assertSame( 'Every 2 fortnights', Recurring::label( 'fortnight', 2 ) );
	}

	/**
	 * The period alone.
	 */
	public function test_recurring_period(): void {
		$this->assertSame( 'month', Recurring::period( 'month' ) );
		$this->assertSame( '3 months', Recurring::period( 'month', 3 ) );
		$this->assertSame( 'year', Recurring::period( 'year', -4 ) );
		$this->assertSame( '', Recurring::period( '' ) );
		$this->assertSame( '2 fortnights', Recurring::period( 'fortnight', 2 ) );
	}

	/**
	 * A stored period comes apart into what period() was given.
	 */
	public function test_parse_period(): void {
		$this->assertSame( [ 'month', 1 ], Recurring::parse_period( 'month' ) );
		$this->assertSame( [ 'month', 3 ], Recurring::parse_period( '3 months' ) );
		$this->assertSame( [ 'week', 2 ], Recurring::parse_period( ' 2  Weeks ' ) );
		$this->assertSame( [ 'year', 1 ], Recurring::parse_period( '1 year' ) );
		$this->assertSame( [ 'month', 1 ], Recurring::parse_period( 'months' ) );
		$this->assertSame( [ 'day', 1 ], Recurring::parse_period( '0 days' ) );
		$this->assertSame( [ '', 1 ], Recurring::parse_period( '' ) );
		$this->assertSame( [ '', 1 ], Recurring::parse_period( '  ' ) );

		// One the library has not heard of is kept as given.
		$this->assertSame( [ 'term', 1 ], Recurring::parse_period( 'term' ) );
		$this->assertSame( [ 'term', 2 ], Recurring::parse_period( '2 terms' ) );
		$this->assertSame( [ 'class', 1 ], Recurring::parse_period( 'class' ) );

		// And round it goes.
		foreach ( [ 'day', '2 days', 'week', 'month', '3 months', 'year', 'term', '2 terms' ] as $period ) {
			$this->assertSame( $period, Recurring::period( ...Recurring::parse_period( $period ) ) );
		}

		$this->assertSame( '£35.99 / 3 months', Recurring::with_amount( '£35.99', ...[ ...Recurring::parse_period( '3 months' ), Recurring::LONG ] ) );
	}

	/**
	 * An amount with how often, both ways of writing it.
	 */
	public function test_recurring_with_amount(): void {
		$this->assertSame( '£35.99 / month', Recurring::with_amount( '£35.99', 'month', 1, Recurring::LONG ) );
		$this->assertSame( '$120.00 / 3 months', Recurring::with_amount( '$120.00', 'month', 3, Recurring::LONG ) );

		$this->assertSame( '$9.99/mo', Recurring::with_amount( '$9.99', 'month' ) );
		$this->assertSame( '$30.00 every 3 months', Recurring::with_amount( '$30.00', 'month', 3 ) );

		// The short one is what format() writes.
		$this->assertSame(
			Money::format(
				999,
				[
					'currency' => 'USD',
					'interval' => 'year',
				]
			),
			Recurring::with_amount( Money::format( 999, [ 'currency' => 'USD' ] ), 'year' )
		);

		// No interval, no period.
		$this->assertSame( '£35.99', Recurring::with_amount( '£35.99', '', 1, Recurring::LONG ) );
		$this->assertSame( '£35.99', Recurring::with_amount( '£35.99', ' ' ) );
	}
}
