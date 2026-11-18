<?php
/**
 * Sanitising tests.
 *
 * @package SugarCommerce\Money
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Tests;

use SugarCommerce\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An amount and a rate on their way into storage: good, messy and rubbish.
 * Rubbish is nought, never an error.
 */
final class SanitizeTest extends TestCase {

	/**
	 * @param mixed $value  The value.
	 * @param int   $expect Minor units.
	 */
	#[DataProvider( 'amounts' )]
	public function test_sanitize_amount( mixed $value, int $expect ): void {
		$this->assertSame( $expect, Money::sanitize_amount( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function amounts(): array {
		return [
			'an int'                => [ 4900, 4900 ],
			'digits'                => [ '4900', 4900 ],
			'nought'                => [ 0, 0 ],
			'a negative'            => [ -5, 0 ],
			'a negative string'     => [ '-5', 0 ],
			'a float short of whole' => [ 19.99 * 100, 1999 ],
			'a float'               => [ 4900.0, 4900 ],
			'a decimal string'      => [ '19.6', 20 ],
			'a long run of digits'  => [ '9007199254740993', 9007199254740993 ],
			'too big'               => [ '1e30', PHP_INT_MAX ],
			'a word'                => [ 'free', 0 ],
			'empty'                 => [ '', 0 ],
			'null'                  => [ null, 0 ],
			'an array'              => [ [ 4900 ], 0 ],
			'true'                  => [ true, 0 ],
		];
	}

	public function test_sanitize_exchange_rate(): void {
		$this->assertSame( 1.0, Money::sanitize_exchange_rate( 1 ) );
		$this->assertSame( 0.85123457, Money::sanitize_exchange_rate( '0.851234567891' ) );
		$this->assertSame( 0.85, Money::sanitize_exchange_rate( 0.851234, 2 ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( 0 ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( -1.2 ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( 'about one' ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( null ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( [ 1 ] ) );
		$this->assertSame( 0.0, Money::sanitize_exchange_rate( INF ) );
	}
}
