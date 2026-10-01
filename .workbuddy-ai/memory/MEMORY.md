# Project memory — lovecatz-woocommerce-complement

## Environment and conventions
- WP: `C:\laragon\www\ddistillers`; PHP CLI: `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`; WooCommerce 11.1.1.
- MySQL may be stopped. Start `mysqld.exe --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini"` in background; verify on 127.0.0.1:3306.
- Test harnesses belong in gitignored `tmp/`, use DB transaction/rollback, and must be deleted after verification.
- WordPress style: tabs, `lwc_` prefix, text domain `lovecatz-wc`. Bump plugin header and `LWC_VERSION` together.
- **Never tell the user to deactivate/reactivate the plugin.** Nothing is gated behind `register_activation_hook`: `lwc_install()` is hooked to `admin_init` and self-heals whenever `lwc_schema_version !== LWC_VERSION` (so a manual file upload migrates on the next wp-admin page view), and `lwc_maybe_flush_rewrite_rules()` self-heals on `init` by comparing `lwc_coupon_endpoint_rewrite_version` with `LWC_VERSION`. Only the DB/schema steps need wp-admin; options-only features (e.g. the FedEx custom shipper address) are live as soon as the files are uploaded.
- Per-feature toggles are the real "off" switch, not activation. Order IDs: `lwc_order_number_enabled` (default `no`) under LoveCatz → **Order** tab → "Custom Order ID". FedEx custom shipper address: `lwc_fedex_shipper_address_enabled` (default `no`) under LoveCatz → **Shipping → FedEx**.

## Custom order IDs
- Runtime: `includes/core/class-lwc-order-number.php`; admin correction UI: `includes/admin/class-lwc-order-number-admin.php` plus matching JS/CSS.
- Format: prefix + date + Local/Global label + atomic per-scope sequence + optional suffix. Country resolution: shipping → billing → store base; `ID` is Local.
- Primary assignment hook is `woocommerce_before_order_object_save`; defensive hooks are `woocommerce_new_order` and `woocommerce_process_shop_order_meta`. `woocommerce_order_number` is display-only and must never assign from a read. Never assign while destination is unknown on a new/draft order.
- Meta: `_lwc_order_number`, `_lwc_order_number_scope`. Existing IDs are never automatically changed. `lwc_order_number_enabled_at` prevents old orders being numbered later; missing timestamps self-heal with a one-day grace window.
- Counter uses one SQL atomic increment per scope. Do not replace with PHP read/modify/write. New candidates are duplicate-checked and advance again after a collision.
- Admin corrections are allowed on every order, reject duplicates across HPOS/CPT, preserve the WooCommerce internal ID, and re-derive scope. An empty correction keeps the current custom ID.
- WooCommerce test trap: reload an auto-draft with `wc_get_order()` before simulating its next save; reusing the unread object does not record changes correctly.

## Shipping and AWB policy
- `LWC_AWB_Automation` alone owns `woocommerce_order_status_processing`. Automatic: J&T Express and RaySpeed. FedEx is always manual because ship date, carton dimensions/weight, and DESC1 are fixed at AWB creation. J&T Cargo has no automatic creation path.
- Courier switching uses the buyer shipping address; submitted address is authoritative. Incomplete address suppresses live tariff couriers. Admin rate conversion must temporarily detach cart currency conversion.
- J&T route mapping requires province + city + district. Sandbox tariff `11111 × weight` is a provider stub.

## J&T Cargo (OpenAPI)
- Official manual: `E:\Downloads\JTC_API_Integration_Manual_251208_121336 (1).pdf` (20 pages; extract with pypdf in the managed venv). It is the only source of truth for field names — never guess them.
- Add Order customer order number is **`txlogisticId`** (`String(50)`, required), not `customerOrderNo`/`orderNo`. Error `145003082` = empty or too long.
- Other required bizContent fields: `expressType`(`FT`), `orderType`(`1`/`2`), `serviceType`(`01`/`02`), `deliveryType`(`101`), `payType`(`PP_PM`/`CC_CASH`/`PP_CASH`), `sender{}`, `receiver{}`, `goodsType`(`bm000001..bm000011`), `weight` String(12) **min 1 KG**, `offerFee`. `totalQuantity` is the number of **koli**, not items.
- Party fields: name(32) mobile(30) countryCode(20, `IDN`) prov(32) city(32) area(32) address(150). Response type is documented as "JSON (URL-encoded)".
- Signing: header digest = `base64(md5_raw(bizContent + privateKey))`; body digest = `base64(md5_raw(customerCode + UPPER(md5(pwd + 'jadada236t2')) + privateKey))` — 24 chars, independent of the payload. Do not "fix" the doc's `String(30)`.
- `LWC_JTC_API::send()` is the only canonical path: one array → one `wp_json_encode` → digest over that exact string → one `bizContent` form field. The raw body (it holds customerCode + digest) is never returned in the envelope; only `biz_content_sha256`/`biz_content_length`.
- `LWC_JTC_Order` owns mapping/validation/response classification; `LWC_JTC_API` owns credentials. Customer order number lives in `_lwc_jtc_txlogistic_id` and is reused on retry. Debug log: option `lwc_jtc_debug`, source `lwc-jtc`, credentials masked.
- J&T Cargo is never auto-created (`LWC_AWB_Automation` excludes it); the order-screen metabox is manual.

## FedEx
- `LWC_FedEx_API::get_shipment_coverage()` is the sole source of item coverage for both UI and handler. An active legacy AWB without `item_ids` blocks all new labels.
- Printed `DESC1` comes from the first customs commodity description. International selected items are aggregated into one commodity.
- Ship date, carton measurements and label description cannot be edited after AWB creation; cancel and recreate.
- **Origin/shipper address** is resolved by `LWC_FedEx_Shipper_Address` (`shipping/fedex/class-lwc-fedex-shipper-address.php`), an optional custom address that overrides the WooCommerce store address on rates, shipments, labels, AWBs, and pickups. Toggle `lwc_fedex_shipper_address_enabled`; parts under `lwc_fedex_shipper_address_{name,company,phone,address_1,address_2,city,state,postcode,country}`. `LWC_FedEx_API::resolve_origin_address()`/`build_shipper_contact()` fall back to store values when the toggle is off or the address is incomplete.
- **Two-tier failure policy (deliberate, do not "simplify"):** rate quotes fall back silently to the store address so checkout never breaks; AWB/label creation and pickups are *blocked* via `validate_origin_for_shipment()` with an actionable message, because the toggle's whole purpose is that the custom origin is the one printed on the label.
- `LWC_FedEx_API::get_origin_summary()` is public and feeds the "Shipping from …" line + warning notice in the FedEx order metabox.
- `FIELDS` in `LWC_FedEx_Shipper_Address` is the single source of truth for labels, required flags, and FedEx max lengths; the settings UI and payload builders both derive from it.
- Order #32 carries an AWB without `item_ids`, so its metabox always emits an unrelated `notice-warning`. Tests must assert on the custom-address wording, not on the presence of `notice-warning`.

## Currency, promos, integrations
- Base currency IDR; configured `USD=15000` means USD→IDR multiplies by 15000. Desty REST conversion belongs in `LWC_Currency_Converter` and uses the frozen order rate when available.
- Promo percentage and shipping caps use separate meta keys; always read through `LWC_Promo_Discounts::get_maximum_discount()`.
- JNE 1.9.6 pickup sender name is the WordPress site title and must be ≤50 chars; fix the title, not the third-party plugin.
- Checkout uses the **WooCommerce Checkout Block** (client-rendered React), so `woocommerce_before_checkout_form` never fires and `.lwc-checkout-promos` never exists there. The promo picker is injected by `promo/promo-dashboard.js` into the first **visible** `.wc-block-components-totals-coupon`.
- Below a 700px **container** width (theme container ≈ 90% of viewport) WooCommerce renders a second order summary, `checkout-order-summary-block-fill-wrapper`, inside the actions block; above it, that wrapper is `display:none` while React may still render it. Never attach the picker to a container without checking visibility.
- WooCommerce scopes checkout layout with `@container` queries against `.wp-block-woocommerce-checkout` (`container-type: inline-size`), not viewport media queries. Any matching override in this plugin must use a container query at the same 700px breakpoint or it will desync.
- On mobile the sidebar (`wc-block-checkout__sidebar`) gets `order:0`, so its order-summary title would show as a redundant heading at the top; `promo/promo-dashboard.css` hides it below 700px.
- **Customer history metabox** (`woocommerce/templates/order/customer-history.php`) used to render in the store base currency even when the customer paid in USD. `LWC_Currency_Converter` now overrides it via the `wc_get_template` filter (only when `is_admin()` and a current order resolves from `$_GET['post'|'id']` or `$_POST['post_ID']`). New helper `get_customer_history_for_currency($order, $currency)` re-aggregates the customer's paid orders in the requested currency (HPOS: `wc_orders` SQL with `currency` filter; CPT: `wc_get_orders(['currency'=>...])`) and the override template passes `currency` to `wc_price()`. Order totals stay aligned with the buyer's checkout currency and the existing `wp_wc_order_stats` repair is left alone (it remains a defensive cleanup for the rare prior stage).

## Blocksy Complement (separate plugin)
- Plugin `lovecatz-blocksy-complement` (NOT merged into this plugin).
  Prefix `lbc_`, text domain `lbc-blocksy`, version constant `LBC_VERSION`.
  Soft dependencies: Blocksy (theme) and WooCommerce.
- **Blocksy discovers header/footer elements by scanning directories**, not
  via a registry filter. `blocksy:header:settings` only carries panel-level
  extra options — it does NOT add palette elements. Each element needs
  `blocksy-header-items/<id>/{config,options,view}.php`, appended through
  the `blocksy:header:items-root-paths` filter. Do NOT hook
  `blocksy:header:items-paths` with a parent dir: it is globbed, so
  unavailable elements would leak into the palette.
- `view.php` is `require`d inside `blocksy_render_view()`'s `extract()`, so
  it sees `$atts`, `$device`, `$item_id`, `$panel_type`, `$section_id`,
  `$row_id`, `$attr`. That is why `LBC_Elements_Registry` is a singleton —
  the shim files have no plugin handle.
- Element classes in `includes/elements/` are the single source of truth;
  the directory shims are thin delegators. Registered explicitly from the
  main bootstrap before `LBC_Plugin::init()`.
- Blocksy stores item settings inside the `header_placements` theme mod as
  a flat `items` list of `{ id, values }` per section — NOT standalone theme
  mods. Read via `LBC_Elements_Registry::get_item_values()`.
- Blocksy Free already supports multiple header sections (customizer JS
  `CREATE_NEW_SECTION`, ids `ct-custom-*`). Only conditional display is
  Pro-only; `LBC_Header_Instances` implements that alone.
- Blocksy detection: `function_exists('blocksy_default_akg')` — there is no
  `blocksy_get_theme_options()`.
- File convention: `class-lbc-{name}.php`; autoloader maps `LBC_Foo_Bar`
  to `includes/class-lbc-foo-bar.php` and `LBC_Element_Baz` to
  `includes/elements/class-lbc-element-baz.php`.
- Child theme: `wp-content/themes/blocksy-child-ddistillers/`
  (`Template: blocksy`), carries the Blocksy dark-mode variable bridge.
- **Test harness safety (learned the hard way):** never call
  `set_theme_mod()` in a harness — a crashed run permanently overwrote the
  site's Blocksy header config. Inject synthetic state via reflection and
  assert the DB is untouched. Wrap any theme switch in
  `register_shutdown_function()` so a fatal cannot leave the site switched.
  There is no public `Blocksy_Header_Builder::wipe_cache()`; its
  `section_value`/`current_section` are private.
- Smoke tests use raw PHP `wp-load.php` + `do_action('plugins_loaded')`
  (no WP-CLI installed). Blocksy's `inc/init.php` needs Blocksy active
  because it resolves paths via `get_template_directory()`.
- Site's Blocksy header config (in `theme_mods_blocksy`): one section
  `type-1`; `top-row/end = [text]` holding `[lwc_currency_switcher]`,
  `middle-row/start = [logo]`, `middle-row/end = [menu, search]`, mobile at
  Blocksy defaults. No nav menus are registered on the site.
- Active theme remains `ddistillers` (FSE block theme); the plugin is left
  inactive. Blocksy is a separate, currently unused track.
