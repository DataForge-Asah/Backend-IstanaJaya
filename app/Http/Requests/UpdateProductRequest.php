<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('kode_barang')) {
            $kode = trim((string) $this->input('kode_barang'));
            if ($kode !== '' && strlen($kode) < 8) {
                $kode = str_pad($kode, 8, '0', STR_PAD_LEFT);
            }
            $merged['kode_barang'] = $kode;
        }

        if ($this->has('nama_barang')) {
            $merged['nama_barang'] = preg_replace('/\s+/', ' ', trim((string) $this->input('nama_barang'))) ?? '';
        }

        if ($this->has('dept')) {
            $dept = trim((string) $this->input('dept'));
            $merged['dept'] = $dept === '' ? null : strtoupper($dept);
        }

        if ($this->has('barcode')) {
            $barcode = trim((string) $this->input('barcode'));
            $merged['barcode'] = $barcode === '' ? null : $barcode;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'kode_barang' => ['sometimes', 'required', 'string', 'size:8', Rule::unique('products', 'kode_barang')->ignore($productId)],
            'nama_barang' => ['sometimes', 'required', 'string', 'max:150'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'dept' => ['nullable', 'string', 'max:10'],
            'barcode' => ['nullable', 'string', 'max:30', Rule::unique('products', 'barcode')->ignore($productId)],
            'harga' => ['sometimes', 'required', 'integer', 'min:0'],
            'satuan' => ['sometimes', 'required', 'string', 'max:30'],
            'min_stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'lokasi_rak' => ['nullable', 'string', 'max:50'],
            'deskripsi' => ['nullable', 'string'],
            'needs_stock_check' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
