<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

/** Internal SDK storage adapter. Never expose this service through a controller or HTTP serializer. */
interface CredentialStore
{
    public function ready(): void;

    /** @param array<string, mixed> $payload */
    public function stage(#[\SensitiveParameter] array $payload): void;

    /** Metadata only: no credential material is ever returned by the store. @return array<string, mixed> */
    public function metadata(#[\SensitiveParameter] string $id): array;

    /** Compare-and-set, immutable identity/purpose; pending-link completion is intentionally absent. */
    public function transition(#[\SensitiveParameter] string $id, int $version, string $from, string $to, #[\SensitiveParameter] array $association = []): void;

    public function delete(#[\SensitiveParameter] string $id, int $version, string $terminal = 'deleted'): void;

    /** Compensate only this attempt/version; a losing binder must never touch a winner. */
    public function abandonBinding(#[\SensitiveParameter] string $id, int $version, #[\SensitiveParameter] string $attempt): void;

    public function cleanup(int $limit = 100): int;
}
