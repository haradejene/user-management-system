<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

use Doxa\Laravel\Credentials\ProtectedValue;
use Doxa\Laravel\Exceptions\RefreshFailure;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Token\RefreshResponse;

/** In-memory response classification, not a network result provenance or dispatch authority. */
final readonly class ManagedTransportResult extends ProtectedValue
{
    private function __construct(
        public ManagedDispatchOutcome $outcome,
        public ?int $status,
        public ?RefreshFailure $failure,
        private ?RefreshResponse $refreshResponse = null,
    ) {}

    /** Reserved for locally established non-dispatch; never call for a transport exception. */
    public static function beforeDispatch(): self
    {
        return new self(ManagedDispatchOutcome::BeforeDispatch, null, RefreshFailure::BeforeDispatch);
    }

    /** All generic transport exceptions are uncertain, including connection failures. */
    public static function uncertain(): self
    {
        return new self(ManagedDispatchOutcome::Uncertain, null, RefreshFailure::Uncertain);
    }

    /** Sanitized HTTP outcome only; accepts neither a raw body nor credentials. */
    public static function response(int $status, ?RefreshFailure $failure = null): self
    {
        return new self(ManagedDispatchOutcome::ResponseReceived, $status, $failure);
    }

    /** The parser value contains metadata only, never raw tokens or a response body. */
    public static function validated(RefreshResponse $response): self
    {
        return new self(ManagedDispatchOutcome::ResponseReceived, 200, null, $response);
    }

    /** Retrieves already-validated metadata; cannot parse or retrieve a credential. */
    public function validatedRefresh(): RefreshResponse
    {
        if ($this->failure !== null || $this->status !== 200 || $this->refreshResponse === null) {
            throw new RefreshResponseException($this->failure ?? RefreshFailure::ContractViolation);
        }

        return $this->refreshResponse;
    }
}
