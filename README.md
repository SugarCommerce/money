# Money

Keep money as whole numbers of pence, cents and yen, and turn them into prices
people can read — in 136 currencies, each with the right number of decimal
places.

## What it does

Storing money as a float is how a total ends up at £9.999999999. Storing it as
an integer number of pence is correct, but then every currency needs its own
rules: yen has no decimal places, Kuwaiti dinar has three, and Hungarian forint
takes a payout only in whole units. Dividing by 100 is wrong for twenty
currencies.

This holds those rules. Amounts go in and out as integers; formatting, parsing
and validation know what each currency expects, and nothing is ever routed
through a float on the way.

## Features

* Format an amount for any of 136 currencies, with the right decimals
* Advertise a price without the zeros (`£29`), or write it the way a reader's
  locale does (`29,00 €`)
* Read a typed amount back to minor units, whatever separators were used — and
  refuse one finer than the currency can hold
* Check an amount is chargeable, and say why it is not
* Render a sale price with the old price struck through and screen-reader labels
* Apply a percentage or a flat discount, rounded exactly, and split an amount
  without losing a penny
* Say how a subscription bills — "/mo", "every 3 months", "Every 3 months"
* Convert between currencies' minor units, and fill a currency select

## Installation

```bash
composer require sugarcommerce/money
```

## Quick start

```php
use SugarCommerce\Money\Money;

Money::set_default_currency( fn() => get_option( 'myshop_currency', 'GBP' ) );

echo Money::format( 4999 );                    // £49.99
echo Money::format( 1000, 'JPY' );             // ¥1,000 — not ¥10.00

$amount = Money::parse( '1,299.50', 'GBP' );   // 129950

if ( ! Money::is_chargeable( $amount, 'GBP' ) ) {
	$why = Money::why_not_chargeable( $amount, 'GBP' );
}
```

## The default currency

Every call that takes an optional currency uses the default when it is left
out. Set a code, or a closure asked on every call — so a multi-currency shop
can answer per request, and run its own filter:

```php
Money::set_default_currency( 'EUR' );
Money::set_default_currency( fn() => apply_filters( 'myshop_currency', get_option( 'myshop_currency' ) ) );

Money::currency();   // 'EUR'
```

A default that is not a currency this library knows is US dollars, rather than
every price on the site losing its symbol. The default lives on the class, so
each plugin that bundles this under its own prefix keeps its own.

## Writing an amount

One formatter, with options rather than a method per combination. A string in
place of the options is the currency.

```php
Money::format( 4999, [ 'symbol' => false, 'code' => true ] );   // 49.99 USD
Money::format( 123456789, [ 'separators' => false ] );          // $1234567.89
Money::format( 2900, [ 'compact' => true ] );                   // $29 — $29.50 stays
Money::format( 999, [ 'interval' => 'month' ] );                // $9.99/mo
Money::format( 2900, [ 'currency' => 'EUR', 'locale' => 'de' ] );  // 29,00 €
Money::format( 2900, [ 'currency' => 'EUR', 'locale' => 'de', 'compact' => true ] );  // 29 €

Money::decimal( 1234567, 'KWD', false );   // 1234.567 — for a CSV, every place kept
Money::input_value( 123450, 'USD' );       // 1234.50 — for a form field
Money::value( $total, 'GBP', true );       // £12.00, or — when the total is null
```

`compact` is for a price being advertised, never a receipt. `locale` needs
ext-intl, and falls back to the plain format without it or for a locale ICU
refuses; `Currencies::locale( 'EUR' )` gives each currency's own. A misspelled
option is reported through `_doing_it_wrong()` under `WP_DEBUG`.

## Reading an amount

```php
Money::parse( '€1.234,56', 'EUR' );   // 123456
Money::parse( '1,000', 'JPY' );       // 1000
Money::parse( '1.234', 'USD' );       // throws — finer than a cent
Money::sanitize_input( $_POST['price'] ?? null, 'USD' );   // an int, a missing field, or typed text

Money::sanitize_amount( '1999' );           // minor units from an API or a column, never negative
Money::sanitize_exchange_rate( '1.2345' );  // 1.2345, or 0.0 when it is not a rate
```

`parse()` throws `InvalidArgumentException` for an amount too large for an
integer, and for one with more decimal places than the currency has: storing
$1.23 for a typed `1.234`, or ¥101 for `100.5`, is a price nobody typed. Catch
it where a form is saved and show it as a validation message.

## Can it be charged?

```php
Money::is_chargeable( 30, 'USD' );        // false — under the 50¢ minimum
Money::why_not_chargeable( 555, 'ISK' );  // 'ISK amounts must be a multiple of 100.'
Money::round_to_valid( 1234, 'BHD' );     // 1230 — dinars settle in hundredths
Money::is_valid_payout( 1045, 'HUF' );    // false — chargeable, but paid out in whole forints
Currencies::minimum_charge( 'GBP' );      // 30
```

The reason is safe to show a shopper: an unknown code is only repeated when it
is three plain letters.

## Sale prices

```php
use SugarCommerce\Money\Render;

echo Render::price( 3999, [ 'compare_at' => 4999 ] );
// <span class="price price--sale"><del>…$49.99</del> <ins>…$39.99</ins></span>

Money::saving_percentage( 3999, 4999 );   // 20 — rounded down, never overstated
```

Escaped once, at the end. A `compare_at` that is not higher renders as an
ordinary price.

## Percentages, discounts and splits

```php
Money::percentage( 180, 17.5 );    // 32 — the tie floats round down, rounded up
Money::allocate( 100, 3 );         // [ 34, 33, 33 ]

use SugarCommerce\Money\Rate;

Rate::applied_to( 5000, 20, 'percent' );   // 1000
Rate::applied_to( 5000, 500, 'flat' );     // 500 — never more than the amount
Rate::format( 8.875, 'percent', '', 3 );   // 8.875%
Rate::percentage( 20.0 );                  // 20%
```

A rate is a value and a kind, always both: `20` is twenty percent or twenty
pence, and nothing here guesses which.

## Subscriptions

```php
use SugarCommerce\Money\Recurring;

Recurring::label( 'month', 3 );                                    // Every 3 months
Recurring::period( 'month', 3 );                                   // 3 months
Recurring::with_amount( '£35.99', 'month', 1, Recurring::LONG );   // £35.99 / month
[ $interval, $count ] = Recurring::parse_period( '3 months' );     // 'month', 3
Recurring::options();                                              // for a dropdown
```

## Currencies

```php
use SugarCommerce\Money\Currencies;

Currencies::decimals( 'BHD' );             // 3
Currencies::symbol( 'GBP' );               // £
Currencies::name( 'JPY' );                 // Japanese Yen
Currencies::is_zero_decimal( 'KRW' );      // true
Currencies::scale( 'JPY', 'GBP' );         // 100 — multiply converted minor units by it
Currencies::country( 'EUR' );              // 'EU' — the issuing country
Currencies::for_country( 'DE' );           // [ 'EUR' ] — what a shopper there pays in
Currencies::paid_in( 'BG' );               // 'EUR' (since 2026); null for "use your default"
Currencies::sanitize_code( $_GET['c'] );   // three upper-case letters, or ''
Currencies::options( '{name} ({code})' );  // 'USD' => 'US Dollar (USD)', sorted
```

`country()` answers "whose is this currency": the euro is filed under `EU`.
`for_country()` and `paid_in()` answer the other question, "what does a shopper
in Germany pay in", for deciding which of several prices to show; nothing is
converted. The whole euro area is listed, Bulgaria included since 1 January 2026,
and so are the places that spend a neighbour's money (Ecuador and Timor-Leste in
dollars, Liechtenstein in francs, the CFA franc zones). A country whose money is
not in the dataset gives `null` / `[]`, which means "use your default". The lev,
the Antillean guilder and the old leone stay in the dataset so old orders still
format, but no country pays in them.

## Security

* A currency code from a query string never reaches the page: an unknown one is
  dropped unless it is three plain letters, and ICU never sees it.
* A typed amount is never rounded or clamped into a different price: finer than
  the currency, or past the integer range, it throws.
* `−5.00` with a Unicode minus stays negative, so a credit never reads as a charge.
* Digits from other scripts (`١٠٠`, `１００`) read as the number they are, not
  as nought; text that is not UTF-8 is refused rather than read as free.
* The dots in `د.إ`, `ل.ل` and `ر.ع.` are not read as separators, so the
  library's own output parses back to the same amount.
* An unknown billing interval is never read as a `sprintf()` format.
* No arithmetic goes through a float where it could lose a penny: percentages,
  savings and large amounts are exact, and out-of-range values are clamped or
  refused rather than wrapped.
* `Render::price()` escapes once, at the end.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* ext-intl, optionally, for the `locale` option

## License

GPL-2.0-or-later
