<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;

/** @internal */
class TypeSafeClient
{
    public function __construct(private Factory $http, private Repository $config, private LoggerInterface $logger) {}

    public function check(string|array $state, string $context, Configuration $configuration): Verdict
    {
        $key = Configuration::text($this->config->get('spam-guard.api_key'), 'spam-guard.api_key');
        if (preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new InvalidArgumentException('spam-guard.api_key cannot contain whitespace or control characters.');
        }

        $body = json_encode([
            'state' => $state,
            'model' => $configuration->model,
            'questions' => ['spam' => SpamQuestion::forContext($context)],
        ], State::JSON_FLAGS);
        $started = hrtime(true);

        try {
            $response = $this->http->withToken($key)
                ->acceptJson()
                ->withBody($body, 'application/json')
                ->withOptions([
                    'timeout' => $configuration->timeout,
                    'connect_timeout' => $configuration->connectTimeout,
                ])
                ->withoutRedirecting()
                ->post('https://api.typesafe.ai/v1/systemone');
        } catch (ConnectionException $exception) {
            $previous = $exception->getPrevious();
            $reason = $previous instanceof ConnectException && ($previous->getHandlerContext()['errno'] ?? null) === 28
                ? 'timeout' : 'connection';

            return $this->unavailable($reason, null, $started, $configuration);
        }

        if (! $response->successful()) {
            $reason = match (true) {
                in_array($response->status(), [401, 403], true) => 'authentication',
                $response->status() === 422 => 'rejected_request',
                $response->status() === 429 => 'rate_limited',
                $response->status() === 529 => 'overloaded',
                $response->serverError() => 'server_error',
                default => 'http_error',
            };

            return $this->unavailable($reason, $response->status(), $started, $configuration);
        }

        try {
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unavailable('invalid_response', $response->status(), $started, $configuration);
        }

        $answer = is_array($data) ? ($data['answers']['spam'] ?? null) : null;
        $probability = is_array($answer) ? ($answer['noul'] ?? null) : null;
        if (! is_array($answer) || ($answer['type'] ?? null) !== 'noul'
            || (! is_int($probability) && ! is_float($probability))
            || ! is_finite($probability) || $probability < 0 || $probability > 1) {
            return $this->unavailable('invalid_response', $response->status(), $started, $configuration);
        }

        $model = $data['model'] ?? null;
        $tokens = $data['usage']['input_tokens'] ?? null;

        return Verdict::classified(
            (float) $probability,
            threshold: $configuration->threshold,
            model: is_string($model) && trim($model) !== '' ? $model : null,
            inputTokens: is_int($tokens) && $tokens >= 0 ? $tokens : null,
            durationMs: (hrtime(true) - $started) / 1_000_000,
        );
    }

    private function unavailable(string $reason, ?int $status, int $started, Configuration $configuration): Verdict
    {
        $duration = (hrtime(true) - $started) / 1_000_000;
        $this->logger->log($reason === 'authentication' ? 'error' : 'warning', 'Spam check unavailable.', [
            'reason' => $reason,
            'http_status' => $status,
            'duration_ms' => $duration,
            'attempts' => 1,
        ]);

        return Verdict::unavailable($reason, threshold: $configuration->threshold, durationMs: $duration);
    }
}
