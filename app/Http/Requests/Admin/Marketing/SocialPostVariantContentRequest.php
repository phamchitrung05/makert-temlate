<?php

namespace App\Http\Requests\Admin\Marketing;

use Illuminate\Foundation\Http\FormRequest;

final class SocialPostVariantContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
            'content.title' => ['nullable', 'string', 'max:255'],
            'content.body' => ['required', 'string', 'min:1', 'max:5000'],
            'content.bullet_points' => ['nullable', 'array', 'max:8'],
            'content.bullet_points.*' => ['string', 'min:1', 'max:300'],
            'content.cta' => ['nullable', 'string', 'max:500'],
            'content.contact' => ['nullable', 'array'],
            'content.contact.address' => ['nullable', 'string', 'max:500'],
            'content.contact.phone' => ['nullable', 'string', 'max:120'],
            'content.contact.website' => ['nullable', 'string', 'max:500'],
            'content.hashtags' => ['nullable', 'array', 'max:12'],
            'content.hashtags.*' => ['string', 'min:1', 'max:80'],
            'content.link_url' => ['nullable', 'url', 'max:1000'],
        ];
    }
}
