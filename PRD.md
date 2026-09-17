# LoveCatz WooCommerce Complement — Product Requirements and Design

Last code review: 2026-09-11
Current plugin version: 1.0.66

## Purpose

This document describes the behavior implemented by the current repository. Treat the PHP code as authoritative if it and this document disagree.

The plugin extends WooCommerce with:

- an admin-managed promo catalog and customer coupon dashboard;
- per-product purchase quantity limits and out-of-stock pre-orders;
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
- `products/` — quantity limits and pre-order behavior.
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
- **Products** — quantity-limit and pre-order feature controls.
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

The implementation adds Inventory and Quick Edit controls, adjusts single-product inputs, validates add-to-cart/cart updates, and checks existing cart items before cart/checkout. It handles product/variation resolution where applicable. Configured minimum and maximum values are also exposed as visible cart-item data in both classic templates and WooCommerce Cart/Checkout Blocks. Quantity-input filters must preserve WooCommerce's `max_value` key and use its unlimited sentinel so Store API responses remain warning-free.

## Product pre-orders

`lwc_enable_product_preorder` enables pre-orders for products whose current stock status is out of stock or on backorder. `lwc_preorder_all_products=yes` covers the catalog through one flag without persisting every product ID. When that flag is off, `lwc_preorder_product_ids` stores only products chosen through WooCommerce's AJAX product search; variations inherit their parent's eligibility.

Eligible out-of-stock products use WooCommerce's native backorder-safe purchase path, display `Available for pre-order` and a `Pre-order` button, and remain unchanged while in stock. Cart and Checkout Blocks expose the pre-order status, and an immutable `_lwc_preorder` order-item marker plus a visible order-item label and order note carry the fulfillment warning into admin.

Limits are per-product only. Previously documented global default min/max options are not active.

## Membership and import

The active implementation is in `LWC_Admin_Settings`. It supports CSV, XLSX, XML Spreadsheet XLS, and—when COM/Excel is available—binary XLS. It accepts legacy/current aliases including Odoo-style `External ID`, `Name`, `Contact Name`, `Street`, and `Mobile`.

Rules and behavior:

- a valid, unique email and a name are required; every other column may be empty, and rows whose email already exists are skipped;
- username may come from `Username`, customer/external ID, or the email local part, and is suffixed when necessary to remain unique;
- each row can select any currently editable WordPress/WooCommerce role, with an admin-selected default when `Role` is empty;
- the generated XLSX template contains the standard user fields and a role dropdown populated from the site's editable roles;
- new users receive the administrator-configured default import password and the standard new-user notification;
- billing/shipping identity and address metadata are populated;
- the optional source ID is stored as `lwc_customer_id`;
- imports create users and do not update existing users;
- admins can list/delete members and print cards;
- customers get a card button on their account dashboard;
- printable cards use an external QR-image URL.

`membership/class-lwc-membership-admin.php` and `membership/class-lwc-admin-members.php` substantially duplicate active behavior but are not initialized. Consolidate before extending membership.

## Completed-order product reviews

The **Review** tab controls `lwc_product_review_enabled`. When enabled, the WooCommerce My Account orders table adds a **Write Review** column. A completed order owned by the logged-in customer shows one button for each unique, still-available product whose reviews are enabled; variations link to the parent product review section. Orders in every other status display a dash.

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
- **Tracking Production Credentials** — a separate account number, API key, and secret can be saved for the dedicated Basic Integrated Visibility project. Tracking OAuth uses this pair without changing the credentials used by rates, labels, shipment cancellation, or pickup. The key and secret are encrypted at rest; installations whose legacy project already includes Track API permission continue to fall back to the active FedEx credentials until the dedicated fields are populated.
- **Worldwide availability without zones** — the method hooks `woocommerce_shipping_packages` and appends its rates to every cart package whenever a zone instance isn't already providing `lwc_fedex` rates, so it works out of the box for all destinations.
- **Max package weight** (`lwc_fedex_max_package_weight_kg`, default 10, clamped 0–68) — drives package splitting for rating and labels and travels via rate meta to label creation.
- **Service types** (`lwc_fedex_services`, multiselect from the `lwc_fedex_available_services` catalog; empty selection falls back to Ground/Express Saver/International Economy/Priority).

Only display options (method title/description) and the fallback rate remain per-instance in WooCommerce.

Cartons are derived from product data: items are grouped into packages that respect a per-instance max package weight (kg; 0 disables splitting), and package dimensions come from the cube root of the summed item volume when products have dimensions set. The same packages drive rating and label creation. The split threshold is hard-capped at the FedEx parcel ceiling of 68 kg (150 lbs) — `lwc_fedex_package_weight_ceiling_kg` filter; heavier loads require FedEx Freight, which is out of scope.

Checkout currency handling uses the global currency owner. The built-in LoveCatz converter converts every shipping method when enabled; when CURCY or another supported external converter is active, LoveCatz steps aside and that converter handles the native FedEx rate.

The connection check in Settings → Shipping → FedEx performs separate real OAuth handshakes for the stored Sandbox and Production credentials and verifies the dedicated production tracking credentials against `/track/v1/trackingnumbers`. A deliberately nonexistent tracking number keeps the permission probe read-only while distinguishing an authorized endpoint response from HTTP 401/403 or FedEx authorization errors. Its combined status pill is green only when Sandbox, Production, and Basic Integrated Visibility connect; failures and incomplete credential sets remain explicit.

Order edit screen adds a **FedEx Shipping** side metabox (`LWC_FedEx_Order_Admin`) with:

- **Test rate quote** — live quote auto-filled from the order ship-to address;
- **FedEx service** — an always-available selector limited to FedEx International Priority and FedEx International Economy; it defaults to the matching service stored on the order, otherwise Economy, and does not require a prior rate quote;
- **Create FedEx label** — creates the shipment and shows the tracking number;
- **Download label (AWB)** — streams the stored PDF once generated.

Shipment creation sends a complete REST v1 payload: shipper/recipient contacts and full street addresses, ship date, `shippingChargesPayment` (SENDER + account), PDF label specification, and — for international destinations — `customsClearanceDetail` with one commodity for every selected order/package product (description, quantity, unit price, customs value in the order currency, weight, and a country of manufacture defaulting to the store base country and filterable per item via `lwc_fedex_commodity_country_of_manufacture`). The standard FedEx label derives `DESC1`–`DESC4` from those commodity descriptions and exposes no separate label-only description field. FedEx documents that human-readable content in the common label area cannot be altered, so the integration must not merge or falsify customs commodities merely to change the standard PDF; a fully custom label workflow is separate and outside this integration. Duties payment defaults to RECIPIENT (`lwc_fedex_duties_payment_type` filter) and terms of sale defaults to DAP (`lwc_fedex_terms_of_sale` filter). Shipment creation uses the explicitly selected International Priority or International Economy service from the order metabox; non-metabox callers retain the checkout-service/country-default fallback. Package weight comes from the shipping option selected at checkout unless an actual-carton override is supplied. Before label creation, the order metabox optionally accepts an actual packed-carton weight and a complete length/width/height set in centimeters. Any supplied values override product-derived estimates and intentionally produce one package line item; partial or nonpositive measurements are rejected server-side. The label PDF is stored in uploads, the tracking number saved to `_lwc_fedex_tracking_number`, and an order note added. Shipper contact details come from the `lwc_fedex_shipper_name` and `lwc_fedex_shipper_phone` options (phone required by FedEx).

Authenticated AJAX actions are `lwc_check_fedex_connection`, `lwc_fedex_get_rate_quote`, `lwc_fedex_create_shipment`, `lwc_fedex_cancel_shipment`, and `lwc_fedex_download_label`. All require `manage_woocommerce` and the FedEx nonce. The create-shipment response omits the raw API body and returns the tracking number plus a nonce-protected download URL. Shipment cancellation calls the FedEx Ship API with the stored tracking number, store-origin country, and `DELETE_ALL_PACKAGES`; local fulfillment state changes only after FedEx explicitly returns `cancelledShipment=true`. Label download normalizes and constrains paths to the uploads directory.

FedEx courier pickup is a two-step Production-only flow. **Check availability** sends the pickup address, requested date, ready/close times, carrier, domestic/international relationship, and the number of business days until dispatch using the Pickup Availability schema. **Request pickup** is enabled only after a matching successful check within 15 minutes and sends the account/address type, same-day/future-day type, on-call pickup type, package location, carrier-specific account type, package count, and total shipment weight using the Create Pickup schema. FedEx Express supports current-day or next-business-day pickup; unavailable windows and carrier validation failures must remain visible without creating local pickup state. The plugin stores a pickup only after FedEx returns a pickup confirmation number. That confirmation number is the operational pickup identifier and is displayed as **Pickup ID (confirmation)**; the FedEx Express location code is stored and displayed separately when returned because both values are required for cancellation. Existing pickup records that contain only `confirmation_number` remain compatible.

Order fulfillment metaboxes are provider-specific: FedEx controls and assets load only when an order shipping item has method ID `lwc_fedex`, while RaySpeed controls and assets load only for `lwc_rayspeed`. J&T orders therefore do not display unrelated FedEx or RaySpeed settings. This routing reads the order's shipping items and supports both classic and HPOS order screens.

### Built-in currency converter

`LWC_Currency_Converter` (Currency tab) switches the shop between the base currency and manually configured targets. Rates use one line per currency, `CODE=rate`, where rate is base-currency units per one unit of the target (`USD=16500` means 1 USD = 16,500 IDR; conversion divides). Shoppers switch with `?currency=USD`, persisted in a 30-day `lwc_currency` cookie.

When active it overrides `woocommerce_currency`, maps symbols for common currencies, matches price decimals to the selected currency (zero-decimal currencies such as IDR round to integers), converts product/variation prices and every shipping rate cost/tax via `woocommerce_package_rates`, and recalculates cart totals after a switch. It steps aside automatically when an external converter is detected (CURCY/woo-multi-currency, WOOCS, Aelia, WPML, YayCurrency; filterable via `lwc_currency_external_converter_active`), so conversion never happens twice.

Shared helpers used across shipping: `LWC_Currency_Converter::round_for_currency()` and `get_currency_decimals()` round provider amounts to the active currency's decimals — integer-safe for providers that reject decimals (J&T whole-rupiah amounts) while keeping two decimals for USD-like currencies.

Options: `lwc_currency_enabled`, `lwc_currency_rates`.

### International order handling

`LWC_International_Order_Fee` (Payment tab) adds an optional non-taxable checkout fee for international handling. Administrators can trigger it when the active checkout currency is USD, when the shipping country is outside Indonesia, or combine both enabled triggers with OR/AND matching. The fee can be a percentage of discounted cart contents plus taxes and shipping, or a fixed amount configured in the WooCommerce base currency. Eligibility never reads the shopper's selected payment method.

The feature is available only while the official WooCommerce PayPal Payments plugin is active and its main `ppcp-gateway` gateway is enabled. Disabling that gateway immediately prevents the fee at runtime and disables the Payment-tab controls. The settings warn administrators to use the feature only for genuine international order handling rather than passing PayPal processing charges to the buyer.

Options: `lwc_international_fee_enabled`, `lwc_international_fee_match_mode`, `lwc_international_fee_trigger_usd`, `lwc_international_fee_trigger_abroad`, `lwc_international_fee_type`, `lwc_international_fee_amount`, `lwc_international_fee_label`.

### J&T Express and J&T Cargo

#### Certification regression requirements

Tariff requests use J&T city/district **names** (for example `JAKARTA` / `KALIDERES`), whereas Order uses `JKT` / `JKT002`. The API client must reject empty or malformed tariff services, invalid prices, mismatched tracking identities, and unsuccessful cancellation details; HTTP 200 or a top-level success flag alone is insufficient. A tariff without a quote must never become a free shipping rate or a green connection indicator.

Before AWB creation, authorized order administrators may save a nonnegative whole-IDR insurance value on that J&T Express order. Default is zero; no percentage is inferred from the account agreement. Automatic and manual AWB creation both use that saved value. Issued shipments cannot have their insurance rewritten locally.

Tracking refresh retains the carrier's raw positive weight and records changes between successive carrier observations without changing WooCommerce product/order weight or assuming an undocumented tracking weight unit. Zero means no usable weight observation yet. Cancellation refusals (including `GOT` after pickup) remain errors in fulfillment, retain the carrier reason, and must not mark the order cancelled. A negative certification test passes when the expected refusal is observed, not when cancellation succeeds.

Certification tooling and evidence are development artifacts, archived separately from the distributable plugin; the production plugin and release ZIP must not contain test runners or fixtures. Certification tooling covers all 16 scenarios in the supplied 12-case document, saves redacted HTTP evidence, distinguishes LIVE from MOCK regression tests, and supports resuming the weight-change and pickup scenarios with dedicated Sandbox AWBs prepared by J&T. No fabricated carrier events, rates, or unconditional success responses are permitted. Provider prerequisites remain BLOCKED until actually observed. Reference: https://developer.jet.co.id/documentation.

J&T Express label requests use the official `ROTAPRINT` message type for both Sandbox and Production Print endpoints. The request remains `application/x-www-form-urlencoded`, signs the exact `logistics_interface` JSON plus the configured Print Key, and preserves raw carrier responses for troubleshooting.

J&T is split into two independent providers, each with its own credentials, zone method, and weight rules:

- **`lwc_jt_express`** (`LWC_Shipping_JT_Express`) — regular parcels; auto-split threshold default 10 kg, hard ceiling 100 kg (`lwc_jt_express_package_weight_ceiling_kg` filter).
- **`lwc_jt_cargo`** (`LWC_Shipping_JT_Cargo`) — large/heavy shipments (10 kg minimum billable, tiers H50–H500); auto-split off by default, ceiling 500 kg (`lwc_jt_cargo_package_weight_ceiling_kg` filter).

Both extend `LWC_Shipping_JT_Base` and retain independent configuration. Rates are rejected unless the destination country is Indonesia (`ID`). J&T Express has global service coverage with a backward-compatible default of all Indonesia/cross-island, or a Java-only mode covering Banten, DKI Jakarta, West Java, Central Java, Yogyakarta, and East Java. Java-only filtering applies in method availability and again to the final WooCommerce package rates so cached, global, and zone-provided Express rates cannot leak into an out-of-area checkout. The legacy `lwc_jt` method id aliases to Express so existing zone instances keep working. J&T Express and J&T Cargo appear as independent Shipping provider tabs. The J&T admin page is limited to activation, coverage, active Sandbox/Production environment, credentials, and a read-only REST connection indicator; mapping import/export, endpoint inputs, and stateful test tools are not exposed. Express stores the distinct Order, Tariff, Tracking, Print, and Cancellation credentials required by J&T Indonesia; Cargo retains a generic independent account form until its API contract is supplied. Sandbox and Production endpoint URLs are fixed backend constants. Developers may still override an environment's complete endpoint array with the corresponding `lwc_jt_express_{environment}_endpoints` filter. Legacy pre-split credentials migrate to Express once.

Cancellation uses the three values supplied in the official account panel: signing Key, Username, and API Key. Tracking status codes 162 and 163 are authoritative cancellation evidence. An idempotent cancellation response whose matching order is already in `CANCEL_ORDER` is reconciled as cancelled instead of being left as a local failure. A recent cancelled AWB is preferred by the connection check so a real carrier cancellation can satisfy the Cancellation service indicator.

Managed-provider orders have a **Change Shipping** metabox. An active J&T shipment must first be cancelled by the API or confirmed by carrier tracking status 162/163. Active FedEx shipments must first be cancelled through the FedEx Ship API from the order metabox; RaySpeed and provisional J&T Cargo require explicit administrator confirmation when an existing AWB was cancelled externally because the plugin has no shipment-void endpoint for those providers. Cancelling a pickup alone is insufficient. Replacement choices are recalculated from the order address and products and are restricted to currently available LoveCatz methods plus the official JNE method (`jneshof_shipping`) when that plugin is active and its matching WooCommerce zone returns live rates. The selected rate's title, method/instance IDs, cost, taxes, and carrier metadata replace the old shipping item, totals are recalculated, previous fulfillment data is archived, and the new AWB must be created manually. A generation suffix prevents a replacement J&T shipment from reusing its prior `orderid`.

J&T Express routing in both Sandbox and Production uses the complete A–H province/city/district mapping returned by J&T. Green columns A–C are the customer-facing address hierarchy; red columns D–H contain J&T province/city/district names, `origin_code`/`destination_code`, and `receiver_area`. The exact green province + city + district combination is the mapping key. Postcodes are required and independently validated as exactly five digits but are never used as route keys. The bundled snapshot is installed atomically behind the scenes and records row count, version, timestamp, and SHA-256 provenance metadata.

Indonesian addresses use one plugin-owned offline table, `{prefix}lwc_indonesia_regions`, generated from the confirmed J&T workbook. It contains exactly 34 provinces, 514 cities/regencies, and 7,128 districts. The same row stores its server-only J&T values, eliminating a second postcode mapping table. The previous BIG background refresh is disabled so government naming cannot overwrite the approved J&T mapping.

For country `ID`, classic checkout, Checkout Block, My Account addresses, and WooCommerce customer profiles replace the core city text input with a province-dependent city/regency dropdown and add a city-dependent district dropdown. Province labels also follow J&T column A while retaining WooCommerce's native 34 state codes, so other couriers continue receiving normal WooCommerce state/city/postcode values. Non-Indonesian addresses restore WooCommerce's normal fields. Saved legacy values are reconciled when uniquely matchable; otherwise the customer must reselect them. J&T codes never appear in browser responses and are resolved server-side only when calculating a J&T rate or creating its AWB.

J&T request validation remains in the runtime integration: Basic Order payloads are checked before HTTP, recipient postcodes must contain exactly five digits, parcel weight cannot exceed 100 kg, and every J&T timestamp is generated in `Asia/Jakarta` (UTC+7). Stateful Sandbox testing is performed outside the production admin UI.

When checkout contains two or more shipping choices, the frontend progressively enhances each shipping-method list into a compact accordion. Its header displays the selected courier, expands accessibly to show all choices, and closes after selection. The enhancement is reapplied after classic checkout AJAX refreshes and WooCommerce Blocks DOM updates; a single shipping choice remains unchanged.

J&T Express is an environment-aware live integration. At checkout it calls the fixed official Tariff endpoint belonging to the active Sandbox or Production account and publishes only services returned by the API; there is no configurable or synthetic checkout-rate fallback. Both environments resolve origin and destination through the bundled official mapping and fail closed when the address cannot be mapped. The selected environment, service, route, weight, and live-rate source are copied to private shipping-item metadata.

FedEx and RaySpeed retain their provider-specific connection indicators. J&T Express displays one connection indicator and checks only the read-only Tariff REST endpoint for the currently selected Active API Environment when its settings page opens, the environment changes, or an active credential changes. It never checks the inactive J&T environment in the background. This verifies authentication without creating an order or AWB; incomplete non-Tariff credentials are reported separately.

When a J&T Express order enters **Processing**, `LWC_JT_Order_Admin` validates store and recipient fields, creates the J&T order exactly once, saves the AWB/order ID/ETD, and immediately requests tracking. Sender name/address come from WooCommerce store data, sender phone uses the shared store contact, and the backend `lwc_jt_express_shipper` filter can override them without adding J&T form fields. Failures are retained in order metadata and order notes without issuing duplicate orders on later status changes. The provider-specific order metabox offers retry, tracking refresh, and cancellation controls; AWB and stored tracking events are also displayed to the customer. Classic checkout and Checkout Block both require the recipient phone and postcode when J&T is selected. J&T Cargo remains provisional and has no Express API coupling.

### Manual partial shipping (FedEx)

The order-screen metabox provides a package-content editor. Unshipped order lines appear as checked checkboxes, start in the package, and may be unchecked or removed; an administrator may add an in-stock catalog product or variation with quantity one through a full-width product-search dropdown and **Add item** button. After every successful addition, the product search resets and remains available for adding another product. The same catalog product may be added repeatedly; repeated additions increase its manifest/customs quantity rather than being discarded as duplicates. The checkboxes select manifest/customs contents only: when a custom carton weight is entered, checking or unchecking products never recalculates that weight. Catalog additions are shipment-manifest substitutions only: they drive package estimates when no custom weight is present, FedEx customs commodities, and the stored shipment audit snapshot, but never silently alter WooCommerce order totals or stock. Items already covered by an active AWB are marked *already shipped* and locked. The optional actual-carton fields allow an administrator to replace the calculated package weight after packing with a standalone custom value that is never recomputed or compared with product weights. For international shipment payloads, that manual total is distributed across the individual commodity lines so their combined weight exactly equals the manually entered package weight and FedEx never compares it with stale product weights. Dimensions are optional; when used, length/width/height must be supplied as a complete set and are rounded up to whole centimeters. Without custom dimensions, dimensions remain product-derived while the custom weight is sent exactly as entered. **Test rate quote** uses the current package-content editor plus the custom weight/dimensions and displays each returned service, currency, and tariff before label creation. Each active shipment has a **Cancel AWB** action with an explicit confirmation. An active scheduled pickup must be cancelled first. The carrier cancellation uses the stored tracking number and unlocks that shipment's items only after FedEx confirms cancellation; a failed or ambiguous carrier response leaves the local shipment active. Cancelled records and their label files remain in history for audit but are excluded from current tracking and fulfillment state. When cancelled items are available again, the metabox presents an explicit **Create replacement AWB** action using the current ship date and any corrected measurements. Shipment creation rejects items already covered by an active AWB; an unscoped legacy active shipment must be cancelled before another AWB can be created. Every created shipment appends to `_lwc_fedex_shipments` (tracking number, label file, order-item IDs, added product IDs, content snapshot, package measurements, timestamp, status) with per-shipment download links; the latest active tracking/label also update the legacy single-shipment meta. The complete content snapshot remains stored for audit, but shipment history in the metabox never lists individual product names; it displays only the generic **Essential Oils** description plus the stored package weight/dimensions.

## Data model

- Options: `lwc_menu_*`, `lwc_enable_product_quantity_limits`, `lwc_enable_product_preorder`, `lwc_preorder_all_products`, `lwc_preorder_product_ids`, `lwc_jt_{express|cargo}_*` (plus legacy `lwc_jt_*`), `lwc_fedex_*` (including `lwc_fedex_shipper_name` and `lwc_fedex_shipper_phone`), `lwc_currency_enabled`, `lwc_currency_rates`, `lwc_international_fee_*`, rewrite-version, and legacy promo options.
- Tables: `{prefix}lwc_fedex_accounts`, `{prefix}lwc_jt_express_accounts`, `{prefix}lwc_jt_cargo_accounts` (legacy `{prefix}lwc_jt_accounts` dropped on uninstall).
- Product meta: `_lwc_minimum_quantity`, `_lwc_maximum_quantity`; pre-order eligibility is option-based rather than duplicated per product.
- User meta: `lwc_imported_member`, optional `lwc_customer_id`, plus WooCommerce billing/shipping fields.
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
- Test international handling with PayPal enabled/disabled, USD/IDR, Indonesia/non-Indonesia shipping, OR/AND trigger matching, and classic/Block checkout recalculation.
- Verify manual partial shipping: uncheck an item, create the AWB, confirm the shipment history grows, the item is marked shipped, each label downloads individually, and the history shows only `Essential Oils` plus package measurements rather than every product name.
- Verify FedEx AWB replacement: reject cancellation while a pickup is scheduled; retain active state on carrier failure; on `cancelledShipment=true`, mark only that shipment cancelled, exclude its tracking number, unlock only its items, preserve its archived label, and allow a replacement label with the current ship date.
- Verify original and replacement FedEx AWBs can select International Priority or International Economy without first requesting a rate; reject every other posted service value, send the selected code as `requestedShipment.serviceType`, and store it in shipment history.
- Verify FedEx pickup in Production: a same-day and next-business-day Express availability request use schema-correct scalar account/date fields and business-day count; a successful check enables pickup creation; create-pickup sends `pickupAddressType`, `pickupDateType`, `packageLocation`, `pickupType`, `countryRelationships`, and the carrier-specific account type in their documented locations; invalid/unavailable windows do not store a pickup; a returned confirmation number is persisted as both the compatible confirmation value and pickup ID, displayed with an explicit label, and the Express location code is shown separately when returned.
- Verify actual-carton overrides: accept a standalone positive custom weight without comparing it to selected-product weights; distribute that total across all international commodity lines so their combined weight exactly equals the package weight; reject partial/zero dimension sets; round fractional dimensions up to whole centimeters; send one package with custom weight plus supplied or product-derived dimensions; and retain the override in shipment history while leaving the fully product-derived path unchanged when every override field is blank.
- Verify international FedEx payloads retain every selected commodity and do not merge customs lines merely to alter `DESC1`–`DESC4`; accept that these fields belong to the carrier-controlled standard PDF.
- Verify the FedEx package editor: available order lines render as checked checkboxes; unchecking one excludes it without changing a supplied custom carton weight; the product search and Add button fill but never overflow the metabox; removing an unshipped order line and adding multiple in-stock catalog products sequentially resets the selector after each addition and updates the manifest; adding the same product repeatedly preserves every addition and aggregates its FedEx quantity; out-of-stock/invalid additions are rejected server-side; order totals and inventory remain unchanged; and the stored shipment/customs snapshot reflects only the edited package contents.
- Verify the order-screen rate quote changes when custom weight/dimensions or package contents change and clearly displays the FedEx service, currency, and tariff returned for those current inputs.
- Verify J&T Express end to end: live tariff at checkout, required recipient data, transition to Processing, one AWB only, immediate/manual tracking, cancellation, and customer tracking output. Keep Cargo isolated and provisional.

## Priorities

1. Complete live native FedEx staging verification.
2. Restrict Promo administration to marked coupons or deliberately document all-coupon management.
3. Remove/migrate the legacy auto-promo path.
4. Consolidate membership classes.
5. Consolidate shipping registration without changing persisted IDs.
6. Implement or remove the Currency placeholder.
7. Keep the hardcoded official J&T Production endpoints and complete province/city/district-to-J&T area mapping current before enabling Production; postcodes remain separately validated as exactly five digits.
8. Define uninstall retention for options, metadata, coupons, tables, and labels.
