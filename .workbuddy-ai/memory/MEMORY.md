# Project memory — lovecatz-woocommerce-complement

## Environment and conventions
- WP: `C:\laragon\www\ddistillers`; PHP CLI: `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`; WooCommerce 11.1.1.
- MySQL may be stopped. Start `mysqld.exe --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini"` in background; verify on 127.0.0.1:3306.
- Test harnesses belong in gitignored `tmp/`, use DB transaction/rollback, and must be deleted after verification.
- WordPress style: tabs, `lwc_` prefix, text domain `lovecatz-wc`. Bump plugin header and `LWC_VERSION` together.

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

## FedEx
- `LWC_FedEx_API::get_shipment_coverage()` is the sole source of item coverage for both UI and handler. An active legacy AWB without `item_ids` blocks all new labels.
- Printed `DESC1` comes from the first customs commodity description. International selected items are aggregated into one commodity.
- Ship date, carton measurements and label description cannot be edited after AWB creation; cancel and recreate.

## Currency, promos, integrations
- Base currency IDR; configured `USD=15000` means USD→IDR multiplies by 15000. Desty REST conversion belongs in `LWC_Currency_Converter` and uses the frozen order rate when available.
- Promo percentage and shipping caps use separate meta keys; always read through `LWC_Promo_Discounts::get_maximum_discount()`.
- JNE 1.9.6 pickup sender name is the WordPress site title and must be ≤50 chars; fix the title, not the third-party plugin.
