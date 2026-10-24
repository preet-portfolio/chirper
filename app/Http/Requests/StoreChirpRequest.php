<?php

namespace App\Http\Requests;

use App\Rules\NoPersonalData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChirpRequest extends FormRequest
{
    /**
     * Anyone may post; abuse is limited by the `chirps` rate limiter.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:255', new NoPersonalData],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Please write something to chirp!',
            'message.max' => 'Chirps must be 255 characters or less.',
        ];
    }

    /**
     * Bots fill every field; people never see the hidden `website` input.
     */
    public function isFromBot(): bool
    {
        return $this->filled('website');
    }
}
