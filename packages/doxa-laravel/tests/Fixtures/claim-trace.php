<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Http\JsonResponse;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;

[$script, $source, $field] = $argv;
$h = new Harness(['userinfo_enabled' => true]);
try {
    $h->begin();
    $claims = ['email' => 'private.person@example.test', 'name' => 'Private Person',
        'given_name' => 'PrivateGiven', 'family_name' => 'PrivateFamily',
        'picture' => 'https://images.example.test/private-avatar', 'sub' => 'private-public-subject',
        $field => ['malformed-private-value']];
    $h->claimChanges = $source === 'idtoken' ? $claims : ($source === 'userinfo_response'
        ? [...$claims, $field => null] : ['sub' => 'private-public-subject']);
    if ($source === 'userinfo') {
        $h->http->responses[Harness::USERINFO] = $claims;
    }
    if ($source === 'userinfo_response') {
        $h->http->responses[Harness::USERINFO] = $field === 'subject'
            ? [...$claims, 'sub' => 'different-subject']
            : new JsonResponse($claims, null, 'text/plain');
    }
    try {
        $h->client->handleCallback($h->callback());
        throw new RuntimeException('Malformed claims accepted');
    } catch (DoxaException $exception) {
        $trace = $exception->getTrace();
        $result = ['ignore_args' => ini_get('zend.exception_ignore_args'), 'class' => $exception::class,
            'functions' => array_map(static fn ($frame) => ($frame['class'] ?? '').'::'.$frame['function'], $trace),
            'category' => $exception->category, 'previous' => $exception->getPrevious(),
            'has_arguments' => (bool) array_filter($trace, static fn ($frame) => ! empty($frame['args'])),
            'arguments' => TraceArguments::inspect(array_column($trace, 'args'))];
    }
    try {
        $h->client->handleCallback($h->callback());
        throw new RuntimeException('Replay accepted');
    } catch (DoxaException $exception) {
        $result['replay'] = $exception->category;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} finally {
    $h->cleanup();
}
