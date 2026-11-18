<?php
/**
 * The options a formatter takes.
 *
 * @package   SugarCommerce\Money
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Money;

/**
 * Class Options
 *
 * One formatter and one renderer, each taking an array, rather than a function
 * per combination. There were fifteen global helpers before this: a formatter,
 * a formatter with the code, a formatter with an interval, a renderer, a
 * renderer for sales, and accessors for everything else.
 *
 * Every one of those was a combination of the same handful of decisions, and
 * the combinations multiply -- add subscriptions and you need a recurring twin
 * of each; add sales and you need those again.
 *
 * An enum was tried and thrown away: these are not one axis. Whether to show
 * the symbol, whether to name the currency, whether it repeats and what it
 * cost before are independent questions, and an enum forces them into one list
 * where you cannot ask two at once.
 *
 * The usual objection to configuration arrays is that a typo does nothing and
 * says nothing. An unrecognised key raises _doing_it_wrong() under WP_DEBUG.
 *
 * @since 1.0.0
 */
final class Options {

	/**
	 * What a formatter reads.
	 *
	 * @var array<string, mixed>
	 */
	private const FORMAT = array(
		// ISO-4217 code. Empty means the default, from
		// Money::set_default_currency().
		'currency'       => '',

		// Show the currency's symbol: `$49.99`.
		'symbol'         => true,

		// Name the currency after the amount: `49.99 USD`. With `symbol` as
		// well, `$49.99 USD` -- which an invoice sometimes wants and a shop
		// front never does.
		'code'           => false,

		// Group the thousands: `1,999.00` rather than `1999.00`. Off for a
		// text input, so a value round-trips through a form unchanged.
		'separators'     => true,

		// Drop the minor units when they are all nought: `$49` rather than
		// `$49.00`, but `$49.99` untouched. For a price being advertised --
		// a listing, a button, a pricing table -- never for a receipt, where
		// the zeros say the number is exact.
		'compact'        => false,

		// Write it the way a locale does, through ext-intl: `29,00 €` for
		// `de`, `€29.00` for `en_IE`. Empty for this library's own format,
		// which is also what is used when ext-intl is missing or the locale
		// is one ICU refuses. Currencies::locale() has each currency's own.
		'locale'         => '',

		// A billing period: 'day', 'week', 'month' or 'year'. Set, the amount
		// gains `/mo` or `every 3 months`.
		'interval'       => '',

		// How many intervals between charges.
		'interval_count' => 1,
	);

	/**
	 * What a renderer reads, on top of the above.
	 *
	 * @var array<string, mixed>
	 */
	private const RENDER = array(
		// What it cost before. Set and higher than the amount, the price
		// renders as a reduction.
		'compare_at' => null,

		// Class for the wrapper.
		'class'      => 'price',
	);

	/**
	 * Fill in what was not given, and complain about what is not read.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $options What the caller passed.
	 * @param bool                 $render  Whether the rendering keys are allowed too.
	 *
	 * @return array<string, mixed>
	 */
	public static function parse( array $options = array(), bool $render = false ): array {
		$allowed = self::defaults( $render );

		self::warn_about_unknown_keys( $options, $render );

		return array_merge( $allowed, array_intersect_key( $options, $allowed ) );
	}

	/**
	 * The defaults for a formatter, or for a renderer.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $render Whether to include the rendering keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults( bool $render = false ): array {
		return $render ? array_merge( self::FORMAT, self::RENDER ) : self::FORMAT;
	}

	/**
	 * The keys that are read.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $render Whether to include the rendering keys.
	 *
	 * @return string[]
	 */
	public static function keys( bool $render = false ): array {
		return array_keys( self::defaults( $render ) );
	}

	/**
	 * The keys given that nothing reads.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $options What the caller passed.
	 * @param bool                 $render  Whether the rendering keys are allowed.
	 *
	 * @return string[]
	 */
	public static function unknown_keys( array $options, bool $render = false ): array {
		return array_values( array_diff( array_keys( $options ), self::keys( $render ) ) );
	}

	/**
	 * Say so, while somebody is looking.
	 *
	 * A misspelled key is not an error in PHP, the option silently does
	 * nothing, and the only symptom is a price that does not look the way it
	 * was asked to. `compare_at` passed to a formatter rather than a renderer
	 * is the one this will catch most.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $options What the caller passed.
	 * @param bool                 $render  Whether the rendering keys are allowed.
	 *
	 * @return void
	 */
	private static function warn_about_unknown_keys( array $options, bool $render ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		$unknown = self::unknown_keys( $options, $render );

		if ( array() === $unknown ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			esc_html(
				sprintf(
					/* translators: 1: comma-separated list of option keys, 2: comma-separated list of keys that are read. */
					__( 'Money was given options nothing reads: %1$s. The options are: %2$s.', 'sugarcommerce' ),
					implode( ', ', $unknown ),
					implode( ', ', self::keys( $render ) )
				)
			),
			'1.3.0'
		);
	}
}
