<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    /**
     * Adjust product stock atomically with row-level lock and audit log.
     *
     * @return array{product: Product, stock_log: StockLog}
     */
    public function adjust(
        Product $product,
        string $changeType,
        int $amount,
        ?string $note = null,
        ?int $userId = null,
    ): array {
        return DB::transaction(function () use ($product, $changeType, $amount, $note, $userId): array {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $qtyBefore = $lockedProduct->qty;

            if ($changeType === 'IN') {
                $qtyAfter = $qtyBefore + $amount;
                $qtyChange = $amount;
            } elseif ($changeType === 'OUT') {
                if ($amount > $qtyBefore) {
                    throw ValidationException::withMessages([
                        'amount' => [
                            "Stok tidak mencukupi untuk pengeluaran. Stok saat ini {$qtyBefore}, diminta {$amount}.",
                        ],
                    ]);
                }

                $qtyAfter = $qtyBefore - $amount;
                $qtyChange = -$amount;
            } else {
                // OPNAME
                $qtyAfter = $amount;
                $qtyChange = $qtyAfter - $qtyBefore;
                $lockedProduct->needs_stock_check = false;
            }

            $lockedProduct->qty = $qtyAfter;
            $lockedProduct->save();

            $stockLog = StockLog::query()->create([
                'product_id' => $lockedProduct->id,
                'user_id' => $userId,
                'change_type' => $changeType,
                'qty_before' => $qtyBefore,
                'qty_change' => $qtyChange,
                'qty_after' => $qtyAfter,
                'note' => $note,
            ]);

            $lockedProduct->load('category');
            $stockLog->load(['product', 'user']);

            return [
                'product' => $lockedProduct,
                'stock_log' => $stockLog,
            ];
        });
    }
}
