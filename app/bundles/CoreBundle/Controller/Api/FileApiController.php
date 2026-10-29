<?php

namespace Mautic\CoreBundle\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\ApiBundle\Helper\EntityResultHelper;
use Mautic\CoreBundle\Exception\MediaStorageException;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Storage\MediaStorage;
use Mautic\CoreBundle\Translation\Translator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * @extends CommonApiController<object>
 */
class FileApiController extends CommonApiController
{
    /**
     * Holds array of allowed file extensions.
     *
     * @var array
     */
    protected $allowedExtensions = [];

    public function __construct(CorePermissions $security, Translator $translator, EntityResultHelper $entityResultHelper, RouterInterface $router, FormFactoryInterface $formFactory, AppVersion $appVersion, RequestStack $requestStack, ManagerRegistry $doctrine, ModelFactory $modelFactory, EventDispatcherInterface $dispatcher, CoreParametersHelper $coreParametersHelper)
    {
        $this->entityNameOne     = 'file';
        $this->entityNameMulti   = 'files';
        $this->allowedExtensions = $coreParametersHelper->get('allowed_extensions');

        parent::__construct($security, $translator, $entityResultHelper, $router, $formFactory, $appVersion, $requestStack, $doctrine, $modelFactory, $dispatcher, $coreParametersHelper);
    }

    /**
     * Uploads a file.
     *
     * @return Response
     */
    public function createAction(Request $request, PathsHelper $pathsHelper, LoggerInterface $mauticLogger, MediaStorage $mediaStorage, $dir)
    {
        if ($mediaStorage->isEnabled() && null !== ($subdir = $this->getRemoteImagesSubdir($request, $dir))) {
            return $this->createRemoteImage($request, $pathsHelper, $mauticLogger, $mediaStorage, $subdir);
        }

        try {
            $path = $this->getAbsolutePath($request, $pathsHelper, $mauticLogger, $dir, true);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        $response = [$this->entityNameOne => []];
        if ($request->files->count() > 0) {
            foreach ($request->files as $file) {
                $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
                if (in_array($extension, $this->allowedExtensions)) {
                    $fileName = md5(uniqid()).'.'.$extension;
                    $moved    = $file->move($path, $fileName);

                    if (str_starts_with($dir, 'images')) {
                        $response[$this->entityNameOne]['link'] = $this->getMediaUrl($request).'/'.$fileName;
                    }

                    $response[$this->entityNameOne]['name'] = $fileName;
                } else {
                    return $this->returnError('The uploaded file can have only these extensions: '.implode(',', $this->allowedExtensions).'.', Response::HTTP_NOT_ACCEPTABLE);
                }
            }
        } else {
            return $this->returnError('File was not found in the request.', Response::HTTP_NOT_ACCEPTABLE);
        }

        $view = $this->view($response);

        return $this->handleView($view);
    }

    /**
     * List the files in /media directory.
     *
     * @return Response
     */
    public function listAction(Request $request, PathsHelper $pathsHelper, LoggerInterface $mauticLogger, MediaStorage $mediaStorage, $dir)
    {
        if ($mediaStorage->isEnabled() && null !== ($subdir = $this->getRemoteImagesSubdir($request, $dir))) {
            return $this->listRemoteImages($mediaStorage, $subdir);
        }

        try {
            $filePath = $this->getAbsolutePath($request, $pathsHelper, $mauticLogger, $dir);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        $fnames = scandir($filePath);

        if (is_array($fnames)) {
            foreach ($fnames as $key => $name) {
                // remove hidden files
                if (str_starts_with($name, '.')) {
                    unset($fnames[$key]);
                }
            }
        } else {
            return $this->returnError(ucfirst($dir).' dir is not readable');
        }

        $view = $this->view([$this->entityNameMulti => $fnames]);

        return $this->handleView($view);
    }

    /**
     * Delete a file from /media directory.
     *
     * @return Response
     */
    public function deleteAction(Request $request, PathsHelper $pathsHelper, LoggerInterface $mauticLogger, MediaStorage $mediaStorage, $dir, $file)
    {
        $response = ['success' => false];

        if ($mediaStorage->isEnabled() && null !== ($subdir = $this->getRemoteImagesSubdir($request, $dir))) {
            return $this->deleteRemoteImage($mediaStorage, $subdir, (string) $file);
        }

        try {
            $filePath = $this->getAbsolutePath($request, $pathsHelper, $mauticLogger, $dir).'/'.basename($file);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        if (!file_exists($filePath)) {
            return $this->returnError('File does not exist', Response::HTTP_NOT_FOUND);
        }
        if (!is_writable($filePath)) {
            return $this->returnError('File is not writable');
        }
        unlink($filePath);
        $response['success'] = true;

        $view = $this->view($response);

        return $this->handleView($view);
    }

    /**
     * Returns the sanitized sub directory (possibly empty) when the request targets the "images" dir, null otherwise.
     * Uses the same sanitizing as getAbsolutePath().
     */
    private function getRemoteImagesSubdir(Request $request, mixed $dir): ?string
    {
        $dir    = InputHelper::alphanum((string) $dir, true, null, ['_', '.']);
        $subdir = trim(InputHelper::alphanum($request->get('subdir', ''), true, null, ['/']));

        if (str_contains($dir, '.') && !$subdir) {
            $dirs   = explode('.', $dir);
            $dir    = $dirs[0];
            $subdir = implode('/', array_slice($dirs, 1));
        }

        if ('images' !== $dir) {
            return null;
        }

        $segments = array_filter(explode('/', $subdir), static fn (string $segment): bool => '' !== $segment && '.' !== $segment && '..' !== $segment);

        return implode('/', $segments);
    }

    private function remoteImagePrefix(string $subdir): string
    {
        return MediaStorage::IMAGES_PREFIX.('' === $subdir ? '' : $subdir.'/');
    }

    private function createRemoteImage(Request $request, PathsHelper $pathsHelper, LoggerInterface $mauticLogger, MediaStorage $mediaStorage, string $subdir): Response
    {
        $tmpDir = realpath($pathsHelper->getSystemPath('images', true));
        if (false === $tmpDir || !is_writable($tmpDir)) {
            return $this->returnError('images dir is not writable', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (0 === $request->files->count()) {
            return $this->returnError('File was not found in the request.', Response::HTTP_NOT_ACCEPTABLE);
        }

        $response = [$this->entityNameOne => []];
        foreach ($request->files as $file) {
            $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
            if (!in_array($extension, $this->allowedExtensions)) {
                return $this->returnError('The uploaded file can have only these extensions: '.implode(',', $this->allowedExtensions).'.', Response::HTTP_NOT_ACCEPTABLE);
            }

            $fileName  = md5(uniqid()).'.'.$extension;
            $localPath = $tmpDir.'/'.$fileName;
            try {
                $file->move($tmpDir, $fileName);
                $url = $mediaStorage->putFile($this->remoteImagePrefix($subdir).$fileName, $localPath);
            } catch (\Exception $e) {
                // MediaStorageException or a failed move: never report success for a local-only file.
                $mauticLogger->error($e->getMessage(), ['exception' => $e]);

                return $this->returnError('The file could not be stored.', Response::HTTP_INTERNAL_SERVER_ERROR);
            } finally {
                // The file was created by this request.
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
            }

            $response[$this->entityNameOne]['link'] = $url;
            $response[$this->entityNameOne]['name'] = $fileName;
        }

        return $this->handleView($this->view($response));
    }

    private function listRemoteImages(MediaStorage $mediaStorage, string $subdir): Response
    {
        $prefix = $this->remoteImagePrefix($subdir);
        $names  = [];

        try {
            foreach ($mediaStorage->listObjects($prefix) as $object) {
                $name = substr($object->key, strlen($prefix));
                if ('' !== $name && !str_contains($name, '/') && !str_starts_with($name, '.')) {
                    $names[] = $name;
                }
            }
        } catch (MediaStorageException) {
            return $this->returnError('Images dir is not readable');
        }

        return $this->handleView($this->view([$this->entityNameMulti => $names]));
    }

    private function deleteRemoteImage(MediaStorage $mediaStorage, string $subdir, string $file): Response
    {
        $name = basename($file);
        if ('' === $name || '.' === $name || '..' === $name || str_contains($name, '/') || str_contains($name, "\0")) {
            return $this->returnError('File does not exist', Response::HTTP_NOT_FOUND);
        }

        $key = $this->remoteImagePrefix($subdir).$name;
        try {
            if (!$mediaStorage->exists($key)) {
                return $this->returnError('File does not exist', Response::HTTP_NOT_FOUND);
            }
            $mediaStorage->delete($key);
        } catch (MediaStorageException) {
            return $this->returnError('File could not be deleted');
        }

        return $this->handleView($this->view(['success' => true]));
    }

    /**
     * Get the Media directory full file system path.
     *
     * @param string $dir
     * @param bool   $createDir
     */
    protected function getAbsolutePath(Request $request, PathsHelper $pathsHelper, LoggerInterface $mauticLogger, $dir, $createDir = false): string
    {
        try {
            $possibleDirs = ['media', 'images'];
            $dir          = InputHelper::alphanum($dir, true, null, ['_', '.']);
            $subdir       = trim(InputHelper::alphanum($request->get('subdir', ''), true, null, ['/']));

            // Dots in the dir name are slashes
            if (str_contains($dir, '.') && !$subdir) {
                $dirs = explode('.', $dir);
                $dir  = $dirs[0];
                unset($dirs[0]);
                $subdir = implode('/', $dirs);
            }

            if (!in_array($dir, $possibleDirs)) {
                throw new \InvalidArgumentException($dir.' not found. Only '.implode(' or ', $possibleDirs).' options are possible.');
            }

            if ('images' === $dir) {
                $absoluteDir = realpath($pathsHelper->getSystemPath($dir, true));
            } elseif ('media' === $dir) {
                $absoluteDir = realpath($this->coreParametersHelper->get('upload_dir'));
            }

            if (false === $absoluteDir) {
                throw new \InvalidArgumentException($dir.' dir does not exist', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if (false === is_writable($absoluteDir)) {
                throw new \InvalidArgumentException($dir.' dir is not writable', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $path = $absoluteDir.'/'.$subdir;

            if (!file_exists($path)) {
                if ($createDir) {
                    if (false === mkdir($path)) {
                        throw new \InvalidArgumentException($dir.'/'.$subdir.' subdirectory could not be created.', Response::HTTP_INTERNAL_SERVER_ERROR);
                    }
                } else {
                    throw new \InvalidArgumentException($subdir.' doesn\'t exist in the '.$dir.' dir.');
                }
            }

            return $path;
        } catch (\Exception $e) {
            $mauticLogger->error($e->getMessage(), ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * Get the Media directory full file system path.
     */
    protected function getMediaUrl(Request $request): string
    {
        return $request->getScheme().'://'
            .$request->getHttpHost()
            .':'.$request->getPort()
            .$request->getBasePath().'/'
            .$this->coreParametersHelper->get('image_path');
    }
}
