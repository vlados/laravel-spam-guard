<?php

namespace Vlados\LaravelSpamGuard\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Vlados\LaravelSpamGuard\Rules\NotSpam;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['bail', 'required', 'string', 'max:5000'];

        if (! $this->isPrecognitive()) {
            $rules[] = new NotSpam;
        }

        return ['description' => $rules];
    }
}
