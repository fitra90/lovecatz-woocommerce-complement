# LoveCatz WooCommerce Complement — Product Requirements and Design

Last code review: 2026-08-21
Current plugin version: 1.0.22

## Purpose

This document describes the behavior implemented by the current repository. Treat the PHP code as authoritative if it and this document disagree.

The plugin extends WooCommerce with:

- an admin-managed promo catalog and customer coupon dashboard;
- per-product purchase quantity limits;
- member import, management, printable cards, and customer card access;
- native LoveCatz FedEx live rates, order-screen AWB labels, and tracking;
- a built-in manual currency converter that coexists with external plugins;
- J&T settings and a provisional flat-rate method;
- a configurable LoveCatz admin workspace.

## Requirements

- WordPress 5.8+, WooCommerce 6.0+, and PHP 7.4+.
- OpenSSL is recommended for credential encryption.
- `ZipArchive` is required for XLSX import/template handling.
- Binary XLS import requires Windows COM and Excel; XML Spreadsheet XLS does not.

The plugin exits early and shows an admin notice when WooCommerce is inactive.

## Current layout

- `lovecatz-woocommerce-complement.php` — bootstrap, lifecycle, global hooks, credential helpers, and FedEx AJAX.
- `includes/core/` — loader, logger, and built-in currency converter.
- `includes/admin/` — active settings and membership implementation plus admin assets.
- `products/` — quantity limits.
- `promo/` — promo administration and customer/checkout integration.
- `shipping/fedex/` — account storage, REST client, native method, and order-screen label controls.
- `shipping/jt/` — per-provider account storage and the provisional J&T Express / J&T Cargo methods.
- `membership/` — duplicate/alternate membership classes which `LWC_Core` does not instantiate.
- `assets/` — logo and default promo artwork.
- `tasks/` — implementation and QA notes.

Older references to `includes/promo`, `includes/products`, and `includes/shipping` are obsolete. If loaded files move, update the bootstrap and core loader together.

## Bootstrap and lifecycle

At `plugins_loaded` priority 20, the bootstrap checks WooCommerce, loads required classes, constructs `LWC_Core`, and initializes features.

Activation creates `{prefix}lwc_fedex_accounts`, `{prefix}lwc_jt_express_accounts`, and `{prefix}lwc_jt_cargo_accounts`, registers the `coupon` endpoint, flushes rewrite rules, and stores `lwc_coupon_endpoint_rewrite_version`. Normal `init` re-flushes if the stored version or endpoint rule is stale.

Lifecycle guarantees:

- **Activation** is idempotent (`lwc_install()`): tables are created only when missing, `dbDelta` adds columns introduced by newer versions, and legacy J&T credentials migrate once. A stored `lwc_schema_version` gates the work; `admin_init` re-runs the installer whenever the plugin version changes, so schema upgrades apply even if the activation hook was skipped.
- **Deactivation** deletes nothing — tables, options, order meta, and label files are preserved so reactivation resumes exactly where things stopped; only rewrite rules are flushed.
- **Uninstall** removes everything: all four account tables (including legacy), every `lwc_*` option, `lwc_*` transients plus timeouts, `_lwc_*` post meta and HPOS order meta, the `lwc_customer_id` user meta (WooCommerce's own address fields are untouched), and generated `fedex-label-*.pdf` files in uploads.

Uninstall currently drops only the two account tables. It does not remove options, metadata, coupons, generated labels, or other uploads.

## Admin workspace

The top-level menu requires `manage_woocommerce`; its label and Dashicon are configurable.

- **Setting** — menu title and icon.

The sidebar menu expands into one submenu entry per main tab (Setting, Products, Members, Shipping, Promo, Currency); each links straight to that tab via `?page=lovecatz-wc&tab=…`, and the open tab highlights its entry through a `submenu_file` filter.
- **Products** — quantity-limit feature switch.
- **Members** — import, list, delete, and print cards.
- **Shipping** — J&T and FedEx settings.
- **Promo** — coupon create/edit/list/trash workspace.
- **Currency** — built-in currency converter (enable switch and manual rates).

## Promo coupons

Promos use native `WC_Coupon` records so WooCommerce remains responsible for ordinary validation and usage tracking. The manager supports percentage and fixed-cart discounts, expiry, total/per-user limits, individual use, selected users, percentage maximum caps, and active/disabled images. Mutations require `manage_woocommerce` and nonces.

Metadata:

- `_lwc_promo_created` — customer-dashboard marker;
- `_lwc_promo_eligible_user_ids`;
- `_lwc_promo_maximum_discount`;
- `_lwc_promo_active_image_id`;
- `_lwc_promo_disabled_image_id`.

Selected users are also saved as WooCommerce email restrictions. The percentage cap is enforced through `woocommerce_coupon_get_discount_amount`.

Customer surfaces are `/my-account/coupon/`, the **Coupon** account-menu item, `[lwc_promo_dashboard]`, cart/checkout promo cards, and Checkout Block selection. The dashboard requires login, queries marked LoveCatz promos, evaluates eligibility/expiry/usage, and renders active or disabled cards. WooCommerce performs final application validation. The checkout coupon modal is portalled directly under `<body>` with a viewport-level overlay so payment buttons and third-party iframes cannot paint above it. Missing artwork uses `assets/2026_VOUCHER-REORDER_FINAL.webp`.

Known gaps:

- The Promo admin list queries every published WooCommerce coupon, not only marked LoveCatz promos. It can therefore edit or trash ordinary coupons.
- Legacy option-based automatic coupon creation/loading helpers remain in `LWC_Promo_Dashboard`. New work should use the managed catalog.

## Product quantity limits

`lwc_enable_product_quantity_limits` enables the feature. Per-product metadata is `_lwc_minimum_quantity` and `_lwc_maximum_quantity`.

The implementation adds Inventory and Quick Edit controls, adjusts single-product inputs, validates add-to-cart/cart updates, and checks existing cart items before cart/checkout. It handles product/variation resolution where applicable.

Limits are per-product only. Previously documented global default min/max options are not active.

## Membership and import

The active implementation is in `LWC_Admin_Settings`. It supports CSV, XLSX, XML Spreadsheet XLS, and—when COM/Excel is available—binary XLS. It accepts legacy/current aliases including Odoo-style `External ID`, `Name`, `Contact Name`, `Street`, and `Mobile`.

Rules and behavior:

- customer/external ID is required and becomes the username;
- imported users receive the WooCommerce `customer` role;
- a random password and standard new-user notification are generated;
- missing/invalid email becomes a unique `@no-email.local` address;
- billing/shipping identity and address metadata are populated;
- the source ID is stored as `lwc_customer_id`;
- duplicates and invalid/missing IDs are skipped;
- imports create users and do not update existing users;
- admins can list/delete members and print cards;
- customers get a card button on their account dashboard;
- printable cards use an external QR-image URL.

`membership/class-lwc-membership-admin.php` and `membership/class-lwc-admin-members.php` substantially duplicate active behavior but are not initialized. Consolidate before extending membership.

## Shipping

### Credentials

FedEx and J&T secrets are encrypted on option save and decrypted through option filters; legacy plaintext remains readable. Account classes synchronize options to custom tables.

Encryption uses AES-256-CBC with a key and deterministic IV derived from the WordPress auth salt. It obscures database values but is not authenticated encryption and lacks a random per-value IV.

### Native LoveCatz FedEx

The `lwc_fedex` zone method performs live REST quotes at checkout: it builds a rate request from the store origin, the package destination, and real cart weight (per item × quantity), authenticates with a cached OAuth token (~1 hour), calls `/rate/v1/rates/quotes`, and caches successful quotes per request payload for 30 minutes (`lwc_fedex_rate_cache_ttl` filter). No `serviceType` is sent, so FedEx returns every applicable service; each enabled service becomes its own checkout option. When no service matches or the live quote fails, an optional flat fallback rate is offered (enabled by default, cost configurable); disabling the fallback hides the method instead.

### RaySpeed RETAIL Sandbox

The `lwc_rayspeed` method is available only for destinations outside Indonesia. It posts the destination, actual cart weight, longest product dimension, category, content type, origin, and Regular/Express selection to the RaySpeed Sandbox pricing endpoint and publishes the returned IDR price with lead time at checkout. It is injected globally like FedEx, so no shipping-zone setup is required. The Shipping page has separate FedEx, J&T, and RaySpeed provider tabs.

International order screens include a RaySpeed panel for creating one development AWB through `awb_post.php` and refreshing its tracking history through `current.php`. The AWB and tracking events are stored in `_lwc_rayspeed_awb` and `_lwc_rayspeed_tracking`. RaySpeed documentation provides no label-download endpoint, so this integration does not claim to generate a printable label. Production endpoints and credentials must be obtained from RaySpeed before live fulfillment is enabled.

FedEx is managed entirely from the plugin's Shipping tab — no WooCommerce shipping zone setup required:

- **Enable FedEx** (`lwc_fedex_enabled`, default on) — a checkbox toggle on the settings page gates every rate path (`calculate_shipping`, `is_available`, and the global injector).
- **API environment** (`lwc_fedex_environment`) — Sandbox and Production each retain their own account number, API key, and API secret. Switching the environment immediately selects the matching stored account for rates, labels, and shipments; legacy single-account credentials remain available as a migration fallback.
- **Worldwide availability without zones** — the method hooks `woocommerce_shipping_packages` and appends its rates to every cart package whenever a zone instance isn't already providing `lwc_fedex` rates, so it works out of the box for all destinations.
- **Max package weight** (`lwc_fedex_max_package_weight_kg`, default 10, clamped 0–68) — drives package splitting for rating and labels and travels via rate meta to label creation.
- **Service types** (`lwc_fedex_services`, multiselect from the `lwc_fedex_available_services` catalog; empty selection falls back to Ground/Express Saver/International Economy/Priority).

Only display options (method title/description) and the fallback rate remain per-instance in WooCommerce.

Cartons are derived from product data: items are grouped into packages that respect a per-instance max package weight (kg; 0 disables splitting), and package dimensions come from the cube root of the summed item volume when products have dimensions set. The same packages drive rating and label creation. The split threshold is hard-capped at the FedEx parcel ceiling of 68 kg (150 lbs) — `lwc_fedex_package_weight_ceiling_kg` filter; heavier loads require FedEx Freight, which is out of scope.

Checkout currency handling uses the global currency owner. The built-in LoveCatz converter converts every shipping method when enabled; when CURCY or another supported external converter is active, LoveCatz steps aside and that converter handles the native FedEx rate.

The connection check in Settings → Shipping → FedEx performs separate real OAuth handshakes for the stored Sandbox and Production credentials. Its combined status pill is green only when both environments connect, red when either handshake fails, and orange when either credential set is incomplete.

Order edit screen adds a **FedEx Shipping** side metabox (`LWC_FedEx_Order_Admin`) with:

- **Test rate quote** — live quote auto-filled from the order ship-to address;
- **Create FedEx label** — creates the shipment and shows the tracking number;
- **Download label (AWB)** — streams the stored PDF once generated.

Shipment creation sends a complete REST v1 payload: shipper/recipient contacts and full street addresses, ship date, `shippingChargesPayment` (SENDER + account), PDF label specification, and — for international destinations — `customsClearanceDetail` built from order items (descriptions, quantities, unit prices, customs values in order currency, weights, and a country of manufacture defaulting to the store base country — filterable per item via `lwc_fedex_commodity_country_of_manufacture`) with duties payment defaulting to RECIPIENT (`lwc_fedex_duties_payment_type` filter) and terms of sale defaulting to DAP (`lwc_fedex_terms_of_sale` filter). The service type and package weight come from the shipping option the customer chose at checkout (rate meta transferred to the order), falling back to the country defaults. The label PDF is stored in uploads, the tracking number saved to `_lwc_fedex_tracking_number`, and an order note added. Shipper contact details come from the `lwc_fedex_shipper_name` and `lwc_fedex_shipper_phone` options (phone required by FedEx).

Authenticated AJAX actions are `lwc_check_fedex_connection`, `lwc_fedex_get_rate_quote`, `lwc_fedex_create_shipment`, and `lwc_fedex_download_label`. All require `manage_woocommerce` and the FedEx nonce. The create-shipment response omits the raw API body and returns the tracking number plus a nonce-protected download URL. Label download normalizes and constrains paths to the uploads directory.

Order fulfillment metaboxes are provider-specific: FedEx controls and assets load only when an order shipping item has method ID `lwc_fedex`, while RaySpeed controls and assets load only for `lwc_rayspeed`. J&T orders therefore do not display unrelated FedEx or RaySpeed settings. This routing reads the order's shipping items and supports both classic and HPOS order screens.

### Built-in currency converter

`LWC_Currency_Converter` (Currency tab) switches the shop between the base currency and manually configured targets. Rates use one line per currency, `CODE=rate`, where rate is base-currency units per one unit of the target (`USD=16500` means 1 USD = 16,500 IDR; conversion divides). Shoppers switch with `?currency=USD`, persisted in a 30-day `lwc_currency` cookie.

When active it overrides `woocommerce_currency`, maps symbols for common currencies, matches price decimals to the selected currency (zero-decimal currencies such as IDR round to integers), converts product/variation prices and every shipping rate cost/tax via `woocommerce_package_rates`, and recalculates cart totals after a switch. It steps aside automatically when an external converter is detected (CURCY/woo-multi-currency, WOOCS, Aelia, WPML, YayCurrency; filterable via `lwc_currency_external_converter_active`), so conversion never happens twice.

Shared helpers used across shipping: `LWC_Currency_Converter::round_for_currency()` and `get_currency_decimals()` round provider amounts to the active currency's decimals — integer-safe for providers that reject decimals (J&T whole-rupiah amounts) while keeping two decimals for USD-like currencies.

Options: `lwc_currency_enabled`, `lwc_currency_rates`.

### J&T Express and J&T Cargo

J&T is split into two independent providers, each with its own credentials, zone method, and weight rules:

- **`lwc_jt_express`** (`LWC_Shipping_JT_Express`) — regular parcels; auto-split threshold default 10 kg, hard ceiling 100 kg (`lwc_jt_express_package_weight_ceiling_kg` filter).
- **`lwc_jt_cargo`** (`LWC_Shipping_JT_Cargo`) — large/heavy shipments (10 kg minimum billable, tiers H50–H500); auto-split off by default, ceiling 500 kg (`lwc_jt_cargo_package_weight_ceiling_kg` filter).

Both extend `LWC_Shipping_JT_Base` and retain independent configuration. Rates are rejected unless the destination country is Indonesia (`ID`). The legacy `lwc_jt` method id aliases to Express so existing zone instances keep working. J&T Express and J&T Cargo appear as independent Shipping provider tabs. Each has its own active Sandbox/Production selector and isolated credential namespace. Express stores the distinct Order, Tariff, Tracking, and Cancellation credentials required by J&T Indonesia; Cargo retains a generic independent account form until its API contract is supplied. Sandbox endpoint URLs are backend constants rather than editable settings; production endpoints are injected later via the `lwc_jt_express_production_endpoints` filter. Settings contain no direct API test actions. Legacy pre-split credentials migrate to Express once.

When checkout contains two or more shipping choices, the frontend progressively enhances each shipping-method list into a compact accordion. Its header displays the selected courier, expands accessibly to show all choices, and closes after selection. The enhancement is reapplied after classic checkout AJAX refreshes and WooCommerce Blocks DOM updates; a single shipping choice remains unchanged.

J&T Express is a live sandbox integration. At checkout it calls the J&T Tariff endpoint and publishes only services returned by the API; there is no configurable or synthetic checkout-rate fallback. Sandbox routing codes are backend-owned constants. Production endpoints and official mapped routes are supplied through backend filters and fail closed when unavailable. The selected environment, service, route, weight, and live-rate source are copied to private shipping-item metadata.

Every shipping-provider settings page shows an automatic connection indicator when opened and rechecks after credential edits. FedEx authenticates both OAuth environments, J&T Express performs a non-mutating Tariff authentication check per environment while separately requiring every Order/Tracking/Cancellation field, and RaySpeed authenticates its Sandbox key. J&T Cargo is explicitly marked unverifiable until its independent API contract and endpoints are supplied; credential completeness alone is never presented as a successful connection.

When a J&T Express order enters **Processing**, `LWC_JT_Order_Admin` validates store and recipient fields, creates the J&T order exactly once, saves the AWB/order ID/ETD, and immediately requests tracking. Sender name/address come from WooCommerce store data, sender phone uses the shared store contact, and the backend `lwc_jt_express_shipper` filter can override them without adding J&T form fields. Failures are retained in order metadata and order notes without issuing duplicate orders on later status changes. The provider-specific order metabox offers retry, tracking refresh, and cancellation controls; AWB and stored tracking events are also displayed to the customer. Classic checkout and Checkout Block both require the recipient phone and postcode when J&T is selected. J&T Cargo remains provisional and has no Express API coupling.

### Manual partial shipping (FedEx)

The order-screen metabox lists every line item with a checkbox. Creating a label ships only the checked items — leave all checked for a full shipment, uncheck some to split manually even below any threshold. Items already covered by a previous AWB are marked *already shipped* and locked. Every created shipment appends to `_lwc_fedex_shipments` (tracking number, label file, item IDs, timestamp) with per-shipment download links; the latest tracking/label also update the legacy single-shipment meta. Packages and customs commodities are built from the selected items only.

## Data model

- Options: `lwc_menu_*`, `lwc_enable_product_quantity_limits`, `lwc_jt_{express|cargo}_*` (plus legacy `lwc_jt_*`), `lwc_fedex_*` (including `lwc_fedex_shipper_name` and `lwc_fedex_shipper_phone`), `lwc_currency_enabled`, `lwc_currency_rates`, rewrite-version, and legacy promo options.
- Tables: `{prefix}lwc_fedex_accounts`, `{prefix}lwc_jt_express_accounts`, `{prefix}lwc_jt_cargo_accounts` (legacy `{prefix}lwc_jt_accounts` dropped on uninstall).
- Product meta: `_lwc_minimum_quantity`, `_lwc_maximum_quantity`.
- User meta: `lwc_customer_id` plus WooCommerce billing/shipping fields.
- Coupon meta: LoveCatz promo fields listed above.
- Order meta: `_lwc_fedex_label_path`, `_lwc_fedex_tracking_number`, `_lwc_jt_awb`, `_lwc_jt_order_id`, `_lwc_jt_etd`, `_lwc_jt_tracking`, `_lwc_jt_create_error`, `_lwc_jt_tracking_error`, `_lwc_jt_cancelled`.

## Known architecture issues

- Membership logic is duplicated across three classes.
- Shipping methods are registered in the bootstrap and `LWC_Core`, with additional bootstrap aliases. Preserve persisted IDs during consolidation.
- `LWC_Shipping_Provider` exists, but active providers extend `WC_Shipping_Method` directly.
- Promo administration lists all coupons while customer presentation filters by the LoveCatz marker.
- Legacy auto-promo behavior coexists with the managed catalog.
- README claims/dates do not fully reflect active behavior.
- No repository-level WordPress/WooCommerce integration suite is visible.

## QA checklist

- Activate with/without WooCommerce; verify tables, notice, endpoint, and permissions.
- Test promo types, expiry, limits, eligibility, images, maximum cap, classic checkout, and Checkout Block.
- Ensure ordinary coupons are not unintentionally modified through LoveCatz.
- Test simple/variable product limits through product, cart, Quick Edit, and checkout.
- Test current/legacy member files, duplicates, missing IDs, invalid emails, metadata, notifications, deletion, cards, and QR rendering.
- Test native FedEx auth, quotes, fallback, shipment, and authorized label download.
- Verify multi-service checkout: several FedEx options appear with distinct labels and prices; the chosen service is the one used on the created AWB.
- Verify package splitting: a cart heavier than the max package weight quotes/labels multiple packages; products without dimensions still rate correctly.
- On the order screen: verify the quote button uses the ship-to address, label creation stores tracking + note, and the download link streams the PDF only for permitted users.
- Test native FedEx currency paths: base IDR checkout (integer amounts), USD via CURCY, and USD via the built-in converter — confirm no double conversion and correct decimals in each.
- Verify manual partial shipping: uncheck an item, create the AWB, confirm the shipment history grows, the item is marked shipped, and each label downloads individually.
- Verify J&T Express end to end: live tariff at checkout, required recipient data, transition to Processing, one AWB only, immediate/manual tracking, cancellation, and customer tracking output. Keep Cargo isolated and provisional.

## Priorities

1. Complete live native FedEx staging verification.
2. Restrict Promo administration to marked coupons or deliberately document all-coupon management.
3. Remove/migrate the legacy auto-promo path.
4. Consolidate membership classes.
5. Consolidate shipping registration without changing persisted IDs.
6. Implement or remove the Currency placeholder.
7. Add the official J&T Production endpoints and complete postcode/area mappings before enabling Production.
8. Define uninstall retention for options, metadata, coupons, tables, and labels.
