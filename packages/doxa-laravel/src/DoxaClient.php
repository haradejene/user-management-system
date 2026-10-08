<?php

declare(strict_types=1);

namespace Doxa\Laravel;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Authorization\Callback;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\AuthorizationException;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Exceptions\ExpiredTransactionException;
use Doxa\Laravel\Exceptions\ProviderDeniedException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DoxaClient
{
    public function __construct(
        private readonly DoxaConfig $config,
        private readonly Discovery $discovery,
        private readonly Authorization $authorization,
        private readonly BrowserContext $browser,
        private readonly TransactionStore $transactions,
        private readonly CodeExchange $exchange,
        private readonly IdTokenValidator $validator,
        private readonly UserInfo $userInfo,
        private readonly Request $request,
    ) {}

    public function discover(): ProviderMetadata
    {
        return $this->discovery->discover();
    }

    public function __debugInfo(): array
    {
        return ['client' => '[redacted]'];
    }

    public function beginLogin(#[\SensitiveParameter] ?AuthorizationOptions $options = null): RedirectResponse
    {
        try {
            $provider = $this->discover();
            $transaction = $this->authorization->create($this->config, $provider, $this->browser->binding($this->request));
            $url = $this->authorization->url($transaction, $options);
            $this->transactions->create($transaction);

            return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (DoxaException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AuthorizationException('authorization_error');
        }
    }

    public function handleCallback(#[\SensitiveParameter] Request $request): DoxaIdentity
    {
        $callback = Callback::parse($request);
        $transaction = $this->transactions->claim($callback->state, $this->browser->binding($request));
        $success = false;
        try {
            if ($transaction->expiresAt <= time()) {
                throw new ExpiredTransactionException('transaction_expired');
            }
            if ($callback->error !== null) {
                if ($callback->error === 'access_denied') {
                    throw new ProviderDeniedException('user_denied_authorization');
                }
                throw new AuthorizationException('authorization_error');
            }
            $tokens = $this->exchange->exchange($callback->code ?? '', $transaction);
            $identity = DoxaIdentity::authenticate($this->validator, $tokens->idToken(), $transaction);
            if ($this->config->userInfo) {
                $identity = $identity->enriched($this->userInfo, $tokens, $transaction);
            }
            if ($transaction->expiresAt <= time()) {
                throw new ExpiredTransactionException('transaction_expired');
            }
            $success = true;

            return $identity;
        } catch (DoxaException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AuthorizationException('authorization_error');
        } finally {
            $this->transactions->finish($callback->state, $success);
        }
    }
}
