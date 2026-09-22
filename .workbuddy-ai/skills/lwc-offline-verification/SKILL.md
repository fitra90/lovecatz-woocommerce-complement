---
name: lwc-offline-verification
description: This skill should be used when verifying LoveCatz WooCommerce Complement behaviour without a browser — cart totals, coupon/discount maths, shipping rates, currency conversion, order meta, or any PHP class in the plugin. Use it when a change needs empirical proof (numbers, not reasoning), when reproducing a WooCommerce bug on this site, or when writing a regression harness in tmp/. It covers bootstrapping WordPress from the CLI, building a standalone WC_Cart, forcing the shopper currency, and safely mutating test data.
agent_created: true
---

# Offline verification for LoveCatz WooCommerce Complement

## Purpose

Prove plugin behaviour with real WooCommerce objects instead of reading code and guessing.
Every check in this plugin's history that was worth trusting (courier switching, tariff factors,
promo caps) was settled by running a CLI harness, not by inspection.

## When to use

- A change touches money: cart totals, discounts, fees, shipping rates, currency conversion.
- A bug report needs a reproduction before a fix.
- A fix needs proof that it works and that the old behaviour is gone.
- Something "should" work but no one has observed it working.

## Environment facts

| Thing | Value |
| --- | --- |
| WordPress root | `C:/laragon/www/ddistillers` |
| Plugin root | `C:/laragon/www/ddistillers/wp-content/plugins/lovecatz-woocommerce-complement` |
| PHP CLI | `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe` (`php` is NOT on PATH) |
| MySQL CLI | `C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe` |
| Database | `ddistillers_db`, user `root`, no password, prefix `wp_` |
| Harness location | `tmp/` inside the plugin root |

Write throwaway harnesses into `tmp/`. Do not leave them in `promo/`, `shipping/`, etc.

## Workflow

1. **Read the real data first.** Before writing any code, query the actual option and meta values
   so the expected numbers come from the database, not from assumptions:

   ```bash
   cd "C:/laragon/www/ddistillers" && \
   /c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -u root ddistillers_db -e \
   "SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'lwc_%' OR option_name LIKE 'woocommerce_%currency%';"
   ```

2. **State the expected numbers before running.** Decide what a correct result looks like
   (for example "50% of Rp400,000 capped at Rp150,000 = Rp150,000") and turn it into a
   `PASS`/`FAIL` assertion in the harness. A harness that only prints values proves nothing.

3. **Boot WordPress.** Copy `scripts/wp-bootstrap.php` from this skill into `tmp/` as the
   header of the harness. WordPress must be loaded with `WP_USE_THEMES` false.

4. **Build the objects under test** — see `references/woocommerce-cart-recipes.md` for the
   cart, currency, and coupon recipes.

5. **Run, then assert.**
   `"C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe" tmp/<harness>.php`
   Print a failing count so a failure is impossible to miss.

6. **Lint every file touched** with `php -l` before finishing.

7. **Restore or state the final data state.** If the harness wrote to the database, either wrap it
   in a transaction and roll back, or leave the database in a state that is correct to keep — and
   say which one happened.

## Hard rules

- **Never compute a timestamp or a conversion by hand.** Read rates and prices from the database
  or ask the code under test.
- **Mutating tests need `START TRANSACTION` / `ROLLBACK`.** Tables are InnoDB. Wrap writes that
  must not survive. Meta writes that are the *intended* outcome must be left committed — verify
  the final value afterwards and report it.
- **Assertions, not printouts.** A harness that prints `discount=9` is not a test.
- **Do not trust a passing harness without reading its raw output.** Read the numbers.
- **Empty output is a failure, not a pass.** A harness that died before printing looks identical
  to one that printed nothing; always print a final summary line.
- Some code paths need an admin context. Functions such as `submit_button()` live in
  `wp-admin/includes/template.php`; `require_once ABSPATH . 'wp-admin/includes/template.php';`
  after booting WordPress. This is a harness limitation, never a plugin bug.

## Reference

- `scripts/wp-bootstrap.php` — bootstrap header plus assertion helpers.
- `references/woocommerce-cart-recipes.md` — forcing a currency, building a WC_Cart, applying a
  coupon, simulating shipping without a shipping zone, testing an admin form handler that ends
  in `exit`, harness pitfalls, and the currency-conversion trap that has caused two separate
  bugs in this plugin.

Read the reference file before writing a harness for a new module; the "Harness pitfalls"
section in particular saves a round of false failures.
