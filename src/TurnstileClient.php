<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;

/** @internal */
class TurnstileClient
{
    public function __construct(private Factory $http, private Repository $config, private LoggerInterface $logger) {}

    /** Returns true for verified, false for rejected, and null when verification is unavailable. */
    public function verify(string $token, string $action): ?bool
    {
        $secret = Configuration::text($this->config->get('spam-guard.turnstile.secret_key'), 'spam-guard.turnstile.secret_key');
        if (preg_match('/[\x00-\x20\x7F]/', $secret)) {
            throw new InvalidArgumentException('spam-guard.turnstile.secret_key cannot contain whitespace or control characters.');
        }

        $hostname = Configuration::text($this->config->get('spam-guard.turnstile.hostname'), 'spam-guard.turnstile.hostname');
        if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('spam-guard.turnstile.hostname must be a hostname without a scheme, path or port.');
        }

        $timeout = $this->config->get('spam-guard.turnstile.timeout', 5.0);
        $connectTimeout = $this->config->get('spam-guard.turnstile.connect_timeout', 2.0);
        foreach ([$timeout, $connectTimeout] as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException('Turnstile timeouts must be positive finite numbers.');
            }
        }
        if ($connectTimeout > $timeout) {
            throw new InvalidArgumentException('Turnstile connect_timeout must not exceed timeout.');
        }

        try {
            $response = $this->http->asJson()
                ->acceptJson()
                ->withOptions(['timeout' => (float) $timeout, 'connect_timeout' => (float) $connectTimeout])
                ->withoutRedirecting()
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                ]);
        } catch (ConnectionException) {
            return $this->unavailable('connection');
        }

        if (! $response->successful()) {
            return $this->unavailable('http_error', $response->status());
        }

        try {
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unavailable('invalid_response', $response->status());
        }

        if (! is_array($data) || ! is_bool($data['success'] ?? null)) {
            return $this->unavailable('invalid_response', $response->status());
        }

        $errors = $data['error-codes'] ?? [];
        if (! is_array($errors) || ! array_is_list($errors)) {
            return $this->unavailable('invalid_response', $response->status());
        }

        if ($data['success']) {
            if ($errors !== [] || ! is_string($data['hostname'] ?? null) || ! is_string($data['action'] ?? null)) {
                return $this->unavailable('invalid_response', $response->status());
            }

            return strtolower($data['hostname']) === strtolower($hostname) && $data['action'] === $action;
        }

        if ($errors === []) {
            return $this->unavailable('invalid_response', $response->status());
        }
        foreach ($errors as $error) {
            if (! in_array($error, ['missing-input-response', 'invalid-input-response', 'timeout-or-duplicate'], true)) {
                $reason = in_array($error, ['missing-input-secret', 'invalid-input-secret'], true) ? 'authentication' : 'provider_error';

                return $this->unavailable($reason, $response->status());
            }
        }

        return false;
    }

    private function unavailable(string $reason, ?int $status = null): null
    {
        $this->logger->log($reason === 'authentication' ? 'error' : 'warning', 'Bot verification unavailable.', [
            'reason' => $reason,
            'http_status' => $status,
        ]);

        return null;
    }
}
