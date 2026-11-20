<?php
/**
 * Prices that repeat.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money;


/**
 * Class Recurring
 *
 * A subscription price is an amount and a period, and the period changes what
 * the amount means. £9.99 and £9.99 a month are different products.
 *
 * Ported from the JavaScript this set already shipped for the same job, so a
 * price rendered in a checkout by the browser and in an order table by PHP
 * reads the same. That was the point: the two disagreeing is how a customer
 * ends up querying their invoice.
 *
 * @since 1.0.0
 */
final class Recurring {

	/**
	 * The intervals a subscription can bill on.
	 *
	 * Stripe's set. Anything else is passed through as given rather than
	 * refused, because a gateway may add one before this library hears of it.
	 *
	 * @var string[]
	 */
	public const array INTERVALS = array( 'day', 'week', 'month', 'year' );

	/**
	 * What follows the amount.
	 *
	 * Two shapes, because one does not cover both cases. Every month is
	 * `/mo`, which is short enough to sit beside a price. Every three months
	 * is "every 3 months", because `/3mo` is not something anybody reads
	 * correctly on the first attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval 'day', 'week', 'month' or 'year'.
	 * @param int    $count    How many intervals between charges.
	 *
	 * @return string Empty when there is no interval.
	 */
	public static function suffix( string $interval, int $count = 1 ): string {
		$interval = strtolower( trim( $interval ) );

		if ( '' === $interval ) {
			return '';
		}

		// A count of nought or less is not a billing period. Treating it as
		// one would render "every 0 months", which is worse than a plain
		// price.
		$count = max( 1, $count );

		if ( 1 === $count ) {
			return self::short( $interval );
		}

		return ' ' . sprintf(
			/* translators: 1: how many intervals, 2: the interval, pluralised. */
			__( 'every %1$d %2$s', 'sugarcommerce' ),
			$count,
			self::plural( $interval, $count )
		);
	}

	/**
	 * The short form: `/mo`, `/yr`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval The interval.
	 *
	 * @return string
	 */
	private static function short( string $interval ): string {
		return match ( $interval ) {
			'day'   => '/' . __( 'day', 'sugarcommerce' ),
			'week'  => '/' . __( 'wk', 'sugarcommerce' ),
			'month' => '/' . __( 'mo', 'sugarcommerce' ),
			'year'  => '/' . __( 'yr', 'sugarcommerce' ),
			default => '/' . $interval,
		};
	}

	/**
	 * The interval, pluralised.
	 *
	 * Through _n() rather than by appending an "s", because the count decides
	 * the form and not every language agrees that two is plural.
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval The interval.
	 * @param int    $count    How many.
	 *
	 * @return string
	 */
	private static function plural( string $interval, int $count ): string {
		return match ( $interval ) {
			'day'   => _n( 'day', 'days', $count, 'sugarcommerce' ),
			'week'  => _n( 'week', 'weeks', $count, 'sugarcommerce' ),
			'month' => _n( 'month', 'months', $count, 'sugarcommerce' ),
			'year'  => _n( 'year', 'years', $count, 'sugarcommerce' ),
			default => $interval . 's',
		};
	}

	/**
	 * How a price bills, as a label: "Every month", "Every 3 months".
	 *
	 * For a column or a line of its own, where suffix() -- made to sit
	 * beside an amount -- would be a fragment. Each interval has its own
	 * sentences and _n() chooses between them, because "every
	 * {count} {unit}" cannot be translated as a template: the unit inflects
	 * with the count, and in some languages so does the word for "every".
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval 'day', 'week', 'month' or 'year'.
	 * @param int    $count    How many intervals between charges.
	 *
	 * @return string Empty when there is no interval: what a price that
	 *                does not repeat is called is the caller's to say.
	 */
	public static function label( string $interval, int $count = 1 ): string {
		$interval = strtolower( trim( $interval ) );

		if ( '' === $interval ) {
			return '';
		}

		$count = max( 1, $count );

		// One is a sentence of its own and not the singular of the other:
		// a language's "singular" form also serves 21 and 31, which need
		// their number.
		if ( 1 === $count ) {
			return match ( $interval ) {
				'day'   => __( 'Every day', 'sugarcommerce' ),
				'week'  => __( 'Every week', 'sugarcommerce' ),
				'month' => __( 'Every month', 'sugarcommerce' ),
				'year'  => __( 'Every year', 'sugarcommerce' ),
				// An interval this library has not heard of, as it was given.
				default => 'Every ' . $interval,
			};
		}

		$text = match ( $interval ) {
			/* translators: %d: how many days between charges. */
			'day'   => _n( 'Every %d day', 'Every %d days', $count, 'sugarcommerce' ),
			/* translators: %d: how many weeks between charges. */
			'week'  => _n( 'Every %d week', 'Every %d weeks', $count, 'sugarcommerce' ),
			/* translators: %d: how many months between charges. */
			'month' => _n( 'Every %d month', 'Every %d months', $count, 'sugarcommerce' ),
			/* translators: %d: how many years between charges. */
			'year'  => _n( 'Every %d year', 'Every %d years', $count, 'sugarcommerce' ),
			default => 'Every %d ' . self::literal( $interval ) . 's',
		};

		return sprintf( $text, $count );
	}

	/**
	 * The period on its own: "month", "3 months".
	 *
	 * What goes after the slash in "£35.99 / month", and the answer to
	 * "billed every…".
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval 'day', 'week', 'month' or 'year'.
	 * @param int    $count    How many intervals between charges.
	 *
	 * @return string Empty when there is no interval.
	 */
	public static function period( string $interval, int $count = 1 ): string {
		$interval = strtolower( trim( $interval ) );

		if ( '' === $interval ) {
			return '';
		}

		$count = max( 1, $count );

		if ( 1 === $count ) {
			return match ( $interval ) {
				'day'   => __( 'day', 'sugarcommerce' ),
				'week'  => __( 'week', 'sugarcommerce' ),
				'month' => __( 'month', 'sugarcommerce' ),
				'year'  => __( 'year', 'sugarcommerce' ),
				default => $interval,
			};
		}

		$text = match ( $interval ) {
			/* translators: %d: how many days. */
			'day'   => _n( '%d day', '%d days', $count, 'sugarcommerce' ),
			/* translators: %d: how many weeks. */
			'week'  => _n( '%d week', '%d weeks', $count, 'sugarcommerce' ),
			/* translators: %d: how many months. */
			'month' => _n( '%d month', '%d months', $count, 'sugarcommerce' ),
			/* translators: %d: how many years. */
			'year'  => _n( '%d year', '%d years', $count, 'sugarcommerce' ),
			default => '%d ' . self::literal( $interval ) . 's',
		};

		return sprintf( $text, $count );
	}

	/**
	 * An interval made safe to sit inside a sprintf() format.
	 *
	 * An interval this library has not heard of is written out as given,
	 * and it can come from a gateway, an import or a stored row. Left as it
	 * is, `%s` in one throws ArgumentCountError -- a fatal error on the page
	 * -- and `%1$s` prints something else in its place.
	 *
	 * @param string $interval The interval.
	 *
	 * @return string
	 */
	private static function literal( string $interval ): string {
		return str_replace( '%', '%%', $interval );
	}

	/**
	 * A period as it is said -- "month", "3 months" -- back into its parts.
	 *
	 * For a period that was stored as words. The other way round from
	 * period(), so that what was stored in English can be said again in the
	 * reader's language: `parse_period( '3 months' )` is `[ 'month', 3 ]`,
	 * and those go to label(), period() or with_amount().
	 *
	 * Nothing is refused. An interval this library has not heard of comes
	 * back as it was given ("term", "2 terms" as `[ 'term', 2 ]`), and
	 * period() writes it out the same way again.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period A period, as period() writes one in English.
	 *
	 * @return array{0: string, 1: int} The interval and the count. An
	 *                                  empty interval for an empty period.
	 */
	public static function parse_period( string $period ): array {
		$period = strtolower( trim( $period ) );

		if ( 1 !== preg_match( '/^(?:(\d+)\s+)?(\S.*)\z/', $period, $found ) ) {
			return array( '', 1 );
		}

		$count    = max( 1, (int) $found[1] );
		$interval = $found[2];
		$singular = str_ends_with( $interval, 's' ) ? substr( $interval, 0, -1 ) : $interval;

		// The plural is undone for more than one of anything, and for one
		// of the intervals this knows ("1 months" is still a month).
		if ( $count > 1 || in_array( $singular, self::INTERVALS, true ) ) {
			$interval = $singular;
		}

		return array( $interval, $count );
	}

	/**
	 * An amount already written, with how often it is charged.
	 *
	 * Two ways of saying it. Short is what Money::format() does with an
	 * `interval` option, for beside a price: `$9.99/mo`, `$30.00 every 3
	 * months`. Long is the way Stripe writes it on a receipt, for a line of
	 * its own: `£35.99 / month`, `$120.00 / 3 months`.
	 *
	 * Takes the amount as text, so whoever calls decides how the money is
	 * written -- with its code, without its symbol, through a filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $amount   The amount, formatted.
	 * @param string $interval 'day', 'week', 'month' or 'year'. Empty for
	 *                         an amount that does not repeat.
	 * @param int    $count    How many intervals between charges.
	 * @param string $style    self::SHORT or self::LONG.
	 *
	 * @return string The amount alone when there is no interval.
	 */
	public static function with_amount( string $amount, string $interval, int $count = 1, string $style = self::SHORT ): string {
		if ( '' === trim( $interval ) ) {
			return $amount;
		}

		if ( self::LONG !== $style ) {
			return $amount . self::suffix( $interval, $count );
		}

		/* translators: 1: an amount of money, 2: a billing period, such as "month" or "3 months". */
		return sprintf( __( '%1$s / %2$s', 'sugarcommerce' ), $amount, self::period( $interval, $count ) );
	}

	/**
	 * The styles with_amount() writes in.
	 *
	 * @since 1.0.0
	 */
	public const string SHORT = 'short';
	public const string LONG  = 'long';

	/**
	 * The intervals, for a settings dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		return array(
			'day'   => __( 'Daily', 'sugarcommerce' ),
			'week'  => __( 'Weekly', 'sugarcommerce' ),
			'month' => __( 'Monthly', 'sugarcommerce' ),
			'year'  => __( 'Yearly', 'sugarcommerce' ),
		);
	}
}
