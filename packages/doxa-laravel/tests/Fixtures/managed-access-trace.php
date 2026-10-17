<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Exceptions\ApplicationAccessException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

// Separate process: real outward trace/object graphs, without PHPUnit's caller-owned
// TestSuite graph containing deliberately retained request fixtures and test secrets.
$f = new CredentialFixture;
$secret = 'CLIENT_SECRET_SENTINEL_DO_NOT_LEAK';
$method = $argv[1];
$f->login->config = new DoxaConfig([...Harness::config(), 'client_secret' => $secret, 'client_auth_method' => $method]);
$calls = [];
$store = new DatabaseCredentialStore($f->db, $f->cipher, $f->table, 60,
    static function (#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] array $options) use (&$calls, $secret) {
        $calls[] = $request;
        if (count($calls) > 2) {
            return Create::rejectionFor(new RuntimeException($secret));
        }

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $request->getMethod() === 'POST'
            ? json_encode(['access_token' => 'ACCESS_SENTINEL_DO_NOT_LEAK', 'refresh_token' => 'REFRESH_SENTINEL_DO_NOT_LEAK', 'token_type' => 'Bearer', 'expires_in' => 900])
            : '{"effective":true}'));
    });
try {
    $f->configure($store);
    $f->begin();
    $context = $f->client->bindCredentialContext($f->result()->credentialContext());
    $f->mutate($context->recordId(), ['access_expires_at' => time() + 20]);
    $decision = $f->client->checkApplicationAccess($context);
    parse_str((string) $calls[0]->getBody(), $form);
    $header = $calls[0]->getHeaderLine('Authorization');
    $auth = $method === 'client_secret_basic'
        ? $header === 'Basic '.base64_encode(urlencode($f->login->config->clientId).':'.urlencode($secret)) && ! array_key_exists('client_secret', $form) && ! array_key_exists('client_id', $form)
        : $header === '' && $form['client_secret'] === $secret && $form['client_id'] === $f->login->config->clientId;
    $diagnostics = var_export($decision, true).print_r($decision, true).json_encode($decision).serialize($decision);
    try {
        $f->client->checkApplicationAccess($context);
        throw new RuntimeException('Expected safe transport failure');
    } catch (ApplicationAccessException $error) {
        $diagnostics .= var_export($error, true).print_r($error, true).TraceArguments::inspect($error->getTrace());
        $clean = true;
        foreach ([$secret, 'ACCESS_SENTINEL_DO_NOT_LEAK', 'REFRESH_SENTINEL_DO_NOT_LEAK'] as $value) {
            $clean = $clean && ! str_contains($diagnostics, $value);
        }
        echo json_encode(['effective' => $decision->effective, 'auth' => $auth, 'clean' => $clean,
            'category' => $error->category, 'requests' => count($calls), 'cause' => $error->getPrevious() !== null]);
    }
} finally {
    $f->cleanup();
}
