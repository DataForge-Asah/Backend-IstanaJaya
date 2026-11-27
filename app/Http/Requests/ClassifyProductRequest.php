<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassifyProductRequest extends FormRequest
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
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ];
    }
}
