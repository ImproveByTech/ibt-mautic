<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToWriteFile;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Storage\MediaStorageFlysystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaStorageFlysystemAdapterTest extends TestCase
{
    private MockObject&MediaStorage $storage;
    private MediaStorageFlysystemAdapter $adapter;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(MediaStorage::class);
        $this->adapter = new MediaStorageFlysystemAdapter($this->storage);
    }

    public function testWriteUsesImagesPrefix(): void
    {
        $this->storage->expects($this->once())->method('putContents')->with('media/images/a/b.png', 'DATA');
        $this->adapter->write('a/b.png', 'DATA', new Config());
    }

    public function testWriteStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'DATA');
        rewind($stream);
        $this->storage->expects($this->once())->method('putContents')->with('media/images/b.png', 'DATA');
        $this->adapter->writeStream('b.png', $stream, new Config());
    }

    public function testWriteFailureIsTranslated(): void
    {
        $this->storage->method('putContents')->willThrowException(new MediaStorageException('boom'));
        $this->expectException(UnableToWriteFile::class);
        $this->adapter->write('b.png', 'x', new Config());
    }

    public function testRead(): void
    {
        $this->storage->method('getContents')->with('media/images/a.png')->willReturn('BYTES');
        $this->assertSame('BYTES', $this->adapter->read('a.png'));
        $this->assertSame('BYTES', stream_get_contents($this->adapter->readStream('a.png')));
    }

    public function testFileExists(): void
    {
        $this->storage->method('exists')->with('media/images/a.png')->willReturn(true);
        $this->assertTrue($this->adapter->fileExists('a.png'));
    }

    public function testDelete(): void
    {
        $this->storage->expects($this->once())->method('delete')->with('media/images/a.png');
        $this->adapter->delete('a.png');
    }

    public function testDeleteDirectoryUsesTrailingSlash(): void
    {
        $this->storage->expects($this->once())->method('deletePrefix')->with('media/images/sub/');
        $this->adapter->deleteDirectory('sub');
    }

    public function testDeleteRootDirectoryIsRefused(): void
    {
        $this->storage->expects($this->never())->method('deletePrefix');
        $this->expectException(UnableToDeleteDirectory::class);
        $this->adapter->deleteDirectory('');
    }

    public function testDirectoryExists(): void
    {
        $this->storage->method('listObjects')->with('media/images/sub/')->willReturn([$this->object('media/images/sub/a.png')]);
        $this->assertTrue($this->adapter->directoryExists('sub'));
        $this->assertTrue($this->adapter->directoryExists(''));
    }

    public function testCreateDirectoryIsNoOp(): void
    {
        $this->storage->expects($this->never())->method($this->anything());
        $this->adapter->createDirectory('sub', new Config());
    }

    public function testListContentsShallowDerivesDirectories(): void
    {
        $this->storage->method('listObjects')->with('media/images/')->willReturn([
            $this->object('media/images/a.png', 5),
            $this->object('media/images/sub/b.png'),
            $this->object('media/images/sub/deep/c.png'),
        ]);

        $items = iterator_to_array($this->adapter->listContents('', false), false);

        $this->assertCount(2, $items);
        $this->assertInstanceOf(FileAttributes::class, $items[0]);
        $this->assertSame('a.png', $items[0]->path());
        $this->assertSame(5, $items[0]->fileSize());
        $this->assertInstanceOf(DirectoryAttributes::class, $items[1]);
        $this->assertSame('sub', $items[1]->path());
    }

    public function testListContentsDeep(): void
    {
        $this->storage->method('listObjects')->with('media/images/')->willReturn([
            $this->object('media/images/a.png'),
            $this->object('media/images/sub/deep/c.png'),
        ]);

        $paths = array_map(fn ($i) => $i->path(), iterator_to_array($this->adapter->listContents('', true), false));

        $this->assertSame(['a.png', 'sub', 'sub/deep', 'sub/deep/c.png'], $paths);
    }

    public function testListContentsOfSubdirectory(): void
    {
        $this->storage->method('listObjects')->with('media/images/sub/')->willReturn([
            $this->object('media/images/sub/b.png'),
            $this->object('media/images/sub/deep/c.png'),
        ]);

        $paths = array_map(fn ($i) => $i->path(), iterator_to_array($this->adapter->listContents('sub', false), false));

        $this->assertSame(['sub/b.png', 'sub/deep'], $paths);
    }

    public function testMetadata(): void
    {
        $this->storage->method('listObjects')->with('media/images/a.png')->willReturn([
            $this->object('media/images/a.png.bak', 1),
            $this->object('media/images/a.png', 42),
        ]);

        $this->assertSame(42, $this->adapter->fileSize('a.png')->fileSize());
        $this->assertSame(1700000000, $this->adapter->lastModified('a.png')->lastModified());
        $this->assertSame('image/png', $this->adapter->mimeType('a.png')->mimeType());
        $this->assertSame('public', $this->adapter->visibility('a.png')->visibility());
    }

    public function testMoveAndCopy(): void
    {
        $this->storage->method('getContents')->with('media/images/a.png')->willReturn('X');
        $this->storage->expects($this->exactly(2))->method('putContents')->with('media/images/b.png', 'X');
        $this->storage->expects($this->once())->method('delete')->with('media/images/a.png');

        $this->adapter->copy('a.png', 'b.png', new Config());
        $this->adapter->move('a.png', 'b.png', new Config());
    }

    /**
     * @dataProvider badPaths
     */
    public function testRejectsUnsafePaths(string $path): void
    {
        $this->storage->expects($this->never())->method('putContents');
        $this->storage->expects($this->never())->method('delete');
        $this->storage->expects($this->never())->method('deletePrefix');

        try {
            $this->adapter->write($path, 'x', new Config());
            $this->fail('write should have been rejected');
        } catch (UnableToWriteFile) {
        }

        $this->expectException(\League\Flysystem\UnableToDeleteFile::class);
        $this->adapter->delete($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPaths(): iterable
    {
        yield 'parent' => ['../x'];
        yield 'nested parent' => ['a/../../x'];
        yield 'absolute' => ['/x'];
        yield 'nul' => ["a\0b.png"];
    }

    private function object(string $key, int $size = 1): MediaObject
    {
        return new MediaObject($key, 'https://x/'.$key, $size, new \DateTimeImmutable('@1700000000'));
    }
}
