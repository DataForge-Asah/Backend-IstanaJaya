<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama_barang' => ['required', 'string', 'min:2', 'max:150'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'harga' => ['nullable', 'integer', 'min:0'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'apply_to_product' => ['sometimes', 'boolean'],
        ];
    }
}
