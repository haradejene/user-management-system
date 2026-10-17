<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\RefreshFailure;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Token\RefreshResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** Non-dispatchable policy: no client, handler, request builder or send method. */
final readonly class ManagedHttpPolicy
{
    public const MAX_BODY_BYTES = 262144;

    private int $timeout;

    public function __construct(#[\SensitiveParameter] DoxaConfig $config)
    {
        // Retain only the validated, non-secret value used by this policy.
        $this->timeout = $config->timeout;
    }

    /** Fixed future Guzzle options. B3 must use a dedicated client without retry middleware.
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return ['verify' => true, 'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout, 'read_timeout' => $this->timeout,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            'debug' => false, 'cookies' => false, 'idn_conversion' => false];
    }

    /** Inspect an already-received response, never send. $startedAt is microtime(true) request start. */
    public function inspect(#[\SensitiveParameter] ResponseInterface $response, float $startedAt): ManagedTransportResult
    {
        return $this->consume($response, $startedAt);
    }

    /** Bounded read and validation in one pass; no raw body escapes into the result.
     * @param  array<string, mixed>  $binding  Trusted stored metadata, not browser input.
     */
    public function inspectRefresh(#[\SensitiveParameter] ResponseInterface $response, float $startedAt,
        #[\SensitiveParameter] string $oldRefreshToken, #[\SensitiveParameter] array $binding,
        int $tokenStartedAt, int $safetyMargin = 60): ManagedTransportResult
    {
        return $this->consume($response, $startedAt, $oldRefreshToken, $binding, $tokenStartedAt, $safetyMargin);
    }

    /** @param array<string, mixed> $binding */
    private function consume(#[\SensitiveParameter] ResponseInterface $response, float $startedAt,
        #[\SensitiveParameter] ?string $oldRefreshToken = null, #[\SensitiveParameter] array $binding = [],
        ?int $tokenStartedAt = null, int $safetyMargin = 60): ManagedTransportResult
    {
        $status = null;
        $body = null;
        try {
            $status = $response->getStatusCode();
            $failure = $status >= 500 ? RefreshFailure::Uncertain : RefreshFailure::ContractViolation;
            $body = $response->getBody();
            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            if ($type !== 'application/json' || ($status >= 300 && $status < 400)
                || ! is_finite($startedAt) || $startedAt > microtime(true)) {
                return ManagedTransportResult::response($status, $failure);
            }
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (! ctype_digit($length) || (float) $length > self::MAX_BODY_BYTES)) {
                return ManagedTransportResult::response($status, $failure);
            }
            $json = '';
            while (! $body->eof()) {
                if (microtime(true) >= $startedAt + $this->timeout) {
                    return ManagedTransportResult::response($status, RefreshFailure::Uncertain);
                }
                $chunk = $this->readChunk($body, min(8192, self::MAX_BODY_BYTES + 1 - strlen($json)));
                if ($chunk === '' && ! $body->eof()) {
                    return ManagedTransportResult::response($status, RefreshFailure::Uncertain);
                }
                $json .= $chunk;
                if (strlen($json) > self::MAX_BODY_BYTES) {
                    return ManagedTransportResult::response($status, $failure);
                }
            }
            if (microtime(true) >= $startedAt + $this->timeout) {
                return ManagedTransportResult::response($status, RefreshFailure::Uncertain);
            }
            if ($length !== '' && (int) $length !== strlen($json)) {
                return ManagedTransportResult::response($status, $failure);
            }
            if ($status === 200) {
                return $oldRefreshToken === null ? ManagedTransportResult::response($status)
                    : ManagedTransportResult::validated(RefreshResponse::parse($json, $oldRefreshToken, $binding, $tokenStartedAt ?? 0, $safetyMargin));
            }
            // Only recognized OAuth errors are classified; no descriptions or guessed denial causes.
            if ($status === 400 || $status === 401) {
                $error = json_decode($json, true, 32);
                $failure = match (is_array($error) ? ($error['error'] ?? null) : null) {
                    'invalid_grant' => RefreshFailure::CredentialInvalid,
                    'invalid_client' => RefreshFailure::ClientUnusable,
                    default => $failure,
                };
            }

            return ManagedTransportResult::response($status, $failure);
        } catch (RefreshResponseException $error) {
            return $status === null ? ManagedTransportResult::uncertain()
                : ManagedTransportResult::response($status, $error->failure);
        } catch (\Throwable) {
            return $status === null ? ManagedTransportResult::uncertain()
                : ManagedTransportResult::response($status, RefreshFailure::Uncertain);
        } finally {
            try {
                $body?->close();
            } catch (\Throwable) {
                // Never expose stream/transport diagnostics with response bodies.
            }
        }
    }

    /** Reading advances the stream and can change eof().
     * @phpstan-impure
     */
    private function readChunk(#[\SensitiveParameter] StreamInterface $body, int $length): string
    {
        return $body->read($length);
    }
}
