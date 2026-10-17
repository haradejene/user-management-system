"""Sequential SDK matrix. Reuses profile locks when available; never changes host/IAM projects.

Usage: python tools/verify-managed.py --composer-phar PATH [--postgresql]
Historical locks retain their dependencies; audit findings are recorded, not suppressed.
"""
from pathlib import Path
import argparse
import json
import os
import shutil
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--composer-phar', required=True)
parser.add_argument('--postgresql', action='store_true')
parser.add_argument('--reuse-locks', action='store_true', help='Recheck final source with validated existing locks/audits, without new resolution or audit network calls')
parser.add_argument('--historical-advisories', action='store_true', help='Explicit Composer resolution exception for historical compatibility fixtures only')
parser.add_argument('--profiles', nargs='+', default=['laravel10', 'laravel11', 'laravel12', 'floors'])
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
out = root / '.compatibility/managed-credentials'
out.mkdir(parents=True, exist_ok=True)
base_env = os.environ.copy()
base_env.pop('COMPOSER', None)
base_env['COMPOSER_CACHE_DIR'] = str(root / '.compatibility/composer-cache')
composer = ['php', str(Path(args.composer_phar).resolve())]
results_path = out / 'results.json'
results = json.loads(results_path.read_text()) if results_path.exists() else {}


def run(profile, step, command, env, allowed=(0,)):
    process = subprocess.run(command, cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    (out / f'{profile}-{step}.log').write_bytes(process.stdout)
    tail = process.stdout.decode(errors='replace').splitlines()[-7:]
    print(f'{profile}/{step}: exit {process.returncode}\n' + '\n'.join(tail), flush=True)
    results.setdefault(profile, {})[step] = {'exit_code': process.returncode, 'summary': tail}
    (out / 'results.json').write_text(json.dumps(results, indent=2))
    if process.returncode not in allowed:
        raise RuntimeError(f'{profile}/{step} failed; see its log')


def checks(profile, env):
    fixture = profile != 'baseline'
    run(profile, 'validate', composer + ['validate', '--strict'] + (['--no-check-all', '--no-check-publish'] if fixture else []), env)
    run(profile, 'platform', composer + ['check-platform-reqs'], env)
    sqlite = env.copy()
    sqlite.pop('DOXA_CREDENTIAL_TEST_DRIVER', None)
    run(profile, 'phpunit', ['php', 'vendor/bin/phpunit', '--do-not-cache-result', '--log-junit', str(out / f'{profile}.junit.xml')], sqlite)
    if args.postgresql:
        pgsql = env.copy()
        pgsql['DOXA_CREDENTIAL_TEST_DRIVER'] = 'pgsql'
        run(profile, 'pgsql', ['php', 'vendor/bin/phpunit', '--filter', 'ManagedCredentialsTest|CredentialRaceTest|BindingCompletionTest|RefreshCoordinationTest|ManagedApplicationAccessTest|PendingLinkCompletionTest', '--do-not-cache-result'], pgsql)
    run(profile, 'phpstan', ['php', 'vendor/bin/phpstan', 'analyse', '--no-progress'], env)
    run(profile, 'pint', ['php', 'vendor/bin/pint', '--test'], env)
    # Audit nonzero means findings, not passing security evidence; preserve full JSON report.
    if not args.reuse_locks:
        run(profile, 'audit', composer + ['audit', '--format=json'], env, allowed=(0, 1, 2, 3))


try:
    for profile in args.profiles:
        if profile not in ['laravel10', 'laravel11', 'laravel12', 'floors']:
            raise ValueError('Unsupported compatibility profile')
        run(profile, 'manifest', ['php', 'tools/compatibility.php', profile], base_env)
        manifest = out / f'composer-{profile}.json'
        lock = out / f'composer-{profile}.lock'
        generated = root / f'.compatibility/composer-{profile}.json'
        if args.reuse_locks:
            if not lock.exists() or json.loads(manifest.read_text()) != json.loads(generated.read_text()):
                raise RuntimeError('Existing fixture must match the current generated manifest')
        else:
            shutil.copyfile(generated, manifest)
            source_lock = root / f'.compatibility/composer-{profile}.lock'
            if source_lock.exists():
                shutil.copyfile(source_lock, lock)
        env = base_env.copy()
        env['COMPOSER'] = str(manifest)
        # Refresh dependency classification/hash using Composer; no dependency upgrades.
        options = ['--no-security-blocking'] if args.historical_advisories and profile in ['laravel10', 'laravel11', 'floors'] else []
        if not args.reuse_locks:
            run(profile, 'lock', composer + ['update'] + (['--lock'] if lock.exists() else [])
                + ['--no-install', '--no-interaction', '--no-scripts', '--no-audit'] + options, env)
        run(profile, 'install', composer + ['install', '--no-interaction', '--prefer-dist'], env)
        checks(profile, env)
finally:
    run('baseline', 'restore', composer + ['install', '--no-interaction', '--prefer-dist'], base_env)
checks('baseline', base_env)
