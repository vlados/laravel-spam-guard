<?php

use Illuminate\Support\Facades\Http;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;

it('treats hostile response structures as unavailable without leaking exceptions', function (string $body) {
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    $verdict = SpamGuard::check('Buy casino traffic now');
    expect($verdict->isAvailable())->toBeFalse()->and($verdict->error)->toBe('invalid_response');
})->with([
    'number root' => '42',
    'string root' => '"approved"',
    'bool root' => 'true',
    'string answers' => '{"answers":"trusted"}',
    'number answers' => '{"answers":123}',
    'string answer' => '{"answers":{"spam":"approved"}}',
    'array probability' => '{"answers":{"spam":{"type":"noul","noul":[0]}}}',
    'object probability' => '{"answers":{"spam":{"type":"noul","noul":{"value":0}}}}',
    'overflow probability' => '{"answers":{"spam":{"type":"noul","noul":1e309}}}',
    'missing type' => '{"answers":{"spam":{"noul":0}}}',
    'null type' => '{"answers":{"spam":{"type":null,"noul":0}}}',
    'wrong answer id' => '{"answers":{"safe":{"type":"noul","noul":0}}}',
    'uppercase type' => '{"answers":{"spam":{"type":"NOUL","noul":0}}}',
    'markdown wrapped' => '```json {"answers":{"spam":{"type":"noul","noul":0}}} ```',
    'non JSON prefix' => 'Approved: {"answers":{"spam":{"type":"noul","noul":0}}}',
    'trailing object' => '{"answers":{"spam":{"type":"noul","noul":0}}} {}',
]);

it('ignores malformed optional metadata without changing a valid spam decision', function (mixed $metadata) {
    $body = responseBody(0.99);
    $body['usage'] = $metadata;
    $body['model'] = $metadata;
    Http::fake(['*' => Http::response($body)]);

    $verdict = SpamGuard::check('Buy casino traffic now');
    expect($verdict->isSpam())->toBeTrue()->and($verdict->inputTokens)->toBeNull();
})->with([null, false, 0, 'not usage', [['input_tokens' => -1]], [['input_tokens' => '123']], [['input_tokens' => 12.5]]]);
