<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()->with('category');

        $isActiveParam = $request->query('is_active', 'true');
        if ($isActiveParam !== 'all') {
            $isActive = filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $query->where('is_active', $isActive ?? true);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('nama_barang', 'like', "%{$search}%")
                    ->orWhere('kode_barang', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('lokasi_rak', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($categoryCode = trim((string) $request->query('category_code', ''))) {
            $query->whereHas('category', fn ($q) => $q->where('code', strtoupper($categoryCode)));
        }

        if ($dept = trim((string) $request->query('dept', ''))) {
            $query->where('dept', strtoupper($dept));
        }

        if ($request->filled('needs_stock_check')) {
            $needsCheck = filter_var($request->query('needs_stock_check'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($needsCheck !== null) {
                $query->where('needs_stock_check', $needsCheck);
            }
        }

        if ($status = strtoupper(trim((string) $request->query('status', '')))) {
            if ($status === 'HABIS') {
                $query->where('qty', 0);
            } elseif ($status === 'MENIPIS') {
                $query->where('qty', '>', 0)->whereColumn('qty', '<=', 'min_stock');
            } elseif ($status === 'AMAN') {
                $query->whereColumn('qty', '>', 'min_stock');
            }
        }

        $allowedSort = ['nama_barang', 'kode_barang', 'harga', 'qty', 'updated_at', 'created_at'];
        $sortBy = in_array($request->query('sort_by'), $allowedSort, true)
            ? (string) $request->query('sort_by')
            : 'nama_barang';
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $products = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function lookup(Request $request): JsonResponse|ProductResource
    {
        $code = trim((string) $request->query('code', $request->query('barcode', $request->query('kode_barang', ''))));

        if ($code === '') {
            return response()->json([
                'message' => 'Parameter code, barcode, atau kode_barang wajib diisi.',
            ], 422);
        }

        $paddedCode = strlen($code) < 8 ? str_pad($code, 8, '0', STR_PAD_LEFT) : $code;

        $product = Product::query()
            ->with('category')
            ->where('barcode', $code)
            ->orWhere('kode_barang', $code)
            ->orWhere('kode_barang', $paddedCode)
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Produk tidak ditemukan.',
            ], 404);
        }

        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user('sanctum')?->id;

        $product = DB::transaction(function () use ($validated, $userId): Product {
            $product = Product::query()->create([
                'kode_barang' => $validated['kode_barang'],
                'nama_barang' => $validated['nama_barang'],
                'category_id' => $validated['category_id'],
                'dept' => $validated['dept'] ?? null,
                'barcode' => $validated['barcode'] ?? null,
                'harga' => $validated['harga'],
                'qty' => $validated['qty'] ?? 0,
                'satuan' => $validated['satuan'] ?? 'pcs',
                'min_stock' => $validated['min_stock'] ?? 5,
                'lokasi_rak' => $validated['lokasi_rak'] ?? null,
                'deskripsi' => $validated['deskripsi'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if ($product->qty > 0) {
                StockLog::query()->create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'change_type' => 'IN',
                    'qty_before' => 0,
                    'qty_change' => $product->qty,
                    'qty_after' => $product->qty,
                    'note' => 'Stok awal saat penambahan produk baru',
                ]);
            }

            return $product->load('category');
        });

        return (new ProductResource($product))
            ->additional(['message' => 'Produk berhasil ditambahkan.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $product->load([
            'category',
            'stockLogs' => fn ($q) => $q->with('user')->latest()->limit(20),
            'aiLogs' => fn ($q) => $q->latest()->limit(10),
        ]);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return (new ProductResource($product->fresh()->load('category')))
            ->additional(['message' => 'Data produk berhasil diperbarui.']);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);

        return response()->json([
            'message' => 'Produk berhasil dinonaktifkan.',
            'data' => new ProductResource($product->fresh()->load('category')),
        ]);
    }
}
