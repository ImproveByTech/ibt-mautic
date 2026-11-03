<?php

declare(strict_types=1);

namespace MauticPlugin\GrapesJsBuilderBundle\Helper;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\DTO\MediaObject;
use Mautic\CoreBundle\Storage\ImageStorage;
use Mautic\CoreBundle\Storage\MediaStorage;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class FileManager
{
    public const GRAPESJS_IMAGES_DIRECTORY = '';

    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    public function __construct(
        private readonly FileUploader $fileUploader,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly PathsHelper $pathsHelper,
        private readonly MediaStorage $mediaStorage,
        private readonly ImageStorage $imageStorage,
    ) {
    }

    public function usesRemoteStorage(): bool
    {
        return $this->mediaStorage->isEnabled();
    }

    /**
     * @return array
     *
     * @throws FileUploadException
     */
    public function uploadFiles($request)
    {
        if (isset($request->files->all()['files'])) {
            $files         = $request->files->all()['files'];
            $uploadDir     = $this->getUploadDir();
            $uploadedFiles = [];

            foreach ($files as $file) {
                $this->fileUploader->validateImage($file);
            }

            $pushedKeys = [];
            try {
                foreach ($files as $file) {
                    $fileName = $this->fileUploader->upload($uploadDir, $file);

                    if ($this->mediaStorage->isEnabled()) {
                        try {
                            $stored = $this->imageStorage->store($uploadDir, $fileName);
                        } catch (MediaStorageException $e) {
                            throw new FileUploadException($e->getMessage(), 0, $e);
                        }
                        $pushedKeys[]    = $stored['key'];
                        $uploadedFiles[] = $stored['url'];

                        continue;
                    }

                    $uploadedFiles[] = $this->getFullUrl($fileName);
                }
            } catch (FileUploadException $e) {
                // Only keys created by this request.
                $this->imageStorage->deleteQuietly($pushedKeys);

                throw $e;
            }
        }

        return $uploadedFiles;
    }

    /**
     * @param string $fileName
     */
    public function deleteFile($fileName): void
    {
        if ($this->mediaStorage->isEnabled()) {
            $this->mediaStorage->delete($this->getRemoteKey((string) $fileName));

            return;
        }

        $this->fileUploader->delete($this->getCompleteFilePath($fileName));
    }

    public function remoteFileExists(string $fileName): bool
    {
        try {
            return $this->mediaStorage->exists($this->getRemoteKey($fileName));
        } catch (\InvalidArgumentException|MediaStorageException) {
            return false;
        }
    }

    /**
     * @param string $fileName
     */
    public function getCompleteFilePath($fileName): string
    {
        $uploadDir = $this->getUploadDir();

        return $uploadDir.$fileName;
    }

    private function getUploadDir(): string
    {
        return $this->getGrapesJsImagesPath(true);
    }

    public function getFullUrl($fileName, $separator = '/'): string
    {
        // if a static_url (CDN) is configured use that, otherwise use the site url
        $url = $this->coreParametersHelper->get('static_url') ?? $this->coreParametersHelper->get('site_url');

        return $url
            .$separator
            .$this->getGrapesJsImagesPath(false, $separator)
            .$fileName;
    }

    /**
     * @param string $separator
     */
    private function getGrapesJsImagesPath(bool $fullPath = false, $separator = '/'): string
    {
        return $this->pathsHelper->getSystemPath('images', $fullPath)
            .$separator
            .self::GRAPESJS_IMAGES_DIRECTORY;
    }

    /**
     * @deprecated since Mautic 5.2, to be removed in 6.0. Use FileManager::getMediaFiles instead
     */
    public function getImages(): array
    {
        if ($this->mediaStorage->isEnabled()) {
            $files = [];
            foreach ($this->getRemoteObjects() as $object) {
                $info    = $this->getRemoteFileInfo($object);
                $files[] = 'document' === $info['type'] ? $info['src'] : $info;
            }

            return $files;
        }

        $files      = [];
        $uploadDir  = $this->getUploadDir();

        $fileSystem = new Filesystem();

        if (!$fileSystem->exists($uploadDir)) {
            try {
                $fileSystem->mkdir($uploadDir);
            } catch (IOException) {
                return $files;
            }
        }

        $finder = new Finder();
        $finder->files()->in($uploadDir);

        foreach ($finder as $file) {
            // exclude certain folders from grapesjs file manager
            if (in_array($file->getRelativePath(), $this->coreParametersHelper->get('image_path_exclude'))) {
                continue;
            }

            $filePath = $this->getCompleteFilePath($file->getRelativePathname());
            if ($size = @getimagesize($filePath)) {
                $files[] = [
                    'src'    => $this->getFullUrl($file->getRelativePathname()),
                    'width'  => $size[0],
                    'type'   => 'image',
                    'height' => $size[1],
                ];
            } elseif ('svg' === strtolower($file->getExtension())) {
                $files[] = $this->getSvgFileInfo($filePath, $file->getRelativePathname());
            } else {
                $files[] = $this->getFullUrl($file->getRelativePathname());
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMediaFiles(int $page, int $limit): array
    {
        if ($this->mediaStorage->isEnabled()) {
            return $this->getRemoteMediaFiles($page, $limit);
        }

        $files      = [];
        $uploadDir  = $this->getUploadDir();
        $fileSystem = new Filesystem();

        if (!$fileSystem->exists($uploadDir)) {
            try {
                $fileSystem->mkdir($uploadDir);
            } catch (IOException) {
                return [
                    'data'            => [],
                    'page'            => $page,
                    'limit'           => $limit,
                    'totalItems'      => 0,
                    'totalPages'      => 0,
                    'hasNextPage'     => false,
                    'hasPreviousPage' => false,
                ];
            }
        }

        $finder = new Finder();
        $finder->files()->in($uploadDir)->sortByModifiedTime()->reverseSorting();

        $totalFiles = iterator_count($finder);
        $totalPages = (int) ceil($totalFiles / $limit);

        // Check if the requested page is out of range
        if ($page < 1 || $page > $totalPages) {
            return [
                'data'            => [],
                'page'            => $page,
                'limit'           => $limit,
                'totalItems'      => $totalFiles,
                'totalPages'      => $totalPages,
                'hasNextPage'     => $page < $totalPages,
                'hasPreviousPage' => $page > 1,
            ];
        }

        $offset = ($page - 1) * $limit;

        $filesIterator = new \LimitIterator($finder->getIterator(), $offset, $limit);

        foreach ($filesIterator as $file) {
            if (in_array($file->getRelativePath(), $this->coreParametersHelper->get('image_path_exclude'))) {
                continue;
            }

            $fileInfo = $this->getFileInfo($file);
            if ($fileInfo) {
                $files[] = $fileInfo;
            }
        }

        return [
            'data'            => $files,
            'page'            => $page,
            'limit'           => $limit,
            'totalItems'      => $totalFiles,
            'totalPages'      => $totalPages,
            'hasNextPage'     => $page < $totalPages,
            'hasPreviousPage' => $page > 1,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getFileInfo(SplFileInfo $file): ?array
    {
        $filePath  = $this->getCompleteFilePath($file->getRelativePathname());
        $size      = @getimagesize($filePath);
        $extension = strtolower($file->getExtension());

        if ($size) {
            $info = [
                'src'    => $this->getFullUrl($file->getRelativePathname()),
                'width'  => $size[0],
                'height' => $size[1],
                'type'   => 'image',
            ];
        } elseif ('svg' === $extension) {
            $info = $this->getSvgFileInfo($filePath, $file->getRelativePathname());
        } elseif (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            $info = [
                'src'  => $this->getFullUrl($file->getRelativePathname()),
                'type' => 'document',
            ];
        } else {
            $info = null;
        }

        return $info;
    }

    /**
     * Extract full file metadata from an SVG file.
     *
     * @return array<string, int|string>
     */
    private function getSvgFileInfo(string $filePath, string $relativePathName): array
    {
        $info = [
            'src'    => $this->getFullUrl($relativePathName),
            'width'  => 0,
            'height' => 0,
            'type'   => 'image',
        ];

        return $this->applySvgDimensions($info, @file_get_contents($filePath));
    }

    /**
     * @param array<string, int|string> $info
     *
     * @return array<string, int|string>
     */
    private function applySvgDimensions(array $info, string|false $svgContent): array
    {
        if ($svgContent) {
            // Suppress XML warnings and enable internal error handling
            $previousErrors = libxml_use_internal_errors(true);

            // Parse SVG with network access disabled to mitigate XXE/SSRF risks
            $svg = simplexml_load_string($svgContent, 'SimpleXMLElement', LIBXML_NONET);

            // Clear any accumulated libxml errors and restore previous setting
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            if ($svg) {
                $svgAttributes = $svg->attributes();

                // Try to get width and height directly
                if (isset($svgAttributes->width) && isset($svgAttributes->height)) {
                    $info['width']  = (int) $svgAttributes->width;
                    $info['height'] = (int) $svgAttributes->height;
                } elseif (isset($svgAttributes->viewBox)) {
                    // Parse the viewBox attribute (format: "x y width height")
                    $viewBox = explode(' ', (string) $svgAttributes->viewBox);
                    if (4 === count($viewBox)) {
                        $info['width']  = (int) $viewBox[2];
                        $info['height'] = (int) $viewBox[3];
                    }
                }
            }
        }

        return $info;
    }

    private function getRemoteKey(string $fileName): string
    {
        return $this->imageStorage->getKey($fileName);
    }

    /**
     * Supported objects in the bucket, newest first, without the excluded folders.
     *
     * @return list<MediaObject>
     */
    private function getRemoteObjects(): array
    {
        $exclude = (array) $this->coreParametersHelper->get('image_path_exclude');
        $allowed = array_map('strtolower', $this->fileUploader->getAllowedImageExtensions());
        $objects = [];

        foreach ($this->mediaStorage->listObjects(MediaStorage::IMAGES_PREFIX) as $object) {
            $relative = substr($object->key, strlen(MediaStorage::IMAGES_PREFIX));
            if (str_contains($relative, '/') && in_array(explode('/', $relative, 2)[0], $exclude, true)) {
                continue;
            }

            if (in_array($this->getExtension($object), array_merge($allowed, ['svg'], self::DOCUMENT_EXTENSIONS), true)) {
                $objects[] = $object;
            }
        }

        usort($objects, fn (MediaObject $a, MediaObject $b) => $b->lastModified <=> $a->lastModified);

        return $objects;
    }

    private function getExtension(MediaObject $object): string
    {
        return strtolower(pathinfo($object->key, PATHINFO_EXTENSION));
    }

    /**
     * Width and height are omitted for raster images to avoid a request per object.
     *
     * @return array<string, mixed>
     */
    private function getRemoteFileInfo(MediaObject $object): array
    {
        $extension = $this->getExtension($object);

        if ('svg' === $extension) {
            $info = ['src' => $object->url, 'width' => 0, 'height' => 0, 'type' => 'image'];
            try {
                $content = $this->mediaStorage->getContents($object->key);
            } catch (MediaStorageException) {
                $content = false;
            }

            return $this->applySvgDimensions($info, $content);
        }

        if (in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
            return ['src' => $object->url, 'type' => 'document'];
        }

        return ['src' => $object->url, 'type' => 'image'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRemoteMediaFiles(int $page, int $limit): array
    {
        $objects    = $this->getRemoteObjects();
        $totalFiles = count($objects);
        $totalPages = (int) ceil($totalFiles / max(1, $limit));
        $data       = [];

        if ($page >= 1 && $page <= $totalPages) {
            foreach (array_slice($objects, ($page - 1) * $limit, $limit) as $object) {
                $data[] = $this->getRemoteFileInfo($object);
            }
        }

        return [
            'data'            => $data,
            'page'            => $page,
            'limit'           => $limit,
            'totalItems'      => $totalFiles,
            'totalPages'      => $totalPages,
            'hasNextPage'     => $page < $totalPages,
            'hasPreviousPage' => $page > 1,
        ];
    }
}
