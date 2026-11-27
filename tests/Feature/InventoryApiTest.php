<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_cors_headers_for_vite(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_categories_crud_and_delete_protection(): void
    {
        $create = $this->postJson('/api/v1/categories', [
            'code' => 'bl',
            'name' => 'Bola Lampu',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'BL')
            ->assertJsonPath('data.name', 'Bola Lampu');

        $categoryId = $create->json('data.id');

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/categories/{$categoryId}", [
            'name' => 'Lampu LED',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Lampu LED');

        Product::query()->create([
            'kode_barang' => '00001415',
            'nama_barang' => 'BOLA LAMPU 5W',
            'category_id' => $categoryId,
            'harga' => 15000,
            'qty' => 10,
        ]);

        // Cannot delete category that still has products
        $this->deleteJson("/api/v1/categories/{$categoryId}")
            ->assertStatus(409);
    }

    public function test_products_crud_search_lookup_and_computed_stock_status(): void
    {
        $category = Category::query()->create(['code' => 'BL', 'name' => 'Bola Lampu']);

        $store = $this->postJson('/api/v1/products', [
            'kode_barang' => '1415',
            'nama_barang' => 'BOLA LAMPU STARLUX 5W',
            'category_id' => $category->id,
            'dept' => 'lp',
            'barcode' => '8990100658053',
            'harga' => 15000,
            'qty' => 4,
            'min_stock' => 5,
            'satuan' => 'pcs',
            'lokasi_rak' => 'A-01',
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.kode_barang', '00001415')
            ->assertJsonPath('data.dept', 'LP')
            ->assertJsonPath('data.stock_status', 'MENIPIS');

        $productId = $store->json('data.id');

        // Lookup by barcode & unpadded kode_barang
        $this->getJson('/api/v1/products/lookup?code=8990100658053')
            ->assertOk()
            ->assertJsonPath('data.id', $productId);

        $this->getJson('/api/v1/products/lookup?code=1415')
            ->assertOk()
            ->assertJsonPath('data.id', $productId);

        // Search & filter by status MENIPIS
        $this->getJson('/api/v1/products?search=STARLUX&status=MENIPIS')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Soft deactivate
        $this->deleteJson("/api/v1/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Default index excludes inactive products
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_stock_adjustments_follow_business_rules(): void
    {
        $category = Category::query()->create(['code' => 'CA', 'name' => 'Cat']);
        $product = Product::query()->create([
            'kode_barang' => '00002001',
            'nama_barang' => 'CAT TEMBOK PUTIH 5KG',
            'category_id' => $category->id,
            'harga' => 85000,
            'qty' => 10,
            'qty_asli' => -3,
            'needs_stock_check' => true,
            'min_stock' => 5,
        ]);

        // IN: 10 + 5 = 15
        $this->postJson("/api/v1/products/{$product->id}/stock", [
            'change_type' => 'IN',
            'amount' => 5,
            'note' => 'Restok gudang',
        ])->assertOk()
            ->assertJsonPath('data.product.qty', 15)
            ->assertJsonPath('data.product.stock_status', 'AMAN')
            ->assertJsonPath('data.stock_log.qty_before', 10)
            ->assertJsonPath('data.stock_log.qty_change', 5)
            ->assertJsonPath('data.stock_log.qty_after', 15);

        // OUT > qty_before -> HTTP 422
        $this->postJson("/api/v1/products/{$product->id}/stock", [
            'change_type' => 'OUT',
            'amount' => 20,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        // OUT valid: 15 - 15 = 0 -> HABIS
        $this->postJson("/api/v1/products/{$product->id}/stock", [
            'change_type' => 'OUT',
            'amount' => 15,
            'note' => 'Barang keluar habis',
        ])->assertOk()
            ->assertJsonPath('data.product.qty', 0)
            ->assertJsonPath('data.product.stock_status', 'HABIS')
            ->assertJsonPath('data.stock_log.qty_change', -15);

        // OPNAME sets exact qty and clears needs_stock_check
        $this->postJson("/api/v1/products/{$product->id}/stock", [
            'change_type' => 'OPNAME',
            'amount' => 7,
            'note' => 'Hasil cek fisik rak',
        ])->assertOk()
            ->assertJsonPath('data.product.qty', 7)
            ->assertJsonPath('data.product.needs_stock_check', false)
            ->assertJsonPath('data.stock_log.qty_before', 0)
            ->assertJsonPath('data.stock_log.qty_change', 7)
            ->assertJsonPath('data.stock_log.qty_after', 7);

        $this->getJson("/api/v1/products/{$product->id}/stock-logs")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/stock-logs?change_type=OPNAME')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_dashboard_summary_aggregates_inventory_metrics(): void
    {
        $cat = Category::query()->create(['code' => 'PP', 'name' => 'Pipa']);

        Product::query()->create([
            'kode_barang' => '00003001',
            'nama_barang' => 'PIPA 1/2"',
            'category_id' => $cat->id,
            'harga' => 12000,
            'qty' => 20,
            'min_stock' => 5,
            'needs_stock_check' => false,
        ]);

        Product::query()->create([
            'kode_barang' => '00003002',
            'nama_barang' => 'PIPA 3/4"',
            'category_id' => $cat->id,
            'harga' => 15000,
            'qty' => 3,
            'min_stock' => 5,
            'needs_stock_check' => true,
        ]);

        Product::query()->create([
            'kode_barang' => '00003003',
            'nama_barang' => 'PIPA 1"',
            'category_id' => $cat->id,
            'harga' => 18000,
            'qty' => 0,
            'min_stock' => 5,
            'needs_stock_check' => false,
        ]);

        $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.active_products', 3)
            ->assertJsonPath('data.total_stock_qty', 23)
            ->assertJsonPath('data.needs_stock_check_count', 1)
            ->assertJsonPath('data.stock_status.AMAN', 1)
            ->assertJsonPath('data.stock_status.MENIPIS', 1)
            ->assertJsonPath('data.stock_status.HABIS', 1);
    }

    public function test_ai_classify_generate_description_and_offline_handling(): void
    {
        $cat = Category::query()->create(['code' => 'BL', 'name' => 'Bola Lampu']);
        $product = Product::query()->create([
            'kode_barang' => '00004001',
            'nama_barang' => 'LAMPU LED PHILIPS 12W',
            'category_id' => $cat->id,
            'harga' => 35000,
            'qty' => 12,
        ]);

        Http::fake([
            '*/classify' => Http::response([
                'predicted_category' => 'Bola Lampu',
                'confidence_score' => 0.9625,
                'model_name' => 'tfidf-linear-svc-v1',
            ], 200),
            '*/generate-description' => Http::response([
                'deskripsi' => 'Lampu LED hemat energi 12W dengan cahaya terang tahan lama.',
                'model_name' => 'gemini-2.5-flash',
            ], 200),
        ]);

        $this->postJson('/api/v1/ai/classify', [
            'nama_barang' => 'LAMPU LED PHILIPS 12W',
            'product_id' => $product->id,
        ])->assertOk()
            ->assertJsonPath('data.predicted_category', 'Bola Lampu')
            ->assertJsonPath('data.matched_category.code', 'BL')
            ->assertJsonPath('data.confidence_score', 0.9625);

        $this->postJson('/api/v1/ai/generate-description', [
            'nama_barang' => 'LAMPU LED PHILIPS 12W',
            'kategori' => 'Bola Lampu',
            'product_id' => $product->id,
            'apply_to_product' => true,
        ])->assertOk()
            ->assertJsonPath('data.applied_to_product', true);

        $this->assertSame(
            'Lampu LED hemat energi 12W dengan cahaya terang tahan lama.',
            $product->fresh()->deskripsi
        );

        $this->getJson('/api/v1/ai/logs')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Offline AI service returns 503 gracefully
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->postJson('/api/v1/ai/classify', [
            'nama_barang' => 'CAT MINYAK',
        ])->assertStatus(503);
    }

    public function test_auth_flow_and_stock_log_user_attribution(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nauval Owner',
            'username' => 'nauval',
            'email' => 'nauval@istanajaya.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'owner',
        ]);

        $register->assertCreated()
            ->assertJsonPath('data.user.role', 'owner');

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'nauval',
            'password' => 'password123',
        ]);

        $login->assertOk();
        $token = $login->json('data.token');
        $userId = User::query()->where('username', 'nauval')->value('id');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'nauval');

        $cat = Category::query()->create(['code' => 'KS', 'name' => 'Kuas']);
        $product = Product::query()->create([
            'kode_barang' => '00005001',
            'nama_barang' => 'KUAS CAT 2 INCH',
            'category_id' => $cat->id,
            'harga' => 10000,
            'qty' => 5,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/products/{$product->id}/stock", [
                'change_type' => 'IN',
                'amount' => 3,
            ])->assertOk()
            ->assertJsonPath('data.stock_log.user_id', $userId);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }
}
