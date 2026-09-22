# WooCommerce cart recipes for offline harnesses

All recipes assume the bootstrap in `scripts/wp-bootstrap.php` has run.

## Force the shopper currency

`LWC_Currency_Converter::get_selected_currency()` caches its answer in a private
`$selected` property and resolves it from `?currency=`, then the `lwc_currency` cookie,
then IP geolocation. Setting `$_GET['currency']` alone is not enough because the value is
cached, and cookie writes are unreliable under the CLI. Set the cache directly:

```php
/** Force the converter's cached shopper currency without touching cookies. */
function lwc_force_currency( $code ) {
	$converter = LWC_Currency_Converter::instance();
	$property  = new ReflectionProperty( $converter, 'selected' );
	$property->setAccessible( true );
	$property->setValue( $converter, $code );
}
```

After this, `get_woocommerce_currency()` returns the forced code, product prices are
converted, and `LWC_Promo_Discounts::active_currency()` agrees.

Pass an empty string to simulate "no shopper currency" (base currency only).

## Build a cart and apply a coupon

A standalone `WC_Cart` is enough — no session, no request.

```php
$cart      = new WC_Cart();
WC()->cart = $cart;                 // required: several plugin hooks read WC()->cart
$cart->add_to_cart( $product_id, $quantity );
$cart->calculate_totals();
$applied = $cart->apply_coupon( $coupon_code );
$cart->calculate_totals();

$subtotal = $cart->get_subtotal();
$discount = $cart->get_discount_total();
```

Capture coupon validation failures — a silently unapplied coupon makes a harness
report a "correct" discount of zero:

```php
if ( function_exists( 'wc_get_notices' ) ) {
	foreach ( wc_get_notices( 'error' ) as $notice ) {
		$message = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
		echo '  error: ' . wp_strip_all_tags( $message ) . "\n";
	}
	wc_clear_notices();
}
```

Assigning `WC()->cart` matters because `LWC_Promo_Discounts::limit_percentage_discount()`
and `apply_selected_shipping_discount()` both read `WC()->cart`, and
`LWC_Promo_Dashboard` reads `WC()->cart->has_discount()`.

## Reach a private method under test

```php
$method = new ReflectionMethod( $object, 'private_method_name' );
$method->setAccessible( true );
$result = $method->invoke( $object, $arg1, $arg2 );
```

Use this to test admin-only or internal helpers without simulating a full HTTP request
(which would need nonces and would call `wp_safe_redirect()` + `exit`).

## The currency-conversion trap

This plugin converts product prices, shipping rates, and display currency, so **any
amount inside a cart calculation is already in the shopper currency**. Two bugs have
already come from ignoring this:

1. **Shipping switcher** — `woocommerce_package_rates` rewrote tariffs into the shopper
   currency while the admin metabox formatted them with the order currency, producing
   bogus `Rp1`/`Rp3` values.
2. **Promo caps** — `_lwc_promo_maximum_discount` stayed in the base currency while the
   discount it capped was in the shopper currency, so `$uncapped > $maximum` never fired
   and the cap silently vanished.

   The accepted fix was deliberately small: one dedicated cap pair **per promo type**
   (`_lwc_promo_maximum_discount` / `_usd` for percentage, `_lwc_promo_shipping_maximum_discount`
   / `_usd` for shipping) with `maximum_discount_keys( $type )` as the single source of truth
   for the mapping. A first attempt that derived the currency list from the configured
   exchange rates and converted the cap at runtime was rejected as too heavy. Default to
   dedicated fields over generic machinery in this project, and never default or derive a
   cap amount — every cap is user input, and an empty field means no cap.

Before comparing two money values, confirm both are in the same currency. When one is
stored configuration (base currency) and the other is a live cart amount, convert
deliberately and say which direction the conversion goes.

## Testing an admin form handler

`LWC_Promo_Admin::save_coupon()` ends in `redirect_with_notice()`, which calls `exit`, so it
**cannot** be driven from a harness. Returning `false` from the `wp_redirect` filter stops
`wp_safe_redirect()` from exiting, but not the explicit `exit;` that follows it. Do not fight
this — extract the logic under test into its own method (as `save_maximum_discounts()` was)
and invoke that via reflection. Then pin the delegation with a source-level assertion:

```php
$source = (string) file_get_contents( LWC_PLUGIN_DIR . 'promo/class-lwc-promo-admin.php' );
lwc_assert( 'save_coupon delegates cap persistence', false !== strpos( $source, '$this->save_maximum_discounts( $id, $type );' ) );
```

The same trick proves "no hardcoded value" invariants cheaply, for example that the admin
never references a cap meta constant directly:
`0 === preg_match_all( '/LWC_Promo_Discounts::META_(SHIPPING_)?MAXIMUM/', $source )`.

## Simulating shipping without a shipping zone

`apply_selected_shipping_discount()` reads `$cart->get_shipping_total()`, which is always 0 in a
CLI cart. Set it directly and invoke the callback the way the hook would:

```php
$totals_property = new ReflectionProperty( $cart, 'totals' );
$totals_property->setAccessible( true );
$totals                   = $totals_property->getValue( $cart );
$totals['shipping_total'] = 100.0;
$totals_property->setValue( $cart, $totals );

( new LWC_Promo_Discounts() )->apply_selected_shipping_discount( $cart );
```

Then read `$cart->get_fees()` and sum the amounts. Call the method directly rather than
`calculate_totals()`, which would overwrite the shipping total you just set.

## Harness pitfalls learned the hard way

- **`WC_Coupon` and `WP_Post` cache their meta.** Capture values into plain variables before
  mutating or deleting the meta, or later assertions read a stale object. (A harness that
  asserted a USD cap *after* deliberately deleting it produced a false FAIL.)
- **Assertion order matters.** Sections that mutate fixtures must not precede assertions that
  depend on the pre-mutation values.
- **A FAIL is not automatically a code bug.** Read the raw numbers first: both failures in the
  promo save harness were wrong assertions, not wrong behaviour.
- **Do not `define()` a constant and use it on an earlier line.** PHP constants are not hoisted.


## Do not trust sandbox or stub numbers

Known non-bugs that look like bugs:

- J&T Express sandbox returns a stub price (`11111 × weight`) identical for every
  destination. Real distance pricing needs production credentials.
- J&T Cargo's `calculate_shipping()` is intentionally empty — internal courier, no rates.
- JNE's tariff API needs credentials, so JNE paths cannot be exercised offline at all.

## Render an admin screen offline

```php
$_GET['page']      = 'lovecatz-wc';
$_GET['tab']       = 'promo';
$_GET['coupon_id'] = $coupon_id;
ob_start();
$admin->render_manager();
$html = ob_get_clean();
```

Then assert on the markup (`strpos`/`preg_match`) — for example that an input exists and
carries the stored value. Remember `wp-admin/includes/template.php` for `submit_button()`.

## Escaping round-trip check

When a plugin string is escaped again downstream, assert on the *escaped* output too.
`wc_price()` returns HTML entities for many symbols (WooCommerce stores USD as `&#36;`),
so `esc_html( wc_price( 9, array( 'currency' => 'USD' ) ) )` yields the literal
`&#36;9` unless the entity is decoded first. This exact bug reached the promo admin list
and the checkout coupon card.
