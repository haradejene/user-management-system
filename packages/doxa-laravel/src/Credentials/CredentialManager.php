<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Contracts\ManagedTransactionStore;
use Doxa\Laravel\Contracts\RefreshCredentialStore;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\ApplicationAccessException;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Token\CodeExchange;
use Doxa\Laravel\Token\TokenResponse;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Illuminate\Http\Request;

final class CredentialManager
{
    /** Process-local invocation provenance, never a persisted dispatch permission.
     * @var \WeakMap<object, array<string, mixed>>|null
     */
    private static ?\WeakMap $accessInvocations = null;

    /** The only operation allowed for pending-link credentials. */
    public function checkApplicationAccess(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, #[\SensitiveParameter] \Closure $discover): ApplicationAccessDecision
    {
        $record = $this->owned($context, $request, 'check_application_access');
        $this->assertAccessRecord($context, $record);
        $store = $this->services()['store'];
        if (! $store instanceof DatabaseCredentialStore) {
            throw new ApplicationAccessException('credential_lifecycle_failure');
        }
        $store->ready();
        $provider = $discover();
        if (! $provider instanceof ProviderMetadata || $provider->issuer !== $this->config->issuer) {
            throw new ApplicationAccessException('incompatible_context');
        }
        $ticket = new \stdClass;
        self::$accessInvocations ??= new \WeakMap;
        self::$accessInvocations[$ticket] = ['manager' => $this, 'store' => $store,
            'context' => $context, 'config' => $this->config, 'provider' => $provider, 'margin' => $this->services()['margin'],
            'validate' => function () use ($context, $request): array {
                $record = $this->owned($context, $request, 'check_application_access');
                $this->assertAccessRecord($context, $record);

                return $record;
            }];
        try {
            return $store->checkApplicationAccess($this, $ticket);
        } finally {
            unset(self::$accessInvocations[$ticket]);
        }
    }

    /** Internal one-shot handoff. A caller-created attempt/status/ticket authorizes nothing.
     * @return array<string, mixed>
     */
    public function consumeAccessInvocation(object $ticket, #[\SensitiveParameter] DatabaseCredentialStore $store): array
    {
        $invocation = self::$accessInvocations[$ticket] ?? null;
        if ($invocation === null || $invocation['manager'] !== $this || $invocation['store'] !== $store) {
            throw new ApplicationAccessException('incompatible_context');
        }
        unset(self::$accessInvocations[$ticket]);
        unset($invocation['manager'], $invocation['store']);

        return $invocation;
    }

    /** @param array<string, mixed> $record */
    private function assertAccessRecord(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $record): void
    {
        $pending = $record['purpose'] === 'pending_link' && $record['owner'] === null
            && ($record['policy']['host']['deadline'] ?? 0) > time();
        $ordinary = $record['purpose'] === 'ordinary' && $record['owner'] !== null && $context->confirms($record);
        if ((! $pending && ! $ordinary) || $record['grant'] !== 'authorization_code'
            || ! in_array('openid', $record['scopes'], true)) {
            throw new ApplicationAccessException('incompatible_context');
        }
        if (! in_array($record['status'], [$pending ? 'pending_link' : 'active', 'refreshing', 'checking'], true)
            || $record['absolute_expires_at'] <= time() || $record['expires_at'] <= time()) {
            throw new ApplicationAccessException('credential_lifecycle_failure');
        }
    }

    /** @var \WeakMap<CredentialContext, AuthorizationTransaction> */
    private \WeakMap $staged;

    /** @var array{store: CredentialStore, host: CredentialHostBinding, ttl: int, unbound_ttl: int, margin: int}|null */
    private ?array $services = null;

    /** @param \Closure(): array<string, mixed> $factory */
    public function __construct(private readonly DoxaConfig $config, private readonly \Closure $factory)
    {
        $this->staged = new \WeakMap;
    }

    /** @return array<string, mixed> */
    public function prepare(ManagedLoginPolicy $policy, #[\SensitiveParameter] Request $request): array
    {
        try {
            $services = $this->services();
            $services['store']->ready();
            $host = $services['host']->capture($request, $policy);
            // Require a bounded JSON host snapshot, never a serializable model/closure/service.
            $json = json_encode($host, JSON_THROW_ON_ERROR);
            if (strlen($json) > 16384 || json_decode($json, true, 16, JSON_THROW_ON_ERROR) !== $host) {
                throw new CredentialException('incompatible_context');
            }
            if ($policy->purpose === 'pending_link') {
                foreach (['initiator', 'link', 'target'] as $field) {
                    if (! is_string($host[$field] ?? null) || $host[$field] === '' || strlen($host[$field]) > 255) {
                        throw new CredentialException('incompatible_context');
                    }
                }
                if ($host['link'] !== $policy->linkReference || ! is_int($host['deadline'] ?? null)
                    || $host['deadline'] <= time() || $host['deadline'] > time() + 600) {
                    throw new CredentialException('incompatible_context');
                }
            }
            $snapshot = ['purpose' => $policy->purpose, 'host' => $host, 'browser' => (new BrowserContext)->binding($request)];
            $services['host']->validate($request, $snapshot, 'begin');

            return $snapshot;
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_configuration_error');
        }
    }

    public function validateCallback(#[\SensitiveParameter] AuthorizationTransaction $transaction, #[\SensitiveParameter] Request $request): void
    {
        try {
            $policy = $transaction->managedPolicy();
            if ($transaction->expiresAt <= time() || $policy === null || ! in_array($policy['purpose'] ?? null, ['ordinary', 'pending_link'], true)
                || ($policy['browser'] ?? null) !== (new BrowserContext)->binding($request)
                || ($policy['purpose'] === 'pending_link' && ($policy['host']['deadline'] ?? 0) <= time())) {
                throw new CredentialException('incompatible_context');
            }
            $this->services()['host']->validate($request, $policy, 'callback');
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('incompatible_context');
        }
    }

    public function stage(#[\SensitiveParameter] TokenResponse $tokens, #[\SensitiveParameter] DoxaIdentity $identity, #[\SensitiveParameter] AuthorizationTransaction $transaction, #[\SensitiveParameter] Request $request, #[\SensitiveParameter] IdTokenValidator $validator): CredentialContext
    {
        $this->validateCallback($transaction, $request);
        CodeExchange::assertIssued($tokens, $transaction);
        $validator->assertVerifiedIdentity($tokens, $identity, $transaction);
        if ($identity->issuer() !== $transaction->issuer || $transaction->issuer !== $this->config->issuer || $transaction->clientId !== $this->config->clientId) {
            throw new CredentialException('incompatible_context');
        }
        $policy = $transaction->managedPolicy();
        $services = $this->services();
        $absolute = time() + $services['ttl'];
        $deadline = $policy['purpose'] === 'pending_link' ? $policy['host']['deadline'] : time() + $services['unbound_ttl'];
        $context = CredentialContext::fromServerReference(bin2hex(random_bytes(32)));
        $metadata = ['id' => $context->recordId(), 'provenance' => hash('sha256', $transaction->state),
            'status' => 'staged', 'version' => 1, 'purge_at' => null, 'created_at' => time(),
            'issuer' => $identity->issuer(), 'client' => $transaction->clientId, 'subject' => $identity->subject(),
            'scopes' => $tokens->scopes, 'scope_ceiling' => $transaction->scopes, 'grant' => 'authorization_code',
            'lineage' => bin2hex(random_bytes(32)), 'provider_token_endpoint' => $transaction->provider->tokenEndpoint,
            'auth_method' => $this->config->authMethod, 'purpose' => $policy['purpose'], 'policy' => $policy, 'owner' => null,
            'absolute_expires_at' => $absolute, 'expires_at' => min($absolute, $deadline)];
        // On insert/commit uncertainty the handle is never returned. Any committed row remains staged.
        $tokens->persist($services['store'], $metadata);
        $this->staged[$context] = $transaction;

        return $context;
    }

    public function activate(CredentialContext $context, #[\SensitiveParameter] AuthorizationTransaction $transaction, ManagedTransactionStore $transactions, #[\SensitiveParameter] Request $request): void
    {
        if (! isset($this->staged[$context]) || $this->staged[$context] !== $transaction) {
            throw new CredentialException('incompatible_context');
        }
        $transactions->assertFinished($transaction);
        $this->validateCallback($transaction, $request);
        $store = $this->services()['store'];
        $record = $store->metadata($context->recordId());
        $store->transition($context->recordId(), 1, 'staged', $record['purpose'] === 'pending_link' ? 'pending_link' : 'awaiting_binding');
        // Completion proof must not keep nonce/PKCE transaction secrets alive with a returned handle.
        unset($this->staged[$context]);
    }

    /** Abort only SDK-created callback material. Never an owner authorization bypass API. */
    public function abort(CredentialContext $context): void
    {
        if (! isset($this->staged[$context])) {
            throw new CredentialException('incompatible_context');
        }
        try {
            $store = $this->services()['store'];
            $record = $store->metadata($context->recordId());
            $store->delete($context->recordId(), $record['version'], 'unusable');
        } catch (\Throwable) {
            // No result/handle is released; staged/unassociated records expire through cleanup.
        }
    }

    public function bind(CredentialContext $context, #[\SensitiveParameter] Request $request): CredentialContext
    {
        $store = $this->services()['store'];
        $record = $this->owned($context, $request, 'bind');
        if ($record['status'] !== 'awaiting_binding' || $record['purpose'] !== 'ordinary' || $record['expires_at'] <= time()) {
            throw new CredentialException('credential_lifecycle_failure');
        }
        $attempt = bin2hex(random_bytes(32));
        $proof = bin2hex(random_bytes(32));
        try {
            $store->transition($context->recordId(), $record['version'], 'awaiting_binding', 'binding',
                ['attempt' => $attempt, 'proof_hash' => hash('sha256', $proof)]);
            $association = $this->services()['host']->complete($request, $record);
            if ($association['session'] !== (new BrowserContext)->binding($request)) {
                throw new CredentialException('incompatible_context');
            }
            $store->transition($context->recordId(), $record['version'] + 1, 'binding', 'active',
                [...$association, 'attempt' => $attempt, 'proof_hash' => hash('sha256', $proof)]);
        } catch (\Throwable) {
            // Never reload a newer version and delete it. Only our exact committed attempt
            // can be compensated. If storage is unavailable, no completion proof is released.
            try {
                $store->abandonBinding($context->recordId(), $record['version'] + 1, $attempt);
                $store->abandonBinding($context->recordId(), $record['version'] + 2, $attempt);
            } catch (\Throwable) {
            }
            throw new CredentialException('credential_binding_failure');
        }

        // Release only after acknowledged durability. Never persist the proof preimage.
        // A crash/lost acknowledgement cannot turn the old unbound reference into success.
        return CredentialContext::fromServerReference($context->recordId().'.'.$proof);
    }

    public function delete(CredentialContext $context, #[\SensitiveParameter] Request $request): void
    {
        $record = $this->owned($context, $request, 'delete');
        $this->services()['store']->delete($context->recordId(), $record['version']);
    }

    /** Internal B1 primitives. No public client refresh API or network operation. */
    public function claimRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, int $seconds = 30): RefreshAttempt
    {
        return $this->refreshStore()->claimRefresh($context, $this->owned($context, $request, 'refresh'), $seconds);
    }

    public function dispatchRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, RefreshAttempt $attempt): RefreshAttempt
    {
        return $this->refreshStore()->dispatchRefresh($context, $this->owned($context, $request, 'refresh'), $attempt);
    }

    /** @param array<string, mixed> $replacement Internal validated material; never an HTTP result. */
    public function completeRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, RefreshAttempt $attempt, #[\SensitiveParameter] array $replacement): void
    {
        $this->refreshStore()->completeRefresh($context, $this->owned($context, $request, 'refresh'), $attempt, $replacement);
    }

    public function releaseRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, RefreshAttempt $attempt): void
    {
        $this->refreshStore()->releaseRefresh($context, $this->owned($context, $request, 'refresh'), $attempt);
    }

    public function failRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] Request $request, RefreshAttempt $attempt): void
    {
        $this->refreshStore()->failRefresh($context, $this->owned($context, $request, 'refresh'), $attempt);
    }

    private function refreshStore(): RefreshCredentialStore
    {
        $store = $this->services()['store'];
        if (! $store instanceof RefreshCredentialStore) {
            throw new CredentialException('credential_configuration_error');
        }

        return $store;
    }

    /** Restores an opaque handle from protected host storage, with independent authorization. */
    public function restore(#[\SensitiveParameter] string $reference, #[\SensitiveParameter] Request $request): CredentialContext
    {
        $context = CredentialContext::fromServerReference($reference);
        $record = $this->owned($context, $request, 'restore');
        if (! in_array($record['status'], ['awaiting_binding', 'pending_link', 'active'], true) || $record['expires_at'] <= time()) {
            throw new CredentialException('credential_lifecycle_failure');
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private function owned(CredentialContext $context, #[\SensitiveParameter] Request $request, string $operation): array
    {
        try {
            $record = $this->services()['store']->metadata($context->recordId());
            if ($record['issuer'] !== $this->config->issuer || $record['client'] !== $this->config->clientId) {
                throw new CredentialException('incompatible_context');
            }
            $browser = $record['owner']['session'] ?? $record['policy']['browser'] ?? null;
            if ($operation !== 'bind' && $browser !== (new BrowserContext)->binding($request)) {
                throw new CredentialException('incompatible_context');
            }
            if ($record['owner'] !== null && ! $context->confirms($record)) {
                throw new CredentialException('incompatible_context');
            }
            $this->services()['host']->validate($request, $record, $operation);

            return $record;
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('incompatible_context');
        }
    }

    /** @return array{store: CredentialStore, host: CredentialHostBinding, ttl: int, unbound_ttl: int, margin: int} */
    private function services(): array
    {
        try {
            if ($this->services === null) {
                $services = ($this->factory)();
                $services['margin'] ??= 60;
                if (! $services['store'] instanceof CredentialStore || ! $services['host'] instanceof CredentialHostBinding
                    || ! is_int($services['ttl']) || ! is_int($services['unbound_ttl'])
                    || ! is_int($services['margin']) || $services['margin'] < 0 || $services['margin'] > 60
                    || $services['ttl'] < 60 || $services['ttl'] > 604800 || $services['unbound_ttl'] < 1 || $services['unbound_ttl'] > 600) {
                    throw new CredentialException('credential_configuration_error');
                }
                $this->services = $services;
            }

            return $this->services;
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_configuration_error');
        }
    }

    public function __debugInfo(): array
    {
        return ['manager' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credential manager serialization is prohibited.');
    }
}
