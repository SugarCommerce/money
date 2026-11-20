<?php
/**
 * Amounts in a declared unit.
 *
 * @package SugarCommerce\Money
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Money\Models\Unit;
use SugarCommerce\Money\Money;

/**
 * A caller says which unit its amounts are in; nothing is guessed from the
 * value, and each currency's own exponent is used.
 */
final class UnitTest extends TestCase {

	/**
	 * Minor units are taken as they are, rounded to a whole number.
	 */
	public function test_minor_amounts_are_taken_as_they_are(): void {
		$this->assertSame( 14800, Money::minor( 14800, 'USD', Unit::Minor ) );
		$this->assertSame( 14800, Money::minor( '14800', 'USD', Unit::Minor ) );
		$this->assertSame( 5000, Money::minor( 5000, 'JPY', Unit::Minor ) );
		$this->assertSame( 1500, Money::minor( 1500, 'KWD', Unit::Minor ) );
		$this->assertSame( 14800, Money::minor( 14799.6, 'USD', Unit::Minor ) );
		$this->assertSame( -1480, Money::minor( -1480, 'USD', Unit::Minor ) );
		$this->assertSame( 0, Money::minor( 'free', 'USD', Unit::Minor ) );
	}

	/**
	 * Major units, the default, by the currency's own exponent -- a round
	 * amount is not taken for minor units.
	 */
	public function test_major_amounts_use_the_currencys_exponent(): void {
		$this->assertSame( 14800, Money::minor( 148, 'USD' ) );
		$this->assertSame( 5000, Money::minor( 50, 'USD' ) );
		$this->assertSame( 1480, Money::minor( '14.80', 'USD' ) );
		$this->assertSame( 5000, Money::minor( 5000, 'JPY' ) );
		$this->assertSame( 1500, Money::minor( 1.5, 'KWD' ) );
		$this->assertSame( 1235, Money::minor( 12.345, 'USD' ) );
		$this->assertSame( -1480, Money::minor( -14.8, 'USD' ) );
		$this->assertSame( 0, Money::minor( null, 'USD' ) );
	}

	/**
	 * Minor only when a setting says exactly that.
	 */
	public function test_the_unit_is_minor_only_when_it_says_so(): void {
		$this->assertSame( Unit::Minor, Unit::of( 'minor' ) );
		$this->assertSame( Unit::Minor, Unit::of( Unit::Minor ) );
		$this->assertSame( Unit::Major, Unit::of( 'major' ) );
		$this->assertSame( Unit::Major, Unit::of( 'MINOR' ) );
		$this->assertSame( Unit::Major, Unit::of( null ) );
		$this->assertSame( Unit::Major, Unit::of( '' ) );
	}

	/**
	 * Minor units written back as a decimal for a form, exactly.
	 */
	public function test_a_minor_amount_as_a_decimal(): void {
		$this->assertSame( '148.00', Money::decimal( 14800, 'USD', false ) );
		$this->assertSame( '5000', Money::decimal( 5000, 'JPY', false ) );
		$this->assertSame( '1.500', Money::decimal( 1500, 'KWD', false ) );
	}
}
