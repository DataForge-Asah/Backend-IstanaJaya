<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiLogResource extends JsonResource
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
            'ai_type' => $this->ai_type,
            'input_text' => $this->input_text,
            'output_result' => $this->output_result,
            'confidence_score' => $this->confidence_score !== null ? (float) $this->confidence_score : null,
            'model_name' => $this->model_name,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'kode_barang' => $this->product->kode_barang,
                'nama_barang' => $this->product->nama_barang,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
