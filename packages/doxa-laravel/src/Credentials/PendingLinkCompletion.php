<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Exceptions\CredentialException;

/** Internal one-time purpose transition. Host owns mapping/confirmation and durable crash recovery. */
trait PendingLinkCompletion
{
    public function completePendingLink(#[\SensitiveParameter] CredentialManager $manager, object $ticket): CredentialContext
    {
        $invocation = $manager->consumeLinkInvocation($ticket, $this);
        $attempt = bin2hex(random_bytes(32));
        $proof = bin2hex(random_bytes(32));
        $context = $invocation['context'];
        $host = $invocation['host'];
        $request = $invocation['request'];
        $claimed = null;
        $claimAcknowledged = false;
        try {
            $payload = $this->refreshOwner($context, $invocation['expected'], true);
            if ($payload['status'] !== 'pending_link' || isset($payload['completion'])
                || $payload['version'] !== $invocation['expected']['version']) {
                throw new CredentialException('credential_lifecycle_failure');
            }
            // Durable SDK claim precedes host side effects. A losing worker cannot reach the host.
            $version = $payload['version'];
            $payload['completion'] = ['attempt' => $attempt];
            $payload['version']++;
            $claimed = $payload;
            try {
                $this->replace($payload, $version, 'pending_link', true);
            } catch (CredentialException $error) {
                if ($error->category === 'credential_lifecycle_failure') {
                    // A known CAS loser owns nothing; never compensate a winning confirmer.
                    $claimed = null;
                }
                throw $error;
            }
            $claimAcknowledged = true;
            $metadata = $this->metadata($context->recordId());
            $host->claimPendingLink($request, $metadata, $attempt);
            // Never accept a previously returned/caller-created ApplicationAccessDecision.
            ($invocation['check'])($attempt);
            $metadata = ($invocation['validate'])($attempt);
            $payload = $this->refreshOwner($context, $metadata, true);
            if ($payload['status'] !== 'pending_link' || ($payload['completion']['attempt'] ?? null) !== $attempt) {
                throw new CredentialException('credential_lifecycle_failure');
            }
            $version = $payload['version'];
            $payload['status'] = 'link_binding';
            $payload['version']++;
            // No completed reference/proof is stored in either database before acknowledgement.
            $payload['binding'] = ['attempt' => $attempt, 'proof_hash' => hash('sha256', $proof)];
            $this->replace($payload, $version, 'pending_link', true);
            $metadata = $this->metadata($context->recordId());
            ($invocation['validate'])($attempt);
            try {
                $association = $host->commitPendingLink($request, $metadata, $attempt);
            } catch (\Throwable) {
                // Read-only exact-attempt recovery; never repeat local mapping creation.
                $association = $host->verifyPendingLinkCommit($request, $metadata, $attempt);
            }
            $verified = $host->verifyPendingLinkCommit($request, $metadata, $attempt);
            if ($association !== $verified || ($association['account'] ?? null) !== $metadata['policy']['host']['target']
                || ($association['session'] ?? null) !== (new BrowserContext)->binding($request)) {
                throw new CredentialException('incompatible_context');
            }
            $payload = $this->read($context->recordId());
            if ($payload['status'] !== 'link_binding' || $payload['version'] !== $metadata['version']
                || ($payload['completion']['attempt'] ?? null) !== $attempt
                || $payload['expires_at'] <= time() || $payload['absolute_expires_at'] <= time()
                || $payload['policy']['host']['deadline'] <= time()) {
                throw new CredentialException('credential_lifecycle_failure');
            }
            $version = $payload['version'];
            $payload['purpose'] = 'ordinary';
            $payload['owner'] = $association;
            $payload['status'] = 'active';
            $payload['version']++;
            // Keep BOTH original pending deadline and absolute lifetime. Promotion extends neither.
            try {
                $this->replace($payload, $version, 'link_binding', true);
            } catch (CredentialException) {
                $committed = $this->read($context->recordId());
                // A lost write acknowledgement can only observe this exact already-committed result.
                if ($committed !== $payload) {
                    throw new CredentialException('credential_binding_failure');
                }
            }
            if ($host->verifyPendingLinkCommit($request, $metadata, $attempt) !== $association) {
                throw new CredentialException('credential_binding_failure');
            }
            $completed = CredentialContext::fromServerReference($context->recordId().'.'.$proof);
            $final = $this->read($context->recordId());
            if ($final !== $payload || ! $completed->confirms($final) || $final['expires_at'] <= time()) {
                throw new CredentialException('credential_binding_failure');
            }

            return $completed;
        } catch (\Throwable) {
            if ($claimed !== null) {
                // Unknown initial CAS acknowledgement is not permission to cancel a host
                // transaction: prove ownership of that exact marker before any compensation.
                if (! $claimAcknowledged) {
                    try {
                        $claimAcknowledged = ($this->read($context->recordId())['completion']['attempt'] ?? null) === $attempt;
                    } catch (\Throwable) {
                    }
                }
                try {
                    if ($claimAcknowledged) {
                        $host->abortPendingLink($this->withoutTokens($claimed), $attempt);
                    }
                } catch (\Throwable) {
                    // Host's durable claim/reconciliation must handle unavailable compensation.
                }
                try {
                    $payload = $this->read($context->recordId());
                    if (($payload['completion']['attempt'] ?? null) === $attempt) {
                        $this->delete($context->recordId(), $payload['version'], 'unusable');
                    }
                } catch (\Throwable) {
                    // Pending/link_binding remains fenced; active has no released proof preimage.
                }
            }
            throw new CredentialException('credential_binding_failure');
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutTokens(#[\SensitiveParameter] array $payload): array
    {
        unset($payload['access_token'], $payload['refresh_token']);

        return $payload;
    }
}
