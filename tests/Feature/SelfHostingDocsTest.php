<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Self-hosting documentation audit -- issue #26 (item/self-hosting-docs)
|--------------------------------------------------------------------------
|
| Spec §12 lists six pages a self-hoster needs: quick start, configuration
| reference, storage, search, operations runbook, and backup/restore +
| upgrading + troubleshooting. This file enforces two things about them.
|
| 1. The configuration reference documents every environment variable that
|    actually matters, and nothing that doesn't -- see the docblock on
|    DOC_AUDIT_ENV_KEY_EXEMPTIONS below for why "every one of the 141 keys
|    this codebase reads" is the wrong bar.
|
| 2. Every listed page exists and is non-trivial -- see the docblock on
|    DOC_AUDIT_PAGES for what "non-trivial" is taken to mean here, and why
|    a stub fails it.
|
| Follows tests/Feature/SchemaPortabilityTest.php's pattern deliberately:
| a registry with a one-line reason per entry, checked against the actual
| source in both directions, so it can neither miss a new key nor rot into
| a list of keys that no longer exist.
*/

/**
 * Every env('KEY', ...) key read anywhere under app/, config/, or docker/
 * that is NOT documented in docs/self-hosting/configuration-reference.md,
 * with the specific reason it is a framework default (or otherwise not a
 * self-hosting decision) rather than an oversight.
 *
 * A self-hosted install reads well over a hundred environment variables,
 * because it inherits every one Laravel and its framework packages define
 * -- APP_FAKER_LOCALE, the Beanstalkd/SQS/DynamoDB queue and cache drivers
 * doccum never wires a service for, and so on. Documenting all of them
 * would bury the roughly two dozen that a self-hoster actually sets under
 * the framework's own noise, which is the opposite of what a configuration
 * reference is for. So this is a registry with justified exemptions, not a
 * shorter list living beside the real one: every key this codebase reads
 * is accounted for in exactly one of two places, and the test below fails
 * when a key is in neither (a real key silently undocumented) or when an
 * entry here no longer matches anything in the source (a stale exemption).
 *
 * @var array<string, string>
 */
const DOC_AUDIT_ENV_KEY_EXEMPTIONS = [
    'APP_FAKER_LOCALE' => 'Only read by database factories under database/factories, which a self-hosted instance never runs -- no seeding or demo-data command ships.',
    'APP_FALLBACK_LOCALE' => 'doccum ships exactly one locale (en) with no translated strings to fall back from; nothing in app/ branches on it.',
    'APP_LOCALE' => 'Same one-locale reasoning as APP_FALLBACK_LOCALE: doccum has no locale switcher, so there is nothing for an operator to set.',
    'APP_MAINTENANCE_DRIVER' => 'Laravel\'s own `php artisan down`, which this project\'s operations runbook does not document -- the runbook\'s maintenance story is stopping the container, not this framework feature.',
    'APP_MAINTENANCE_STORE' => 'Companion to APP_MAINTENANCE_DRIVER, one entry up: only read when that driver is \'cache\', which nothing in this project\'s docs or scripts sets.',
    'APP_PREVIOUS_KEYS' => 'Supports decrypting old ciphertext during an APP_KEY rotation; doccum\'s own key story (README\'s \'bring APP_KEY with it\') is never losing the original key, not rotating past it.',

    'AUTH_GUARD' => 'Fixed by config/auth.php\'s own default (\'web\') and never overridden -- doccum ships one guard, backed by one User model; no supported deployment swaps it.',
    'AUTH_MODEL' => 'Same reasoning as AUTH_GUARD: fixed to App\\Models\\User, the only Authenticatable doccum defines.',
    'AUTH_PASSWORD_BROKER' => 'Fixed to the one password-reset broker Fortify wires up; nothing in the self-hosting surface names an alternate broker.',
    'AUTH_PASSWORD_RESET_TOKEN_TABLE' => 'Table name for the one password-reset broker above; doccum never renames it.',
    'AUTH_PASSWORD_TIMEOUT' => 'Password-confirmation timeout for Fortify\'s \'password confirmation\' middleware, used at its stock value; not part of any self-hosting decision.',

    'AZURE_STORAGE_CONNECTION_STRING' => 'config/filesystems.php\'s \'azure\' disk entry is marked \'Reference only\' in that file\'s own comment -- doccum\'s actual Azure Blob provider (App\\Enums\\StorageProvider::AzureBlob) is configured through the installer\'s settings-backed storage.* keys (RuntimeConfigServiceProvider), never these two stock Laravel env vars.',
    'AZURE_STORAGE_CONTAINER' => 'Same reference-only \'azure\' disk entry as AZURE_STORAGE_CONNECTION_STRING, one entry up.',

    'BEANSTALKD_QUEUE' => 'Beanstalkd is one of Laravel\'s stock queue drivers; doccum ships database (default) and Redis (cache profile) only -- nothing in compose.yaml, the Dockerfile, or the docs offers Beanstalkd as an option.',
    'BEANSTALKD_QUEUE_HOST' => 'Companion host setting for the unused Beanstalkd driver, one entry up.',
    'BEANSTALKD_QUEUE_RETRY_AFTER' => 'Companion retry setting for the unused Beanstalkd driver, two entries up.',

    'CACHE_PREFIX' => 'Cache key prefix; the default (the app name) is fine for the single-instance deployment doccum ships -- no documented reason to change it.',
    'CACHE_STORAGE_DISK' => 'Only read when CACHE_STORE=file, which doccum\'s default is not (config/cache.php\'s \'database\' entry, matching compose.yaml\'s CACHE_STORE=database).',
    'CACHE_STORAGE_PATH' => 'Companion path for the unused \'file\' cache store, one entry up.',

    'DB_BUSY_TIMEOUT' => 'SQLite driver option at its stock default; doccum\'s own SQLite tuning is a schema/connection decision already made in code, not an operator-facing env var.',
    'DB_CACHE_CONNECTION' => 'Only read when the cache table lives on a non-default database connection; doccum\'s database cache store uses the app\'s one connection.',
    'DB_CACHE_LOCK_CONNECTION' => 'Same reasoning as DB_CACHE_CONNECTION, one entry up, for the cache lock table specifically.',
    'DB_CACHE_LOCK_TABLE' => 'Table name for the cache lock table above; doccum never renames it.',
    'DB_CACHE_TABLE' => 'Table name for the database cache store; doccum never renames it from Laravel\'s stock \'cache\'.',
    'DB_CHARSET' => 'MySQL/Postgres charset at its stock default (utf8mb4); nothing in the schema portability audit (tests/Feature/SchemaPortabilityTest.php) asks an operator to override it.',
    'DB_COLLATION' => 'Companion collation setting, one entry up -- and the actual case/accent folding rules doccum relies on are stated in PHP (NameKey, EmailKey), deliberately not delegated to this.',
    'DB_ENCRYPT' => 'SQL Server-only connection option; doccum does not support SQL Server as a database driver at all (see config/database.php\'s connection list).',
    'DB_FOREIGN_KEYS' => 'SQLite pragma at its stock default (enabled); nothing in the self-hosting surface disables foreign keys.',
    'DB_JOURNAL_MODE' => 'SQLite pragma at its stock default; doccum\'s SQLite tuning is a schema/config decision already made, not an operator knob.',
    'DB_QUEUE' => 'Only relevant to the \'database\' queue driver\'s table/queue name, at its stock default; doccum\'s own queue names (\'default\', \'ingest\') come from the artisan commands compose.yaml runs, not this env var.',
    'DB_QUEUE_CONNECTION' => 'Only read when the queue table lives on a non-default connection; doccum\'s database queue driver uses the app\'s one connection.',
    'DB_QUEUE_RETRY_AFTER' => 'Database queue driver\'s retry-after at its stock default; the artisan commands in compose.yaml already set --tries and --timeout explicitly per worker.',
    'DB_QUEUE_TABLE' => 'Table name for the database queue driver; doccum never renames it.',
    'DB_SOCKET' => 'MySQL unix-socket connection option; doccum\'s compose stack reaches MySQL/Postgres over TCP (DB_HOST/DB_PORT), never a local socket.',
    'DB_SSLMODE' => 'PostgreSQL SSL mode at its stock default; not part of the documented Postgres path (the \'db\' compose profile), which runs on the Docker network without TLS between containers.',
    'DB_SYNCHRONOUS' => 'SQLite pragma at its stock default; not a self-hosting-facing tuning knob.',
    'DB_TRANSACTION_MODE' => 'SQLite driver option at its stock default; not a self-hosting-facing tuning knob.',
    'DB_TRUST_SERVER_CERTIFICATE' => 'SQL Server-only connection option; doccum does not support SQL Server.',
    'DB_URL' => 'A single-string alternative to the discrete DB_HOST/DB_PORT/etc. fields; the configuration reference documents the discrete fields doccum\'s installer and compose.yaml actually use, not this alternate encoding of the same information.',

    'DYNAMODB_CACHE_TABLE' => 'DynamoDB is a stock Laravel cache store doccum never wires up -- no compose service, no docs mention, no code path selects it.',
    'DYNAMODB_ENDPOINT' => 'Companion endpoint for the unused DynamoDB cache store, one entry up.',

    'LOG_CHANNEL' => 'Fixed at its stock default (\'stack\' -> single file); doccum ships one logging configuration and does not document swapping channels.',
    'LOG_DAILY_DAYS' => 'Only read by the unused \'daily\' log channel.',
    'LOG_DEPRECATIONS_CHANNEL' => 'Deprecation-warning routing at its stock default; not a self-hosting concern.',
    'LOG_DEPRECATIONS_TRACE' => 'Companion trace flag for deprecation logging, one entry up.',
    'LOG_LEVEL' => 'Left at its stock default; an operator who wants more verbosity can already read it with docker logs, and no self-hosting doc promises a different default.',
    'LOG_PAPERTRAIL_HANDLER' => 'Only read by the unused Papertrail log channel.',
    'LOG_SLACK_EMOJI' => 'Only read by the unused Slack log channel.',
    'LOG_SLACK_USERNAME' => 'Only read by the unused Slack log channel, same as LOG_SLACK_EMOJI.',
    'LOG_SLACK_WEBHOOK_URL' => 'Only read by the unused Slack log channel; doccum ships no Slack integration.',
    'LOG_STACK' => 'Companion to LOG_CHANNEL, one entry up: which channels the stack driver combines, unused since doccum stays on the stock single-channel default.',
    'LOG_STDERR_FORMATTER' => 'Only read by the unused \'stderr\' log channel.',
    'LOG_SYSLOG_FACILITY' => 'Only read by the unused \'syslog\' log channel.',

    'MAIL_EHLO_DOMAIN' => 'MAIL_MAILER defaults to \'log\' (nothing in compose.yaml or .env.example sets an SMTP mailer), so no SMTP-specific setting under this block, including this one, is read at all in a default install; an operator who wires up real mail can already read Laravel\'s own mail documentation for it.',
    'MAIL_FROM_ADDRESS' => 'Same reasoning as MAIL_EHLO_DOMAIN: only meaningful once a real mailer is configured, which doccum\'s zero-configuration boot never requires -- verification and password-reset emails are the only mail doccum sends, and both work with the stock \'log\' driver during evaluation.',
    'MAIL_FROM_NAME' => 'Same reasoning as MAIL_FROM_ADDRESS, one entry up.',
    'MAIL_HOST' => 'Same reasoning as MAIL_EHLO_DOMAIN; SMTP host is meaningless while MAIL_MAILER=log.',
    'MAIL_LOG_CHANNEL' => 'Only read when MAIL_MAILER=log, and even then only to pick which log channel receives the mail -- the stock default channel is fine for evaluating doccum.',
    'MAIL_MAILER' => 'The driver selector for the whole MAIL_* block; doccum\'s self-hosting docs do not promise a mail setup story, so this is left at its stock \'log\' default rather than documented as a decision to make.',
    'MAIL_PASSWORD' => 'SMTP credential, meaningless while MAIL_MAILER=log; same reasoning as MAIL_HOST.',
    'MAIL_PORT' => 'SMTP port, meaningless while MAIL_MAILER=log; same reasoning as MAIL_HOST.',
    'MAIL_SCHEME' => 'SMTP scheme, meaningless while MAIL_MAILER=log; same reasoning as MAIL_HOST.',
    'MAIL_SENDMAIL_PATH' => 'Only read by the unused \'sendmail\' mailer.',
    'MAIL_URL' => 'A single-string alternative to the discrete MAIL_* fields, same reasoning as DB_URL: not documented because the discrete fields it duplicates are not documented either.',
    'MAIL_USERNAME' => 'SMTP credential, meaningless while MAIL_MAILER=log; same reasoning as MAIL_HOST.',
    'POSTMARK_MESSAGE_STREAM_ID' => 'Only read by the unused Postmark mailer.',

    'MEMCACHED_HOST' => 'Memcached is a stock Laravel cache store doccum never wires up -- CACHE_STORE defaults to \'database\', and no compose service runs Memcached.',
    'MEMCACHED_PASSWORD' => 'Companion credential for the unused Memcached store, one entry up.',
    'MEMCACHED_PERSISTENT_ID' => 'Companion setting for the unused Memcached store.',
    'MEMCACHED_PORT' => 'Companion port for the unused Memcached store.',
    'MEMCACHED_USERNAME' => 'Companion credential for the unused Memcached store.',

    'MYSQL_ATTR_SSL_CA' => 'PDO MySQL SSL option at its stock default (unset); not part of the documented MySQL/\'db\' profile path, which runs over the Docker network.',

    'PAPERTRAIL_PORT' => 'Only read by the unused Papertrail log channel, same as LOG_PAPERTRAIL_HANDLER.',
    'PAPERTRAIL_URL' => 'Companion host for the unused Papertrail log channel.',

    'PASSKEYS_USER_HANDLE_SECRET' => 'Fortify config for WebAuthn passkeys, a feature doccum\'s spec (section 10, \'Authentication\') never turns on -- login is username/email plus password only.',

    'POSTMARK_API_KEY' => 'Only read by the unused Postmark mailer, same as POSTMARK_MESSAGE_STREAM_ID.',

    'QUEUE_FAILED_DRIVER' => 'Failed-job table storage at its stock default; doccum\'s queue workers (compose.yaml) already set --tries explicitly, and no self-hosting doc promises a failed-job inspection workflow.',

    'REDIS_BACKOFF_ALGORITHM' => 'Client-level retry tuning for the optional Redis cache profile, left at the phpredis default; the configuration reference documents connecting to Redis (REDIS_HOST/PORT/PASSWORD/CLIENT), not its retry internals.',
    'REDIS_BACKOFF_BASE' => 'Same reasoning as REDIS_BACKOFF_ALGORITHM, one entry up.',
    'REDIS_BACKOFF_CAP' => 'Same reasoning as REDIS_BACKOFF_ALGORITHM.',
    'REDIS_CACHE_CONNECTION' => 'Only relevant if the Redis cache store is pointed at a non-default Redis connection; the cache profile\'s single redis service needs no such split.',
    'REDIS_CACHE_DB' => 'Redis logical database number for the cache store, left at its stock default; the cache profile runs one Redis instance for one purpose, so there is no database-number collision to configure around.',
    'REDIS_CACHE_LOCK_CONNECTION' => 'Same reasoning as REDIS_CACHE_CONNECTION, for the cache lock connection specifically.',
    'REDIS_CLUSTER' => 'Redis Cluster mode, unsupported by the single-instance redis service compose.yaml\'s cache profile starts.',
    'REDIS_DB' => 'Redis logical database number at its stock default (0); same one-Redis-one-purpose reasoning as REDIS_CACHE_DB.',
    'REDIS_MAX_RETRIES' => 'Client-level retry tuning, same reasoning as REDIS_BACKOFF_ALGORITHM.',
    'REDIS_PERSISTENT' => 'phpredis persistent-connection tuning at its stock default; not part of the self-hosting decision to opt into the cache profile at all.',
    'REDIS_PREFIX' => 'Redis key prefix at its stock default (the app name); a single-instance deployment has no other tenant to collide with.',
    'REDIS_QUEUE' => 'Only relevant to a Redis-backed queue, which doccum does not ship -- QUEUE_CONNECTION defaults to \'database\' and the Redis profile\'s own docs (this reference and README) describe it as a cache accelerant, not a queue driver.',
    'REDIS_QUEUE_CONNECTION' => 'Companion connection setting for the unused Redis queue driver, one entry up.',
    'REDIS_QUEUE_RETRY_AFTER' => 'Companion retry setting for the unused Redis queue driver.',
    'REDIS_URL' => 'A single-string alternative to REDIS_HOST/PORT/PASSWORD, same reasoning as DB_URL: the discrete fields are what the reference documents.',
    'REDIS_USERNAME' => 'Redis ACL username, unused by the single-user redis service compose.yaml\'s cache profile starts (no ACL is configured).',

    'RESEND_API_KEY' => 'Only read by the unused Resend mailer.',

    'SESSION_CONNECTION' => 'Only relevant if the database session store used a non-default connection; doccum\'s one connection needs no such split.',
    'SESSION_DOMAIN' => 'Cookie domain at its stock default (unset -- current host); doccum\'s single-host deployment model (one container, one URL) has no cross-subdomain cookie need to configure.',
    'SESSION_ENCRYPT' => 'Session-cookie encryption at its stock default; doccum\'s own encryption story (settings secrets, sealed with APP_KEY) is documented separately in the configuration reference and spec section 10a.',
    'SESSION_EXPIRE_ON_CLOSE' => 'Session lifetime behaviour at its stock default; not part of any self-hosting decision.',
    'SESSION_HTTP_ONLY' => 'Cookie security flag at its stock (secure) default; nothing in the self-hosting docs asks an operator to weaken it.',
    'SESSION_LIFETIME' => 'Session length at its stock default; not part of any self-hosting decision doccum\'s docs make for the operator.',
    'SESSION_PARTITIONED_COOKIE' => 'CHIPS partitioned-cookie opt-in, relevant to third-party embedding scenarios doccum\'s single-origin app does not have.',
    'SESSION_PATH' => 'Cookie path at its stock default (\'/\'); doccum is never mounted under a sub-path.',
    'SESSION_SAME_SITE' => 'Cookie SameSite policy at its stock default; doccum\'s single-origin login flow has no cross-site request to accommodate.',
    'SESSION_SECURE_COOKIE' => 'Cookie Secure flag at its stock default; TRUSTED_PROXIES and the reverse-proxy guidance already in .env.example cover doccum\'s actual TLS-termination story.',
    'SESSION_STORE' => 'A Laravel 11+ alias resolving to the same driver SESSION_DRIVER already sets in this reference; documenting both would duplicate one decision under two names.',
    'SESSION_TABLE' => 'Table name for the database session store; doccum never renames it.',

    'SLACK_BOT_USER_DEFAULT_CHANNEL' => 'Only read by the unused Slack notification channel (config/services.php), distinct from and unrelated to doccum\'s own document storage or search.',
    'SLACK_BOT_USER_OAUTH_TOKEN' => 'Companion credential for the unused Slack notification channel, one entry up.',

    'SQS_PREFIX' => 'Amazon SQS is a stock Laravel queue driver doccum never wires up -- QUEUE_CONNECTION defaults to \'database\', with Redis as the only documented alternative.',
    'SQS_QUEUE' => 'Companion queue name for the unused SQS driver, one entry up.',
    'SQS_SUFFIX' => 'Companion setting for the unused SQS driver.',
];

/**
 * Every self-hosting page spec §12 lists, and what makes each one
 * non-trivial rather than a stub with the right filename.
 *
 * "Non-trivial" is taken to mean, uniformly, at least DOC_AUDIT_MIN_WORDS
 * words and DOC_AUDIT_MIN_HEADINGS `##`/`###` headings, and no placeholder
 * marker (see DOC_AUDIT_PLACEHOLDER_MARKERS) -- and, specifically to each
 * page, a handful of keywords that could only be present if the page
 * actually describes doccum's own mechanism rather than a generic template.
 * A one-paragraph stub titled "# Storage" with a single heading and no
 * mention of MinIO, S3, or servesPresignedUrls() fails every one of these:
 * it is short, it is un-headinged, and its keywords are absent. That is
 * the point -- see this item's report for why this bar was chosen over a
 * looser one (e.g. "file exists and is non-empty") that a stub would pass.
 *
 * @var array<string, list<string>>
 */
const DOC_AUDIT_PAGES = [
    'docs/self-hosting/quick-start.md' => [
        'docker compose up',
        'first-run setup screen',
        'first upload',
        'docker run',
        'ExtractText',
    ],
    'docs/self-hosting/configuration-reference.md' => [
        'Local (default)',
        'Remote / production',
        'DOC_AUDIT_ENV_KEY_EXEMPTIONS',
    ],
    'docs/self-hosting/storage.md' => [
        'MinIO',
        'S3',
        'MINIO_ROOT_PASSWORD',
        'servesPresignedUrls',
        'presigned',
    ],
    'docs/self-hosting/search.md' => [
        'FTS5',
        'LikeSearchIndex',
        'Typesense',
        'Meilisearch',
        'SearchIndex',
    ],
    'docs/self-hosting/operations-runbook.md' => [
        'doccum:purge-period',
        '--force',
        'legal_hold',
        'doccum:close-periods',
        'dry-run',
    ],
    'docs/self-hosting/backup-and-restore.md' => [
        '/data',
        'APP_KEY',
        'runtime.json',
        'minio.env',
        'objects/',
    ],
    'docs/self-hosting/upgrading.md' => [
        'AUTORUN_LARAVEL_MIGRATION',
        'docker pull',
        'CHANGELOG.md',
        'DOCCUM_VERSION',
        'Downgrading',
    ],
    'docs/self-hosting/troubleshooting.md' => [
        'RuntimeConfigUnreadable',
        'MINIO_ROOT_PASSWORD',
        'ObjectMissingFromStorage',
        'servesPresignedUrls',
        'doccum:config:reset',
    ],
];

const DOC_AUDIT_MIN_WORDS = 150;

const DOC_AUDIT_MIN_HEADINGS = 3;

/**
 * Case-insensitive placeholder markers that make a page a stub regardless
 * of its length -- a page can be padded with prose and still be a stub if
 * the real content was never written in.
 *
 * @var list<string>
 */
const DOC_AUDIT_PLACEHOLDER_MARKERS = [
    'TODO',
    'TBD',
    'FIXME',
    'coming soon',
    'lorem ipsum',
    'to be written',
    'to be documented',
    '[placeholder]',
];

/**
 * Recursively scans every file under the given path (relative to
 * base_path()) for env('KEY', ...) reads -- pure PHP, no external `grep`,
 * per CLAUDE.md's "no test may require an external binary". Unlike
 * SchemaPortabilityTest's scan, this one is not limited to .php files:
 * docker/ is shell scripts and a supervisor .conf file, and while none of
 * them currently call the PHP env() helper, a future one legitimately
 * could (nothing stops a script under docker/entrypoint.d from shelling
 * into `php artisan tinker` or similar), so the scan does not silently
 * exempt that directory's non-.php files by construction.
 *
 * @return array<string, list<string>> env key => list of "relative/path:line"
 */
function docAuditScanEnvKeys(string $relativeDirectory): array
{
    $pattern = "~env\\(\\s*'([A-Z0-9_]+)'~";

    $root = base_path($relativeDirectory);
    $hits = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relativePath = $relativeDirectory.substr($file->getPathname(), strlen($root));
        $relativePath = str_replace('\\', '/', $relativePath);

        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match_all($pattern, $line, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $hits[$key][] = $relativePath.':'.($number + 1);
                }
            }
        }
    }

    ksort($hits);

    return $hits;
}

it('documents every self-hosting-relevant env() key, and exempts every other one with a live reason', function () {
    $hits = array_merge(
        docAuditScanEnvKeys('app'),
        docAuditScanEnvKeys('config'),
        docAuditScanEnvKeys('docker'),
    );

    $reference = file_get_contents(base_path('docs/self-hosting/configuration-reference.md'));

    expect($reference)->not->toBeFalse();

    $documented = [];
    $undocumented = [];

    foreach (array_keys($hits) as $key) {
        $inReference = str_contains($reference, $key);
        $inExemptions = array_key_exists($key, DOC_AUDIT_ENV_KEY_EXEMPTIONS);

        if ($inReference || $inExemptions) {
            $documented[] = $key;
        } else {
            $undocumented[] = $key;
        }
    }

    expect($undocumented)->toBe(
        [],
        'Found env() key(s) read under app/, config/, or docker/ that are neither in '.
        'docs/self-hosting/configuration-reference.md nor in DOC_AUDIT_ENV_KEY_EXEMPTIONS: '.
        implode(', ', $undocumented).'. Either add a row to the configuration reference, or '.
        'register an exemption here with the specific reason it is not a self-hosting concern.',
    );

    // The second failure mode: an exemption that no longer matches anything
    // the scan finds, because the key was renamed or removed from the
    // source it used to describe. Kept as a real check, not a comment,
    // because CLAUDE.md's own testing section is explicit that an
    // unregistered rot condition is exactly the kind of thing that
    // silently stops being true.
    $stale = array_values(array_diff(array_keys(DOC_AUDIT_ENV_KEY_EXEMPTIONS), array_keys($hits)));

    expect($stale)->toBe(
        [],
        'DOC_AUDIT_ENV_KEY_EXEMPTIONS lists key(s) no env() read in app/, config/, or docker/ still '.
        'matches: '.implode(', ', $stale).'. Remove the stale entry.',
    );

    // Sanity check on the mechanism itself: if this ever reports fewer than
    // a hundred keys total, the scan regex broke, not the codebase --
    // there is no plausible world where doccum's dependency on Laravel's
    // own configuration surface shrinks that far.
    expect(count($documented) + count($undocumented))->toBeGreaterThan(100);
});

it('lists every self-hosting page from spec §12, and each one is non-trivial', function () {
    foreach (DOC_AUDIT_PAGES as $relativePath => $keywords) {
        $path = base_path($relativePath);

        expect(is_file($path))->toBeTrue("Missing self-hosting page: {$relativePath}");

        $content = file_get_contents($path);
        expect($content)->not->toBeFalse();

        foreach (DOC_AUDIT_PLACEHOLDER_MARKERS as $marker) {
            expect(stripos($content, $marker))->toBe(
                false,
                "{$relativePath} contains the placeholder marker \"{$marker}\" -- write the real content.",
            );
        }

        $wordCount = str_word_count(preg_replace('/`{1,3}[^`]*`{1,3}/', ' ', $content) ?? $content);

        expect($wordCount)->toBeGreaterThanOrEqual(
            DOC_AUDIT_MIN_WORDS,
            "{$relativePath} has only {$wordCount} words (excluding code spans/fences), under the ".
            DOC_AUDIT_MIN_WORDS.'-word floor for a non-trivial page.',
        );

        $headingCount = preg_match_all('/^#{2,3}\s+\S/m', $content);

        expect($headingCount)->toBeGreaterThanOrEqual(
            DOC_AUDIT_MIN_HEADINGS,
            "{$relativePath} has only {$headingCount} level-2/3 heading(s), under the ".
            DOC_AUDIT_MIN_HEADINGS.'-heading floor for a non-trivial page.',
        );

        // Whitespace-normalised so a keyword phrase that happens to wrap
        // across a Markdown source line (the raw file is hard-wrapped for
        // readability; the rendered page is not) still matches.
        $normalized = preg_replace('/\s+/', ' ', $content);

        foreach ($keywords as $keyword) {
            expect(stripos($normalized, $keyword))->not->toBe(
                false,
                "{$relativePath} is missing the keyword \"{$keyword}\", which its own real content ".
                'requires -- a page that never mentions it is either off-topic or a stub.',
            );
        }
    }
});
