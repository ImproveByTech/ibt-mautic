<?php

namespace Mautic\CoreBundle\Controller;

use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Helper\FileUploader;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Storage\ImageStorage;
use Mautic\CoreBundle\Storage\MediaStorage;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FileController extends AjaxController
{
    public const EDITOR_CKEDITOR = 'ckeditor';

    protected $response = [];

    protected $statusCode = Response::HTTP_OK;

    /**
     * Uploads a file.
     *
     * @throws FileUploadException
     */
    public function uploadAction(Request $request, PathsHelper $pathsHelper, FileUploader $fileUploader, MediaStorage $mediaStorage, ImageStorage $imageStorage): JsonResponse
    {
        $editor   = $request->get('editor');
        $mediaDir = $this->getMediaAbsolutePath($pathsHelper);
        if (!isset($this->response['error'])) {
            foreach ($request->files as $file) {
                /** @var UploadedFile $file */
                try {
                    $fileUploader->validateImage($file);
                    $fileName = $fileUploader->upload($mediaDir, $file);
                    $url      = null;
                    if ($mediaStorage->isEnabled()) {
                        try {
                            ['name' => $fileName, 'url' => $url] = $imageStorage->store($mediaDir, $fileName);
                        } catch (MediaStorageException) {
                            throw new FileUploadException('Could not store the uploaded image');
                        }
                    }
                    $this->successfulResponse($request, $fileName, $editor, $url);
                } catch (FileUploadException) {
                    $this->failureResponse($editor);
                }
            }
        }

        return $this->sendJsonResponse($this->response, $this->statusCode);
    }

    /**
     * List the files in /media directory.
     */
    public function listAction(Request $request, PathsHelper $pathsHelper, FileUploader $fileUploader, MediaStorage $mediaStorage): JsonResponse
    {
        if ($mediaStorage->isEnabled()) {
            return $this->listRemote($fileUploader, $mediaStorage);
        }

        $fnames = scandir($this->getMediaAbsolutePath($pathsHelper));

        if ($fnames) {
            foreach ($fnames as $name) {
                $imagePath = $this->getMediaAbsolutePath($pathsHelper).'/'.$name;
                $imageUrl  = $this->getMediaUrl($request).'/'.$name;
                $imageFile = new File($imagePath, checkPath: false);
                if (!is_dir($name)) {
                    try {
                        $fileUploader->validateImage($imageFile);
                        $this->response[] = [
                            'url'   => $imageUrl,
                            'thumb' => $imageUrl,
                            'name'  => $name,
                        ];
                    } catch (FileUploadException) {
                    }
                }
            }
        } else {
            $this->response['error'] = 'Images folder does not exist!';
        }

        return $this->sendJsonResponse($this->response, $this->statusCode, false);
    }

    /**
     * Delete a file from /media directory.
     */
    public function deleteAction(Request $request, PathsHelper $pathsHelper, MediaStorage $mediaStorage, ImageStorage $imageStorage): JsonResponse
    {
        $src = InputHelper::clean($request->request->get('src'));

        if ($mediaStorage->isEnabled()) {
            return $this->deleteRemote($src, $mediaStorage, $imageStorage);
        }

        $imagePath = $this->getMediaAbsolutePath($pathsHelper).'/'.basename($src);

        if (!file_exists($imagePath)) {
            $this->response['error'] = 'File does not exist';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
        } elseif (!is_writable($imagePath)) {
            $this->response['error'] = 'File is not writable';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
        } else {
            unlink($imagePath);
            $this->response['deleted'] = true;
        }

        return $this->sendJsonResponse($this->response, $this->statusCode);
    }

    /**
     * Get the Media directory full file system path.
     *
     * @return string
     */
    public function getMediaAbsolutePath(PathsHelper $pathsHelper)
    {
        $mediaDir = realpath($pathsHelper->getSystemPath('images', true));

        if (false === $mediaDir) {
            $this->response['error'] = 'Media dir does not exist';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        if (false === is_writable($mediaDir)) {
            $this->response['error'] = 'Media dir is not writable';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return $mediaDir;
    }

    /**
     * Get the Media directory full file system path.
     */
    public function getMediaUrl(Request $request): string
    {
        return $request->getScheme().'://'
            .$request->getHttpHost()
            .$request->getBasePath().'/'
            .$this->coreParametersHelper->get('image_path');
    }

    private function listRemote(FileUploader $fileUploader, MediaStorage $mediaStorage): JsonResponse
    {
        $allowed = array_map('strtolower', $fileUploader->getAllowedImageExtensions());

        try {
            foreach ($mediaStorage->listObjects(MediaStorage::IMAGES_PREFIX) as $object) {
                $name = substr($object->key, strlen(MediaStorage::IMAGES_PREFIX));
                // Mirror the local listing: top level files only.
                if ('' === $name || str_contains($name, '/') || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowed, true)) {
                    continue;
                }

                $this->response[] = [
                    'url'   => $object->url,
                    'thumb' => $object->url,
                    'name'  => $name,
                ];
            }
        } catch (MediaStorageException) {
            $this->response['error'] = 'Images could not be listed';
        }

        return $this->sendJsonResponse($this->response, $this->statusCode, false);
    }

    private function deleteRemote(string $src, MediaStorage $mediaStorage, ImageStorage $imageStorage): JsonResponse
    {
        try {
            $key = $imageStorage->getKey($src);
        } catch (\InvalidArgumentException) {
            $this->response['error'] = 'File does not exist';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;

            return $this->sendJsonResponse($this->response, $this->statusCode);
        }

        try {
            if ($mediaStorage->exists($key)) {
                $mediaStorage->delete($key);
                $this->response['deleted'] = true;
            } else {
                $this->response['error'] = 'File does not exist';
                $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
        } catch (MediaStorageException) {
            $this->response['error'] = 'File could not be deleted';
            $this->statusCode        = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return $this->sendJsonResponse($this->response, $this->statusCode);
    }

    private function successfulResponse(Request $request, string $fileName, string $editor, ?string $url = null): void
    {
        $filePath = $url ?? $this->getMediaUrl($request).'/'.$fileName;
        if (self::EDITOR_CKEDITOR === $editor) {
            $this->response['uploaded'] = true;
            $this->response['url']      = $filePath;
        } else {
            $this->response['link'] = $filePath;
        }
    }

    private function failureResponse(string $editor): void
    {
        $errorMsg = 'The uploaded image does not have an allowed mime type';
        if (self::EDITOR_CKEDITOR === $editor) {
            $this->response['uploaded']         = false;
            $this->response['error']['message'] = $errorMsg;
        } else {
            $this->response['error'] = $errorMsg;
        }
    }
}
