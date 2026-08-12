Product Requirements & Design (PRD) — LoveCatz WooCommerce Complement

Last updated: 2026-08-12T23:36:30+07:00 (updated 2026-08-12T23:50:00+07:00)
Author: Automated update (assistant - Copilot CLI runtime in VS Code)

Purpose
-------
This document describes the features, behavior, architecture, and operational notes for the LoveCatz WooCommerce Complement plugin. It is intended as a single source of truth so different agents or developers can continue work without losing context.

High-level overview
-------------------
LoveCatz WC Complement augments WooCommerce with:
- Promo / coupon dashboard exposed to customers (/my-account/coupon/ endpoint and [lwc_promo_dashboard] shortcode)
- Shipping provider integrations (J&T and FedEx) with account management and FedEx API features
- Product-level quantity limits (minimum and maximum) configurable via admin and enforced frontend/back-end
- Admin settings UI that groups settings into feature tabs (Settings / Products / Shipping / Promo / Members / Currency)

Current file layout (canonical for this repository state)
--------------------------------------------------------
IMPORTANT: The repository currently organizes shipping provider code under the plugin root `shipping/` folder (with subfolders `shipping/fedex/` and `shipping/jt/`). Other features live under `includes/`.

Canonical locations used by code (do not change paths without updating loader):
- lovecatz-woocommerce-complement.php  (plugin bootstrap)
- includes/core/           -> core bootstrap and logger (class-lwc-core.php, class-lwc-logger.php)
- includes/admin/          -> admin UI classes (settings, members handlers)
- includes/promo/          -> promo / coupon dashboard frontend and assets
- includes/products/       -> product-related features (product meta, quantity limits)
- shipping/                -> shipping provider implementations (root-level folder)
  - shipping/fedex/        -> FedEx provider classes (class-lwc-fedex-api.php, class-lwc-fedex-account.php, class-lwc-shipping-fedex.php)
  - shipping/jt/           -> J&T provider classes (class-lwc-jt-account.php, class-lwc-shipping-jt.php)
- includes/legacy/         -> legacy files kept temporarily (audit/archive)

Developer instruction (short)
- Do not add new top-level folders. Follow the existing layout: new feature code should be placed under `includes/<feature>/` when possible. For shipping provider code, follow existing `shipping/` layout and update loader requires accordingly.
- Update includes/core/class-lwc-core.php and lovecatz-woocommerce-complement.php when adding new features so loader points to actual file paths in the repository.

Core features and behaviors
---------------------------
1. Promo / Coupon Dashboard
  - Endpoint: /my-account/coupon/
    - Registered with add_rewrite_endpoint('coupon', EP_ROOT | EP_PAGES)
    - Activation: plugin activation flushes rewrite rules and updates option 'lwc_coupon_endpoint_rewrite_flushed' to ensure endpoint is active immediately after plugin activation.
  - Shortcode: [lwc_promo_dashboard] — places the same dashboard on any page (useful if permalinks are not flushed or if you want a public page).
  - Rendering behavior:
    - Visible to logged-in users by default (the shortcode can be placed publicly if desired).
    - Dashboard lists coupons that the plugin created and marked with post meta _lwc_promo_created = '1'.
    - If no promo coupon exists, the plugin will create a coupon using configured prefix and options, then store the coupon code/id in options (lwc_promo_coupon_code / lwc_promo_coupon_id).
    - Promo cards are clickable and store coupon code to localStorage so the checkout UI can pick it up and apply the coupon.
  - Assets: includes/promo/promo-dashboard.js and includes/promo/promo-dashboard.css; enqueued by the promo class.

2. Product quantity limits
  - Per-product meta:
    - _lwc_minimum_quantity (int)
    - _lwc_maximum_quantity (int)
  - Admin defaults (plugin settings):
    - lwc_product_default_minimum_quantity
    - lwc_product_default_maximum_quantity
  - UI placement: fields added to the product Inventory admin area (using WooCommerce product hooks).
  - Frontend enforcement:
    - Filter woocommerce_quantity_input_args to set min_value, max_value and input_value on single product pages.
    - validation on add-to-cart and cart update (woocommerce_add_to_cart_validation and woocommerce_update_cart_validation) to block quantities outside allowed range.
    - check_cart_items is used to ensure cart/checkout remains valid.

3. Shipping integrations
  - Location: shipping/ (root), subfolders: shipping/fedex/, shipping/jt/
  - AJAX endpoints (main plugin file):
    - wp_ajax_lwc_check_fedex_connection — credential checks
    - wp_ajax_lwc_fedex_get_rate_quote — rate quote via LWC_FedEx_API
    - wp_ajax_lwc_fedex_create_shipment — create shipment and return result
    - lwc_fedex_download_label — direct file download of label (sanitized and validated path inside uploads dir)
  - Security and robustness:
    - FedEx label downloads are sanitized using basename(), wp_normalize_path and validated to be within wp_upload_dir()['basedir'] to prevent directory traversal.

4. Admin UI & Settings
  - Central settings page: top-level menu 'LoveCatz' (manage_woocommerce capability required)
  - Tabs: Settings, Products, Shipping, Promo, Currency, Members
  - Products tab contains default min/max settings; product-level overrides are saved per product (Inventory tab) via process_product_meta hooks.

5. Core architecture and loader
  - Bootstrap: lovecatz-woocommerce-complement.php
    - Defines LWC_PLUGIN_DIR, LWC_PLUGIN_URL, LWC_VERSION
    - Checks WooCommerce active; requires critical includes from includes/core/ and shipping/ as implemented in this repository
    - Registers activation/uninstall hooks
    - On activation the plugin will add the coupon rewrite endpoint and flush rules; it sets option 'lwc_coupon_endpoint_rewrite_flushed' = 'yes'.
  - Core loader: includes/core/class-lwc-core.php
    - Responsible for requiring feature files from includes/ and shipping/ (shipping carried in repository root shipping/).
    - Initializes admin or frontend handlers depending on is_admin()

Refactors and recent behavior changes
------------------------------------
- 2026-08-12: Reorganized code to match repository owner's decisions about file layout. Shipping provider code lives under plugin root `shipping/` while other features live under `includes/`.
- 2026-08-12: Promo dashboard moved to includes/promo; shortcode fallback added; coupon marking meta _lwc_promo_created introduced.
- 2026-08-12: Product quantity limits consolidated into includes/products/class-lwc-product-quantity-limits.php; admin Products tab added for defaults.
- 2026-08-12: Activation now flushes rewrite rules and sets 'lwc_coupon_endpoint_rewrite_flushed' so /my-account/coupon/ is available after activation.
- 2026-08-12: Updated code to use the repository's actual paths; replaced references to includes/shipping/ to point to shipping/ where the shipping providers live.

Developer guidelines (enforced)
-------------------------------
- When changing file locations, update the loader (includes/core/class-lwc-core.php) and the bootstrap (lovecatz-woocommerce-complement.php) to require the correct files; do not assume includes/shipping/ unless it exists.
- New feature code should go into includes/<feature>/ whenever possible. For shipping provider code, follow the existing root-level shipping/ layout.
- Avoid adding new top-level folders. If a different structure is desired later, coordinate the change and update PRD.md and loader references together.

Testing & QA checklist
----------------------
(unchanged — see earlier content)

Planned cleanup
---------------
- Keep includes/legacy/ for at least one release after refactor. Remove after staging verification.

Change log
----------
- See earlier "Refactors and recent behavior changes" section.
