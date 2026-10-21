# Project memory — lovecatz-woocommerce-complement

## Environment
- WP `C:\laragon\www\ddistillers`; PHP CLI `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`; Woo 11.1.1. Site `http://ddistillers.test`; active theme `ddistillers` (FSE). Blocksy track separate/unused.
- MySQL: `mysqld.exe --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini"`; **DB `ddistillers_db`**; client `.../bin/mysql.exe`, `--defaults-file` must be FIRST arg. HPOS order tables have no `shipping_total_amount` — use `wc_get_order()`.
- No web server by default; verify via PHP CLI harnesses in gitignored `tmp/` (deleted after).
- Style: tabs, `lwc_` prefix, text domain `lovecatz-wc`; bump plugin header + `LWC_VERSION` together.
- **Never tell the user to deactivate/reactivate the plugin** — `lwc_install()` self-heals on `admin_init` when `lwc_schema_version !== LWC_VERSION`; rewrite rules self-heal on `init`. Only DB/schema work needs wp-admin.
- Feature toggles: `lwc_order_number_enabled`, `lwc_fedex_shipper_address_enabled`, `lwc_jtc_debug`.

## Custom order IDs
`includes/core/class-lwc-order-number.php` (+ admin). prefix+date+Local/Global+atomic per-scope sequence+suffix; country from shipping→billing→store base. Primary hook `woocommerce_before_order_object_save`; defensive `woocommerce_new_order`, `woocommerce_process_shop_order_meta`. `woocommerce_order_number` is display-only. Meta `_lwc_order_number`, `_lwc_order_number_scope`; existing IDs never auto-change; one SQL atomic increment per scope. Test trap: reload an auto-draft via `wc_get_order()` before simulating the next save.

## PO / DP payments
`payment/class-lwc-payment-plan.php` + PO/DP gateways + admin. Plan meta `_lwc_payment_plan`=`full|po|dp`; ledger `_lwc_payments`. **Paid/balance/status always derived from the ledger.** `get_deposit_amount()`=0 unless plan is `dp`. Not a custom order status — a DP order must still reach `processing` so AWB automation fires; `process_payment` only applies pending|on-hold|processing. `is_available()` gated on `lwc_po_dp_enabled`. `sync_gateway_defaults()` writes gateway settings only when absent. Admin-created orders: `sync_plan_from_payment_method()` on new_order + process_shop_order_meta. Order-list columns: HPOS `manage_woocommerce_page_wc-orders_*` (gets WC_Order) vs classic `manage_edit-shop_order_*` (gets post ID). `refresh_paid_state()` owns `_date_paid`. Options: `lwc_po_dp_enabled`, `lwc_dp_default_percent`(50), `lwc_po/dp_default_status`(on-hold), `lwc_po_terms_days`(30), `lwc_po_require_login`.

## Shipping & AWB
- `LWC_AWB_Automation` alone owns `woocommerce_order_status_processing`. Auto: J&T Express, RaySpeed. FedEx always manual; J&T Cargo manual.
- Courier switching uses the buyer shipping address (authoritative); incomplete address suppresses live tariff couriers.

## J&T Cargo (OpenAPI)
Source of truth, never guess fields: `E:\Downloads\JTC_API_Integration_Manual_251208_121336 (1).pdf` (20 pages; no error-code table).
Customer order number is **`txlogisticId`** (String(50)) — not `customerOrderNo`; error `145003082` = empty/too long.
Required: `expressType=FT`, `orderType`(1/2), `serviceType`(01/02), `deliveryType=101`, `payType`(PP_PM/CC_CASH/PP_CASH), `sender{}`, `receiver{}`, `goodsType`(bm000001..bm000011), `weight` ≥1KG, `offerFee`; `totalQuantity` = koli count. Party limits: name32 mobile30 countryCode20(`IDN`) prov32 city32 area32 address150.
Signing: header digest `base64(md5(bizContent+privateKey))`; body digest `base64(md5(customerCode+UPPER(md5(pwd+'jadada236t2'))+privateKey))`, 24 chars. Both verified live against the sandbox.
`LWC_JTC_API::send()` is the only path; `LWC_JTC_Order` owns mapping/validation; customer number in `_lwc_jtc_txlogistic_id`, reused on retry.
**Sandbox state (verified 2026-10-12):** creds saved (demo creds J0086024191/pwd from manual), env=sandbox; signed `getAddress{}` returns `code:"1" success` (7.5 MB body) — signing+transport correct. Known codes: `145002001` duplicate order (sample ID12345678 already created), `145003083` incomplete sender, `145003050` Illegal parameters (getOrders rejects all variants — contract unknown, manual 3.4.2 is a copy-paste of 3.4.1). **Bug:** `transmit()` truncates >1 MB bodies with a text suffix → invalid JSON → `evaluate_business_response()` reports "not a JSON object" and counts a failure; any interface whose success payload exceeds 1 MB can never log a successful hit (blocks the manual's 3-hits-per-interface joint debugging). Test console: Settings → Shipping → J&T Cargo → **Sandbox Test** tab; sample payloads only for add_order/orders/shipment_track; progress = 20 endpoints × 3 hits.

## FedEx
- `LWC_FedEx_API::get_shipment_coverage()` = sole item coverage; legacy AWB without `item_ids` blocks new labels. Ship date/dims/DESC1 frozen at creation — cancel+recreate to change.
- Carton dims round DOWN to whole cm: `floor_dimension_cm()` (weight not floored); `cube_dimensions()` needs integer correction (`pow($v,1/3)`: 13³=2197→12.999…).
- Origin `LWC_FedEx_Shipper_Address` (toggle `lwc_fedex_shipper_address_enabled` + `lwc_fedex_shipper_address_*`); two-tier policy: rates fall back to store address silently, AWB/label/pickup blocked via `validate_origin_for_shipment()`. Order #32 has an AWB without `item_ids` (metabox notice-warning expected).
- **Declared customs value = invoice grand total** (`get_declared_value_total()`, filter `lwc_fedex_declared_value_total`); item selection only drives manifest qty+weight.
- `LWC_Currency_Converter::round_for_currency()` = ceil to whole units.

## Currency, promos, checkout
Base IDR, USD=15000; frozen order rate. Promo keys via `LWC_Promo_Discounts::get_maximum_discount()`. Checkout Block (React): `woocommerce_before_checkout_form` never fires; promo JS injects into the first visible `.wc-block-components-totals-coupon`; WC scopes with @container at 700px (below 700px a second summary appears; CSS hides its title). Customer-history metabox overrides the template via `wc_get_template`.

**Discounts are registered-customer only** (since 1.0.99, no toggle — ask before adding one): `LWC_Promo_Discounts::discounts_require_account()` is the single gate; it is false for anyone logged in AND for `is_admin()`/cron/WP-CLI so staff and jobs still use coupons. Server enforcement lives on `woocommerce_coupon_is_valid`+`woocommerce_coupon_error` (priority 5, inside `WC_Discounts::is_coupon_valid()` — the one path shared by cart AJAX, checkout block and Store API). Session coupons restored after logout are dropped on `woocommerce_cart_loaded_from_session` (WC does not re-validate restored coupons). UI enforcement: `LWC_Promo_Dashboard::hide_native_coupon_ui_for_guests()` filters `woocommerce_coupons_enabled` (frontend class only), which also drives the Cart/Checkout blocks' `couponsEnabled`; `get_checkout_coupon_data()` returns `array()` for guests; JS renders only the login invite for guests. Test caveat: `WC_Coupon::get_code()` is lower-cased.

**Guest promo CTA → My Account** (since 1.1.0): `LWC_Promo_Dashboard::get_promo_login_url()` is the single URL builder — `my-account?redirect_to=<cart|checkout>`; `add_query_arg()` encodes once, so never pre-encode the value. WC's My Account templates (9.9.0) have **no** hidden `redirect` field and post to the current URL (no `action`), so the plugin injects one via `woocommerce_login_form`/`woocommerce_register_form`; the destination is then applied by `woocommerce_login_redirect` + `woocommerce_registration_redirect`. Open-redirect guard: `wp_validate_redirect( $url, '' )` returns `''` for a foreign host or non-http scheme — empty means "reject". `render_checkout_coupons()` uses a `static $rendered` flag = one render per request, and that static is shared across instances (not per-instance), so harnesses must run each mode in its own process.

## Blocksy complement (separate plugin)
Prefix `lbc_`, text domain `lbc-blocksy`. Blocksy discovers header/footer elements by directory scan — each element needs `blocksy-header-items/<id>/{config,options,view}.php` appended via `blocksy:header:items-root-paths` (never `items-paths` with a parent dir). `view.php` sees extracted `$atts/$device/$item_id/$panel_type/$section_id/$row_id/$attr`. Item settings live in the `header_placements` theme mod. Detection: `function_exists('blocksy_default_akg')`. Child theme `blocksy-child-ddistillers` carries the dark-mode bridge. **Harness: never call `set_theme_mod()`** — inject via reflection, wrap theme switches in `register_shutdown_function()`.

## ddistillers theme (FSE)
Sections = file-based block patterns in `themes/ddistillers/patterns/`, composed by `templates/front-page.html` via `wp:pattern` (16 registered). File patterns are read-only in the Site Editor until "Detach pattern". Colours are theme.json presets via `--wp--preset--color--*` in `block-styles.css`; never hard-code hex. Fonts declared twice (theme.json `fontFace` + `functions.php` enqueue). `add_editor_style()` must list both style.css and assets/css/block-styles.css. Shortcode block needs a `render_block_core/shortcode` filter; RFQ = `[dd_rfq_form]`.
