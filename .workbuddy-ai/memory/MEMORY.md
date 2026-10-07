# Project memory — lovecatz-woocommerce-complement

## Environment
- WP: `C:\laragon\www\ddistillers`; PHP CLI `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`; WooCommerce 11.1.1; WP 7.1.2.
- Site URL is `http://ddistillers.test` (hosts entry). Active theme: **`ddistillers`** (FSE block theme, `wp-content/themes/ddistillers`). Blocksy + `lovecatz-blocksy-complement` are a separate, unused track.
- MySQL: start `mysqld.exe --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini"` (background). datadir `C:/laragon/data/mysql-8.4`. **DB name is `ddistillers_db`, not `ddistillers`.** Client: `C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe`; `--defaults-file=...` must be the **first** argument or MySQL errors out. HPOS tables (`wp_wc_orders`, `wp_wc_orders_meta`) have no `shipping_total_amount` column — read totals through `wc_get_order()`, not SQL.
- No web server runs by default — Laragon's nginx/apache were absent; verify with PHP CLI instead of HTTP.
- Verify WP things via CLI: set `$_SERVER['HTTP_HOST'/'REQUEST_URI'/'REQUEST_METHOD']`, `require wp-load.php`, then `WP_Block_Patterns_Registry` + `do_blocks()`. Force a theme with a temp mu-plugin filtering `template`/`stylesheet` (never write the DB). Harnesses go in gitignored `tmp/` and are deleted after.
- Style: tabs, `lwc_` prefix, text domain `lovecatz-wc`; bump plugin header + `LWC_VERSION` together.
- **Never tell the user to deactivate/reactivate the plugin.** `lwc_install()` runs on `admin_init` and self-heals when `lwc_schema_version !== LWC_VERSION`; `lwc_maybe_flush_rewrite_rules()` self-heals on `init`. Only DB/schema work needs wp-admin; options-only features are live on file upload.
- Per-feature toggles are the real off switch: `lwc_order_number_enabled`, `lwc_fedex_shipper_address_enabled`, `lwc_jtc_debug`.

## Custom order IDs
`includes/core/class-lwc-order-number.php` + `includes/admin/class-lwc-order-number-admin.php`.
Format: prefix + date + Local/Global + atomic per-scope sequence + suffix. Country from shipping → billing → store base; `ID` = Local.
Hooks: primary `woocommerce_before_order_object_save`; defensive `woocommerce_new_order`, `woocommerce_process_shop_order_meta`. `woocommerce_order_number` is display-only — never assign from a read; never assign while destination is unknown.
Meta `_lwc_order_number`, `_lwc_order_number_scope`; existing IDs never auto-change; `lwc_order_number_enabled_at` gates old orders. One SQL atomic increment per scope (do not replace with PHP read/modify/write); duplicate-check and advance.
Woo test trap: reload an auto-draft with `wc_get_order()` before simulating the next save.

## Shipping & AWB
- `LWC_AWB_Automation` alone owns `woocommerce_order_status_processing`. Auto: J&T Express, RaySpeed. FedEx always manual (ship date, carton dims, DESC1 frozen at creation). J&T Cargo has no auto path.
- Courier switching uses the buyer shipping address (authoritative); incomplete address suppresses live tariff couriers. Admin rate conversion must detach cart currency conversion.

## J&T Cargo (OpenAPI)
Manual (source of truth, never guess fields): `E:\Downloads\JTC_API_Integration_Manual_251208_121336 (1).pdf`.
Customer order number is **`txlogisticId`** (String(50), required) — not `customerOrderNo`. Error `145003082` = empty/too long.
Required: `expressType`=`FT`, `orderType`(`1`/`2`), `serviceType`(`01`/`02`), `deliveryType`=`101`, `payType`(`PP_PM`/`CC_CASH`/`PP_CASH`), `sender{}`, `receiver{}`, `goodsType`(`bm000001..bm000011`), `weight` min 1 KG, `offerFee`. `totalQuantity` = number of koli. Party: name32 mobile30 countryCode20(`IDN`) prov32 city32 area32 address150.
Signing: header digest `base64(md5_raw(bizContent + privateKey))`; body digest `base64(md5_raw(customerCode + UPPER(md5(pwd+'jadada236t2')) + privateKey))`, 24 chars.
`LWC_JTC_API::send()` is the only path: one array → one `wp_json_encode` → digest → one `bizContent` field. `LWC_JTC_Order` owns mapping/validation. Customer number in `_lwc_jtc_txlogistic_id`, reused on retry.

## FedEx
- `LWC_FedEx_API::get_shipment_coverage()` is the sole source of item coverage. An active legacy AWB without `item_ids` blocks new labels. Printed `DESC1` = first customs commodity description.
- Ship date, carton measurements and label description cannot be edited after creation — cancel and recreate.
- **Manual carton dimensions round DOWN** to whole cm: `LWC_FedEx_API::floor_dimension_cm()` (`max(1, floor())`), used by both `normalize_package_override()` and `build_override_package()`. Weight is *not* floored (still `round(..., 2)`). The product-derived estimate (`cube_dimensions()`) also floors now — and needs an integer correction because `pow( $v, 1/3 )` lands just below exact cubes (2197 = 13³ → 12.999999999999998): `if ( pow( $side + 1, 3 ) <= $volume_cm3 ) { $side++; }`. This path also feeds checkout rate quotes.
- Origin: `LWC_FedEx_Shipper_Address` (optional custom address overriding the store address). Toggle `lwc_fedex_shipper_address_enabled` + `lwc_fedex_shipper_address_{name,company,phone,address_1,address_2,city,state,postcode,country}`. `FIELDS` is the single source of truth for labels/required/max lengths. `get_origin_summary()` feeds the metabox.
- **Two-tier failure policy (deliberate):** rates fall back silently to the store address so checkout never breaks; AWB/label/pickup are *blocked* via `validate_origin_for_shipment()`.
- Order #32 has an AWB without `item_ids` → its metabox always emits an unrelated `notice-warning`; assert on wording, not on presence.
- **Declared customs value = invoice grand total** (user decision). `LWC_FedEx_API::get_declared_value_total( $order )` (public, filter `lwc_fedex_declared_value_total`) returns `$order->get_total()` and feeds `customsValue`, `unitPrice` and `totalCustomsValue` — never derive the value from items again (the old per-item path ignored discounts/shipping and was inflated by `ceil()` in `round_for_currency`). Item selection only drives manifest `quantity` + `weight`. Partial shipments still declare the full invoice total (open question).
- `LWC_Currency_Converter::round_for_currency()` is `ceil()` to whole units, not per-currency decimals.

## Currency, promos, checkout
- Base IDR; configured `USD=15000`. Desty REST conversion lives in `LWC_Currency_Converter`, uses the frozen order rate.
- Promo % and shipping caps use separate meta keys; read via `LWC_Promo_Discounts::get_maximum_discount()`.
- Checkout uses the **Checkout Block** (React), so `woocommerce_before_checkout_form` never fires and `.lwc-checkout-promos` never exists there. `promo/promo-dashboard.js` injects into the first *visible* `.wc-block-components-totals-coupon`.
- WooCommerce scopes checkout with `@container` queries on `.wp-block-woocommerce-checkout` at 700px, not viewport media queries — match it or it desyncs. Below 700px a second summary (`checkout-order-summary-block-fill-wrapper`) appears; `promo/promo-dashboard.css` hides the sidebar title there.
- Customer-history metabox: `LWC_Currency_Converter` overrides `woocommerce/templates/order/customer-history.php` via `wc_get_template` (admin + resolvable order only) and re-aggregates via `get_customer_history_for_currency()`. JNE pickup sender name = site title, ≤50 chars.

## Blocksy Complement (separate plugin)
`lovecatz-blocksy-complement`, prefix `lbc_`, text domain `lbc-blocksy`, `LBC_VERSION`. Soft deps: Blocksy + Woo.
- **Blocksy discovers header/footer elements by directory scan.** `blocksy:header:settings` only adds panel options. Each element needs `blocksy-header-items/<id>/{config,options,view}.php` appended via `blocksy:header:items-root-paths`. Never hook `blocksy:header:items-paths` with a parent dir (globbed → unavailable elements leak).
- `view.php` is `require`d inside `blocksy_render_view()`'s `extract()` → sees `$atts`, `$device`, `$item_id`, `$panel_type`, `$section_id`, `$row_id`, `$attr`. Registry is a singleton because shims have no plugin handle.
- Item settings live in the `header_placements` theme mod as a flat `items` list of `{id, values}` per section — not standalone mods.
- Detection: `function_exists('blocksy_default_akg')`. Autoloader: `LBC_Foo_Bar` → `includes/class-lbc-foo-bar.php`, `LBC_Element_Baz` → `includes/elements/class-lbc-element-baz.php`.
- Child theme `wp-content/themes/blocksy-child-ddistillers/` carries the dark-mode variable bridge.
- **Harness safety:** never call `set_theme_mod()` — a crashed run once overwrote the site header config. Inject state via reflection; wrap theme switches in `register_shutdown_function()`.

## ddistillers theme (FSE) — design & editability
- Sections are **file-based block patterns** in `wp-content/themes/ddistillers/patterns/`, composed by `templates/front-page.html` via `wp:pattern`. 16 registered (15 sections + `page-home` full page).
- File-based patterns are **read-only in the Site Editor until "Detach pattern"**; the full-page pattern and per-section inserter entries are the editable routes.
- All colours are theme.json presets, read through `--wp--preset--color--*` custom props in `block-styles.css` → Styles → Colours re-skins everything. Never hard-code hex in patterns.
- Fonts declared twice on purpose: theme.json `fontFace` for the Site Editor, `functions.php` enqueue for the front end.
- `add_editor_style()` must list both `style.css` and `assets/css/block-styles.css` or the Site Editor is not WYSIWYG.
- Core's Shortcode block never runs `do_shortcode()` in block templates — `functions.php` filters `render_block_core/shortcode`. RFQ form is `[dd_rfq_form]` (renders once per request; a `static` guard returns '' on a second call).
- Traceability + Quality share one pattern (`traceability.php`); the `#quality` anchor sits on a nested group.
