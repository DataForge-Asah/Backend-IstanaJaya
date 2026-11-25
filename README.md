# Backend-IstanaJaya — Dokumentasi & Spesifikasi Database

Backend REST API untuk proyek Capstone **Asah 2026 (Tim A26-CS182 — DataForge)**:  
**Digitalisasi Pencatatan dan Pemantauan Stok Barang pada Toko Istana Jaya Elektronik**.

Dokumen ini menjadi acuan utama (*single source of truth*) untuk struktur database, relasi antar tabel, aturan bisnis stok, pemetaan dataset dari tim Data Science, serta panduan migrasi awal di **Laravel 13 + MariaDB/MySQL**.

---

## 1. Tech Stack & Lingkungan Database

- **Framework:** Laravel 13 (API-Only / Decoupled, konvensi endpoint `/api/v1/...`)
- **Database:** MariaDB / MySQL (`utf8mb4` / `utf8mb4_unicode_ci`)
- **Integrasi Eksternal:** FastAPI AI Service (Klasifikasi Kategori ML & Deskripsi Generative AI)
- **Sumber Dataset Awal:** `inventaris_clean` dari tim Data Science (`dataset_stok_istana_jaya_final.xlsx` — 158 produk unik, 7 kategori)

### Konfigurasi `.env` (MariaDB Lokal macOS)
```env
APP_NAME="Istana Jaya Inventory API"

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=istana_jaya_db
DB_USERNAME=root
DB_PASSWORD=

AI_SERVICE_URL=[http://127.0.0.1:8001](http://127.0.0.1:8001)
AI_SERVICE_TIMEOUT=15
```

> **Catatan:** Gunakan `127.0.0.1` (bukan `localhost`) untuk menghindari error socket di macOS. Jika menggunakan Oracle MySQL 8+, ganti `DB_CONNECTION=mysql`.

---

## 2. Batasan Cakupan & Privasi Data (WAJIB DIPATUHI)

Sesuai kesepakatan wawancara dengan PIC UMKM (Bapak M. Nauval Rianda), skema database **DILARANG** menyimpan:
- Harga beli / harga modal barang dan nominal keuntungan.
- Data transaksi penjualan / kasir, retur, garansi, atau laporan keuangan.
- Data pribadi pelanggan maupun data pemasok (*supplier*).

Database hanya berfokus pada **katalog inventaris, pencarian stok instan, pembaruan stok, riwayat pergerakan stok (`IN`/`OUT`/`OPNAME`), dan log layanan AI**.

---

## 3. Klasifikasi Tabel & ERD

Database terdiri dari **5 tabel utama** (3 Tabel Master dan 2 Tabel Transaksi/Log):

| Jenis Tabel | Nama Tabel | Status | Peran Utama |
| :--- | :--- | :--- | :--- |
| **Master** | `categories` | Wajib (MVP) | Master 7 kode & nama kategori barang hasil finalisasi tim DS. |
| **Master** | `products` | Wajib (MVP) | Data induk katalog stok barang (mapping dari `inventaris_clean`). |
| **Master** | `users` | Opsional | Akun `owner` & `staff` (memperluas tabel `users` bawaan Laravel). |
| **Transaksi / Log** | `stock_logs` | Wajib (MVP) | Audit trail setiap perubahan stok (`IN`, `OUT`, `OPNAME`). |
| **Transaksi / Log** | `ai_logs` | Pendukung (AI) | Riwayat request/response klasifikasi ML & deskripsi Generative AI. |

### Entity Relationship Diagram (Mermaid)

```mermaid
erDiagram
    categories ||--o{ products : "category_id (RESTRICT)"
    products ||--o{ stock_logs : "product_id (CASCADE)"
    products |o--o{ ai_logs : "product_id (SET NULL)"
    users |o--o{ stock_logs : "user_id (SET NULL)"

    categories {
        bigint id PK
        varchar code UK "VARCHAR 10 NOT NULL"
        varchar name UK "VARCHAR 100 NOT NULL"
        timestamp created_at
        timestamp updated_at
    }

    users {
        bigint id PK
        varchar name "VARCHAR 255 NOT NULL"
        varchar username UK "VARCHAR 50 NULLABLE"
        varchar email UK "VARCHAR 255 NOT NULL"
        varchar password "VARCHAR 255 NOT NULL"
        enum role "owner | staff DEFAULT staff"
        boolean is_active "DEFAULT true"
        timestamp created_at
        timestamp updated_at
    }

    products {
        bigint id PK
        char kode_barang UK "CHAR 8 NOT NULL - cth 00001415"
        varchar nama_barang "VARCHAR 150 NOT NULL INDEX"
        bigint category_id FK "NOT NULL -> categories.id"
        varchar dept "VARCHAR 10 NULL INDEX - BB, BR, LP, AL, PKJ"
        varchar barcode UK "VARCHAR 30 NULL"
        int harga "UNSIGNED NOT NULL DEFAULT 0"
        int qty "UNSIGNED NOT NULL DEFAULT 0 INDEX"
        int qty_asli "SIGNED NULL - arsip kasir"
        boolean needs_stock_check "DEFAULT false INDEX"
        varchar satuan "VARCHAR 30 NOT NULL DEFAULT pcs"
        int min_stock "UNSIGNED NOT NULL DEFAULT 5"
        varchar lokasi_rak "VARCHAR 50 NULL"
        text deskripsi "NULL - manual atau Gen AI"
        boolean is_active "DEFAULT true"
        timestamp created_at
        timestamp updated_at
    }

    stock_logs {
        bigint id PK
        bigint product_id FK "NOT NULL -> products.id"
        bigint user_id FK "NULL -> users.id"
        enum change_type "IN | OUT | OPNAME"
        int qty_before "SIGNED NOT NULL"
        int qty_change "SIGNED NOT NULL"
        int qty_after "UNSIGNED NOT NULL"
        text note "NULL"
        timestamp created_at
        timestamp updated_at
    }

    ai_logs {
        bigint id PK
        bigint product_id FK "NULL -> products.id"
        enum ai_type "CLASSIFICATION | GEN_AI_DESCRIPTION"
        text input_text "NOT NULL"
        text output_result "NOT NULL"
        decimal confidence_score "DECIMAL 5,4 NULL"
        varchar model_name "VARCHAR 100 NULL"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 4. Spesifikasi Detail Migrasi (Laravel Blueprint — MariaDB/MySQL)

> **Penting untuk Migrasi:**
> 1. Laravel 13 sudah memiliki migrasi bawaan `0001_01_01_000000_create_users_table.php`. **Jangan membuat ulang tabel `users`**; tambahkan kolom `username`, `role`, dan `is_active` melalui migrasi `ALTER TABLE` terpisah.
> 2. Semua Primary Key menggunakan `$table->id()` (`BIGINT UNSIGNED AUTO_INCREMENT`), sehingga Foreign Key wajib menggunakan `$table->foreignId(...)`.
> 3. Urutan pembuatan migrasi wajib: `categories` → `products` → `stock_logs` → `ai_logs` (agar Foreign Key tidak gagal saat `php artisan migrate`).

### 4.1. Tabel `categories`
| Kolom | Laravel Blueprint | Constraint & Keterangan |
| :--- | :--- | :--- |
| `id` | `$table->id()` | Primary Key |
| `code` | `$table->string('code', 10)->unique()` | Kode kategori: `BL`, `CA`, `KS`, `KA`, `MT`, `PP`, `LM` |
| `name` | `$table->string('name', 100)->unique()` | Nama tampilan UI |
| `timestamps` | `$table->timestamps()` | `created_at`, `updated_at` |

### 4.2. Alter Tabel `users` (Opsional / Pengembangan Lanjutan)
Tambahkan kolom ke tabel `users` bawaan Laravel lewat migrasi `add_role_fields_to_users_table`:
| Kolom | Laravel Blueprint | Constraint & Keterangan |
| :--- | :--- | :--- |
| `username` | `$table->string('username', 50)->nullable()->unique()->after('name')` | Username login staf/pemilik |
| `role` | `$table->enum('role', ['owner', 'staff'])->default('staff')->after('password')` | Hak akses |
| `is_active` | `$table->boolean('is_active')->default(true)->after('role')` | Status aktif akun |

### 4.3. Tabel `products`
| Kolom | Laravel Blueprint | Constraint & Keterangan |
| :--- | :--- | :--- |
| `id` | `$table->id()` | Primary Key |
| `kode_barang` | `$table->char('kode_barang', 8)->unique()` | Teks 8 digit dari `inventaris_clean.kode` (cth: `'00001415'`) |
| `nama_barang` | `$table->string('nama_barang', 150)->index()` | Nama barang (di-index untuk pencarian cepat) |
| `category_id` | `$table->foreignId('category_id')->constrained('categories')->cascadeOnUpdate()->restrictOnDelete()` | Relasi wajib ke `categories.id` |
| `dept` | `$table->string('dept', 10)->nullable()->index()` | Kode departemen kasir (`BB`, `BR`, `LP`, `AL`, `PKJ`); 2 baris `NULL` |
| `barcode` | `$table->string('barcode', 30)->nullable()->unique()` | Barcode kasir/EAN-13; MariaDB mengizinkan banyak `NULL` pada kolom `UNIQUE` |
| `harga` | `$table->unsignedInteger('harga')->default(0)` | Harga jual (Rp) bilangan bulat tanpa titik ribuan |
| `qty` | `$table->unsignedInteger('qty')->default(0)->index()` | Stok aktif di aplikasi (tidak boleh negatif); awal diisi dari `qty_bersih` |
| `qty_asli` | `$table->integer('qty_asli')->nullable()` | Signed integer; arsip stok asli kasir (boleh negatif atau `NULL`) |
| `needs_stock_check` | `$table->boolean('needs_stock_check')->default(false)->index()` | `true` jika `qty_asli < 0` untuk menampilkan badge **"Perlu Dicek"** di UI |
| `satuan` | `$table->string('satuan', 30)->default('pcs')` | Satuan barang (`pcs`, `kaleng`, `batang`, `buah`, `meter`) |
| `min_stock` | `$table->unsignedInteger('min_stock')->default(5)` | Ambang batas peringatan stok menipis (`qty <= min_stock`) |
| `lokasi_rak` | `$table->string('lokasi_rak', 50)->nullable()` | Lokasi fisik rak/gudang di toko |
| `deskripsi` | `$table->text('deskripsi')->nullable()` | Keterangan manual atau hasil Generative AI |
| `is_active` | `$table->boolean('is_active')->default(true)` | Soft delete barang yang tidak lagi dijual |
| `timestamps` | `$table->timestamps()` | `created_at`, `updated_at` |

### 4.4. Tabel `stock_logs`
| Kolom | Laravel Blueprint | Constraint & Keterangan |
| :--- | :--- | :--- |
| `id` | `$table->id()` | Primary Key |
| `product_id` | `$table->foreignId('product_id')->constrained('products')->cascadeOnDelete()` | Relasi ke `products.id` |
| `user_id` | `$table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()` | Nullable agar MVP berjalan tanpa fitur login |
| `change_type` | `$table->enum('change_type', ['IN', 'OUT', 'OPNAME'])` | Jenis transaksi stok |
| `qty_before` | `$table->integer('qty_before')` | Jumlah stok sebelum perubahan |
| `qty_change` | `$table->integer('qty_change')` | Selisih perubahan (bisa positif/negatif) |
| `qty_after` | `$table->unsignedInteger('qty_after')` | Jumlah stok setelah perubahan (wajib `>= 0`) |
| `note` | `$table->text('note')->nullable()` | Catatan barang masuk/keluar/opname fisik |
| `timestamps` | `$table->timestamps()` | Composite index pada `['product_id', 'created_at']` |

### 4.5. Tabel `ai_logs`
| Kolom | Laravel Blueprint | Constraint & Keterangan |
| :--- | :--- | :--- |
| `id` | `$table->id()` | Primary Key |
| `product_id` | `$table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete()` | Relasi opsional (bisa `NULL` saat prediksi sebelum produk disimpan) |
| `ai_type` | `$table->enum('ai_type', ['CLASSIFICATION', 'GEN_AI_DESCRIPTION'])` | Jenis layanan AI yang dipanggil |
| `input_text` | `$table->text('input_text')` | Nama barang atau prompt input |
| `output_result` | `$table->text('output_result')` | Prediksi kategori atau teks deskripsi hasil AI |
| `confidence_score` | `$table->decimal('confidence_score', 5, 4)->nullable()` | Skor kepercayaan model ML (misal `0.9450`) |
| `model_name` | `$table->string('model_name', 100)->nullable()` | Nama model ML atau API Gen AI yang digunakan |
| `timestamps` | `$table->timestamps()` | `created_at`, `updated_at` |

---

## 5. Relasi Eloquent & Aturan Bisnis Inti

### Relasi Model
- `Category`: `hasMany(Product::class)`
- `Product`: `belongsTo(Category::class)`, `hasMany(StockLog::class)`, `hasMany(AiLog::class)`
- `StockLog`: `belongsTo(Product::class)`, `belongsTo(User::class)`
- `AiLog`: `belongsTo(Product::class)`
- `User`: `hasMany(StockLog::class)`

### Aturan Bisnis Stok
1. Setiap perubahan stok wajib dibungkus `DB::transaction()` dengan `lockForUpdate()` pada baris `products`.
2. **`IN`**: `qty_after = qty_before + amount`, `qty_change = +amount`.
3. **`OUT`**: Tolak dengan HTTP `422` jika `amount > qty_before` (stok tidak boleh menjadi negatif).
4. **`OPNAME`**: `qty_after = amount`, `qty_change = qty_after - qty_before`, dan otomatis set `products.needs_stock_check = false` karena stok fisik sudah diverifikasi.
5. **Status Stok (Computed di API Resource):**
   - `HABIS` jika `qty == 0`
   - `MENIPIS` jika `qty > 0 && qty <= min_stock`
   - `AMAN` jika `qty > min_stock`

---

## 6. Spesifikasi Seeding Data Awal (`CategorySeeder` & `ProductSeeder`)

### 6.1. Master 7 Kategori (`CategorySeeder`)
> Kode `ATG` dari kasir lama sudah digabung ke `MT` (**Meteran**) oleh tim DS. Kategori `LM` (**Lem**, 1 baris) tetap disimpan di database inventaris meskipun dikeluarkan dari dataset latih model AI (`dataset_klasifikasi`).

| `code` | `name` |
| :--- | :--- |
| `BL` | Bola Lampu |
| `CA` | Cat |
| `KS` | Kuas |
| `KA` | Kran Air |
| `MT` | Meteran |
| `PP` | Pipa |
| `LM` | Lem |

### 6.2. Aturan Pemetaan `inventaris_clean.csv` → `products` (`ProductSeeder`)
Letakkan file CSV bersih dari tim DS di `database/data/inventaris_clean.csv`, lalu petakan kolomnya sebagai berikut:

| Kolom CSV (`inventaris_clean`) | Kolom Tabel `products` | Aturan Transformasi |
| :--- | :--- | :--- |
| `kode` | `kode_barang` | Wajib disimpan sebagai string 8 digit (`str_pad(..., 8, '0', STR_PAD_LEFT)`). |
| `nama_barang` | `nama_barang` | Trim spasi berlebih; pertahankan tanda kutip inci (`"`) dengan parser CSV standar (`fgetcsv`). |
| `kategori` | `category_id` | Cocokkan dengan `categories.name` untuk mengambil `id`. |
| `dept` | `dept` | Kosong (`""`) dikonversi menjadi `null`. |
| `barcode` | `barcode` | Simpan sebagai string; kosong (`""`) dikonversi menjadi `null`. |
| `harga` | `harga` | Integer non-negatif. |
| `qty_bersih` | `qty` | Integer non-negatif (stok awal tampil di aplikasi). |
| `qty_asli` | `qty_asli` | Signed integer; kosong (`""`) dikonversi menjadi `null`. |
| *(diturunkan dari `qty_asli`)* | `needs_stock_check` | Set `true` jika `qty_asli !== null && qty_asli < 0` (47 produk), selain itu `false`. |

---

## 7. Perintah Setup & Migrasi Awal

```bash
# 1. Buat database di MariaDB (jika belum dibuat)
# CREATE DATABASE istana_jaya_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 2. Bersihkan cache konfigurasi setelah edit .env
php artisan config:clear

# 3. Jalankan seluruh migrasi & seeder kategori (dan produk jika CSV tersedia)
php artisan migrate:fresh --seed

# 4. Verifikasi status tabel
php artisan migrate:status
```