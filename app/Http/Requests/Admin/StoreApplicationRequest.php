<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:255', Rule::unique('applications', 'slug')],
            'description' => ['nullable', 'string', 'max:255'],
            'initials' => ['nullable', 'string', 'max:4'],
            'accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'launch_url' => ['nullable', 'url', 'max:255'],
            'redirect_uri' => ['required', 'url', 'max:255'],
            'active' => ['boolean'],
            'users' => ['array'],
            'users.*' => [Rule::exists('users', 'id')],
        ];
    }
}
