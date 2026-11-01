<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Services\OidcSigningKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class SdkDownloadController extends Controller
{
    /**
     * List all available official Central IAM SDKs and metadata.
     */
    public function index(): JsonResponse
    {
        $sdks = [
            [
                'framework' => 'laravel',
                'name' => 'Doxa Laravel SDK',
                'language' => 'PHP',
                'runtime' => 'PHP 8.2+',
                'package_name' => 'doxa/oauth-client',
                'supported_versions' => 'Laravel 10.x, 11.x, 12.x',
                'version' => '1.0.0',
                'install_command' => 'composer require doxa/oauth-client',
                'download_url' => url('/api/developers/sdks/laravel/download'),
                'contract_version' => 'v1.3',
                'description' => 'Official service provider, authentication guard, and fail-closed application access checker for Laravel applications.',
                'features' => [
                    'Mandatory S256 PKCE with secure cryptographic random verifier',
                    'Automatic OIDC Discovery & JWKS key rotation cache (300s TTL)',
                    'RS256 ID Token signature and claims validation',
                    'Fail-closed GET /oauth/application-access enforcement',
                    'AccountResolver contract for custom local user mapping',
                    'Drop-in OAuth callback controller & route registration',
                ],
                'env_template' => [
                    'DOXA_ISSUER' => config('oidc.issuer', env('APP_URL', 'https://localhost:8443')),
                    'DOXA_CLIENT_ID' => 'your-client-id-uuid',
                    'DOXA_CLIENT_SECRET' => 'your-client-secret',
                    'DOXA_REDIRECT_URI' => 'https://your-app.test/auth/doxa/callback',
                    'DOXA_SCOPES' => 'openid profile email',
                ],
            ],
            [
                'framework' => 'node',
                'name' => 'Doxa Node.js SDK',
                'language' => 'TypeScript / JavaScript',
                'runtime' => 'Node.js 18+',
                'package_name' => '@doxa/auth-node',
                'supported_versions' => 'Express, Next.js, Fastify, Vanilla Node.js',
                'version' => '1.0.0',
                'install_command' => 'npm install @doxa/auth-node',
                'download_url' => url('/api/developers/sdks/node/download'),
                'contract_version' => 'v1.3',
                'description' => 'Official lightweight TypeScript/Node.js client with native Web/Node crypto, Express middleware adapter, and strict immutable identity.',
                'features' => [
                    'Zero external dependencies (uses native Node crypto)',
                    'Mandatory S256 PKCE with cryptographic entropy',
                    'OIDC Discovery & JWKS public key caching with 300s TTL',
                    'RS256 ID Token signature verification using public RSA keys',
                    'Fail-closed GET /oauth/application-access verification',
                    'Express middleware helper & Next.js route handler support',
                    'Full TypeScript definitions included',
                ],
                'env_template' => [
                    'DOXA_ISSUER' => config('oidc.issuer', env('APP_URL', 'https://localhost:8443')),
                    'DOXA_CLIENT_ID' => 'your-client-id-uuid',
                    'DOXA_CLIENT_SECRET' => 'your-client-secret',
                    'DOXA_REDIRECT_URI' => 'https://your-app.test/auth/doxa/callback',
                    'DOXA_SCOPES' => 'openid profile email',
                ],
            ],
            [
                'framework' => 'django',
                'name' => 'Doxa Django SDK',
                'language' => 'Python',
                'runtime' => 'Python 3.9+',
                'package_name' => 'doxa-django',
                'supported_versions' => 'Django 4.0+, 4.2 LTS, 5.0+',
                'version' => '1.0.0',
                'install_command' => 'pip install doxa-django',
                'download_url' => url('/api/developers/sdks/django/download'),
                'contract_version' => 'v1.3',
                'description' => 'Official Django authentication backend, drop-in views, and universal DoxaClient for Python web frameworks.',
                'features' => [
                    'Mandatory S256 PKCE generation and verification',
                    'OIDC Discovery & JWKS key caching with 300s TTL',
                    'RS256 ID Token cryptographic validation',
                    'Fail-closed GET /oauth/application-access check',
                    'DoxaAuthBackend integration for standard Django login & sessions',
                    'AccountResolver contract for domain-specific local user provisioning',
                    'Drop-in URL routing and configurable settings dictionary',
                ],
                'env_template' => [
                    'DOXA_ISSUER' => config('oidc.issuer', env('APP_URL', 'https://localhost:8443')),
                    'DOXA_CLIENT_ID' => 'your-client-id-uuid',
                    'DOXA_CLIENT_SECRET' => 'your-client-secret',
                    'DOXA_REDIRECT_URI' => 'https://your-app.test/auth/doxa/callback/',
                    'DOXA_SCOPES' => 'openid profile email',
                ],
            ],
        ];

        return response()->json(['data' => $sdks]);
    }

    /**
     * Download a clean zip archive of the requested SDK package.
     */
    public function download(string $framework): BinaryFileResponse|Response
    {
        $framework = strtolower($framework);
        $allowedFrameworks = ['laravel', 'node', 'django'];

        if (! in_array($framework, $allowedFrameworks, true)) {
            abort(Response::HTTP_NOT_FOUND, 'Unsupported SDK framework: '.$framework);
        }

        $packageDir = realpath(base_path('../packages/doxa-'.$framework));

        if (! $packageDir || ! is_dir($packageDir)) {
            abort(Response::HTTP_NOT_FOUND, "Package source for '{$framework}' not found on server.");
        }

        $zipFile = tempnam(sys_get_temp_dir(), 'doxa_sdk_').'.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to create SDK archive.');
        }

        $excludedDirectories = [
            'vendor',
            'node_modules',
            '__pycache__',
            '.pytest_cache',
            '.phpunit.cache',
            '.phpstan-cache',
            '.compatibility',
            '.git',
            '.venv',
            '.idea',
            '.vscode',
        ];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rootLength = strlen($packageDir) + 1;

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, $rootLength);

            // Normalize directory separator
            $normalizedRelative = str_replace('\\', '/', $relativePath);

            // Check if file is inside an excluded directory
            $parts = explode('/', $normalizedRelative);
            $isExcluded = false;
            foreach ($parts as $part) {
                if (in_array($part, $excludedDirectories, true) || str_ends_with($part, '.pyc')) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                continue;
            }

            $zip->addFile($filePath, "doxa-{$framework}/".$normalizedRelative);
        }

        $zip->close();

        return response()->download(
            $zipFile,
            "doxa-{$framework}-sdk.zip",
            ['Content-Type' => 'application/zip']
        )->deleteFileAfterSend(true);
    }

    /**
     * Provide real-time endpoint discovery and active OAuth clients for developer setup.
     */
    public function diagnostics(OidcSigningKey $keys): JsonResponse
    {
        $jwksHealthy = false;
        try {
            $published = $keys->publishedKeys();
            $jwksHealthy = ! empty($published['keys']);
        } catch (\Throwable) {
            $jwksHealthy = false;
        }

        $issuer = config('oidc.issuer', env('APP_URL', 'https://localhost:8443'));

        $applications = Application::query()
            ->with(['oauthClients' => function ($query) {
                $query->where('revoked', false);
            }])
            ->withCount(['oauthClients' => function ($query) {
                $query->where('revoked', false);
            }])
            ->where('status', 'active')
            ->orderByDesc('oauth_clients_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Application $app) => [
                'id' => $app->public_id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'clients' => $app->oauthClients->map(fn (OAuthClient $c) => [
                    'client_id' => $c->id,
                    'name' => $c->name,
                    'redirect_uris' => $c->redirect_uris,
                    'confidential' => ! empty($c->secret),
                ]),
            ]);

        return response()->json([
            'issuer' => $issuer,
            'status' => $jwksHealthy ? 'healthy' : 'degraded',
            'contract_version' => 'v1.3',
            'endpoints' => [
                'discovery' => url('/.well-known/openid-configuration'),
                'jwks' => url('/oauth/jwks'),
                'authorization' => url('/oauth/authorize'),
                'token' => url('/oauth/token'),
                'userinfo' => url('/oauth/userinfo'),
                'application_access' => url('/oauth/application-access'),
            ],
            'requirements' => [
                'code_challenge_method' => 'S256',
                'id_token_signed_response_alg' => 'RS256',
                'access_verification_header' => 'Authorization: Bearer <access_token>',
                'access_verification_field' => 'effective: true',
            ],
            'applications' => $applications,
        ]);
    }
}
