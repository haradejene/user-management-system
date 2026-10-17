<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Http\ManagedHttpPolicy;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;
use Doxa\Laravel\Token\RefreshResponse;
use GuzzleHttp\Psr7\Response;

// No closure or wrapper carries unprotected credentials into an outward trace.
$policy = new ManagedHttpPolicy(new DoxaConfig([...Harness::config(), 'client_secret' => 'CLIENT-SECRET']));
$protocol = in_array($argv[1], ['protocol', 'direct'], true);
$json = $protocol
    ? '{"access_token":"ACCESS-SECRET","refresh_token":"OLD-SECRET","token_type":"Bearer","expires_in":900}'
    : '{"error":"invalid_grant","error_description":"BODY-SECRET"}';
$result = $policy->inspectRefresh(new Response($protocol ? 200 : 400, ['Content-Type' => 'application/json'], $json), microtime(true), 'OLD-SECRET', ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub',
    'scopes' => ['openid'], 'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
try {
    if ($argv[1] === 'direct') {
        RefreshResponse::parse($json, 'OLD-SECRET', ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub',
            'scopes' => ['openid'], 'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
    } else {
        $result->validatedRefresh();
    }
    exit(2);
} catch (RefreshResponseException $error) {
    $trace = TraceArguments::inspect(array_column($error->getTrace(), 'args'));
    echo json_encode(['clean' => ! str_contains($trace.(string) $error.print_r($result, true).var_export($result, true).var_export($policy, true), 'SECRET'),
        'args_enabled' => ini_get('zend.exception_ignore_args') === '0', 'previous' => $error->getPrevious() === null,
        'trace_boundary' => $argv[1] === 'direct'
            ? count(TraceArguments::protectedParameters($error->getTrace())) >= 3
            : count(TraceArguments::protectedParameters($error->getTrace())) === 0]);
}
