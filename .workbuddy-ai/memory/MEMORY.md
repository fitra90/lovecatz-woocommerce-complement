# Project memory — lovecatz-woocommerce-complement

## Environment
- WordPress install lives at `C:\laragon\www\ddistillers`; PHP CLI for linting is `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe` (`php` is not on PATH).
- Smoke tests can bootstrap WP from a CLI script in `tmp/` with `define('WP_USE_THEMES', false)` + `require wp-load.php`. Wrap mutating tests in `START TRANSACTION` / `ROLLBACK` (tables are InnoDB).

## Courier switching (added 2026-09-21)
- `shipping/class-lwc-courier-registry.php`: `LWC_Courier_Registry` detects couriers by evidence — active plugin list, loaded shipping-method class, methods registered in WooCommerce, and shipping-zone instances.
  - Internal: J&T Express (`lwc_jt_express`, `lwc_jt`), J&T Cargo (`lwc_jt_cargo`, internal-only, emits no rates), FedEx, RaySpeed.
  - External: JNE = plugin `jne-shipping-official/jne-shipping-official.php`, method id `jneshof_shipping`, class `Jneshof_Admin_Shipping_Method_View` (confirmed installed on this site).
  - Exclusion option: `lwc_courier_switch_disabled`.
- `shipping/class-lwc-order-shipping-switcher.php`: two modes — `pre_fulfillment` (statuses `on-hold`/`pending`, filterable via `lwc_shipping_switch_pre_fulfillment_statuses`) allows free courier swaps; otherwise the old AWB-cancellation confirmation flow applies.
  - `apply_change( $order, $rate, $confirmed )` is public so it can be reused/tested.
  - Manual option id prefix `lwc_courier:<slug>` for installed couriers with no live rate; cost defaults to the current shipping cost.
  - Order meta: `_lwc_shipping_courier`, `_lwc_shipping_change_history`, `_lwc_shipping_change_count`.
  - Destination = **buyer shipping address**. The order edit screen posts a live `destination` JSON (read from `_shipping_*` fields) so a tariff refresh does not need a save first. A submitted address is authoritative — never merged with the saved order address, or a half-typed address would be quoted against the old city/postcode.
  - `destination_is_complete()`: ID needs country+state+city+postcode; other countries need country+postcode. When incomplete, live tariffs are suppressed, `uses_tariff` couriers are flagged `unavailable` (disabled in the select), and couriers without destination pricing (J&T Cargo) stay selectable.
  - `rate_notice` is shown when the address is valid but no live tariff came back.
  - Money is always rendered with `$order->get_currency()` + `get_woocommerce_currency_symbol()`; the metabox carries `data-currency` / `data-currency-symbol`.
  - **Currency trap**: `LWC_Currency_Converter` hooks `woocommerce_package_rates` (`convert_shipping_rates`) and rewrites tariffs into the shopper currency read from the `lwc_currency` cookie. The admin browser often carries that cookie, so quoted tariffs came back as USD while the metabox formatted them with the order currency — this is what produced the bogus "Rp1"/"Rp3". `calculate_rates()` therefore detaches that callback around `apply_filters('woocommerce_package_rates', …)` and `to_order_currency()` converts base → order currency afterwards (using `_lwc_currency_rate`, falling back to the configured rate table). Rates are quoted in base currency and only converted when the order currency really differs.
  - Carrier rate labels may contain HTML entities (JNE returns `JNE JTR (6 &#8211; 7 days)`); `decode_label()` runs `html_entity_decode()` before the label reaches the select, otherwise the entity shows verbatim.
  - After a successful switch the AJAX response returns `next_step_html` (Create AWB button for jt/fedex/rayspeed + Reload) instead of auto-reloading. The create button reuses `lwc-order-list-shipping.js`, which is now enqueued on the `shop_order` screen too — without that, the handler is absent on the legacy order edit page.

## Tariff factors — weight & distance (verified 2026-09-21)
- The switcher never computes distance itself. It builds a cart-shaped package (items + destination) and asks each courier for a price; the courier's own tariff table supplies the distance component.
- Weight sources: J&T Express = real weight only (`get_package_weight_kg()`, min 0.01 kg). JNE = `max(real, volumetric L×W×H/6000)`, min 0.1 kg. FedEx splits packages above `lwc_fedex_max_package_weight_kg`. RaySpeed kg, min 0.1.
- Destination wire format: J&T sends `sendSiteCode` (origin city) + `destAreaCode` (from `LWC_JT_Route_Mapper`); JNE sends `destination.postcode`; FedEx sends `recipient.address.postalCode/stateOrProvinceCode/countryCode`.
- `LWC_JT_Route_Mapper::resolve()` **requires** a district — without one it always fails with "not present in the official J&T mapping". Valid triples live in `wp_lwc_indonesia_regions` (`wc_state_code` + `city_key` + `district_key`); `region_cookie_district()` only helps when the checkout cookie is present, so CLI tests must pass the district explicitly.
- **J&T sandbox returns a stub price** (11111 × weight, identical for every destination). `lwc_jt_express_environment` = `sandbox` and `LWC_JT_Account::get_credentials('production')` is empty, so real distance pricing needs production credentials. Do not treat sandbox numbers as a plugin bug.
- J&T Cargo's `calculate_shipping()` is intentionally empty — internal courier, emits no rates.
- JNE's tariff API needs credentials ("Kredensial Tidak Valid - Authorization header required" from CLI), so JNE behaviour has to be read from code, not exercised offline.

## Conventions
- PHP: tabs for indentation, WordPress coding style, `lwc_` prefix for options/meta, text domain `lovecatz-wc`.
- Bump both the header `Version:` and `LWC_VERSION` together; `lwc_install()` re-runs when the version changes.
