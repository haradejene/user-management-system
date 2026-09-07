<?php

namespace App\Listeners;

use App\Events\IamActivityOccurred;
use App\Services\AuditService;

class RecordIamActivity
{
    public function __construct(private AuditService $audit) {}

    // Deliberately synchronous: an audit failure must roll back the business transaction.
    public function handle(IamActivityOccurred $event): void
    {
        $this->audit->record($event->action, $event->subject, $event->metadata, $event->actor);
    }
}
