<?php

namespace Tests\Feature\Developers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class SdkDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_all_available_sdks(): void
    {
        $response = $this->getJson('/api/developers/sdks');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'framework',
                        'name',
                        'language',
                        'package_name',
                        'version',
                        'install_command',
                        'download_url',
                        'contract_version',
                        'features',
                        'env_template',
                    ],
                ],
            ]);

        $frameworks = collect($response->json('data'))->pluck('framework')->all();
        $this->assertContains('laravel', $frameworks);
        $this->assertContains('node', $frameworks);
        $this->assertContains('django', $frameworks);
    }

    public function test_can_download_node_sdk_archive(): void
    {
        $response = $this->get('/api/developers/sdks/node/download');

        $response->assertOk();
        $this->assertStringContainsString('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('doxa-node-sdk.zip', $response->headers->get('Content-Disposition'));

        $filePath = $response->getFile()->getPathname();
        $this->assertFileExists($filePath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($filePath));
        // Verify key files inside zip
        $this->assertNotFalse($zip->locateName('doxa-node/package.json'));
        $this->assertNotFalse($zip->locateName('doxa-node/src/client.js'));
        $this->assertNotFalse($zip->locateName('doxa-node/README.md'));
        $zip->close();
    }

    public function test_can_download_django_sdk_archive(): void
    {
        $response = $this->get('/api/developers/sdks/django/download');

        $response->assertOk();
        $this->assertStringContainsString('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('doxa-django-sdk.zip', $response->headers->get('Content-Disposition'));

        $filePath = $response->getFile()->getPathname();
        $this->assertFileExists($filePath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($filePath));
        // Verify key files inside zip
        $this->assertNotFalse($zip->locateName('doxa-django/pyproject.toml'));
        $this->assertNotFalse($zip->locateName('doxa-django/doxa_django/client.py'));
        $this->assertNotFalse($zip->locateName('doxa-django/README.md'));
        $zip->close();
    }

    public function test_can_download_laravel_sdk_archive(): void
    {
        $response = $this->get('/api/developers/sdks/laravel/download');

        $response->assertOk();
        $this->assertStringContainsString('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('doxa-laravel-sdk.zip', $response->headers->get('Content-Disposition'));

        $filePath = $response->getFile()->getPathname();
        $this->assertFileExists($filePath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($filePath));
        // Verify key files inside zip
        $this->assertNotFalse($zip->locateName('doxa-laravel/composer.json'));
        $this->assertNotFalse($zip->locateName('doxa-laravel/src/DoxaClient.php'));
        // Verify vendor is excluded
        $this->assertFalse($zip->locateName('doxa-laravel/vendor/autoload.php'));
        $zip->close();
    }

    public function test_download_unknown_sdk_returns_404(): void
    {
        $response = $this->get('/api/developers/sdks/ruby/download');
        $response->assertNotFound();
    }

    public function test_diagnostics_requires_authentication(): void
    {
        $response = $this->getJson('/api/developers/diagnostics');
        $response->assertUnauthorized();
    }

    public function test_diagnostics_returns_metadata_for_authenticated_user(): void
    {
        $user = User::factory()->systemAdmin()->create();

        $response = $this->actingAs($user)->getJson('/api/developers/diagnostics');

        $response->assertOk()
            ->assertJsonStructure([
                'issuer',
                'status',
                'contract_version',
                'endpoints' => [
                    'discovery',
                    'jwks',
                    'authorization',
                    'token',
                    'userinfo',
                    'application_access',
                ],
                'requirements',
                'applications',
            ]);
    }
}
