<?php

namespace Vlados\LaravelSpamGuard\Tests\Fixtures;

use Livewire\Attributes\Validate;
use Livewire\Component;
use Vlados\LaravelSpamGuard\Rules\NotSpam;

class ContactForm extends Component
{
    #[Validate(onUpdate: false)]
    public string $description = '';

    protected function rules(): array
    {
        return ['description' => ['bail', 'required', 'string', 'max:5000', new NotSpam]];
    }

    public function submit(): void
    {
        $this->validate();
    }

    public function render(): string
    {
        return '<form wire:submit="submit"><textarea wire:model="description"></textarea><button type="submit">Send</button></form>';
    }
}
