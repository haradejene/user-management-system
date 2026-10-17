<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

/** Classification only, never proof/permission to dispatch or retry. */
enum ManagedDispatchOutcome: string
{
    case BeforeDispatch = 'before_dispatch';
    case ResponseReceived = 'response_received';
    case Uncertain = 'uncertain';
}
