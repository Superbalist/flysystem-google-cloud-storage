<?php

namespace Superbalist\Flysystem\GoogleStorage;

use Google\Cloud\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleStorageAdapter extends AbstractAdapter
{
    use StreamedReadingTrait;

    /**
     * @var StorageClient
     */
    protected $storageClient;

    /**
     * @var Bucket
     */
    protected $bucket;

    /**
     * @param StorageClient $storageClient
     * @param Bucket $bucket
     * @param string $pathPrefix
     */
    public function __construct(StorageClient $storageClient, Bucket $bucket, $pathPrefix = null)
    {
        $this->storageClient = $storageClient;
        $this->bucket = $bucket;

        if ($pathPrefix) {
            $this->setPathPrefix($pathPrefix);
        }
    }

    /**
     * Returns the StorageClient.
     *
     * @return StorageClient
     */
    public function getStorageClient()
    {
        return $this->storageClient;
    }

    /**
     * Return the Bucket.
     *
     * @return \Google\Cloud\Storage\Bucket
     */
    protected function getBucket()
    {
        return $this->bucket;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Returns an array of options from the config.
     *
     * @param Config $config
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        if ($config->has('visibility')) {
            $options['predefinedAcl'] = $this->getPredefinedAclForVisibility($config->get('visibility'));
        } else {
            // if a file is created without an acl, it isn't accessible via the console
            // we therefore default to private
            $options['predefinedAcl'] = $this->getPredefinedAclForVisibility(AdapterInterface::VISIBILITY_PRIVATE);
        }

        return $options;
    }

    /**
     * Uploads a file to the Google Cloud Storage service.
     *
     * @param string $path
     * @param string|resource $contents
     * @param Config $config
     * @return array
     */
    protected function upload($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $options = $this->getOptionsFromConfig($config);
        $options['name'] = $path;

        $object = $this->bucket->upload($contents, $options);

        return $this->normaliseObject($object);
    }

    /**
     * Returns a dictionary of object metadata from an object.
     *
     * @param StorageObject $object
     * @return array
     */
    protected function normaliseObject(StorageObject $object)
    {
        $name = $object->name();
        $info = $object->info();

        $isDir = substr($name, -1) === '/';
        if ($isDir) {
            $name = rtrim($name, '/');
        }

        return [
            'type' => $isDir ? 'dir' : 'file',
            'dirname' => Util::dirname($name),
            'path' => $name,
            'timestamp' => strtotime($info['updated']),
            'mimetype' => $info['contentType'],
            'size' => $info['size'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        $newpath = $this->applyPathPrefix($newpath);

        // we want the new file to have the same visibility as the original file
        $visibility = $this->getRawVisibility($path);

        $options = [
            'name' => $newpath,
            'predefinedAcl' => $this->getPredefinedAclForVisibility($visibility),
        ];
        $this->getObject($path)->copy($this->bucket, $options);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $this->getObject($path)->delete();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        return $this->delete($this->normaliseDirName($dirname));
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        return $this->upload($this->normaliseDirName($dirname), '', $config);
    }

    /**
     * Returns a normalised directory name from the given path.
     *
     * @param string $dirname
     * @return string
     */
    protected function normaliseDirName($dirname)
    {
        return rtrim($dirname, '/') . '/';
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->getObject($path);

        if ($visibility === AdapterInterface::VISIBILITY_PRIVATE) {
            $object->acl()->delete('allUsers');
        } else if ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {
            $object->acl()->add('allUsers', Acl::ROLE_READER);
        }

        $normalised = $this->normaliseObject($object);
        $normalised['visibility'] = $visibility;

        return $normalised;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getObject($path)->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $object = $this->getObject($path);
        $contents = $object->downloadAsString();

        $data = $this->normaliseObject($object);
        $data['contents'] = $contents;

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);

        $objects = $this->bucket->objects(['prefix' => $directory]);

        $normalised = [];
        foreach ($objects as $object) {
            $normalised[] = $this->normaliseObject($object);
        }

        return Util::emulateDirectories($normalised);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $object = $this->getObject($path);
        return $this->normaliseObject($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        return [
            'visibility' => $this->getRawVisibility($path)
        ];
    }

    /**
     * @param string $path
     * @return string
     */
    protected function getRawVisibility($path)
    {
        try {
            $acl = $this->getObject($path)->acl()->get(['entity' => 'allUsers']);
            return $acl['role'] === Acl::ROLE_READER ?
                AdapterInterface::VISIBILITY_PUBLIC :
                AdapterInterface::VISIBILITY_PRIVATE;
        } catch (NotFoundException $e) {
            // object may not have an acl entry, so handle that gracefully
            return AdapterInterface::VISIBILITY_PRIVATE;
        }
    }

    /**
     * Returns a storage object for the given path.
     *
     * @param string $path
     * @return \Google\Cloud\Storage\StorageObject
     */
    protected function getObject($path)
    {
        $path = $this->applyPathPrefix($path);
        return $this->bucket->object($path);
    }

    /**
     * @param string $visibility
     * @return string
     */
    protected function getPredefinedAclForVisibility($visibility)
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'publicRead' : 'projectPrivate';
    }
}
