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

    public function testRootUrlIsBucketAndRootWithTrailingSlash(): void
    {
        $this->assertSame('https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/', $this->storage()->getRootUrl());
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDeleteKeyProvider(): iterable
    {
        yield 'database' => ['database/x'];
        yield 'root level' => ['x.png'];
        yield 'dotdot' => ['media/../database/x'];
        yield 'not media' => ['mediafoo/x'];
        yield 'empty' => [''];
    }

    #[DataProvider('badDeleteKeyProvider')]
    public function testDeleteRefusesKeysOutsideMedia(string $key): void
    {
        $this->expectException(MediaStorageException::class);
        $this->storage()->delete($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPrefixProvider(): iterable
    {
        yield 'database' => ['database/'];
        yield 'no trailing slash' => ['media/files/form/7'];
        yield 'media itself' => ['media/'];
        yield 'dotdot' => ['media/../database/'];
        yield 'empty' => [''];
    }

    #[DataProvider('badPrefixProvider')]
    public function testDeletePrefixRefusesUnsafePrefixes(string $prefix): void
    {
        $this->expectException(MediaStorageException::class);
        $this->storage()->deletePrefix($prefix);
    }

    public function testRefusedDeletesNeverCallS3(): void
    {
        try {
            $this->storage()->delete('database/x');
        } catch (MediaStorageException) {
        }
        $this->assertSame([], $this->commands);
    }

    public function testKeyFromPublicUrlOnlyReturnsMediaKeys(): void
    {
        $base = 'https://mautic-selfhosted.s3.sa-east-1.amazonaws.com/gpsdapena/';
        $s    = $this->storage();

        $this->assertNull($s->keyFromPublicUrl($base.'database/x.sql'));
        $this->assertNull($s->keyFromPublicUrl($base.'media/../database/x'));
        $this->assertNull($s->keyFromPublicUrl($base.'media/%2E%2E/database/x'));
        $this->assertSame('media/files/a.pdf', $s->keyFromPublicUrl($base.'media/files/a.pdf?x=1'));
    }

    public function testExists(): void
    {
        $this->mock->append(new Result([]));
        $this->assertTrue($this->storage()->exists('media/files/a.pdf'));
        $this->assertSame('HeadObject', $this->commands[0]->getName());
        $this->assertSame('gpsdapena/media/files/a.pdf', $this->commands[0]['Key']);
    }

    public function testExistsReturnsFalseOn404(): void
    {
        $this->mock->append(fn (CommandInterface $cmd) => new S3Exception('nf', $cmd, ['code' => 'NotFound', 'response' => new \GuzzleHttp\Psr7\Response(404)]));
        $this->assertFalse($this->storage()->exists('media/files/a.pdf'));
    }

    public function testExistsThrowsOnOtherErrors(): void
    {
        $this->mock->append(fn (CommandInterface $cmd) => new S3Exception('denied', $cmd, ['code' => 'AccessDenied', 'response' => new \GuzzleHttp\Psr7\Response(403)]));
        $this->expectException(MediaStorageException::class);
        $this->storage()->exists('media/files/a.pdf');
    }

    public function testGetContents(): void
    {
        $this->mock->append(new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor('hello')]));
        $this->assertSame('hello', $this->storage()->getContents('media/files/a.txt'));
        $this->assertSame('GetObject', $this->commands[0]->getName());
    }

    public function testGetContentsAwsError(): void
    {
        $this->mock->append(fn (CommandInterface $cmd) => new S3Exception('boom', $cmd));
        $this->expectException(MediaStorageException::class);
        $this->storage()->getContents('media/files/a.txt');
    }

    public function testDownloadToPassesSaveAs(): void
    {
        $this->mock->append(new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor('x')]));
        $this->storage()->downloadTo('media/files/a.txt', '/tmp/ignored');
        $this->assertSame('/tmp/ignored', $this->commands[0]['@http']['sink'] ?? $this->commands[0]['SaveAs']);
        $this->assertSame('gpsdapena/media/files/a.txt', $this->commands[0]['Key']);
    }

    public function testDownloadToAwsErrorRemovesPartialFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ms');
        file_put_contents($path, 'partial');
        $this->mock->append(fn (CommandInterface $cmd) => new S3Exception('boom', $cmd));

        try {
            $this->storage()->downloadTo('media/files/a.txt', $path);
            $this->fail('Expected exception');
        } catch (MediaStorageException) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testDelete(): void
    {
        $this->mock->append(new Result([]));
        $this->storage()->delete('media/files/a.txt');
        $this->assertSame('DeleteObject', $this->commands[0]->getName());
        $this->assertSame('gpsdapena/media/files/a.txt', $this->commands[0]['Key']);
    }

    public function testDeleteAwsError(): void
    {
        $this->mock->append(fn (CommandInterface $cmd) => new S3Exception('boom', $cmd));
        $this->expectException(MediaStorageException::class);
        $this->storage()->delete('media/files/a.txt');
    }

    public function testPutFileMissingLocalFile(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->storage()->putFile('media/files/a.txt', '/nonexistent/nope.txt');
    }

    public function testPutFileUnreadableLocalFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ms');
        chmod($path, 0000);
        try {
            if (is_readable($path)) {
                $this->markTestSkipped('Running as a user that can read mode 000 files');
            }
            $this->expectException(MediaStorageException::class);
            $this->storage()->putFile('media/files/a.txt', $path);
        } finally {
            chmod($path, 0600);
            @unlink($path);
        }
    }

    public function testNonAwsRuntimeErrorsAreWrapped(): void
    {
        $this->mock->append(static fn () => new \RuntimeException('no credentials'));
        $this->expectException(MediaStorageException::class);
        $this->storage()->putContents('media/files/a.txt', 'x');
    }

    public function testDeletePrefixBatchesAt1000Keys(): void
    {
        $date     = new \DateTimeImmutable();
        $contents = [];
        for ($i = 0; $i < 1001; ++$i) {
            $contents[] = ['Key' => "gpsdapena/media/files/form/7/k{$i}.txt", 'Size' => 1, 'LastModified' => $date];
        }
        $this->mock->append(new Result(['IsTruncated' => false, 'Contents' => $contents]));
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));

        $this->storage()->deletePrefix('media/files/form/7/');

        $deletes = array_values(array_filter($this->commands, static fn ($c) => 'DeleteObjects' === $c->getName()));
        $this->assertCount(2, $deletes);
        $this->assertCount(1000, $deletes[0]['Delete']['Objects']);
        $this->assertCount(1, $deletes[1]['Delete']['Objects']);
    }

    public function testDeletePrefixThrowsWhenS3ReportsErrors(): void
    {
        $this->mock->append(new Result(['IsTruncated' => false, 'Contents' => [
            ['Key' => 'gpsdapena/media/files/form/7/a.txt', 'Size' => 1, 'LastModified' => new \DateTimeImmutable()],
        ]]));
        $this->mock->append(new Result(['Errors' => [['Key' => 'gpsdapena/media/files/form/7/a.txt', 'Code' => 'AccessDenied']]]));

        $this->expectException(MediaStorageException::class);
        $this->storage()->deletePrefix('media/files/form/7/');
    }
}
