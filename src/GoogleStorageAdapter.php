<?php namespace Superbalist\Flysystem\GoogleStorage;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleStorageAdapter extends AbstractAdapter
{

    /**
     * @var \Google_Service_Storage
     */
    protected $service;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @param \Google_Service_Storage $service
     * @param string $bucket
     */
    public function __construct(\Google_Service_Storage $service, $bucket)
    {
        $this->service = $service;
        $this->bucket = $bucket;
    }

    /**
     * Returns the bucket name.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Returns the Google_Service_Storage service.
     *
     * @return \Google_Service_Storage
     */
    public function getService()
    {
        return $this->service;
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
        // TODO: can we implement a stream with google cloud storage?
        throw new \LogicException('The GoogleStorageAdapter does not support writing from a stream.');
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
        // TODO: can we implement a stream with google cloud storage?
        throw new \LogicException('The GoogleStorageAdapter does not support updating from a stream.');
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
            $options['acl'] = $config->get('visibility') === AdapterInterface::VISIBILITY_PUBLIC ?
                'publicRead' :
                'private';
        }

        if ($config->has('mimetype')) {
            $options['mimetype'] = $config->get('mimetype');
        }

        // TODO: consider other metadata which we can set here

        return $options;
    }

    /**
     * Uploads a file to the Google Cloud Storage service.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array
     */
    protected function upload($path, $contents, Config $config)
    {
        $options = $this->getOptionsFromConfig($config);

        if (! isset($options['mimetype'])) {
            $options['mimetype'] = Util::guessMimeType($path, $contents);
        }

        if (! isset($options['acl'])) {
            $options['acl'] = 'private';
        }

        $object = new \Google_Service_Storage_StorageObject();
        $object->setName($path);
        $object->setContentType($options['mimetype']);

        $params = [
            'data' => $contents,
            'uploadType' => 'media',
            'mimeType' => $options['mimetype'],
            'predefinedAcl' => $options['acl']
        ];

        $object = $this->service->objects->insert($this->bucket, $object, $params);
        return $this->normaliseObject($object);
    }

    /**
     * Returns a dictionary of object metadata from an object.
     *
     * @param \Google_Service_Storage_StorageObject $object
     * @return array
     */
    protected function normaliseObject(\Google_Service_Storage_StorageObject $object)
    {
        return [
            'type' => 'file',
            'dirname' => Util::dirname($object->getName()),
            'path' => $object->getName(),
            'timestamp' => strtotime($object->getUpdated()),
            'mimetype' => $object->getContentType(),
            'size' => $object->getSize(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        if (! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        $params = [
            'destinationPredefinedAcl' => $this->getRawVisibility($path) == AdapterInterface::VISIBILITY_PUBLIC ?
                'publicRead' :
                'private'
        ];
        $this->service->objects->copy(
            $this->bucket,
            $path,
            $this->bucket,
            $newpath,
            new \Google_Service_Storage_StorageObject(),
            $params
        );
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $this->service->objects->delete($this->bucket, $path);
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
        $object = new \Google_Service_Storage_StorageObject();
        $object->setAcl([]);
        $params = [
            'predefinedAcl' => $visibility == AdapterInterface::VISIBILITY_PUBLIC ? 'publicRead' : 'private'
        ];
        $this->service->objects->patch($this->bucket, $path, $object, $params);
        return compact('path', 'visibility');
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        try {
            // to test the existance of an object, we need to retrieve an object
            // there is no api method to check if an object exists or not
            $this->getObject($path);
            return true;
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        // TODO: can this be optimised to not perform 2 x api calls here?
        $object = $this->getObject($path);
        $object = $this->normaliseObject($object);
        $object['contents'] = $this->service->objects->get($this->bucket, $path, ['alt' => 'media']);
        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        // TODO: can we implement a stream with google cloud storage?
        throw new \LogicException('The GoogleStorageAdapter does not support reading from a stream.');
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $results = [];
        $pageToken = null;

        while (true) {
            $params = [];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $objects = $this->service->objects->listObjects($this->bucket, $params);
            $results = array_merge($results, $objects->getItems());
            $pageToken = $objects->getNextPageToken();

            if ($pageToken === null) {
                break;
            }
        }

        $results = array_map(function(\Google_Service_Storage_StorageObject $object) {
            return $this->normaliseObject($object);
        }, $results);
        return Util::emulateDirectories($results);
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
        $controls = $this->service->objectAccessControls->listObjectAccessControls($this->bucket, $path);
        foreach ($controls->getItems() as $control) {
            if ($control['role'] == 'READER' && $control['entity'] == 'allUsers') {
                return AdapterInterface::VISIBILITY_PUBLIC;
            }
        }

        return AdapterInterface::VISIBILITY_PRIVATE;
    }

    /**
     * Returns a storage object for the given path.
     *
     * @param string $path
     * @return \Google_Service_Storage_StorageObject
     */
    protected function getObject($path)
    {
        return $this->service->objects->get($this->bucket, $path);
    }
}
