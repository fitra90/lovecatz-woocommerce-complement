# 🐱 loveCatz: All in One WooCommerce Complement

**A powerful WooCommerce extension** that streamlines user management, membership handling, and shipping — all in one plugin.

---

## 🚀 Features

### ✅ Currently Available

- **Product Pre-order**
  - Allow all products, or only products selected through a searchable selector, to be ordered while out of stock.
  - Pre-order status is retained in the cart, Checkout Blocks, and order line items.

- **International Order Handling**
  - Add an optional fixed or percentage handling fee when checkout uses USD, the shipping country is outside Indonesia, or both.
  - Supports OR/AND matching and stays unavailable unless the official WooCommerce PayPal Payments gateway is enabled.
  - Eligibility is independent of the payment method selected by the shopper.

- **User Import & Membership Management**  
  - Bulk import users via CSV/Excel with custom fields.  
  - Automatic membership assignment based on user roles or purchased products.  
  - **Digital membership card** generation for each member (PDF/PNG).  
  - Manage membership levels, expiration dates, and renewal reminders.

- **Courier Switching on Unprocessed Orders**
  - A **Change Shipping** box is added to orders that are still `on-hold`/`pending`, so the courier can be swapped before the order is processed.
  - Available couriers are detected from this WordPress install: **J&T Express** and **J&T Cargo** (bundled) and **JNE** (external `jne-shipping-official` plugin), plus FedEx and RaySpeed. Detection reads the active plugin list, the shipping methods registered in WooCommerce, and the configured shipping zones.
  - After selecting the replacement service, **Check rate** must succeed and display the quoted service/cost before **Change Shipping** is enabled. The short-lived quote is revalidated when the change is submitted.
  - Before a change is applied, the target courier is re-validated as installed and active; couriers that publish no live rate can be checked with the current cost or a manually entered cost.
  - FedEx/RaySpeed/J&T orders that already have an AWB still require cancellation proof, as before.

- **Completed Order Reviews**
  - Optional Review tab setting adds product review buttons to completed orders in My Account.
  - Each button opens the purchased product's review section.

### 🛠️ In Progress

- **International Shipping (FedEx)**  
  - Real-time FedEx rates at checkout.  
  - Support for FedEx Ground, Express, and International services.  
  - Automatic tracking number generation and email notifications.  
  *(Expected release: Q3 2025)*

- **Local Shipping (J&T Express & J&T Cargo)**  
  - Real-time rates for J&T Express and J&T Cargo.  
  - Support for domestic shipping zones and weight-based calculations.  
  - Automated waybill generation, official J&T label printing, and tracking integration.  
  - Official Sandbox and Production API endpoints are selected automatically; administrators only enter account credentials.
  - Delivery estimation and status updates for customers.  
  *(Expected release: Q4 2025)*

- **Unified order shipment columns**
  - Shows tracking numbers and available fulfillment actions for J&T Express, FedEx, J&T Cargo, and RaySpeed in both legacy and HPOS order lists.
  - Reuses existing JNE `No. Resi` and `JNE Actions` columns when available without changing the JNE plugin, and creates neutral columns when JNE is absent.

---

## 📦 Installation

1. Download the latest release from the [Releases page](https://github.com/fitra90/lovecatz-woocommerce-complement/releases).
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and activate it.
4. Navigate to **WooCommerce → Settings → All in One** to configure.

---

## 🧑‍💻 Usage

### User Import
- Download the Excel template from **LoveCatz → Members**; it includes standard WordPress user fields and a role dropdown using the roles available on the site.
- Upload the completed CSV/XLS/XLSX file and choose a default role for rows whose `Role` cell is empty.
- The importer creates only new users. Email and name are required; every other column may be empty. Existing email addresses are skipped.
- Set and save the default password beside the import form before importing users.

### Membership Cards
- Each member gets a unique card (customizable via **All in One → Card Settings**).
- Cards are available in the user's account page and can be downloaded as PDF.

---

## 🔧 Requirements

- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 7.4 or higher
  
---

## 📄 License

Distributed under the GPLv2 or later license. See [`LICENSE`](LICENSE) for more information.

---

## 🙏 Support

- **Contact**: fitra90@gmail.com

---

> **Note**: This plugin is actively developed. Features and APIs may change until the first stable release.
