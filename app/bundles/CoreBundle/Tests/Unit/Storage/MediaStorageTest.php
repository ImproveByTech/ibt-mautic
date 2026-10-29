<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Storage;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Storage\MediaStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaStorageTest extends TestCase
{
    private const PARAMS = [
        's3_bucket'            => 'mautic-selfhosted',
        's3_region'            => 'sa-east-1',
        's3_access_key_id'     => 'AKIATEST',
        's3_secret_access_key' => 'secret',
        's3_root_directory'    => 'gpsdapena',
    ];

    private MockHandler $mock;

    /**
     * @var list<CommandInterface>
     */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->mock     = new MockHandler();
        $this->commands = [];
    }

    /**
     * @param array<string, string> $overrides
     */
    private function storage(array $overrides = []): MediaStorage
    {
        $params = array_merge(self::PARAMS, $overrides);

        /** @var CoreParametersHelper&MockObject $helper */
        $helper = $this->createMock(CoreParametersHelper::class);
        $helper->method('get')->willReturnCallback(static fn (string $name, $default = null) => $params[$name] ?? $default);

        $client = new S3Client([
            'region'      => 'sa-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler'     => $this->mock,
        ]);
        $client->getHandlerList()->appendSign(function (callable $handler) {
            return function (CommandInterface $command, $request = null) use ($handler) {
                $this->commands[] = $command;

                return $handler($command, $request);
            };
        }, 'record');

        return new MediaStorage($helper, $client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyParamProvider(): iterable
    {
        foreach (array_keys(self::PARAMS) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('emptyParamProvider')]
    public function testDisabledWhenAnyParameterIsEmpty(string $param): void
    {
        $this->assertTrue($this->storage()->isEnabled());
        $this->assertFalse($this->storage([$param => ''])->isEnabled());
    }

    public function testMethodsThrowWhenDisabled(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('S3 media storage is not configured');
        $this->storage(['s3_bucket' => ''])->getPublicUrl('media/images/a.png');
    }

    public function testPutFileSendsKeyUnderRootAndReturnsPublicUrl(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'ms').'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->mock->append(new Result([]));

        $url = $this->storage()->putFile('media/images/a.png', $png);
        @unlink($png);

        $command = end($this->commands);
        $this->assertSame('PutObject', $command->getName());
        $this->assertSame('mautic-selfhosted', $command['Bucket']);
        $this->assertSame('gpsdapena/media/images/a.png', $command['Key']);
        $this->assertSame('image/png', $command['ContentType']);
        $this->assertFalse(isset($command['ACL']));
        $this->assertSame('https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/images/a.png', $url);
    }

    public function testPublicUrlEncodesSegments(): void
    {
        $storage = $this->storage();
        $url     = $storage->getPublicUrl('media/images/foto ção.png');

        $this->assertSame('https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/images/foto%20%C3%A7%C3%A3o.png', $url);
        $this->assertSame('media/images/foto ção.png', $storage->keyFromPublicUrl($url));
    }

    public function testKeyFromPublicUrlRejectsForeignUrls(): void
    {
        $storage = $this->storage();

        $this->assertNull($storage->keyFromPublicUrl('https://example.com/x.pdf'));
        $this->assertNull($storage->keyFromPublicUrl('https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/trilhasdearuanda/media/files/x.pdf'));
        $this->assertNull($storage->keyFromPublicUrl('http://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/files/x.pdf'));
    }

    public function testAwsErrorBecomesMediaStorageException(): void
    {
        $this->mock->append(static fn (CommandInterface $cmd) => new S3Exception('boom', $cmd));

        $this->expectException(MediaStorageException::class);
        $this->storage()->putContents('media/files/a.txt', 'hello');
    }

    public function testListObjectsStripsRootAndFollowsPagination(): void
    {
        $date = new \DateTimeImmutable('2026-01-02 03:04:05');
        $this->mock->append(new Result([
            'IsTruncated'           => true,
            'NextContinuationToken' => 'tok',
            'Contents'              => [['Key' => 'gpsdapena/media/images/a.png', 'Size' => 10, 'LastModified' => $date]],
        ]));
        $this->mock->append(new Result([
            'IsTruncated' => false,
            'Contents'    => [['Key' => 'gpsdapena/media/images/sub/b b.png', 'Size' => 20, 'LastModified' => $date]],
        ]));

        $objects = $this->storage()->listObjects('media/images/');

        $this->assertCount(2, $objects);
        $this->assertSame('media/images/a.png', $objects[0]->key);
        $this->assertSame(10, $objects[0]->size);
        $this->assertSame('https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/media/images/a.png', $objects[0]->url);
        $this->assertSame($date->getTimestamp(), $objects[0]->lastModified->getTimestamp());
        $this->assertSame('media/images/sub/b b.png', $objects[1]->key);
        $this->assertSame('tok', $this->commands[1]['ContinuationToken']);
        $this->assertSame('gpsdapena/media/images/', $this->commands[0]['Prefix']);
    }

    public function testDeletePrefixDeletesEveryListedKey(): void
    {
        $date = new \DateTimeImmutable();
        $this->mock->append(new Result([
            'IsTruncated' => false,
            'Contents'    => [
                ['Key' => 'gpsdapena/media/files/form/7/1/a.pdf', 'Size' => 1, 'LastModified' => $date],
                ['Key' => 'gpsdapena/media/files/form/7/2/b.pdf', 'Size' => 1, 'LastModified' => $date],
            ],
        ]));
        $this->mock->append(new Result([]));

        $this->storage()->deletePrefix('media/files/form/7/');

        $delete = end($this->commands);
        $this->assertSame('DeleteObjects', $delete->getName());
        $keys = array_column($delete['Delete']['Objects'], 'Key');
        $this->assertSame(['gpsdapena/media/files/form/7/1/a.pdf', 'gpsdapena/media/files/form/7/2/b.pdf'], $keys);
    }

    public function testDeletePrefixRefusesEmptyPrefix(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->storage()->deletePrefix('');
    }
}
