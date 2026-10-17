<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\ApplicationAccessException;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Http\ManagedHttpPolicy;
use Doxa\Laravel\Token\RefreshResponse;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** Internal fixed operation, colocated with the protected store boundary. No token read API.
 * Raw credentials exist only in sensitive transient call arguments/locals, never result fields.
 */
trait ManagedApplicationAccess
{
    /** An invocation can only be issued by the owner-validating manager and is consumed once. */
    public function checkApplicationAccess(#[\SensitiveParameter] CredentialManager $manager, object $ticket): ApplicationAccessDecision
    {
        $invocation = $manager->consumeAccessInvocation($ticket, $this);
        try {
            return $this->executeApplicationAccess($invocation);
        } catch (ApplicationAccessException $error) {
            throw new ApplicationAccessException($error->category);
        } catch (CredentialException $error) {
            throw new ApplicationAccessException($error->category);
        } catch (\Throwable) {
            throw new ApplicationAccessException('credential_lifecycle_failure');
        }
    }

    /** @param array<string, mixed> $invocation */
    private function executeApplicationAccess(#[\SensitiveParameter] array $invocation, int $reload = 0): ApplicationAccessDecision
    {
        $context = $invocation['context'];
        $validate = $invocation['validate'];
        $config = $invocation['config'];
        $margin = $invocation['margin'];
        $provider = $invocation['provider'];
        $policy = new ManagedHttpPolicy($config);
        // No host middleware, retry plugin, event dispatcher, cookies or persistent client state.
        $handler = self::$operationHandlers[$this] ?? null;
        $client = $handler === null ? new Client : new Client(['handler' => HandlerStack::create($handler)]);
        $waitUntil = microtime(true) + 0.25;
        do {
            $expected = $validate();
            if (! in_array($expected['status'], ['refreshing', 'checking'], true)) {
                break;
            }
            if (microtime(true) >= $waitUntil) {
                throw new ApplicationAccessException('credential_busy');
            }
            usleep(10000);
        } while (true);
        $payload = $this->accessPayload($context, $expected);
        $pending = $payload['purpose'] === 'pending_link';
        if ($payload['access_expires_at'] <= time() + $margin) {
            if (! is_string($payload['refresh_token'] ?? null) || $payload['refresh_token'] === ''
                || ($payload['refresh_expires_at'] !== null && $payload['refresh_expires_at'] <= time() + $margin)) {
                $this->delete($payload['id'], $payload['version'], 'expired');
                throw new ApplicationAccessException('reauthorization_required');
            }
            $provider->assertManagedRefresh($config);
            if ($payload['provider_token_endpoint'] !== $provider->tokenEndpoint || $payload['auth_method'] !== $config->authMethod) {
                throw new ApplicationAccessException('incompatible_context');
            }
            try {
                $attempt = $this->claimForOperation($context, $validate(), min(60, $config->timeout + 5), $pending, $margin);
            } catch (CredentialException $error) {
                if ($error->category !== 'credential_lifecycle_failure') {
                    throw $error;
                }
                if ($reload === 0) {
                    return $this->executeApplicationAccess($invocation, 1);
                }
                throw new ApplicationAccessException('credential_busy');
            }
            // Only this private control-flow edge can send. Neither an attempt object nor a
            // persisted dispatched phase can enter here. A thrown acknowledgement means NO SEND.
            try {
                $attempt = $this->dispatchForOperation($context, $validate(), $attempt, $pending);
            } catch (\Throwable) {
                // A committed intent with a lost acknowledgement is conservatively unresolved.
                // Cleanup fences it; never reacquire or send speculatively.
                $this->retireOperationAttempt($context, new RefreshAttempt($attempt->contextId, $attempt->id, $attempt->version + 1, 'dispatched'));
                throw new ApplicationAccessException('refresh_outcome_uncertain');
            }
            try {
                $payload = $this->refreshAttempt($context, $validate(), $attempt, 'dispatched', $pending);
                $form = ['grant_type' => 'refresh_token', 'refresh_token' => $payload['refresh_token']];
                $headers = ['Accept' => 'application/json'];
                if ($config->authMethod === 'client_secret_basic') {
                    $headers['Authorization'] = 'Basic '.base64_encode(urlencode($config->clientId).':'.urlencode($config->secret() ?? ''));
                } else {
                    $form['client_id'] = $config->clientId;
                    if ($config->authMethod === 'client_secret_post') {
                        $form['client_secret'] = $config->secret();
                    }
                }
                $started = microtime(true);
                $tokenStart = time();
                $response = $client->request('POST', $provider->tokenEndpoint, [...$policy->options(), 'headers' => $headers, 'form_params' => $form]);
                if ($response->getStatusCode() >= 500 || ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400)) {
                    $this->closeOperationResponse($response);
                    throw new ApplicationAccessException('refresh_outcome_uncertain');
                }
                $json = $this->boundedOperationBody($response, $started, $config->timeout);
                if ($response->getStatusCode() !== 200) {
                    $error = json_decode($json, true, 32);
                    throw new ApplicationAccessException(match (is_array($error) ? ($error['error'] ?? null) : null) {
                        'invalid_grant' => 'credential_invalid',
                        'invalid_client' => 'client_unusable',
                        default => 'refresh_outcome_uncertain',
                    });
                }
                $metadata = RefreshResponse::parse($json, $payload['refresh_token'], $payload, $tokenStart, $margin)->metadata();
                // Parser output deliberately contains no tokens. Decode only the already validated
                // transient input here for the one-way, fenced store write, never a public getter.
                if (! in_array('openid', $metadata['scopes'], true)) {
                    throw new ApplicationAccessException('incompatible_context');
                }
                $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                $replacement = ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'],
                    'scopes' => $metadata['scopes'], 'access_expires_at' => $metadata['access_expires_at'],
                    'refresh_expires_at' => $metadata['refresh_expires_at']];
                try {
                    $this->completeForOperation($context, $validate(), $attempt, $replacement, $pending, $margin);
                } catch (CredentialException) {
                    // Same validated response only; no new HTTP request. The existing store permits
                    // exact read-only reconciliation, or fenced commit if the original write failed.
                    $this->completeForOperation($context, $validate(), $attempt, $replacement, $pending, $margin);
                }
            } catch (\Throwable $error) {
                $this->retireOperationAttempt($context, $attempt);
                throw new ApplicationAccessException($error instanceof RefreshResponseException ? 'refresh_contract_violation'
                    : ($error instanceof ApplicationAccessException ? match ($error->category) {
                        'credential_invalid', 'client_unusable', 'incompatible_context' => $error->category,
                        'protocol_failure' => 'refresh_contract_violation',
                        default => 'refresh_outcome_uncertain',
                    } : 'refresh_outcome_uncertain'));
            }
            unset($json, $data, $replacement, $form, $headers, $response);
            $payload = $this->accessPayload($context, $validate());
        }
        // Recheck all live host bindings immediately before dispatch, including pending-link
        // cancellation and deadlines. No positive result may re-enable a concurrent deletion.
        $payload = $this->accessPayload($context, $validate());
        if ($payload['access_expires_at'] <= time() + $margin || preg_match('/^[^\s,]+$/D', $payload['access_token']) !== 1) {
            throw new ApplicationAccessException('reauthorization_required');
        }
        $liveState = $payload['status'];
        $deadline = $payload['expires_at'];
        $version = $payload['version'];
        $payload['check'] = ['id' => bin2hex(random_bytes(32)), 'return_state' => $liveState, 'context_expires_at' => $deadline];
        $payload['status'] = 'checking';
        $payload['version']++;
        $payload['expires_at'] = min($deadline, time() + $config->timeout + 5);
        try {
            $this->replace($payload, $version, $liveState, true);
        } catch (CredentialException $error) {
            if ($error->category === 'credential_lifecycle_failure') {
                throw new ApplicationAccessException('credential_busy');
            }
            throw $error;
        }
        $endpoint = rtrim($config->issuer, '/').'/oauth/application-access';
        DoxaConfig::url($endpoint);
        try {
            $started = microtime(true);
            $response = $client->request('GET', $endpoint, [...$policy->options(),
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$payload['access_token']]]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                $this->closeOperationResponse($response);
                throw new ApplicationAccessException($status === 401 ? 'credential_invalid' : 'access_denied_or_insufficient_scope');
            }
            if ($status >= 500) {
                $this->closeOperationResponse($response);
                throw new ApplicationAccessException('transient_service_failure');
            }
            $json = $this->boundedOperationBody($response, $started, $config->timeout);
            if ($status !== 200 || preg_match('/\A[ \t\r\n]*\{[ \t\r\n]*"effective"[ \t\r\n]*:[ \t\r\n]*true[ \t\r\n]*\}[ \t\r\n]*\z/D', $json) !== 1) {
                throw new ApplicationAccessException('protocol_failure');
            }
        } catch (ApplicationAccessException $error) {
            $this->finishAccessCheck($payload, $error->category === 'transient_service_failure');
            throw $error;
        } catch (\Throwable) {
            $this->finishAccessCheck($payload, true);
            throw new ApplicationAccessException('transient_service_failure');
        }
        try {
            $validate();
        } catch (\Throwable) {
            $this->finishAccessCheck($payload, false);
            throw new ApplicationAccessException('incompatible_context');
        }
        $this->finishAccessCheck($payload, true);
        $this->accessPayload($context, $validate());

        return ApplicationAccessDecision::confirmed();
    }

    /** Release only this exact check, or erase it; storage failure leaves a durable nonusable fence.
     * @param  array<string, mixed>  $expected
     */
    private function finishAccessCheck(#[\SensitiveParameter] array $expected, bool $reusable): void
    {
        $payload = $this->read($expected['id']);
        if ($payload['version'] !== $expected['version'] || $payload['status'] !== 'checking'
            || ($payload['check']['id'] ?? null) !== $expected['check']['id']) {
            throw new ApplicationAccessException('credential_lifecycle_failure');
        }
        if (! $reusable || $payload['expires_at'] <= time()) {
            $this->delete($payload['id'], $payload['version'], 'unusable');

            return;
        }
        $payload['status'] = $payload['check']['return_state'];
        $payload['expires_at'] = $payload['check']['context_expires_at'];
        unset($payload['check']);
        $payload['version']++;
        $this->replace($payload, $expected['version'], 'checking', true);
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private function accessPayload(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected): array
    {
        $payload = $this->refreshOwner($context, $expected, $expected['purpose'] === 'pending_link');
        if (in_array($payload['status'], ['refreshing', 'checking'], true) || $payload['version'] !== $expected['version']) {
            throw new ApplicationAccessException('credential_busy');
        }
        if ($payload['status'] !== ($payload['purpose'] === 'pending_link' ? 'pending_link' : 'active')
            || ! in_array('openid', $payload['scopes'], true)) {
            throw new ApplicationAccessException('incompatible_context');
        }

        return $payload;
    }

    /** Fenced to this exact attempt, including a committed completion with a lost ack. */
    private function retireOperationAttempt(#[\SensitiveParameter] CredentialContext $context, RefreshAttempt $attempt): void
    {
        try {
            $payload = $this->read($context->recordId());
            if (($payload['refresh']['id'] ?? null) === $attempt->id
                && (($payload['version'] === $attempt->version && $payload['status'] === 'refreshing')
                    || ($payload['version'] === $attempt->version + 1 && ($payload['refresh']['phase'] ?? null) === 'completed'))) {
                $this->delete($payload['id'], $payload['version'], 'unusable');
            }
        } catch (\Throwable) {
            // Durable refreshing fence survives outages; cleanup must run on recovery.
        }
    }

    /** @phpstan-impure */
    private function readOperationChunk(#[\SensitiveParameter] StreamInterface $body, int $length): string
    {
        return $body->read($length);
    }

    /** Only a transient bounded string, never retained in an exportable result/object graph. */
    private function boundedOperationBody(#[\SensitiveParameter] ResponseInterface $response, float $started, int $timeout): string
    {
        $body = $response->getBody();
        try {
            $length = $response->getHeaderLine('Content-Length');
            if (strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) !== 'application/json'
                || ($length !== '' && (! ctype_digit($length) || (float) $length > ManagedHttpPolicy::MAX_BODY_BYTES))) {
                throw new ApplicationAccessException('protocol_failure');
            }
            $json = '';
            while (! $body->eof()) {
                if (microtime(true) >= $started + $timeout) {
                    throw new ApplicationAccessException('transient_service_failure');
                }
                $chunk = $this->readOperationChunk($body, min(8192, ManagedHttpPolicy::MAX_BODY_BYTES + 1 - strlen($json)));
                if ($chunk === '' && ! $body->eof()) {
                    throw new ApplicationAccessException('transient_service_failure');
                }
                $json .= $chunk;
                if (strlen($json) > ManagedHttpPolicy::MAX_BODY_BYTES) {
                    throw new ApplicationAccessException('protocol_failure');
                }
            }
            if (microtime(true) >= $started + $timeout || ($length !== '' && (int) $length !== strlen($json))) {
                throw new ApplicationAccessException('protocol_failure');
            }

            return $json;
        } finally {
            try {
                $body->close();
            } catch (\Throwable) {
                // Closing an untrusted stream must not replace a terminal/protocol outcome.
            }
        }
    }

    private function closeOperationResponse(#[\SensitiveParameter] ResponseInterface $response): void
    {
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
            // Preserve the authoritative status even when stream disposal fails.
        }
    }
}
