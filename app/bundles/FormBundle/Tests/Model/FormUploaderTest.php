<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Tests\Model;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\FormBundle\Crate\UploadFileCrate;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Helper\FormUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FormUploaderTest extends \PHPUnit\Framework\TestCase
{
    private int $formId1   = 1;

    private int $formId2   = 2;

    private string $uploadDir = __DIR__.'/DummyFiles';

    #[\PHPUnit\Framework\Attributes\TestDox('Uploader uploads files correctly')]
    public function testSuccessfulUploadFiles(): void
    {
        $fileUploaderMock         = $this->createMock(FileUploader::class);
        $coreParametersHelperMock = $this->createMock(CoreParametersHelper::class);
        $formUploader             = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));
        $file1Mock                = $this->createStub(UploadedFile::class);
        $file2Mock                = $this->createStub(UploadedFile::class);
        $form1Mock                = $this->createMock(Form::class);
        $field1Mock               = $this->createMock(Field::class);
        $form2Mock                = $this->createMock(Form::class);
        $field2Mock               = $this->createMock(Field::class);

        $coreParametersHelperMock->expects($this->exactly(2))
            ->method('get')
            ->with('form_upload_dir')
            ->willReturn($this->uploadDir);

        $form1Mock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn($this->formId1);

        $field1Mock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn('fieldId1');

        $field1Mock->expects($this->once())
            ->method('getForm')
            ->with()
            ->willReturn($form1Mock);

        $field1Mock->expects($this->once())
            ->method('getAlias')
            ->with()
            ->willReturn('file1');

        $form2Mock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn($this->formId2);

        $field2Mock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn('fieldId2');

        $field2Mock->expects($this->once())
            ->method('getForm')
            ->with()
            ->willReturn($form2Mock);

        $field2Mock->expects($this->once())
            ->method('getAlias')
            ->with()
            ->willReturn('file2');

        $filesToUpload = new UploadFileCrate();
        $filesToUpload->addFile($file1Mock, $field1Mock);
        $filesToUpload->addFile($file2Mock, $field2Mock);

        $submission = new Submission();
        $submission->setResults(['key' => 'value']);

        $path1   = $this->uploadDir.'/1/fieldId1';
        $path2   = $this->uploadDir.'/2/fieldId2';
        $matcher = $this->exactly(2);

        $fileUploaderMock->expects($matcher)
            ->method('upload')->willReturnCallback(function (...$parameters) use ($matcher, $path1, $file1Mock, $path2, $file2Mock): string {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertSame($path1, $parameters[0]);
                    $this->assertSame($file1Mock, $parameters[1]);

                    return 'upload1.jpg';
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertSame($path2, $parameters[0]);
                    $this->assertSame($file2Mock, $parameters[1]);

                    return 'upload2.txt';
                }

                throw new \PHPUnit\Framework\Exception(sprintf('Method not be called for %dth time', $matcher->numberOfInvocations()));
            });

        $formUploader->uploadFiles($filesToUpload, $submission);

        $expected = [
            'key'   => 'value',
            'file1' => 'upload1.jpg',
            'file2' => 'upload2.txt',
        ];

        $this->assertSame($expected, $submission->getResults());
    }

    #[\PHPUnit\Framework\Attributes\TestDox('Uploader delete uploaded file if anz error occures')]
    public function testUploadFilesWithError(): void
    {
        $fileUploaderMock         = $this->createMock(FileUploader::class);
        $coreParametersHelperMock = $this->createMock(CoreParametersHelper::class);
        $formUploader             = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));
        $file1Mock                = $this->createStub(UploadedFile::class);
        $file2Mock                = $this->createStub(UploadedFile::class);
        $form1Mock                = $this->createMock(Form::class);
        $field1Mock               = $this->createMock(Field::class);
        $form2Mock                = $this->createMock(Form::class);
        $field2Mock               = $this->createMock(Field::class);

        $coreParametersHelperMock->expects($this->exactly(2))
            ->method('get')
            ->with('form_upload_dir')
            ->willReturn($this->uploadDir);

        $form1Mock->expects($this->once())
            ->method('getId')
            ->willReturn($this->formId1);

        $field1Mock->expects($this->once())
            ->method('getId')
            ->willReturn('fieldId1');

        $field1Mock->expects($this->once())
            ->method('getForm')
            ->willReturn($form1Mock);

        $field1Mock->expects($this->once())
            ->method('getAlias')
            ->willReturn('file1');

        $form2Mock->expects($this->once())
            ->method('getId')
            ->willReturn($this->formId2);

        $field2Mock->expects($this->once())
            ->method('getId')
            ->willReturn('fieldId2');

        $field2Mock->expects($this->once())
            ->method('getForm')
            ->willReturn($form2Mock);

        $field2Mock->expects($this->once())
            ->method('getAlias')
            ->willReturn('file2');

        $filesToUpload = new UploadFileCrate();
        $filesToUpload->addFile($file1Mock, $field1Mock);
        $filesToUpload->addFile($file2Mock, $field2Mock);

        $submission = new Submission();
        $submission->setResults(['key' => 'value']);

        $path1   = $this->uploadDir.'/1/fieldId1';
        $path2   = $this->uploadDir.'/2/fieldId2';
        $matcher = $this->exactly(2);

        $fileUploaderMock->expects($matcher)
            ->method('upload')->willReturnCallback(function (...$parameters) use ($matcher, $path1, $file1Mock, $path2, $file2Mock) {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertSame($path1, $parameters[0]);
                    $this->assertSame($file1Mock, $parameters[1]);

                    return 'upload1.jpg';
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertSame($path2, $parameters[0]);
                    $this->assertSame($file2Mock, $parameters[1]);
                    throw new FileUploadException();
                }
            });

        $fileUploaderMock->expects($this->once())
            ->method('delete')
            ->with($this->uploadDir.'/1/fieldId1/upload1.jpg');

        $this->expectException(FileUploadException::class);
        $this->expectExceptionMessage('file2');

        $formUploader->uploadFiles($filesToUpload, $submission);

        $expected = [
            'key'   => 'value',
            'file1' => 'upload1.jpg',
            'file2' => 'upload2.txt',
        ];

        $this->assertSame($expected, $submission->getResults());
    }

    #[\PHPUnit\Framework\Attributes\TestDox('Uploader do nothing if no files for upload provided')]
    public function testNoFilesUploadFiles(): void
    {
        $fileUploaderMock = $this->createMock(FileUploader::class);

        $fileUploaderMock->expects($this->never())
            ->method('upload');

        $fileUploaderMock->expects($this->never())
            ->method('delete');

        $coreParametersHelperMock = $this->createStub(CoreParametersHelper::class);

        $formUploader = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));

        $filesToUpload = new UploadFileCrate();
        $submission    = new Submission();

        $formUploader->uploadFiles($filesToUpload, $submission);
    }

    #[\PHPUnit\Framework\Attributes\TestDox('Uploader returs correct path for file')]
    public function testGetCompleteFilePath(): void
    {
        $fileUploaderMock = $this->createStub(FileUploader::class);

        $coreParametersHelperMock = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelperMock->expects($this->once())
            ->method('get')
            ->with('form_upload_dir')
            ->willReturn($this->uploadDir);

        $formMock = $this->createMock(Form::class);

        $formMock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn($this->formId1);

        $fieldMock = $this->createMock(Field::class);

        $fieldMock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn('fieldId1');

        $fieldMock->expects($this->once())
            ->method('getForm')
            ->with()
            ->willReturn($formMock);

        $formUploader = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));

        $actual = $formUploader->getCompleteFilePath($fieldMock, 'fileName');

        $this->assertSame($this->uploadDir.'/1/fieldId1/fileName', $actual);
    }

    #[\PHPUnit\Framework\Attributes\TestDox('Uploader delete files correctly')]
    public function testDeleteAllFilesOfFormField(): void
    {
        $fileUploaderMock = $this->createMock(FileUploader::class);

        $fileUploaderMock->expects($this->once())
            ->method('delete')
            ->with($this->uploadDir.'/1/fieldId1');

        $coreParametersHelperMock = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelperMock->expects($this->once())
            ->method('get')
            ->with('form_upload_dir')
            ->willReturn($this->uploadDir);

        $formUploader = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));

        $formMock = $this->createMock(Form::class);

        $formMock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn($this->formId1);

        $fieldMock = $this->createMock(Field::class);

        $fieldMock->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn('fieldId1');

        $fieldMock->expects($this->once())
            ->method('getForm')
            ->with()
            ->willReturn($formMock);

        $fieldMock->expects($this->once())
            ->method('isFileType')
            ->with()
            ->willReturn(true);

        $formUploader->deleteAllFilesOfFormField($fieldMock);
    }

    public function testDeleteFilesOfForm(): void
    {
        $fileUploaderMock         = $this->createMock(FileUploader::class);
        $formMock                 = $this->createMock(Form::class);
        $coreParametersHelperMock = $this->createMock(CoreParametersHelper::class);

        $fileUploaderMock
            ->method('delete')
            ->with($this->uploadDir.'/1');

        $coreParametersHelperMock->expects($this->exactly(2))
            ->method('get')
            ->with('form_upload_dir')
            ->willReturn($this->uploadDir);

        $formMock->expects($this->exactly(2))
            ->method('getId')
            ->willReturnOnConsecutiveCalls($this->formId1, null);

        $formUploader = new FormUploader($fileUploaderMock, $coreParametersHelperMock, $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));
        $formUploader->deleteFilesOfForm($formMock);

        $formMock->deletedId = $this->formId1;

        $formUploader->deleteFilesOfForm($formMock);
    }

    private function createDisabledStorage(): MediaStorage
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('isEnabled')->willReturn(false);
        $storage->expects($this->never())->method('putFile');
        $storage->expects($this->never())->method('delete');
        $storage->expects($this->never())->method('deletePrefix');
        $storage->expects($this->never())->method('downloadTo');

        return $storage;
    }

    private function createEnabledUploader(MediaStorage $storage, ?FileUploader $fileUploader = null, ?\Psr\Log\LoggerInterface $logger = null): FormUploader
    {
        $params = $this->createStub(CoreParametersHelper::class);
        $params->method('get')->willReturn($this->uploadDir);
        $storage->method('isEnabled')->willReturn(true);

        return new FormUploader($fileUploader ?? $this->createStub(FileUploader::class), $params, $storage, $logger ?? $this->createStub(\Psr\Log\LoggerInterface::class));
    }

    private function createField(int $formId, int $fieldId, string $alias = 'f'): Field
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn($formId);
        $field = $this->createStub(Field::class);
        $field->method('getId')->willReturn($fieldId);
        $field->method('getForm')->willReturn($form);
        $field->method('getAlias')->willReturn($alias);
        $field->method('isFileType')->willReturn(true);

        return $field;
    }

    public function testUploadPutsEachFileUnderFormFieldKeyAndRemovesLocal(): void
    {
        $storage      = $this->createMock(MediaStorage::class);
        $fileUploader = $this->createMock(FileUploader::class);
        $uploader     = $this->createEnabledUploader($storage, $fileUploader);

        $fileUploader->method('upload')->willReturnOnConsecutiveCalls('a.txt', 'b.txt');

        $puts = [];
        $storage->expects($this->exactly(2))->method('putFile')
            ->willReturnCallback(function (string $key, string $path) use (&$puts): string {
                $puts[$key] = $path;

                return 'https://s3/'.$key;
            });

        $deleted = [];
        $fileUploader->method('delete')->willReturnCallback(function ($path) use (&$deleted): void {
            $deleted[] = $path;
        });

        $crate = new UploadFileCrate();
        $crate->addFile($this->createStub(UploadedFile::class), $this->createField(1, 10, 'one'));
        $crate->addFile($this->createStub(UploadedFile::class), $this->createField(2, 20, 'two'));
        $submission = new Submission();
        $submission->setResults([]);

        // fixRotationJPG runs exif_imagetype on a real path: create real files.
        @mkdir($this->uploadDir.'/1/10', 0777, true);
        @mkdir($this->uploadDir.'/2/20', 0777, true);
        file_put_contents($this->uploadDir.'/1/10/a.txt', str_repeat('a', 64));
        file_put_contents($this->uploadDir.'/2/20/b.txt', str_repeat('b', 64));

        try {
            $uploader->uploadFiles($crate, $submission);
        } finally {
            @unlink($this->uploadDir.'/1/10/a.txt');
            @unlink($this->uploadDir.'/2/20/b.txt');
            @rmdir($this->uploadDir.'/1/10');
            @rmdir($this->uploadDir.'/1');
            @rmdir($this->uploadDir.'/2/20');
            @rmdir($this->uploadDir.'/2');
        }

        $this->assertSame([
            'media/files/form/1/10/a.txt' => $this->uploadDir.'/1/10/a.txt',
            'media/files/form/2/20/b.txt' => $this->uploadDir.'/2/20/b.txt',
        ], $puts);
        $this->assertSame([$this->uploadDir.'/1/10/a.txt', $this->uploadDir.'/2/20/b.txt'], $deleted);
        $this->assertSame(['one' => 'a.txt', 'two' => 'b.txt'], $submission->getResults());
    }

    public function testUploadFailureRollsBackPutObjectsAndThrowsFileUploadException(): void
    {
        $storage      = $this->createMock(MediaStorage::class);
        $fileUploader = $this->createMock(FileUploader::class);
        $uploader     = $this->createEnabledUploader($storage, $fileUploader);

        $fileUploader->method('upload')->willReturnOnConsecutiveCalls('a.txt', 'b.txt');

        $calls = 0;
        $storage->method('putFile')->willReturnCallback(function (string $key) use (&$calls): string {
            if (2 === ++$calls) {
                throw new MediaStorageException('boom');
            }

            return 'https://s3/'.$key;
        });
        $storage->expects($this->once())->method('delete')->with('media/files/form/1/10/a.txt');

        $deleted = [];
        $fileUploader->method('delete')->willReturnCallback(function ($path) use (&$deleted): void {
            $deleted[] = $path;
        });

        $crate = new UploadFileCrate();
        $crate->addFile($this->createStub(UploadedFile::class), $this->createField(1, 10, 'one'));
        $crate->addFile($this->createStub(UploadedFile::class), $this->createField(1, 11, 'two'));
        $submission = new Submission();
        $submission->setResults([]);

        @mkdir($this->uploadDir.'/1/10', 0777, true);
        @mkdir($this->uploadDir.'/1/11', 0777, true);
        file_put_contents($this->uploadDir.'/1/10/a.txt', str_repeat('a', 64));
        file_put_contents($this->uploadDir.'/1/11/b.txt', str_repeat('b', 64));

        try {
            $uploader->uploadFiles($crate, $submission);
            $this->fail('Expected FileUploadException');
        } catch (FileUploadException $e) {
            $this->assertSame('two', $e->getMessage());
        } finally {
            @unlink($this->uploadDir.'/1/10/a.txt');
            @unlink($this->uploadDir.'/1/11/b.txt');
            @rmdir($this->uploadDir.'/1/10');
            @rmdir($this->uploadDir.'/1/11');
            @rmdir($this->uploadDir.'/1');
        }

        // a.txt was already unlinked after its successful put; its rollback is the S3 delete above.
        $this->assertContains($this->uploadDir.'/1/11/b.txt', $deleted);
        $this->assertSame([], $submission->getResults());
    }

    public function testDeleteFilesOfFormDeletesPrefix(): void
    {
        $storage  = $this->createMock(MediaStorage::class);
        $uploader = $this->createEnabledUploader($storage);

        $storage->expects($this->once())->method('deletePrefix')->with('media/files/form/3/');

        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn(3);
        $uploader->deleteFilesOfForm($form);
    }

    public function testDeleteAllFilesOfFormFieldDeletesPrefix(): void
    {
        $storage  = $this->createMock(MediaStorage::class);
        $uploader = $this->createEnabledUploader($storage);

        $storage->expects($this->once())->method('deletePrefix')->with('media/files/form/3/7/');

        $uploader->deleteAllFilesOfFormField($this->createField(3, 7));
    }

    public function testDeleteUploadedFilesDeletesEachKey(): void
    {
        $storage  = $this->createMock(MediaStorage::class);
        $uploader = $this->createEnabledUploader($storage);

        $keys = [];
        $storage->expects($this->exactly(2))->method('delete')
            ->willReturnCallback(function (string $key) use (&$keys): void {
                $keys[] = $key;
            });

        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn(3);
        $form->method('getFields')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            $this->createField(3, 7, 'one'),
            $this->createField(3, 8, 'two'),
        ]));
        $submission = $this->createStub(Submission::class);
        $submission->method('getForm')->willReturn($form);
        $submission->method('getResults')->willReturn(['one' => 'a.txt', 'two' => 'b.txt']);

        $uploader->deleteUploadedFiles($submission);

        $this->assertSame(['media/files/form/3/7/a.txt', 'media/files/form/3/8/b.txt'], $keys);
    }

    public function testDeleteUploadedFilesSkipsUnsafeNames(): void
    {
        $storage  = $this->createMock(MediaStorage::class);
        $uploader = $this->createEnabledUploader($storage);
        $storage->expects($this->never())->method('delete');

        $form = $this->createStub(Form::class);
        $form->method('getFields')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            $this->createField(3, 7, 'one'),
            $this->createField(3, 8, 'two'),
        ]));
        $submission = $this->createStub(Submission::class);
        $submission->method('getForm')->willReturn($form);
        $submission->method('getResults')->willReturn(['one' => '../x', 'two' => 'a/b']);

        $uploader->deleteUploadedFiles($submission);
    }

    public function testGetLocalCopyDownloadsWhenMissingLocally(): void
    {
        $storage  = $this->createMock(MediaStorage::class);
        $uploader = $this->createEnabledUploader($storage);

        $storage->expects($this->once())->method('downloadTo')
            ->with('media/files/form/1/10/pic.jpg', $this->callback(
                fn (string $path): bool => str_starts_with($path, sys_get_temp_dir().'/mautic-form-') && str_ends_with($path, '-pic.jpg')
            ));

        $path = $uploader->getLocalCopy($this->createField(1, 10), 'pic.jpg');

        $this->assertStringEndsWith('-pic.jpg', $path);
    }

    public function testGetLocalCopyReturnsLocalPathWhenFileExists(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->expects($this->never())->method('downloadTo');
        $uploader = $this->createEnabledUploader($storage);

        @mkdir($this->uploadDir.'/1/10', 0777, true);
        file_put_contents($this->uploadDir.'/1/10/pic.jpg', str_repeat('x', 64));
        try {
            $this->assertSame($this->uploadDir.'/1/10/pic.jpg', $uploader->getLocalCopy($this->createField(1, 10), 'pic.jpg'));
        } finally {
            @unlink($this->uploadDir.'/1/10/pic.jpg');
            @rmdir($this->uploadDir.'/1/10');
            @rmdir($this->uploadDir.'/1');
        }
    }

    public function testGetFileKeyAndUrl(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('getPublicUrl')->willReturnCallback(fn (string $k): string => 'https://s3/'.$k);
        $uploader = $this->createEnabledUploader($storage);
        $field    = $this->createField(1, 10);

        $this->assertSame('media/files/form/1/10/a b.txt', $uploader->getFileKey($field, 'a b.txt'));
        $this->assertSame('https://s3/media/files/form/1/10/a b.txt', $uploader->getFileUrl($field, 'a b.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'traversal' => ['../x'];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'dot' => ['.'];
        yield 'dotdot' => ['..'];
        yield 'empty' => [''];
        yield 'nul' => ["a\0b"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNames')]
    public function testUnsafeFileNamesAreRejectedEverywhere(string $name): void
    {
        $uploader = $this->createEnabledUploader($this->createMock(MediaStorage::class));
        $field    = $this->createField(1, 10);

        foreach (['getFileKey', 'getFileUrl', 'getLocalCopy', 'getCompleteFilePath'] as $method) {
            try {
                $uploader->{$method}($field, $name);
                $this->fail($method.' accepted '.json_encode($name));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDisabledKeepsLocalBehavior(): void
    {
        $uploader = new FormUploader($this->createStub(FileUploader::class), $this->createStub(CoreParametersHelper::class), $this->createDisabledStorage(), $this->createStub(\Psr\Log\LoggerInterface::class));
        $field    = $this->createField(1, 10);

        $this->assertNull($uploader->getFileUrl($field, 'a.txt'));
        $this->assertSame('media/files/form/1/10/a.txt', $uploader->getFileKey($field, 'a.txt'));
        // No local file and storage disabled: returns the (missing) local path, never downloads.
        $this->assertStringEndsWith('/1/10/a.txt', $uploader->getLocalCopy($field, 'a.txt'));
        $uploader->deleteAllFilesOfFormField($field);
    }

    public function testDeleteAllFilesOfFormFieldLogsS3FailureWithoutThrowing(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('deletePrefix')->willThrowException(new MediaStorageException('boom'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('media/files/form/3/7/'));

        $this->createEnabledUploader($storage, null, $logger)->deleteAllFilesOfFormField($this->createField(3, 7));
    }

    public function testDeleteFilesOfFormLogsS3FailureWithoutThrowing(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('deletePrefix')->willThrowException(new MediaStorageException('boom'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('media/files/form/3/'));

        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn(3);
        $this->createEnabledUploader($storage, null, $logger)->deleteFilesOfForm($form);
    }

    public function testDeleteUploadedFilesLogsS3FailureWithoutThrowing(): void
    {
        $storage = $this->createMock(MediaStorage::class);
        $storage->method('delete')->willThrowException(new MediaStorageException('boom'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('media/files/form/3/7/a.txt'));

        $form = $this->createStub(Form::class);
        $form->method('getFields')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$this->createField(3, 7, 'one')]));
        $submission = $this->createStub(Submission::class);
        $submission->method('getForm')->willReturn($form);
        $submission->method('getResults')->willReturn(['one' => 'a.txt']);

        $this->createEnabledUploader($storage, null, $logger)->deleteUploadedFiles($submission);
    }
}
