<?php

declare(strict_types=1);

namespace Doxa\Laravel\Authorization;

use Doxa\Laravel\Exceptions\CallbackException;
use Illuminate\Http\Request;

final readonly class Callback
{
    private function __construct(public string $state, public ?string $code, public ?string $error) {}

    public static function parse(#[\SensitiveParameter] Request $request): self
    {
        $raw = $request->server->get('QUERY_STRING', '');
        // Keep protocol input local, and remove credential-bearing query data from
        // the request before host response/exception diagnostics inspect it.
        $uri = $request->server->get('REQUEST_URI', '/');
        $server = $request->server->all();
        $server['QUERY_STRING'] = '';
        $server['REQUEST_URI'] = is_string($uri) ? explode('?', $uri, 2)[0] : '/';
        // initialize also clears Symfony's previously cached request URI.
        $request->initialize([], $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent());
        if ($request->method() !== 'GET') {
            throw new CallbackException('authorization_error');
        }
        if (! is_string($raw) || strlen($raw) > 32768) {
            throw new CallbackException('authorization_error');
        }
        $values = [];
        foreach (explode('&', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $pair) || str_contains($pair, ';')) {
                throw new CallbackException('authorization_error');
            }
            $key = urldecode($key);
            if (preg_match('/^(code|state|error)(?:\[|\s|\.)/', $key)) {
                throw new CallbackException('authorization_error');
            }
            if (in_array($key, ['state', 'code', 'error'], true)) {
                if (isset($values[$key])) {
                    throw new CallbackException('authorization_error');
                }
                $values[$key] = urldecode($value);
            }
        }
        if (! isset($values['state']) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $values['state']) !== 1) {
            throw new CallbackException('invalid_state');
        }
        if (isset($values['code']) === isset($values['error']) || ($values['code'] ?? $values['error']) === '') {
            throw new CallbackException('authorization_error');
        }

        return new self($values['state'], $values['code'] ?? null, $values['error'] ?? null);
    }

    public function __debugInfo(): array
    {
        return ['callback' => '[redacted]'];
    }
}
