<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;

[$script, $directory, $id, $mode] = $argv;
$input = json_decode(file_get_contents($directory.'/input.json'), true, flags: JSON_THROW_ON_ERROR);
$h = new Harness([], $directory.'/cache', $argv[4] ?? 'file');
$h->query = $input;
file_put_contents($directory.'/ready-'.$id, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/release')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    if ($mode === 'crash') {
        $h->store->claim($input['state'], hash('sha256', str_repeat('a', 40)));
        echo json_encode(['result' => 'claimed', 'token_calls' => 0]);
        exit;
    }
    if ($mode === 'exchange') {
        $transaction = $h->store->claim($input['state'], hash('sha256', str_repeat('a', 40)));
        $tokens = (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
        $identity = DoxaIdentity::authenticate($h->validator, $tokens->idToken(), $transaction);
        $h->store->finish($input['state'], true);
    } else {
        $identity = $h->client->handleCallback($h->callback());
    }
    echo json_encode(['result' => 'success', 'subject' => $identity->subject(), 'token_calls' => $h->http->count(Harness::TOKEN)]);
} catch (DoxaException $exception) {
    echo json_encode(['result' => $exception->category, 'token_calls' => $h->http->count(Harness::TOKEN)]);
}
