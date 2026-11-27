<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('change_type')) {
            $this->merge(['change_type' => strtoupper(trim((string) $this->input('change_type')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minAmount = in_array($this->input('change_type'), ['IN', 'OUT'], true) ? 1 : 0;

        return [
            'change_type' => ['required', 'string', Rule::in(['IN', 'OUT', 'OPNAME'])],
            'amount' => ['required', 'integer', "min:{$minAmount}"],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
