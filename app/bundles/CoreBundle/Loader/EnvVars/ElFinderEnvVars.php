<?php

namespace Mautic\CoreBundle\Loader\EnvVars;

use Symfony\Component\HttpFoundation\ParameterBag;

class ElFinderEnvVars implements EnvVarsInterface
{
    public static function load(ParameterBag $config, ParameterBag $defaultConfig, ParameterBag $envVars): void
    {
        $root = rtrim((string) $defaultConfig->get('local_root'), '/') ?: '%kernel.project_dir%';

        $relativeImageFolderPath = trim((string) $config->get('image_path'), '/');
        $absoluteImageFolderPath = $root.'/'.$relativeImageFolderPath;
        $envVars->set('MAUTIC_EL_FINDER_PATH', $absoluteImageFolderPath);

        $url = rtrim((string) $config->get('site_url'), '/').'/'.$relativeImageFolderPath;

        // Thumbnails are generated on local disk, so their URL stays on the site in both modes.
        $envVars->set('MAUTIC_EL_FINDER_TMB_URL', $url.'/.tmb');

        $s3 = [];
        foreach (['s3_bucket', 's3_region', 's3_access_key_id', 's3_secret_access_key', 's3_root_directory'] as $name) {
            $s3[$name] = trim((string) $config->get($name, ''));
        }
        if (!in_array('', $s3, true)) {
            // Must equal MediaStorage::getRootUrl().'media/images'
            $s3Root = implode('/', array_map('rawurlencode', explode('/', trim($s3['s3_root_directory'], " \t/"))));
            $url    = sprintf('https://%s.s3.%s.amazonaws.com/%s/media/images', $s3['s3_bucket'], $s3['s3_region'], $s3Root);
        }

        $envVars->set('MAUTIC_EL_FINDER_URL', $url);
    }
}
