<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookmarkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
        ];
    }
}
