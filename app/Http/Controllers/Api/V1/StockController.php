<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockLogResource;
use App\Models\Product;
use App\Models\StockLog;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    public function adjust(AdjustStockRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->stockService->adjust(
            product: $product,
            changeType: $validated['change_type'],
            amount: (int) $validated['amount'],
            note: $validated['note'] ?? null,
            userId: $request->user('sanctum')?->id,
        );

        return response()->json([
            'message' => 'Stok produk berhasil diperbarui.',
            'data' => [
                'product' => new ProductResource($result['product']),
                'stock_log' => new StockLogResource($result['stock_log']),
            ],
        ]);
    }

    public function productLogs(Request $request, Product $product): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $logs = $product->stockLogs()
            ->with('user')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return StockLogResource::collection($logs);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockLog::query()->with(['product', 'user']);

        if ($productId = $request->query('product_id')) {
            $query->where('product_id', (int) $productId);
        }

        if ($changeType = strtoupper(trim((string) $request->query('change_type', '')))) {
            if (in_array($changeType, ['IN', 'OUT', 'OPNAME'], true)) {
                $query->where('change_type', $changeType);
            }
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', (string) $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', (string) $dateTo);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $logs = $query
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return StockLogResource::collection($logs);
    }
}
