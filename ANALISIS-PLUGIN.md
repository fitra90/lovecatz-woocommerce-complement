# Analisis Plugin — LoveCatz WooCommerce Complement

Analisis kode sumber pada `main` (versi terdeklarasi **1.0.76**), kondisi working tree: banyak perubahan belum di-commit.

---

## 1. Identitas & prasyarat

| Item | Nilai |
|---|---|
| Nama | LoveCatz WooCommerce Complement |
| Versi | 1.0.76 |
| Author | Fitra Fadilana |
| Text domain | `lovecatz-wc` |
| Prasyarat | WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+ |
| Opsional | OpenSSL (enkripsi kredensial), `ZipArchive` (XLSX), Windows COM + Excel (XLS biner) |
| Lisensi | GPLv2+ |

Plugin **berhenti total** dan hanya memunculkan admin notice bila WooCommerce tidak aktif (`lwc_is_woocommerce_active()`).

---

## 2. Cara plugin dimuat (bootstrap)

Semua bermula di `lovecatz-woocommerce-complement.php` pada hook `plugins_loaded` prioritas 20:

1. Cek WooCommerce aktif.
2. Daftarkan ~40 filter `option_lwc_*` yang **mendekripsi kredensial secara transparan** saat dibaca.
3. `require_once` seluruh kelas inti (logger, settings, akun kurir, API, metode pengiriman, order admin).
4. Migrasi kredensial J&T lama → Express (sekali).
5. `new LWC_Core()->init()` + `LWC_Order_List_Shipping`, `LWC_Order_Shipping_Switcher`, `LWC_Indonesia_Regions`.

`LWC_Core` kemudian memuat modul fitur dan mendaftarkan **global rate injector** — inilah kunci desain plugin: FedEx, RaySpeed, dan J&T Express disuntikkan lewat `woocommerce_package_rates` sehingga **tidak perlu dikonfigurasi di WooCommerce Shipping Zone**.

### Enkripsi kredensial
- `lwc_encrypt_secret()` — AES-256-**GCM** dengan IV acak 12 byte, prefix `lwc2:`.
- Fallback AES-256-CBC dengan IV deterministik, prefix `lwc1:` (tidak terautentikasi).
- Kunci = `sha256(wp_salt('auth'))`. Nilai plain tetap terbaca (kompatibilitas mundur).

### Lifecycle
- **Aktivasi** (`lwc_install()`, idempoten, juga jalan di `admin_init` saat versi berubah): membuat tabel, migrasi kredensial, mendaftar endpoint `coupon`, flush rewrite rules.
- **Nonaktifkan**: tidak menghapus apa pun; hanya flush rewrite rules dan menghentikan cron sinkronisasi wilayah.
- **Uninstall**: menghapus 6 tabel, semua opsi `lwc_*`, transient `lwc_*`, post meta `_lwc_*`, HPOS order meta, user meta `lwc_customer_id`, dan file `fedex-label-*.pdf` di uploads.

---

## 3. Modul fitur

### 3.1 Pengiriman

| Metode | ID | Cakupan | Status |
|---|---|---|---|
| FedEx | `lwc_fedex` / `lwc_shipping_fedex` | Luar negeri | Aktif, live REST |
| J&T Express | `lwc_jt_express` (+ alias legacy `lwc_jt`) | Dalam negeri (seluruh ID atau Jawa saja) | Aktif, live tariff |
| RaySpeed | `lwc_rayspeed` | Luar negeri | Aktif, **Sandbox saja** |
| J&T Cargo | `lwc_jtc` | — | Internal, rate-nya disembunyikan dari checkout |

#### FedEx (`shipping/fedex/`)
- OAuth ke `/oauth/token`, token di-cache di transient (TTL = masa berlaku − 1 menit).
- Rate: `POST /rate/v1/rates/quotes`. Tidak mengirim `serviceType`, jadi FedEx mengembalikan semua layanan; yang dipakai difilter oleh opsi `lwc_fedex_services`. Hasil di-cache 30 menit per hash payload.
- Berat dihitung dari produk nyata × qty; paket dipecah menurut `lwc_fedex_max_package_weight_kg` (default 10, hard cap 68 kg). Dimensi = akar pangkat tiga dari total volume.
- Pembuatan AWB: `POST /ship/v1/shipments` + `customsClearanceDetail` otomatis untuk tujuan luar negeri (duties `RECIPIENT`, terms `DAP`). Label PDF disimpan di uploads, nomor resi ke `_lwc_fedex_tracking_number`, riwayat ke `_lwc_fedex_shipments`.
- Fitur lain: tracking (`/track/v1/trackingnumbers`), cancel shipment, pickup 2 langkah (availability → create), partial shipping per item, override berat karton aktual.
- Kredensial Sandbox/Production terpisah, plus **kredensial tracking production terpisah**.
- Fallback flat rate per-instance bila live quote gagal.

#### J&T Express (`shipping/jt/`)
- Endpoint resmi di-hardcode untuk sandbox & production (create order, tariff, track, print `ROTAPRINT`, cancel).
- Tanda tangan: `base64(md5(json + key))`; tracking pakai HTTP Basic `companyId:password`.
- Tarif checkout live: rute tujuan di-resolve dari **provinsi + kota + kecamatan** (bukan kode pos), berat maksimal 100 kg, timestamp selalu `Asia/Jakarta`.
- Saat order masuk **Processing**, `LWC_JT_Order_Admin` membuat AWB **tepat satu kali** (guard: meta `_lwc_jt_awb` kosong), lalu langsung minta tracking.
- Pembatalan hanya disimpan bila kurir menerima; status tracking **162/163** dianggap bukti batal yang sah.
- Validasi ketat di `LWC_JT_Request_Validator`: telepon `+62` (8–12 digit), kode pos tepat 5 digit, berat ≤ 100 kg.

#### RaySpeed (`shipping/rayspeed/`)
- Sandbox pricing + `awb_post.php` + `current.php`. Harga IDR beserta lead time ditampilkan di checkout. **Tidak ada endpoint unduh label.**

#### J&T Cargo (`shipping/jtc/`)
- Modul terisolasi: 20 endpoint sandbox whitelist + konsol uji admin, progress 3× HTTP 2xx per endpoint. Production belum ada URL → belum bisa dipakai. Rate-nya dibuang defensif dari semua quote.

#### Panel order terpadu
- `LWC_Order_List_Shipping`: kolom **No. Resi** dan **Shipping Actions** di order list (legacy + HPOS). **Memakai ulang kolom JNE** bila plugin JNE ada.
- `LWC_Order_Shipping_Switcher`: metabox **Change Shipping** — mengganti kurir setelah AWB lama dibatalkan. Aturan: J&T wajib dibatalkan via API/tracking 162-163; FedEx wajib cancel via API; RaySpeed & Cargo butuh konfirmasi admin.

### 3.2 Wilayah Indonesia (`includes/checkout/class-lwc-indonesia-regions.php`)
- Tabel sendiri `{prefix}lwc_indonesia_regions` berisi **7.128 baris**: 34 provinsi, 514 kota, 7.128 kecamatan — hasil impor atomik dari `data/indonesia-regions-jt-2026-09.csv` (transaksi + rollback, lengkap dengan SHA-256 provenance).
- Mengganti input kota teks menjadi **dropdown kota → kecamatan** bergantung provinsi, di classic checkout, Checkout Block, My Account, dan profil customer admin.
- Label provinsi mengikuti nama J&T, tetapi kode state WooCommerce (34 kode) tetap dipertahankan agar kurir lain tidak terdampak.
- Kode internal J&T (`jt_city_code`, `jt_area_code`) **tidak pernah dikirim ke browser** — hanya dipakai server-side saat hitung tarif/buat AWB.
- Endpoint REST publik `/lwc/v1/indonesia-regions/{cities,districts}` hanya mengembalikan nama (kolom hijau).
- Cron sinkronisasi BIG (pemerintah) sengaja **dimatikan** agar tidak menimpa mapping resmi J&T.

### 3.3 Promo & kupon (`promo/`)
- Berbasis **`WC_Coupon` native** — validasi dan usage tracking tetap milik WooCommerce.
- Tipe: persen, fixed cart, dan tipe khusus `lwc_free_shipping` (diskon pada tarif kirim yang dipilih, diimplementasikan sebagai **fee negatif**).
- Fitur: maksimum diskon (cap proporsional), expiry, limit total & per user, target user (juga disimpan sebagai email restriction), gambar aktif & nonaktif, aktif/nonaktif.
- **Aturan kombinasi**: whitelist dua arah yang disinkron otomatis, divalidasi di `woocommerce_coupon_is_valid` dengan pesan error yang jelas.
- Tampilan customer: endpoint `/my-account/coupon/`, menu **Coupon**, shortcode `[lwc_promo_dashboard]`, kartu promo di cart/checkout, dan modal Checkout Block.

### 3.4 Produk (`products/`)
- **Batas kuantitas**: meta `_lwc_minimum_quantity` / `_lwc_maximum_quantity` per produk (variasi mewarisi induk). Berlaku di product page, quick edit, add-to-cart, update cart, dan sebelum checkout — satu filter `woocommerce_quantity_input_args` mengcover classic **dan** Blocks/Store API.
- **Pre-order**: produk out-of-stock/onbackorder tetap bisa dibeli lewat jalur backorder aman WooCommerce; label "Available for pre-order", tombol "Pre-order", penanda `_lwc_preorder` di item order + catatan order. Bisa global (`lwc_preorder_all_products`) atau produk terpilih.

### 3.5 Mata uang & biaya
- **Konverter bawaan** (`class-lwc-currency-converter.php`): kurs manual `USD=16500` (1 USD = 16.500 IDR, konversi **membagi**). Shopper pindah via `?currency=USD` (cookie 30 hari) atau shortcode `[lwc_currency_switcher]`. Mengoverride `woocommerce_currency`, simbol, desimal, harga produk, dan semua ongkir.
  - **Auto mundur** bila CURCY/WOOCS/Aelia/WPML/YayCurrency terdeteksi (filter `lwc_currency_external_converter_active`).
  - Deteksi negara via geolocate IP: ID → IDR, selain itu → USD.
- Kurs **dibekukan** di order (`_lwc_currency_rate`, `_lwc_currency_base_total`) agar referensi nilai dasar tetap tersedia tanpa mengubah nominal laporan WooCommerce. Analytics menyimpan nominal asli sesuai mata uang order sehingga customer history USD tidak salah membaca nilai IDR sebagai USD.
- **Biaya penanganan internasional** (`payment/`): fee persen atau tetap, trigger USD dan/atau negara tujuan luar ID, mode OR/AND. **Hanya aktif bila WooCommerce PayPal Payments resmi aktif dan gateway `ppcp-gateway` enabled.** Tidak pernah membaca metode pembayaran yang dipilih shopper.

### 3.6 Membership (`includes/admin/class-lwc-admin-settings.php`)
- Impor CSV, XLSX (parser manual `ZipArchive` + `SimpleXML`, anti-XXE & anti zip-bomb), XML Spreadsheet, dan XLS biner via COM Excel (Windows).
- Syarat: email valid+unik dan nama; baris dengan email yang sudah ada **dilewati** (impor hanya membuat user baru, tidak memperbarui).
- Role bisa dipilih per baris dengan default dari form; username otomatis unik.
- Menulis meta billing/shipping lengkap + `lwc_customer_id`; mengirim notifikasi user baru; password default bersama disimpan terenkripsi.
- Template XLSX dibangun dari nol (OOXML) lengkap dengan dropdown role.
- Kartu anggota cetak (HTML) dengan QR dari layanan eksternal `api.qrserver.com`; tombol tersedia di dashboard customer.

### 3.7 Lainnya
- **Review produk**: opsi menambah kolom **Write Review** di My Account untuk order completed.
- **Admin**: menu top-level `LoveCatz` (capability `manage_woocommerce`) dengan 8 tab: Setting, Products, store-members, Review, Shipping, Promo, Payment, Currency.
- **Logger**: `LWC_Logger` → WooCommerce logger, source `lovecatz-wc`.
- **Diagnostik checkout FedEx**: aktif hanya dengan `?lwc_fedex_debug=1` + capability `manage_woocommerce`; menyimpan 60 event terakhir di session tanpa kredensial.

---

## 4. Model data

**Tabel:** `{prefix}lwc_fedex_accounts`, `lwc_jt_express_accounts`, `lwc_jt_cargo_accounts`, `lwc_indonesia_regions` (+ legacy `lwc_jt_accounts`, `lwc_jt_area_map` dibuang saat uninstall/upgrade).

**Opsi utama:** `lwc_menu_*`, `lwc_enable_product_quantity_limits`, `lwc_enable_product_preorder`, `lwc_preorder_*`, `lwc_jt_express_*`, `lwc_jt_cargo_*`, `lwc_fedex_*`, `lwc_rayspeed_*`, `lwc_currency_*`, `lwc_international_fee_*`, `lwc_product_review_enabled`, `lwc_schema_version`.

**Order meta:** `_lwc_fedex_tracking_number`, `_lwc_fedex_shipments`, `_lwc_fedex_label_path`, `_lwc_fedex_pickup`, `_lwc_jt_awb`, `_lwc_jt_order_id`, `_lwc_jt_etd`, `_lwc_jt_tracking`, `_lwc_jt_cancelled`, `_lwc_rayspeed_awb`, `_lwc_shipping_change_history`, `_lwc_{billing|shipping}_jt_*`.

**Produk:** `_lwc_minimum_quantity`, `_lwc_maximum_quantity`. **Kupon:** `_lwc_promo_created`, `_lwc_promo_eligible_user_ids`, `_lwc_promo_maximum_discount`, `_lwc_promo_allow_combination`, `_lwc_promo_combination_coupon_ids`, `_lwc_promo_{active|disabled}_image_id`.

---

## 5. Temuan: risiko & utang teknis

**Keamanan**
1. Tanda tangan J&T berbasis **MD5 + Base64** (bukan HMAC, tanpa nonce/timestamp) → request dapat diputar ulang. Ini ketentuan J&T, bukan bug, tapi perlu dicatat.
2. `LWC_JT_Express_API` mengembalikan `exchange` berisi **header Basic auth dan body request utuh** ke browser lewat AJAX admin — berpotensi membocorkan kredensial di respons HTTP.
3. Password default impor member **didekripsi dan dicetak ke atribut `value`** di form, sehingga siapa pun dengan cap `create_users` dapat melihatnya di source HTML.
4. `get_importable_roles()` memakai `get_editable_roles()` → admin dapat mengimpor user dengan role `administrator` memakai password bersama.
5. Validasi file impor **hanya dari ekstensi**; beberapa jalur error tidak menghapus file temporer.
6. Endpoint J&T production di-hardcode dan tersebar di beberapa domain berbeda (`ecommerce.jntexpress.id`, `api.jet.co.id`, `secure-jk.jet.co.id`, `partner-track.jet.co.id`) — perubahan domain butuh rilis kode.
7. `test_tracking_connection()` menggunakan nomor resi fiktif dan bisa mengembalikan "sukses" untuk proyek dengan izin parsial.

**Ketidakkonsistenan**
8. **Password field menampilkan ciphertext** (`lwc2:…`) karena field kredensial memanggil `get_option()` langsung tanpa dekripsi. Idempoten, tapi membingungkan admin.
9. J&T: bila meta environment tidak ada, `get_order_environment()` **default ke sandbox** → order production bisa membuat AWB sandbox.
10. Idempotensi AWB J&T hanya mengandalkan pengecekan meta, tanpa lock/transient → ada potensi race.
11. RaySpeed masih **Sandbox-only** dan tidak punya endpoint label, meski README menyebutkan pelacakan penuh.
12. Tab Currency merender form, tetapi `register_setting`-nya ada di kelas lain — rawan bila urutan load berubah.

**Arsitektur**
13. Logika membership **diduplikasi di 3 kelas**; hanya `LWC_Admin_Settings` yang aktif (`membership/` tidak pernah di-instantiate).
14. Metode pengiriman didaftarkan di **dua tempat** (bootstrap + `LWC_Core`) dengan alias; `LWC_Shipping_Provider` ada tapi tidak dipakai.
15. Promo admin **meng-query semua kupon WooCommerce**, bukan hanya yang bertanda `_lwc_promo_created` → bisa mengedit/menghapus kupon biasa.
16. Masih ada jalur promo otomatis legacy di `LWC_Promo_Dashboard`.
17. README **ketinggalan jauh** dari kode (masih menyebut FedEx/J&T "in progress", padahal sudah live).
18. Tidak ada test suite terlihat di repo.

---

## 6. Rekomendasi prioritas

1. **Redam `exchange` J&T** sebelum dikirim ke browser (masking header auth/body).
2. **Samakan tampilan field kredensial** — dekripsi untuk ditampilkan, atau jangan pernah menampilkan nilai tersimpan.
3. **Batasi role impor member** (jangan izinkan role elevated dari impor massal) dan hentikan pencetakan password default ke HTML.
4. **Default-kan environment J&T ke production bila meta tidak ada**, atau wajibkan meta environment.
5. **Persempit Promo admin** ke kupon bertanda, atau dokumentasikan secara eksplisit bahwa semua kupon memang dikelola.
6. **Konsolidasi membership** (hapus `membership/`) dan pendaftaran shipping method.
7. **Perbarui README** agar sesuai kondisi nyata; sinkronkan juga bagian PRD yang masih menyebut versi 1.0.66.
8. Tambahkan smoke test untuk konverter mata uang, tarif J&T, dan kuota kupon.
