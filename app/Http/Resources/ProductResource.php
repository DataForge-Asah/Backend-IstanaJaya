<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_barang' => $this->kode_barang,
            'nama_barang' => $this->nama_barang,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'dept' => $this->dept,
            'barcode' => $this->barcode,
            'harga' => $this->harga,
            'qty' => $this->qty,
            'qty_asli' => $this->qty_asli,
            'needs_stock_check' => $this->needs_stock_check,
            'satuan' => $this->satuan,
            'min_stock' => $this->min_stock,
            'stock_status' => $this->computeStockStatus(),
            'lokasi_rak' => $this->lokasi_rak,
            'deskripsi' => $this->deskripsi,
            'is_active' => $this->is_active,
            'stock_logs' => StockLogResource::collection($this->whenLoaded('stockLogs')),
            'ai_logs' => AiLogResource::collection($this->whenLoaded('aiLogs')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function computeStockStatus(): string
    {
        if ($this->qty === 0) {
            return 'HABIS';
        }

        if ($this->qty <= $this->min_stock) {
            return 'MENIPIS';
        }

        return 'AMAN';
    }
}
