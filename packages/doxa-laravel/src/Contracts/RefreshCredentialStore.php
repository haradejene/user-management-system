<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\RefreshAttempt;

/** Internal capability only; no credential acquisition or network operation is provided. */
interface RefreshCredentialStore extends CredentialStore
{
    /** @param array<string, mixed> $expected Protected, owner-validated metadata snapshot. */
    public function claimRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, int $seconds): RefreshAttempt;

    /** @param array<string, mixed> $expected */
    public function dispatchRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): RefreshAttempt;

    /** @param array<string, mixed> $expected @param array<string, mixed> $replacement Already validated response material, never a public DTO. */
    public function completeRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt, #[\SensitiveParameter] array $replacement): void;

    /** Only a durable pre-dispatch claim can be released. @param array<string, mixed> $expected */
    public function releaseRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): void;

    /** Terminalize exactly this dispatched attempt, never a newer operation. @param array<string, mixed> $expected */
    public function failRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): void;
}
