# Promo Coupon Manager Tasks

- [x] Create Promo admin workspace and form handlers.
- [x] Save native WooCommerce coupon settings and LoveCatz promo metadata.
- [x] Add percentage maximum-discount calculation.
- [x] Render eligible/disabled promo cards in My Account.
- [x] Apply selected promo at checkout; manual WordPress checkout verification remains required.

# FedEx Currency Adapter Tasks

- [x] Audit Octolize Flexible Shipping FedEx 4.4.2 currency gate (verify_currency / CurrencySwitcherException) and hook surface.
- [x] Add FedEx engine selector (LoveCatz vs Octolize) + adapter settings to LoveCatz -> Shipping -> FedEx.
- [x] Implement gate-fix so Octolize always calculates FedEx rates in the base currency.
- [x] Let woo-multi-currency convert FedEx rates; manual-rate fallback when it is unavailable.
- [x] Prevent double conversion (wmc exclusion filter, per-package idempotent conversion).
- [x] Standalone test harness: 31 assertions pass (incl. IDR 750,000 -> 45.45 USD).
- [ ] Live checkout verification on staging with real FedEx account + USD checkout (see report).
