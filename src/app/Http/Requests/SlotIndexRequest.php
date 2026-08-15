<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SlotIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => 'sometimes|numeric',
        ];
    }
}
