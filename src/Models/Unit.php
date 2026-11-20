<?php
/**
 * The unit an amount is given in.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money\Models;

/**
 * Major units (148.00 is a hundred and forty-eight dollars) or minor units
 * (14800 is the same, as a store keeps it and as this package works in).
 *
 * Declared, never inferred: nothing tells 148 meaning $148.00 from 148
 * meaning $1.48, and round amounts are exactly the ones a person types. A
 * rule that guessed from the value -- "a fraction is major, a whole number
 * minor" -- showed every round price a hundred times too small.
 *
 * @since 1.1.0
 */
enum Unit: string {

	case Major = 'major';
	case Minor = 'minor';

	/**
	 * The unit a configuration names: minor only when it says exactly
	 * `minor`, major for anything else -- what every caller meant before
	 * there was a choice.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $unit What a setting said.
	 *
	 * @return self
	 */
	public static function of( mixed $unit ): self {
		return $unit instanceof self ? $unit : ( self::Minor->value === $unit ? self::Minor : self::Major );
	}
}
