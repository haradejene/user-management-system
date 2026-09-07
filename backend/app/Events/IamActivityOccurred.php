<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** Synchronous business activity; never include credentials or model snapshots in metadata. */
class IamActivityOccurred
{
    use Dispatchable;

    public function __construct(
        public readonly string $action,
        public readonly ?Model $subject = null,
        public readonly array $metadata = [],
        public readonly ?User $actor = null,
    ) {}
}
