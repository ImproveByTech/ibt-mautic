<?php

/**
 * Renders config/local.php on every container boot (web, cron and worker).
 *
 * Settings saved through Mautic's admin UI (Configuration screen: default
 * language, allowed asset extensions, etc.) live in this same file, so we
 * merge instead of overwriting: whatever is already in local.php is kept,
 * and only the infrastructure keys below are forced from env vars. For
 * those UI settings to survive a redeploy, config/ must be a bind mount
 * shared by all three apps (see docs/project/DEPLOYMENT.md).
 */

function requireEnv(string $name): string
{
    $value = getenv($name);
    if (false === $value || '' === $value) {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }

    return $value;
}

function envBool(string $name, bool $default): bool
{
    $value = getenv($name);
    if (false === $value || '' === $value) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function envList(string $name, array $default): array
{
    $value = getenv($name);
    if (false === $value || '' === $value) {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

$parameters = [
    'db_driver'             => getenv('MAUTIC_DB_DRIVER') ?: 'pdo_mysql',
    'db_host'               => requireEnv('MAUTIC_DB_HOST'),
    'db_port'               => (int) (getenv('MAUTIC_DB_PORT') ?: 3306),
    'db_name'               => requireEnv('MAUTIC_DB_NAME'),
    'db_user'               => requireEnv('MAUTIC_DB_USER'),
    'db_password'           => requireEnv('MAUTIC_DB_PASSWORD'),
    'db_table_prefix'       => getenv('MAUTIC_DB_TABLE_PREFIX') ?: null,

    'site_url'              => requireEnv('MAUTIC_SITE_URL'),
    'secret_key'            => requireEnv('MAUTIC_SECRET_KEY'),

    'admin_email'           => getenv('MAUTIC_ADMIN_EMAIL') ?: null,
    'admin_password'        => getenv('MAUTIC_ADMIN_PASSWORD') ?: null,

    'mailer_from_name'      => getenv('MAUTIC_MAILER_FROM_NAME') ?: 'Mautic',
    'mailer_from_email'     => getenv('MAUTIC_MAILER_FROM_EMAIL') ?: null,

    'api_enabled'           => envBool('MAUTIC_API_ENABLED', true),
    'api_enable_basic_auth' => envBool('MAUTIC_API_ENABLE_BASIC_AUTH', true),

    // EasyPanel terminates TLS at Traefik and proxies over the internal
    // network, so trust whichever host makes the direct connection.
    'trusted_proxies'       => envList('MAUTIC_TRUSTED_PROXIES', ['REMOTE_ADDR']),
    'trusted_hosts'         => envList('MAUTIC_TRUSTED_HOSTS', []),
];

// Optional admin-UI settings pinned per client from env vars. Each one is
// only applied when its env var is set (and then wins on every boot, so
// the Configuration screen can't change it anymore); when unset, whatever
// the admin saved in the UI is kept.
$timezone = getenv('MAUTIC_DEFAULT_TIMEZONE') ?: null;
if (null !== $timezone && !in_array($timezone, timezone_identifiers_list(), true)) {
    fwrite(STDERR, "Invalid MAUTIC_DEFAULT_TIMEZONE '{$timezone}' (expected e.g. America/Sao_Paulo), ignoring it.\n");
    $timezone = null;
}
$maxSize = getenv('MAUTIC_ASSET_MAX_SIZE') ?: null;
if (null !== $maxSize && !ctype_digit($maxSize)) {
    fwrite(STDERR, "Invalid MAUTIC_ASSET_MAX_SIZE '{$maxSize}' (expected whole MB, e.g. 254), ignoring it.\n");
    $maxSize = null;
}

$parameters += [
    'locale'             => getenv('MAUTIC_LOCALE') ?: null,
    'default_timezone'   => $timezone,
    'max_size'           => $maxSize,
    'allowed_extensions' => envList('MAUTIC_ASSET_ALLOWED_EXTENSIONS', []) ?: null,
];

// S3 media storage. '' (not null) when unset, so removing an env var really
// disables the feature instead of keeping a stale value from local.php.
$parameters += [
    's3_bucket'            => getenv('S3_BUCKET') ?: '',
    's3_region'            => getenv('S3_REGION') ?: '',
    's3_access_key_id'     => getenv('S3_ACCESS_KEY_ID') ?: '',
    's3_secret_access_key' => getenv('S3_SECRET_ACCESS_KEY') ?: '',
    's3_root_directory'    => getenv('S3_ROOT_DIRECTORY') ?: '',
];

$parameters = array_filter($parameters, static fn ($value) => null !== $value);

$target   = __DIR__.'/../config/local.php';
$existing = [];
if (is_file($target)) {
    $existing = (static function (string $file): array {
        $parameters = [];
        include $file;

        return is_array($parameters) ? $parameters : [];
    })($target);
}

// Env vars win for the keys they manage; everything else (UI settings) is kept.
$merged = array_merge($existing, $parameters);

$exported = var_export($merged, true);
$contents = "<?php\n\n\$parameters = {$exported};\n";

// Write to a temp file and rename, so another container reading the shared
// file mid-write never sees a half-written PHP file.
$tmp = $target.'.tmp.'.getmypid();
file_put_contents($tmp, $contents);
rename($tmp, $target);

fwrite(STDOUT, sprintf(
    "config/local.php rendered: %d env-managed keys, %d kept from existing file.\n",
    count($parameters),
    count(array_diff_key($existing, $parameters))
));
