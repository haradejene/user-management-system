<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\FaultCredentialStore;
use Doxa\Laravel\Tests\Fixtures\FaultTransactionStore;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;

$fault = $argv[1];
$fixture = new CredentialFixture;
try {
    $fixture->configure(new FaultCredentialStore($fixture->store, $fault),
        $fault === 'finish' ? new FaultTransactionStore($fixture->login->store) : null);
    $fixture->begin();
    $pending = $fixture->login->transaction();
    $forbidden = ['ACCESS-SECRET', 'REFRESH-SECRET', 'CODE-SECRET', str_repeat('k', 32),
        $pending->state, $pending->nonce(), $pending->verifier()];
    try {
        $fixture->result();
        exit(2);
    } catch (CredentialException $error) {
        $arguments = TraceArguments::inspect(array_column($error->getTrace(), 'args'));
        $clean = true;
        foreach ($forbidden as $secret) {
            $clean = $clean && ! str_contains($arguments, $secret);
        }
        echo json_encode(['clean' => $clean, 'previous' => $error->getPrevious() === null,
            'args_enabled' => ini_get('zend.exception_ignore_args') === '0']);
    }
} finally {
    $fixture->cleanup();
}
