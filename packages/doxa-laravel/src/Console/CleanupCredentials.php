<?php

declare(strict_types=1);

namespace Doxa\Laravel\Console;

use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Console\Command;

final class CleanupCredentials extends Command
{
    protected $signature = 'doxa:credentials:cleanup {--limit=100 : Maximum records per invocation (1-1000)}';

    protected $description = 'Expire abandoned credential contexts and purge due tombstones';

    public function handle(): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new CredentialException('credential_configuration_error');
            }
            $count = $this->laravel->make(CredentialStore::class)->cleanup($limit);
            $this->info('Credential contexts cleaned: '.$count);

            return self::SUCCESS;
        } catch (CredentialException $error) {
            $this->error($error->category);

            return self::FAILURE;
        }
    }
}
