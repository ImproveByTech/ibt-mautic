<?php

/**
 * Makes sure the language pack for MAUTIC_LOCALE is installed.
 *
 * en_US and pt_BR (committed under translations/pt_BR) ship inside the
 * image, so for those this is a no-op. Any other language is downloaded
 * at runtime into translations/, which is not on a mount, so it is gone
 * after every redeploy and Mautic silently falls back to English. This
 * re-installs that pack on boot, using the same source and layout as the
 * admin UI's own language download. Never fatal: on any failure Mautic
 * still boots, just in English.
 */

require __DIR__.'/../vendor/autoload.php';

use Mautic\CoreBundle\Helper\Language\Installer;

$locale = getenv('MAUTIC_LOCALE') ?: '';
if ('' === $locale || 'en_US' === $locale) {
    exit(0);
}

if (!preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', $locale)) {
    fwrite(STDERR, "Invalid MAUTIC_LOCALE '{$locale}' (expected e.g. pt_BR), skipping language pack.\n");
    exit(0);
}

$root         = dirname(__DIR__);
$translations = $root.'/translations';

if (is_file("{$translations}/{$locale}/config.json")) {
    fwrite(STDOUT, "Language pack {$locale} already installed.\n");
    exit(0);
}

$url = "https://language-packs.mautic.com/{$locale}.zip";
$zip = tempnam(sys_get_temp_dir(), 'langpack');
$tmp = sys_get_temp_dir().'/langpack-'.getmypid();

$data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30]]));
if (false === $data || '' === $data) {
    fwrite(STDERR, "Could not download {$url}, Mautic will run in English until the next boot.\n");
    exit(0);
}
file_put_contents($zip, $data);

$archive = new ZipArchive();
if (true !== $archive->open($zip) || !$archive->extractTo($tmp)) {
    fwrite(STDERR, "Could not extract {$url}, Mautic will run in English until the next boot.\n");
    @unlink($zip);
    exit(0);
}
$archive->close();
@unlink($zip);

if (!is_file("{$tmp}/{$locale}/config.json")) {
    fwrite(STDERR, "Language pack {$locale} has an unexpected layout, skipping.\n");
    exit(0);
}

(new Installer($translations))->install($tmp, $locale)->cleanup();
@rmdir($tmp);

fwrite(STDOUT, "Language pack {$locale} installed.\n");
