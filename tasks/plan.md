# Implementation Plan: Promo Coupon Manager

## Overview

Replace the empty Promo settings tab and automatic single-coupon behavior with an admin-managed coupon catalog backed by native WooCommerce coupons. Customers see only eligible coupons in My Account and can select a coupon for checkout.

## Architecture Decisions

- Use `WC_Coupon` for discount, expiry, overall usage, and per-user usage settings, preserving WooCommerce checkout validation.
- Store LoveCatz-only presentation and eligibility data in coupon post meta: active/disabled image IDs and allowed user IDs.
- Use a custom coupon-meta flag to distinguish LoveCatz promo coupons from ordinary WooCommerce coupons.
- Use a compact, editorial admin workspace: creation form beside the existing promo list; media-library image selection; conditional maximum-discount field.

## Task List

### Phase 1: Coupon creation and administration

- [ ] Task 1: Build the Promo admin screen and secure form handlers for create/update/delete operations.
- [ ] Task 2: Persist WooCommerce coupon settings and LoveCatz promo meta, including percentage cap, user eligibility, images, expiry, and usage limits.

### Checkpoint: Administration

- [ ] A manager can create a percent or fixed-cart coupon and see it in the promo list.
- [ ] Invalid codes, invalid user IDs, and unauthorized submissions are rejected.

### Phase 2: Customer eligibility and presentation

- [ ] Task 3: Render only eligible coupons in My Account with active or disabled visual states.
- [ ] Task 4: Select a coupon from My Account and apply it at checkout; enforce the percentage discount cap.

### Checkpoint: End-to-end

- [ ] A targeted user can apply an active promo at checkout.
- [ ] An ineligible, expired, or exhausted promo remains unavailable or disabled as appropriate.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Coupon rules duplicated outside WooCommerce | Keep native coupon properties as the source of truth and use WooCommerce filters only for the percentage cap. |
| Coupon selection bypasses eligibility | Filter My Account visibility and rely on `WC_Coupon` email restrictions during checkout. |
| Image uploads bypass media permissions | Use WordPress media picker and `manage_woocommerce` authorization. |
