# Project memory — lovecatz-woocommerce-complement

## Environment
- WP `C:\laragon\www\ddistillers`; PHP CLI `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`; Woo 11.1.1; site `http://ddistillers.test`; theme `ddistillers` (FSE). Blocksy track separate/unused.
- MySQL: `mysqld.exe --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini"`; DB `ddistillers_db`; client `.../bin/mysql.exe`, `--defaults-file` FIRST arg. HPOS order tables lack `shipping_total_amount` → use `wc_get_order()`.
- No web server by default; verify via PHP CLI harnesses in gitignored `tmp/` (deleted after).
- Style: tabs, `lwc_` prefix, text domain `lovecatz-wc`; bump plugin header + `LWC_VERSION` together.
- **Never tell user to deactivate/reactivate** — `lwc_install()` self-heals on `admin_init` when `lwc_schema_version !== LWC_VERSION`; rewrite rules self-heal on `init`. Only DB/schema work needs wp-admin.
- Toggles: `lwc_order_number_enabled`, `lwc_fedex_shipper_address_enabled`, `lwc_jtc_debug`.

## Custom order IDs
`includes/core/class-lwc-order-number.php`. prefix+date+Local/Global+atomic per-scope sequence+suffix; country shipping→billing→store base. Hooks `woocommerce_before_order_object_save` (primary), `woocommerce_new_order`, `woocommerce_process_shop_order_meta`; `woocommerce_order_number` display-only. Meta `_lwc_order_number`, `_lwc_order_number_scope`; existing IDs never change. Test trap: reload auto-draft via `wc_get_order()` before next save.

## PO / DP payments
`payment/class-lwc-payment-plan.php` + gateways/admin. `_lwc_payment_plan`=full|po|dp; ledger `_lwc_payments`. **Paid/balance/status always derived from ledger**; `get_deposit_amount()`=0 unless `dp`. DP orders must reach `processing` for AWB automation; `process_payment` applies pending|on-hold|processing. `is_available()` gated on `lwc_po_dp_enabled`. `sync_gateway_defaults()` only writes absent settings. Admin orders: `sync_plan_from_payment_method()` on new_order + process_shop_order_meta. List columns: HPOS `manage_woocommerce_page_wc-orders_*` (WC_Order) vs classic `manage_edit-shop_order_*` (post ID). `refresh_paid_state()` owns `_date_paid`. Options: `lwc_po_dp_enabled`, `lwc_dp_default_percent`(50), `lwc_po/dp_default_status`(on-hold), `lwc_po_terms_days`(30), `lwc_po_require_login`.

## Shipping & AWB
- `LWC_AWB_Automation` alone owns `woocommerce_order_status_processing`. Auto J&T Express + RaySpeed; FedEx + J&T Cargo manual.
- Courier switching uses buyer shipping address (authoritative); incomplete address suppresses live-tariff couriers.
- **General tab (1.1.1)**: `shipping/class-lwc-shipping-general-settings.php` = first Shipping sub-tab before FedEx. Options `lwc_checkout_ship_to_default` (`shipping`|`pickup`) + `lwc_checkout_ship_to_default_enabled`. Checkout Block: filter `woocommerce_store_api_checkout_update_customer_from_request` + `woocommerce_store_api_checkout_update_order_from_request` sets `ship_to_different_address`/shipping address from billing; classic: `woocommerce_ship_to_different_address_checked` + `woocommerce_checkout_get_value`. Self-pickup = local_pickup chosen shipping method, not a separate WC option.

## J&T Cargo (OpenAPI)
Source of truth (never guess fields): `E:\Downloads\JTC_API_Integration_Manual_251208_121336 (1).pdf`.
- Customer order number = **`txlogisticId`** (String(50)), not `customerOrderNo`; err `145003082` empty/too long. Required: `expressType=FT`, `orderType`, `serviceType`, `deliveryType=101`, `payType`, `sender{}`, `receiver{}`, `goodsType`, `weight`≥1KG, `offerFee`; `totalQuantity`=koli count. Party limits: name32 mobile30 countryCode20(IDN) prov/city/area32 address150.
- Signing (verified live): header digest `base64(md5(bizContent+privateKey))`; body digest `base64(md5(customerCode+UPPER(md5(pwd+'jadada236t2'))+privateKey))`, 24 chars.
- `LWC_JTC_API::send()` only path; `LWC_JTC_Order` mapping/validation; number in `_lwc_jtc_txlogistic_id` reused on retry.
- Sandbox (2026-10-12): demo creds J0086024191, env=sandbox; `getAddress{}` → `code:"1"` (7.5 MB). Codes `145002001` duplicate, `145003083` incomplete sender, `145003050` illegal params (getOrders contract unknown, manual 3.4.2 duplicates 3.4.1). **Bug:** `transmit()` truncates >1 MB bodies with text suffix → invalid JSON → `evaluate_business_response()` logs failure; big-payload interfaces can never log success (blocks manual's 3-hit joint debugging). Console: Settings → Shipping → J&T Cargo → Sandbox Test.
- Test list (2026-11-04): `get_test_interfaces()` (filter `lwc_jtc_test_interfaces`) — 6 manual-documented scopes only (never map undocumented): add_order, orders, cancel_order, delivery_sla (agingCost/get), shipment_track, print_order; progress 6×3 hits. Skipped (docblock): auth (digest signing only), service list (none), webhook (3.5.2 "Tidak digunakan"). Registry keeps 20 paths. print_order schema-less → sample disabled. Manual typo: §3.4.6 `order/agingCost/get` → HTTP 500; `agingCost/get` → 200 SUCCESS.
- Flow chaining (2026-11-04): successful add_order stores billCode+txlogisticId in option `lwc_jtc_sandbox_chain` (`LWC_JTC_Account::store_sandbox_chain` via `lwc_jtc_sandbox_request`); `chain_overrides()` substitutes into orders/cancel_order/shipment_track samples (fallback manual examples); add_order sample generates fresh `ID+ymdHis` ID; AJAX returns refreshed `samples`+`chain`, JS shows chain note.

## FedEx
- `get_shipment_coverage()` = sole item coverage; legacy AWB without `item_ids` blocks labels. Ship date/dims/DESC1 frozen at creation → cancel+recreate.
- Carton dims floor to whole cm (`floor_dimension_cm()`, weight not floored); `cube_dimensions()` needs integer correction (`pow($v,1/3)` 13³→12.999…).
- Origin `LWC_FedEx_Shipper_Address` (toggle `lwc_fedex_shipper_address_enabled` + `*_address_*`); rates silently fall back to store address, AWB/label/pickup blocked via `validate_origin_for_shipment()`. Order #32 AWB lacks `item_ids` (metabox warning expected).
- Declared customs value = invoice grand total (`get_declared_value_total()`, filter `lwc_fedex_declared_value_total`); item selection only drives manifest qty+weight. `LWC_Currency_Converter::round_for_currency()` = ceil whole units.

## Currency, promos, checkout
Base IDR, USD=15000, frozen order rate. Promo keys via `get_maximum_discount()`. Checkout Block (React): `woocommerce_before_checkout_form` never fires; promo JS injects into first visible `.wc-block-components-totals-coupon`; WC @container 700px (below → second summary, CSS hides its title). Customer-history metabox overrides template via `wc_get_template`.
- **Discounts registered-customer only** (since 1.0.99, no toggle — ask before adding): `discounts_require_account()` single gate, false for logged-in + is_admin()/cron/WP-CLI. Server: `woocommerce_coupon_is_valid` + `woocommerce_coupon_error` (priority 5, inside `WC_Discounts::is_coupon_valid()` — shared by cart AJAX, block, Store API); restored session coupons dropped on `woocommerce_cart_loaded_from_session`. UI: `hide_native_coupon_ui_for_guests()` filters `woocommerce_coupons_enabled` (frontend only; drives block `couponsEnabled`); `get_checkout_coupon_data()` → `array()` for guests. `WC_Coupon::get_code()` lower-cased.
- **Guest promo CTA → My Account** (1.1.0): `get_promo_login_url()` = `my-account?redirect_to=<cart|checkout>`; `add_query_arg()` encodes once (don't pre-encode). WC My Account (9.9.0) has no hidden `redirect` field and posts to current URL → plugin injects via `woocommerce_login_form`/`woocommerce_register_form`, applied by `woocommerce_login_redirect` + `woocommerce_registration_redirect`; guard `wp_validate_redirect($url,'')` empty = reject. `render_checkout_coupons()` uses static `$rendered` shared across instances → one render per request; harnesses need separate processes per mode.

## Blocksy complement (separate plugin)
Prefix `lbc_`, domain `lbc-blocksy`. Blocksy scans directories — each element needs `blocksy-header-items/<id>/{config,options,view}.php` appended via `blocksy:header:items-root-paths` (never `items-paths` with parent dir). `view.php` gets `$atts/$device/$item_id/$panel_type/$section_id/$row_id/$attr`; settings in `header_placements` theme mod. Detection `function_exists('blocksy_default_akg')`. Child theme `blocksy-child-ddistillers` has dark-mode bridge. Harness: never `set_theme_mod()` — inject via reflection, wrap theme switches in `register_shutdown_function()`.

## ddistillers theme (FSE)
Sections = file-based block patterns in `themes/ddistillers/patterns/`, composed by `templates/front-page.html` via `wp:pattern` (16 registered). File patterns read-only in Site Editor until "Detach". Colours = theme.json presets `--wp--preset--color--*` in `block-styles.css`; never hard-code hex. Fonts declared twice (theme.json fontFace + functions.php enqueue). `add_editor_style()` must list style.css + block-styles.css. Shortcode block needs `render_block_core/shortcode`; RFQ = `[dd_rfq_form]`.
