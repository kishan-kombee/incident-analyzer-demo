<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'logs' => ['required', 'array'],
            'logs.*' => ['string'],
            'metrics' => ['required', 'array'],
            'metrics.cpu' => ['nullable', 'integer', 'min:0', 'max:100'],
            'metrics.db_latency' => ['nullable', 'integer', 'min:0'],
            'metrics.requests_per_sec' => ['nullable', 'string', 'max:64'],
        ];
    }
}
