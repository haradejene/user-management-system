<?php

declare(strict_types=1);

namespace Doxa\Laravel;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Authorization\Callback;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\ManagedTransactionStore;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Credentials\ManagedLoginResult;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\AuthorizationException;
use Doxa\Laravel\Exceptions\CredentialException;
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
        private readonly ?CredentialManager $credentials = null,
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
        $result = $this->callback(Callback::parse($request), $request, false);
        if (! $result instanceof DoxaIdentity) {
            throw new AuthorizationException('authorization_error');
        }

        return $result;
    }

    public function beginManagedLogin(#[\SensitiveParameter] ManagedLoginPolicy $policy, #[\SensitiveParameter] ?AuthorizationOptions $options = null): RedirectResponse
    {
        try {
            if (! $this->transactions instanceof ManagedTransactionStore) {
                throw new CredentialException('credential_configuration_error');
            }
            $snapshot = $this->manager()->prepare($policy, $this->request);
            $provider = $this->discover();
            $transaction = $this->authorization->create($this->config, $provider, $this->browser->binding($this->request), $snapshot);
            $url = $this->authorization->url($transaction, $options);
            $this->transactions->create($transaction);

            return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (DoxaException $error) {
            throw new CredentialException($error->category);
        } catch (\Throwable) {
            throw new CredentialException('credential_configuration_error');
        }
    }

    public function handleManagedCallback(#[\SensitiveParameter] Request $request): ManagedLoginResult
    {
        try {
            $result = $this->callback(Callback::parse($request), $request, true);
            if (! $result instanceof ManagedLoginResult) {
                throw new CredentialException('credential_lifecycle_failure');
            }

            return $result;
        } catch (DoxaException $error) {
            // Drop adapter/transport/container trace arguments as well as low-level causes.
            throw new CredentialException($error->category);
        } catch (\Throwable) {
            throw new CredentialException('credential_lifecycle_failure');
        }
    }

    public function bindCredentialContext(#[\SensitiveParameter] CredentialContext $context): CredentialContext
    {
        try {
            return $this->manager()->bind($context, $this->request);
        } catch (DoxaException $error) {
            throw new CredentialException($error->category);
        } catch (\Throwable) {
            throw new CredentialException('credential_binding_failure');
        }
    }

    public function deleteCredentialContext(#[\SensitiveParameter] CredentialContext $context): void
    {
        try {
            $this->manager()->delete($context, $this->request);
        } catch (DoxaException $error) {
            throw new CredentialException($error->category);
        } catch (\Throwable) {
            throw new CredentialException('credential_lifecycle_failure');
        }
    }

    public function restoreCredentialContext(#[\SensitiveParameter] string $serverReference): CredentialContext
    {
        try {
            return $this->manager()->restore($serverReference, $this->request);
        } catch (DoxaException $error) {
            throw new CredentialException($error->category);
        } catch (\Throwable) {
            throw new CredentialException('credential_lifecycle_failure');
        }
    }

    private function manager(): CredentialManager
    {
        return $this->credentials ?? throw new CredentialException('credential_configuration_error');
    }

    private function callback(#[\SensitiveParameter] Callback $callback, #[\SensitiveParameter] Request $request, bool $managed): DoxaIdentity|ManagedLoginResult
    {
        $transaction = $this->transactions->claim($callback->state, $this->browser->binding($request));
        $success = false;
        $finished = false;
        $context = null;
        try {
            if (($transaction->managedPolicy() !== null) !== $managed || ($managed && ! $this->transactions instanceof ManagedTransactionStore)) {
                throw new CredentialException('credential_policy_mismatch');
            }
            if ($managed) {
                $this->manager()->validateCallback($transaction, $request);
            }
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
            if ($managed) {
                $context = $this->manager()->stage($tokens, $identity, $transaction, $request, $this->validator);
                try {
                    $this->transactions->finish($callback->state, true);
                    $finished = true;
                    $this->manager()->activate($context, $transaction, $this->transactions, $request);
                } catch (\Throwable) {
                    throw new CredentialException('credential_callback_finalization_failure');
                }

                return new ManagedLoginResult($identity, $context);
            }
            $success = true;

            return $identity;
        } catch (DoxaException $exception) {
            if ($context !== null) {
                $this->manager()->abort($context);
            }
            throw $exception;
        } catch (\Throwable) {
            if ($context !== null) {
                $this->manager()->abort($context);
            }
            if ($managed) {
                throw new CredentialException('credential_lifecycle_failure');
            }
            throw new AuthorizationException('authorization_error');
        } finally {
            if (! $finished) {
                try {
                    $this->transactions->finish($callback->state, $success);
                } catch (\Throwable $error) {
                    if ($managed) {
                        throw new CredentialException('credential_callback_finalization_failure');
                    }
                    throw $error;
                }
            }
        }
    }
}
