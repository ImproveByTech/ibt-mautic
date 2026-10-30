<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Loader\EnvVars;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Loader\EnvVars\ElFinderEnvVars;
use Mautic\CoreBundle\Storage\MediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class ElFinderEnvVarsTest extends TestCase
{
    /**
     * @var ParameterBag
     */
    protected $config;

    /**
     * @var ParameterBag
     */
    protected $defaultConfig;

    /**
     * @var ParameterBag
     */
    protected $envVars;

    protected function setUp(): void
    {
        $this->config        = new ParameterBag();
        $this->defaultConfig = new ParameterBag();
        $this->envVars       = new ParameterBag();
    }

    public function testPathAndUrlSet(): void
    {
        $this->config->set('image_path', 'images');
        $this->config->set('site_url', 'https://foo.bar/test');

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);
        $this->assertStringEndsWith('images', $this->envVars->get('MAUTIC_EL_FINDER_PATH'));
        $this->assertEquals('https://foo.bar/test/images', $this->envVars->get('MAUTIC_EL_FINDER_URL'));
    }

    public function testTrailingSlashHandled(): void
    {
        $this->config->set('image_path', 'images/');
        $this->config->set('site_url', 'https://foo.bar/test/');

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);
        $this->assertStringEndsWith('images', $this->envVars->get('MAUTIC_EL_FINDER_PATH'));
        $this->assertEquals('https://foo.bar/test/images', $this->envVars->get('MAUTIC_EL_FINDER_URL'));
    }

    public function testThatTheLocalRootHasPriorityOverTheKernelRootDir(): void
    {
        $this->defaultConfig->set('local_root', '/foo/bar');
        $this->config->set('image_path', 'images/');

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);
        $this->assertSame('/foo/bar/images', $this->envVars->get('MAUTIC_EL_FINDER_PATH'));
    }

    public function testElFinderUrlPointsToBucketWhenS3Configured(): void
    {
        $this->config->set('image_path', 'images');
        $this->config->set('site_url', 'https://foo.bar/test');
        $this->setS3Config('my root');

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);

        $this->assertSame('https://bkt.s3.us-east-1.amazonaws.com/my%20root/media/images', $this->envVars->get('MAUTIC_EL_FINDER_URL'));
        $this->assertStringEndsWith('images', $this->envVars->get('MAUTIC_EL_FINDER_PATH'));
    }

    public function testS3UrlMatchesMediaStorageRootUrl(): void
    {
        $this->setS3Config(' /my root/ ');
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturnCallback(fn (string $name, $default = null) => $this->config->get($name, $default));

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);

        $this->assertSame(
            (new MediaStorage($parameters))->getRootUrl().'media/images',
            $this->envVars->get('MAUTIC_EL_FINDER_URL')
        );
    }

    public function testLocalUrlKeptWhenS3IncompletelyConfigured(): void
    {
        $this->config->set('image_path', 'images');
        $this->config->set('site_url', 'https://foo.bar/test');
        $this->setS3Config('root');
        $this->config->set('s3_bucket', '');

        ElFinderEnvVars::load($this->config, $this->defaultConfig, $this->envVars);

        $this->assertSame('https://foo.bar/test/images', $this->envVars->get('MAUTIC_EL_FINDER_URL'));
    }

    private function setS3Config(string $root): void
    {
        $this->config->set('s3_bucket', 'bkt');
        $this->config->set('s3_region', 'us-east-1');
        $this->config->set('s3_access_key_id', 'AK');
        $this->config->set('s3_secret_access_key', 'SK');
        $this->config->set('s3_root_directory', $root);
    }
}
