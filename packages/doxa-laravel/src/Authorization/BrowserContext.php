<?php

declare(strict_types=1);

namespace Doxa\Laravel\Authorization;

use Doxa\Laravel\Exceptions\CallbackException;
use Illuminate\Http\Request;

final class BrowserContext
{
    public function binding(#[\SensitiveParameter] Request $request): string
    {
        if (! $request->hasSession() || ! $request->session()->isStarted() || $request->session()->getId() === '') {
            throw new CallbackException('invalid_state');
        }

        // Only a hash of the existing server-side browser session ID is persisted.
        return hash('sha256', $request->session()->getId());
    }
}
