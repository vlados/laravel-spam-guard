<?php

use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Vlados\LaravelSpamGuard\Tests\Fixtures\ContactForm;
use Vlados\LaravelSpamGuard\Tests\Fixtures\ContactRequest;

it('checks once on Livewire submit and never while typing', function () {
    app()->register(LivewireServiceProvider::class);
    Http::fake(['*' => Http::response(responseBody())]);

    $component = Livewire::test(ContactForm::class)->set('description', 'Търся накладки')->set('description', 'Търся накладки за BMW');
    Http::assertNothingSent();
    $component->call('submit')->assertHasNoErrors();
    Http::assertSentCount(1);
    $component->call('submit')->assertHasNoErrors();
    Http::assertSentCount(2);
});

it('does not send invalid Livewire submissions to the provider', function () {
    app()->register(LivewireServiceProvider::class);
    Livewire::test(ContactForm::class)->call('submit')->assertHasErrors('description');
    Http::assertNothingSent();
});

it('checks only final submissions through the Precognition middleware', function (bool $precognitive) {
    Route::post('/contact', fn (ContactRequest $request) => response()->json($request->validated()))
        ->middleware(HandlePrecognitiveRequests::class);
    Http::fake(['*' => Http::response(responseBody())]);

    $response = $this->postJson('/contact', ['description' => 'Търся накладки'], $precognitive ? ['Precognition' => 'true'] : []);
    $response->assertStatus($precognitive ? 204 : 200);
    Http::assertSentCount($precognitive ? 0 : 1);
})->with([true, false]);
