<?php

namespace App\Console\Commands;

use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Throwable;

/**
 * Checks a hosted installation for the settings that most often break it after deployment: the app URL,
 * session cookies and Sanctum (the "your session has ended" loop), database tables and migrations, writable
 * folders, built assets, mail and queue. Prints OK / WARN / FAIL with how to fix each problem.
 *
 * Usage on the server: php artisan roster:doctor [--url=https://roster.example.com]
 */
class DeploymentDoctor extends Command
{
    protected $signature = 'roster:doctor {--url= : The address people use in the browser (defaults to APP_URL)}';

    protected $description = 'Check a hosted installation for common configuration problems';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $results = [];

    /**
     * Run every check and print the results; exits with 1 when anything failed.
     */
    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: config('app.url'));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $https = parse_url($url, PHP_URL_SCHEME) === 'https';

        $this->checkApplication($url, $host);
        $this->checkSession($host, $https);
        $this->checkSanctum($host.($port ? ':'.$port : ''));
        $this->checkDatabase();
        $this->checkFiles();
        $this->checkServices();

        $this->table(['Status', 'Check', 'Details / fix'], $this->results);
        $failed = collect($this->results)->where(0, 'FAIL')->count();
        $warned = collect($this->results)->where(0, 'WARN')->count();
        $failed ? $this->error("{$failed} problem(s) must be fixed.") : $this->info('No blocking problems found.');
        if ($warned) {
            $this->warn("{$warned} warning(s) to review.");
        }
        $this->line('After changing .env run: php artisan config:clear (or config:cache), then sign out and in again.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** App key, environment, debug mode and the URL people use. */
    private function checkApplication(string $url, string $host): void
    {
        $this->result(config('app.key') ? 'OK' : 'FAIL', 'APP_KEY', config('app.key') ? 'Set.' : 'Missing: run php artisan key:generate.');
        $this->result(app()->isProduction() ? 'OK' : 'WARN', 'APP_ENV', 'Is "'.app()->environment().'". Use APP_ENV=production on a hosted site.');
        $this->result(config('app.debug') && app()->isProduction() ? 'FAIL' : (config('app.debug') ? 'WARN' : 'OK'), 'APP_DEBUG', config('app.debug') ? 'On: error details can leak. Set APP_DEBUG=false.' : 'Off.');
        if ($host === '') {
            $this->result('FAIL', 'APP_URL', 'Not a valid URL: "'.$url.'". Set APP_URL to the address in the browser, e.g. https://roster.example.com.');

            return;
        }
        $local = str_ends_with($host, '.test') || in_array($host, ['localhost', '127.0.0.1'], true);
        $this->result($local ? 'WARN' : 'OK', 'APP_URL', $local ? '"'.$url.'" looks like a development address. Set it to the hosted address.' : $url);
    }

    /** Session cookie settings that decide whether the browser keeps the sign-in. */
    private function checkSession(string $host, bool $https): void
    {
        $domain = config('session.domain');
        if ($domain && $host !== '' && ! str_ends_with($host, ltrim((string) $domain, '.'))) {
            $this->result('FAIL', 'SESSION_DOMAIN', '"'.$domain.'" does not match '.$host.': the browser will refuse the session cookie. Set SESSION_DOMAIN=null (or the hosted domain).');
        } else {
            $this->result('OK', 'SESSION_DOMAIN', $domain ? (string) $domain : 'Not set (cookie for the current host).');
        }
        $secure = config('session.secure');
        if ($secure && ! $https) {
            $this->result('FAIL', 'SESSION_SECURE_COOKIE', 'True but the site is http://: browsers drop secure cookies, so every request looks signed out. Use https or set it to false.');
        } elseif ($https && ! $secure) {
            $this->result('WARN', 'SESSION_SECURE_COOKIE', 'The site is https://: set SESSION_SECURE_COOKIE=true.');
        } else {
            $this->result('OK', 'SESSION_SECURE_COOKIE', $secure ? 'True.' : 'False.');
        }
        if (config('session.same_site') === 'none' && ! $secure) {
            $this->result('FAIL', 'SESSION_SAME_SITE', '"none" requires SESSION_SECURE_COOKIE=true. Use "lax".');
        }
        if (config('session.driver') === 'database' && ! $this->tableExists(config('session.table', 'sessions'))) {
            $this->result('FAIL', 'SESSION_DRIVER', 'database, but the sessions table is missing: run php artisan migrate --force.');
        } else {
            $this->result('OK', 'SESSION_DRIVER', (string) config('session.driver'));
        }
    }

    /** Sanctum must treat the hosted domain as the app's own front end. */
    private function checkSanctum(string $host): void
    {
        $stateful = config('sanctum.stateful', []);
        $sameHost = in_array(Sanctum::$currentRequestHostPlaceholder, $stateful, true);
        $listed = in_array($host, $stateful, true);
        $this->result($sameHost || $listed ? 'OK' : 'FAIL', 'Sanctum stateful domains', $sameHost || $listed
            ? 'The hosted domain is accepted for signed-in API calls.'
            : 'The hosted domain '.$host.' is not accepted, which causes the "session has ended" loop. Add it to SANCTUM_STATEFUL_DOMAINS or update the app.');
        $this->result(config('roster.trusted_proxies') ? 'OK' : 'WARN', 'TRUSTED_PROXIES', config('roster.trusted_proxies')
            ? 'Set: forwarded https headers are trusted.'
            : 'Not set. If https is handled by a load balancer or proxy (Cloudflare, cPanel proxy, a PaaS), set TRUSTED_PROXIES=* so links and assets use https.');
    }

    /** Connection, migrations, required tables and reference data. */
    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->result('OK', 'Database connection', DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName());
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Database connection', 'Cannot connect: '.$exception->getMessage());

            return;
        }
        $migrator = app('migrator');
        $pending = $migrator->repositoryExists()
            ? array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan())
            : ['(migrations table missing)'];
        $this->result($pending === [] ? 'OK' : 'FAIL', 'Migrations', $pending === [] ? 'All run.' : count($pending).' pending: run php artisan migrate --force.');
        foreach (['cache' => config('cache.default') === 'database', 'jobs' => config('queue.default') === 'database', 'password_reset_tokens' => true] as $table => $needed) {
            if ($needed && ! $this->tableExists($table)) {
                $this->result('FAIL', 'Table '.$table, 'Missing: run php artisan migrate --force.');
            }
        }
        if ($this->tableExists('rule_sets')) {
            $this->result(RuleSet::query()->whereKey(1)->exists() ? 'OK' : 'FAIL', 'Duty rules', RuleSet::query()->whereKey(1)->exists() ? 'Standard rule set present.' : 'Missing: run php artisan db:seed --force (reference data only).');
            $this->result(User::query()->where('role', 'admin')->exists() ? 'OK' : 'WARN', 'Administrator', User::query()->where('role', 'admin')->exists() ? 'At least one administrator account.' : 'No administrator: php artisan roster:create-user you@example.com --role=admin');
        }
    }

    /** Folders the app writes to, and the built front-end assets. */
    private function checkFiles(): void
    {
        foreach (['storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'storage/app', 'bootstrap/cache'] as $path) {
            $full = base_path($path);
            $this->result(is_dir($full) && is_writable($full) ? 'OK' : 'FAIL', 'Writable '.$path, is_dir($full) && is_writable($full) ? 'Yes.' : 'Not writable by the web server: fix the folder permissions/owner.');
        }
        $manifest = public_path('build/manifest.json');
        $this->result(is_file($manifest) ? 'OK' : 'FAIL', 'Built assets', is_file($manifest) ? 'public/build/manifest.json present.' : 'Missing: run npm ci && npm run build, or upload public/build.');
        if (is_file(public_path('hot'))) {
            $this->result('FAIL', 'public/hot', 'Present: pages load assets from a Vite dev server. Delete public/hot on the server.');
        }
    }

    /** Mail and queue, needed for roster emails and password reset links. */
    private function checkServices(): void
    {
        $mailer = (string) config('mail.default');
        $this->result(in_array($mailer, ['log', 'array'], true) ? 'WARN' : 'OK', 'Mail', in_array($mailer, ['log', 'array'], true)
            ? 'MAIL_MAILER='.$mailer.': roster emails and password reset links are not delivered. Configure SMTP or a mail service.'
            : 'MAIL_MAILER='.$mailer.'.');
        $queue = (string) config('queue.default');
        $this->result($queue === 'sync' ? 'OK' : 'WARN', 'Queue', $queue === 'sync' ? 'sync: emails are sent during the request.' : $queue.': keep a worker running (php artisan queue:work) or emails stay queued.');
    }

    /** Whether a table exists (false when the database cannot be reached). */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /** Record one result row. */
    private function result(string $status, string $check, string $details): void
    {
        $this->results[] = [$status, $check, $details];
    }
}
