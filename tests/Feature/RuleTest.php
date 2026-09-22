<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Vlados\LaravelSpamGuard\Rules\NotSpam;

it('rejects spam at the configured threshold and accepts below it', function (float $probability, bool $passes) {
    Http::fake(['*' => Http::response(responseBody($probability))]);
    $validator = Validator::make(['description' => 'A request'], ['description' => ['bail', 'required', 'string', 'max:5000', new NotSpam]]);
    expect($validator->passes())->toBe($passes);
    Http::assertSentCount(1);
})->with([[0.9, false], [0.899, true], [0.0, true], [1.0, false]]);

it('uses a separate outage message under fail closed', function (bool $failOpen, string $locale) {
    config()->set('spam-guard.fail_open', $failOpen);
    app()->setLocale($locale);
    Http::fake(['*' => Http::response([], 529)]);
    $validator = Validator::make(['description' => 'A request'], ['description' => new NotSpam]);
    expect($validator->passes())->toBe($failOpen);
    if (! $failOpen) {
        expect($validator->errors()->first('description'))->toBe(trans('spam-guard::validation.unavailable'))
            ->not->toBe(trans('spam-guard::validation.spam'));
    }
})->with([[true, 'en'], [false, 'en'], [false, 'bg']]);

it('translates spam errors', function () {
    app()->setLocale('bg');
    Http::fake(['*' => Http::response(responseBody(1))]);
    $validator = Validator::make(['description' => 'A request'], ['description' => new NotSpam]);
    expect($validator->errors()->first('description'))->toBe('Съобщението изглежда като спам.');
});

it('sends only explicit scalar siblings and preserves field names', function () {
    Http::fake(['*' => Http::response(responseBody())]);
    $validator = Validator::make([
        'inquiry' => ['description' => 'Накладки', 'vehicle' => 'BMW', 'part' => 123, 'optional' => null],
        'email' => 'private@example.com', 'password' => 'secret',
    ], ['inquiry.description' => NotSpam::make()->context('Parts')->withFields(['inquiry.vehicle', 'inquiry.part', 'inquiry.optional', 'missing'])]);
    expect($validator->passes())->toBeTrue();
    Http::assertSent(fn ($request) => $request['state'] === ['inquiry.description' => 'Накладки', 'inquiry.vehicle' => 'BMW', 'inquiry.part' => 123, 'inquiry.optional' => null]
        && $request['questions']['spam']['instructions']['form_purpose'] === 'Parts');
});

it('does not send unrelated request data by default', function () {
    Http::fake(['*' => Http::response(responseBody())]);
    expect(Validator::make(['description' => 'hello', 'email' => 'private'], ['description' => new NotSpam])->passes())->toBeTrue();
    Http::assertSent(fn ($request) => $request['state'] === ['description' => 'hello']);
});

it('does not call the provider for cheap validation failures', function (array $data) {
    Validator::make($data, ['description' => ['bail', 'required', 'string', 'max:5', new NotSpam]])->passes();
    Http::assertNothingSent();
})->with([[[]], [['description' => '']], [['description' => ['bad']]], [['description' => 'too long']]]);

it('allows nullable omitted text without a check', function () {
    expect(Validator::make(['description' => null], ['description' => ['nullable', new NotSpam]])->passes())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects malformed selected data even before sibling validation runs', function (mixed $value) {
    $validator = Validator::make(['description' => 'hello', 'vehicle' => $value], ['description' => NotSpam::make()->withFields(['vehicle'])]);
    expect($validator->fails())->toBeTrue();
    Http::assertNothingSent();
})->with([[['nested']], [new stdClass], [INF], ["\xB1\x31"]]);

it('rejects nonstring main values without relying on bail', function () {
    expect(Validator::make(['description' => ['bad']], ['description' => new NotSpam])->fails())->toBeTrue();
    Http::assertNothingSent();
});

it('maps oversized composed state to a translated length error', function () {
    config()->set('spam-guard.max_state_chars', 30);
    $validator = Validator::make(['description' => 'hello', 'vehicle' => 'BMW'], ['description' => NotSpam::make()->withFields(['vehicle'])]);
    expect($validator->errors()->first('description'))->toBe(trans('spam-guard::validation.too_long', ['max' => 30]));
    Http::assertNothingSent();
});

it('does not fail open for invalid local configuration', function () {
    config()->set('spam-guard.threshold', 2);
    Validator::make(['description' => 'hello'], ['description' => new NotSpam])->passes();
})->throws(InvalidArgumentException::class);

it('rejects broad or invalid sibling selectors', function (array $fields) {
    NotSpam::make()->withFields($fields);
})->with([[['*']], [['items.*.title']], [[12]], [['']]])->throws(InvalidArgumentException::class);
