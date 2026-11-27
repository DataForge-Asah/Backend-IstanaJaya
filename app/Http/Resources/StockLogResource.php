<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLogResource extends JsonResource
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
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'change_type' => $this->change_type,
            'qty_before' => $this->qty_before,
            'qty_change' => $this->qty_change,
            'qty_after' => $this->qty_after,
            'note' => $this->note,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'kode_barang' => $this->product->kode_barang,
                'nama_barang' => $this->product->nama_barang,
                'satuan' => $this->product->satuan,
            ]),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
