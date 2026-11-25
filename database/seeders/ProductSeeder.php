<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('data/inventaris_clean.csv');

        if (! is_file($csvPath)) {
            $this->command?->warn("File CSV tidak ditemukan di {$csvPath}. Melewati seeding produk.");

            return;
        }

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Gagal membuka file CSV: {$csvPath}");
        }

        try {
            $rawHeaders = fgetcsv($handle, 0, ',', '"', '');

            if ($rawHeaders === false || $rawHeaders === [null]) {
                $this->command?->warn('File CSV kosong. Melewati seeding produk.');

                return;
            }

            $headers = array_map(static function (?string $header, int $index): string {
                $value = (string) $header;

                if ($index === 0) {
                    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
                }

                return strtolower(trim($value));
            }, $rawHeaders, array_keys($rawHeaders));

            $categoryMap = Category::query()
                ->pluck('id', 'name')
                ->mapWithKeys(static fn (int $id, string $name): array => [mb_strtolower(trim($name)) => $id])
                ->all();

            $seededCount = 0;

            DB::transaction(function () use ($handle, $headers, $categoryMap, &$seededCount): void {
                $lineNumber = 1;

                while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $lineNumber++;

                    if ($values === [null] || count(array_filter($values, static fn ($v) => trim((string) $v) !== '')) === 0) {
                        continue;
                    }

                    $row = [];
                    foreach ($headers as $index => $header) {
                        $row[$header] = $values[$index] ?? '';
                    }

                    $rawKode = trim((string) ($row['kode'] ?? ''));
                    if ($rawKode === '') {
                        throw new RuntimeException("Kolom 'kode' kosong pada baris {$lineNumber}.");
                    }

                    $kodeBarang = str_pad($rawKode, 8, '0', STR_PAD_LEFT);
                    $namaBarang = preg_replace('/\s+/', ' ', trim((string) ($row['nama_barang'] ?? ''))) ?? '';

                    $kategoriName = trim((string) ($row['kategori'] ?? ''));
                    $categoryId = $categoryMap[mb_strtolower($kategoriName)] ?? null;

                    if ($categoryId === null) {
                        throw new RuntimeException("Kategori '{$kategoriName}' tidak ditemukan di tabel categories (baris {$lineNumber}).");
                    }

                    $deptRaw = trim((string) ($row['dept'] ?? ''));
                    $dept = $deptRaw === '' ? null : $deptRaw;

                    $barcodeRaw = trim((string) ($row['barcode'] ?? ''));
                    $barcode = $barcodeRaw === '' ? null : $barcodeRaw;

                    $harga = max(0, (int) round((float) trim((string) ($row['harga'] ?? '0'))));
                    $qty = max(0, (int) round((float) trim((string) ($row['qty_bersih'] ?? '0'))));

                    $qtyAsliRaw = trim((string) ($row['qty_asli'] ?? ''));
                    $qtyAsli = $qtyAsliRaw === '' ? null : (int) round((float) $qtyAsliRaw);
                    $needsStockCheck = $qtyAsli !== null && $qtyAsli < 0;

                    Product::updateOrCreate(
                        ['kode_barang' => $kodeBarang],
                        [
                            'nama_barang' => $namaBarang,
                            'category_id' => $categoryId,
                            'dept' => $dept,
                            'barcode' => $barcode,
                            'harga' => $harga,
                            'qty' => $qty,
                            'qty_asli' => $qtyAsli,
                            'needs_stock_check' => $needsStockCheck,
                        ]
                    );

                    $seededCount++;
                }
            });

            $this->command?->info("Berhasil melakukan seeding {$seededCount} produk dari inventaris_clean.csv.");
        } finally {
            fclose($handle);
        }
    }
}
