<?php

namespace Mautic\FormBundle\Helper;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\FormBundle\Crate\UploadFileCrate;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Psr\Log\LoggerInterface;

class FormUploader
{
    public function __construct(
        private readonly FileUploader $fileUploader,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly MediaStorage $mediaStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws FileUploadException
     */
    public function uploadFiles(UploadFileCrate $filesToUpload, Submission $submission): void
    {
        $uploadedFiles = [];
        $uploadedKeys  = [];
        $result        = $submission->getResults();
        $alias         = ''; // Only for IDE - will be overriden by foreach

        try {
            foreach ($filesToUpload as $fileFieldCrate) {
                $field           = $fileFieldCrate->getField();
                $alias           = $field->getAlias();
                $uploadDir       = $this->getUploadDir($field);
                $fileName        = $this->fileUploader->upload($uploadDir, $fileFieldCrate->getUploadedFile());
                $uploadedFile    = $uploadDir.DIRECTORY_SEPARATOR.$fileName;
                $uploadedFiles[] = $uploadedFile;
                $this->fixRotationJPG($uploadedFile);

                if ($this->mediaStorage->isEnabled()) {
                    // The local dir is emptied after each push, so the original name is not unique in S3
                    // and would be guessable: store under a random-prefixed name.
                    [$fileName, $key] = $this->randomizeStoredName($field, $uploadDir, $fileName, $uploadedFiles);
                    $uploadedFile     = end($uploadedFiles);
                    $this->mediaStorage->putFile($key, $uploadedFile);
                    $uploadedKeys[] = $key;
                    $this->fileUploader->delete($uploadedFile);
                }

                $result[$alias] = $fileName;
            }
            $submission->setResults($result);
        } catch (FileUploadException|MediaStorageException|\InvalidArgumentException) {
            foreach ($uploadedKeys as $key) {
                try {
                    $this->mediaStorage->delete($key);
                } catch (MediaStorageException) {
                    // Best effort rollback; the original failure is what matters.
                }
            }
            foreach ($uploadedFiles as $filePath) {
                $this->fileUploader->delete($filePath);
            }
            throw new FileUploadException($alias);
        }
    }

    /**
     * Renames the local upload to a random-prefixed name that has no S3 object yet.
     *
     * @param string[] $uploadedFiles tracked local paths; the last entry is replaced with the renamed path
     *
     * @return array{string, string} new file name and S3 key
     *
     * @throws MediaStorageException
     */
    private function randomizeStoredName(Field $field, string $uploadDir, string $fileName, array &$uploadedFiles): array
    {
        $key = '';
        $new = '';
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $new = bin2hex(random_bytes(8)).'-'.$fileName;
            $key = $this->getFileKey($field, $new);
            if (!$this->mediaStorage->exists($key)) {
                break;
            }
            if (1 === $attempt) {
                throw new MediaStorageException(sprintf('Could not find a free key for "%s"', $key));
            }
        }

        $from = $uploadDir.DIRECTORY_SEPARATOR.$fileName;
        $to   = $uploadDir.DIRECTORY_SEPARATOR.$new;
        if (!@rename($from, $to)) {
            throw new MediaStorageException(sprintf('Could not rename "%s"', $from));
        }
        $uploadedFiles[count($uploadedFiles) - 1] = $to;

        return [$new, $key];
    }

    /**
     * @param string $fileName
     */
    public function getCompleteFilePath(Field $field, $fileName): string
    {
        $this->assertSafeFileName((string) $fileName);
        $uploadDir = $this->getUploadDir($field);

        return $uploadDir.DIRECTORY_SEPARATOR.$fileName;
    }

    /**
     * Whether the name is a plain file name (no directories, no traversal, no NUL).
     */
    public function isSafeFileName(string $fileName): bool
    {
        return '' !== $fileName
            && '.' !== $fileName
            && '..' !== $fileName
            && !str_contains($fileName, "\0")
            && !str_contains($fileName, '/')
            && !str_contains($fileName, '\\')
            && basename($fileName) === $fileName;
    }

    /**
     * @throws \InvalidArgumentException If the file name is not a plain file name
     */
    public function getFileKey(Field $field, string $fileName): string
    {
        $this->assertSafeFileName($fileName);

        return MediaStorage::FORMS_PREFIX.$field->getForm()->getId().'/'.$field->getId().'/'.$fileName;
    }

    /**
     * Public S3 URL of the file, or null when S3 storage is disabled.
     *
     * @throws \InvalidArgumentException If the file name is not a plain file name
     */
    public function getFileUrl(Field $field, string $fileName): ?string
    {
        $key = $this->getFileKey($field, $fileName);

        return $this->mediaStorage->isEnabled() ? $this->mediaStorage->getPublicUrl($key) : null;
    }

    /**
     * Returns the local path when the file exists locally, otherwise a temp copy downloaded from S3.
     * The caller must delete the copy when it differs from getCompleteFilePath().
     *
     * @throws \InvalidArgumentException If the file name is not a plain file name
     * @throws MediaStorageException
     */
    public function getLocalCopy(Field $field, string $fileName): string
    {
        $local = $this->getCompleteFilePath($field, $fileName);
        if (is_file($local) || !$this->mediaStorage->isEnabled()) {
            return $local;
        }

        $tmp = sys_get_temp_dir().'/mautic-form-'.uniqid().'-'.$fileName;
        $this->mediaStorage->downloadTo($this->getFileKey($field, $fileName), $tmp);

        return $tmp;
    }

    public function deleteAllFilesOfFormField(Field $field): void
    {
        if (!$field->isFileType()) {
            return;
        }

        $uploadDir = $this->getUploadDir($field);
        $this->fileUploader->delete($uploadDir);

        if ($this->mediaStorage->isEnabled()) {
            $this->deleteS3Prefix(MediaStorage::FORMS_PREFIX.$field->getForm()->getId().'/'.$field->getId().'/');
        }
    }

    public function deleteFilesOfForm(Form $form): void
    {
        $formId        = $form->getId() ?: $form->deletedId;
        $formUploadDir = $this->getUploadDirOfForm($formId);
        $this->fileUploader->delete($formUploadDir);

        if ($this->mediaStorage->isEnabled()) {
            $this->deleteS3Prefix(MediaStorage::FORMS_PREFIX.$formId.'/');
        }
    }

    /**
     * @todo Refactor code that result can be accessed normally and not only as a array of values
     */
    public function deleteUploadedFiles(Submission $submission): void
    {
        $fields = $submission->getForm()->getFields();
        foreach ($fields as $field) {
            $this->deleteFileOfFormField($submission, $field);
        }
    }

    private function deleteFileOfFormField(Submission $submission, Field $field): void
    {
        $alias   = $field->getAlias();
        $results = $submission->getResults();

        if (!$field->isFileType() || empty($results[$alias])) {
            return;
        }

        $fileName = $results[$alias];
        if (!is_string($fileName) || !$this->isSafeFileName($fileName)) {
            return;
        }

        $filePath = $this->getCompleteFilePath($field, $fileName);
        $this->fileUploader->delete($filePath);

        if ($this->mediaStorage->isEnabled()) {
            $key = $this->getFileKey($field, $fileName);
            try {
                $this->mediaStorage->delete($key);
            } catch (MediaStorageException $e) {
                // Orphaned S3 objects are the lesser harm; never break the DB cleanup that called us.
                $this->logger->error(sprintf('Could not delete form upload "%s" from S3: %s', $key, $e->getMessage()));
            }
        }
    }

    private function deleteS3Prefix(string $prefix): void
    {
        try {
            $this->mediaStorage->deletePrefix($prefix);
        } catch (MediaStorageException $e) {
            $this->logger->error(sprintf('Could not delete form uploads under "%s" from S3: %s', $prefix, $e->getMessage()));
        }
    }

    private function assertSafeFileName(string $fileName): void
    {
        if (!$this->isSafeFileName($fileName)) {
            throw new \InvalidArgumentException('Invalid form upload file name.');
        }
    }

    private function getUploadDir(Field $field): string
    {
        $fieldId       = $field->getId();
        $formUploadDir = $this->getUploadDirOfForm($field->getForm()->getId());

        return $formUploadDir.DIRECTORY_SEPARATOR.$fieldId;
    }

    /**
     * @throws \LogicException If formId is null
     */
    private function getUploadDirOfForm(int $formId): string
    {
        $uploadDir = $this->coreParametersHelper->get('form_upload_dir');

        return $uploadDir.DIRECTORY_SEPARATOR.$formId;
    }

    /**
     * Fix iOS picture orientation after upload PHP
     * https://stackoverflow.com/questions/22308921/fix-ios-picture-orientation-after-upload-php.
     */
    private function fixRotationJPG(string $filename): void
    {
        if (IMAGETYPE_JPEG != exif_imagetype($filename)) {
            return;
        }
        $exif = exif_read_data($filename);
        if (empty($exif['Orientation'])) {
            return;
        }
        $ort   = $exif['Orientation']; /* STORES ORIENTATION FROM IMAGE */
        $ort1  = $ort;
        $image = imagecreatefromjpeg($filename);
        $ort   = $ort1;
        switch ($ort) {
            case 3:
                $image = imagerotate($image, 180, 0);
                break;

            case 6:
                $image = imagerotate($image, -90, 0);
                break;

            case 8:
                $image = imagerotate($image, 90, 0);
                break;
        }
        imagejpeg($image, $filename, 90);
    }
}
