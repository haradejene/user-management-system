<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Http\Request;

final class CredentialHost implements CredentialHostBinding
{
    public bool $live = true;

    public bool $mappingCommitted = true;

    public bool $allowRegeneration = false;

    public string $account = 'local-7';

    public string $initiator = 'admin-1';

    public string $link = 'link-1';

    public string $target = 'local-7';

    public ?int $deadline = null;

    public function capture(Request $request, ManagedLoginPolicy $policy): array
    {
        return ['initiator' => $this->initiator, 'link' => $this->link, 'target' => $this->target, 'deadline' => $this->deadline ?? time() + 300];
    }

    public function validate(Request $request, array $metadata, string $operation): void
    {
        $host = $metadata['policy']['host'] ?? $metadata['host'];
        if (! $this->live || ($metadata['subject'] ?? 'public-subject') !== 'public-subject'
            || ($metadata['owner']['account'] ?? $this->account) !== $this->account
            || ($host['initiator'] ?? null) !== $this->initiator || ($host['target'] ?? null) !== $this->target
            || ($host['link'] ?? null) !== $this->link) {
            throw new CredentialException('incompatible_context');
        }
        if ($operation === 'bind' && ! $this->allowRegeneration
            && $metadata['policy']['browser'] !== hash('sha256', $request->session()->getId())) {
            throw new CredentialException('incompatible_context');
        }
    }

    public function complete(Request $request, array $metadata): array
    {
        if (! $this->mappingCommitted) {
            throw new CredentialException('incompatible_context');
        }
        $this->validate($request, $metadata, 'bind');

        // Fixture stands in for protected host lookup(issuer, subject) + committed mapping.
        return ['account' => $this->account, 'session' => hash('sha256', $request->session()->getId())];
    }
}
