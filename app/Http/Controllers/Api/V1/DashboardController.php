<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\StockLogResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLog;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $activeQuery = Product::query()->where('is_active', true);

        $totalProducts = Product::query()->count();
        $activeProducts = (clone $activeQuery)->count();
        $totalStockQty = (int) (clone $activeQuery)->sum('qty');
        $habisCount = (clone $activeQuery)->where('qty', 0)->count();
        $menipisCount = (clone $activeQuery)
            ->where('qty', '>', 0)
            ->whereColumn('qty', '<=', 'min_stock')
            ->count();
        $amanCount = (clone $activeQuery)
            ->whereColumn('qty', '>', 'min_stock')
            ->count();
        $needsStockCheckCount = (clone $activeQuery)
            ->where('needs_stock_check', true)
            ->count();

        $categoriesBreakdown = Category::query()
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->withSum(['products' => fn ($query) => $query->where('is_active', true)], 'qty')
            ->orderBy('name')
            ->get();

        $recentStockLogs = StockLog::query()
            ->with(['product', 'user'])
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'total_products' => $totalProducts,
                'active_products' => $activeProducts,
                'total_stock_qty' => $totalStockQty,
                'needs_stock_check_count' => $needsStockCheckCount,
                'stock_status' => [
                    'AMAN' => $amanCount,
                    'MENIPIS' => $menipisCount,
                    'HABIS' => $habisCount,
                ],
                'categories' => CategoryResource::collection($categoriesBreakdown),
                'recent_stock_logs' => StockLogResource::collection($recentStockLogs),
            ],
        ]);
    }
}
