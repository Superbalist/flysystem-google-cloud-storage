<?php

namespace Superbalist\Flysystem\GoogleStorage;

use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class GoogleStorageAdapter extends AbstractAdapter
{
    /**
     * @const STORAGE_API_URI_DEFAULT
     */
    const STORAGE_API_URI_DEFAULT = 'https://storage.googleapis.com';

    /**
     * @var StorageClient
     */
    protected $storageClient;

    /**
     * @var Bucket
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $storageApiUri;

    /**
     * @param StorageClient $storageClient
     * @param Bucket $bucket
     * @param string $pathPrefix
     * @param string $storageApiUri
     */
    public function __construct(StorageClient $storageClient, Bucket $bucket, $pathPrefix = null, $storageApiUri = null)
    {
        $this->storageClient = $storageClient;
        $this->bucket = $bucket;

        if ($pathPrefix) {
            $this->setPathPrefix($pathPrefix);
        }

        $this->storageApiUri = ($storageApiUri) ?: self::STORAGE_API_URI_DEFAULT;
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
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Set the storage api uri.
     *
     * @param string $uri
     */
    public function setStorageApiUri($uri)
    {
        $this->storageApiUri = $uri;
    }

    /**
     * Return the storage api uri.
     *
     * @return string
     */
    public function getStorageApiUri()
    {
        return $this->storageApiUri;
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
     *
     * @codeCoverageIgnore
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Returns an array of options from the config.
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        if ($visibility = $config->get('visibility')) {
            $options['predefinedAcl'] = $this->getPredefinedAclForVisibility($visibility);
        } else {
            // if a file is created without an acl, it isn't accessible via the console
            // we therefore default to private
            $options['predefinedAcl'] = $this->getPredefinedAclForVisibility(AdapterInterface::VISIBILITY_PRIVATE);
        }

        if ($metadata = $config->get('metadata')) {
            $options['metadata'] = $metadata;
        }

        return $options;
    }

    /**
     * Uploads a file to the Google Cloud Storage service.
     *
     * @param string $path
     * @param string|resource $contents
     * @param Config $config
     *
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
     *
     * @return array
     */
    protected function normaliseObject(StorageObject $object)
    {
        $name = $this->removePathPrefix($object->name());
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
            'mimetype' => isset($info['contentType']) ? $info['contentType'] : '',
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
        $dirname = $this->normaliseDirName($dirname);
        $objects = $this->listContents($dirname, true);

        // We first delete the file, so that we can delete
        // the empty folder at the end.
        uasort($objects, function ($a, $b) {
            return $b['type'] === 'file' ? 1 : -1;
        });

        // We remove all objects that should not be deleted.
        $filtered_objects = [];
        foreach ($objects as $object) {
            // normalise directories path
            if ($object['type'] === 'dir') {
                $object['path'] = $this->normaliseDirName($object['path']);
            }

            if (strpos($object['path'], $dirname) !== false) {
                $filtered_objects[] = $object;
            }
        }

        // Execute deletion for each object.
        foreach ($filtered_objects as $object) {
            $this->delete($object['path']);
        }

        return true;
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
     *
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
        } elseif ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {
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
    public function readStream($path)
    {
        $object = $this->getObject($path);

        $data = $this->normaliseObject($object);
        $data['stream'] = StreamWrapper::getResource($object->downloadAsStream());

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
            'visibility' => $this->getRawVisibility($path),
        ];
    }

    /**
     * Return a public url to a file.
     *
     * Note: The file must have `AdapterInterface::VISIBILITY_PUBLIC` visibility.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $uri = rtrim($this->storageApiUri, '/');
        $path = $this->applyPathPrefix($path);

        // Generating an uri with whitespaces or any other characters besides alphanumeric characters or "-_.~" will
        // not be RFC 3986 compliant. They will work in most browsers because they are automatically encoded but
        // may fail when passed to other software modules which are not doing automatic encoding.
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));

        // Only prepend bucket name if no custom storage uri specified
        // Default: "https://storage.googleapis.com/{my_bucket}/{path_prefix}"
        // Custom: "https://example.com/{path_prefix}"
        if ($this->getStorageApiUri() === self::STORAGE_API_URI_DEFAULT) {
            $path = $this->bucket->name() . '/' . $path;
        }

        return $uri . '/' . $path;
    }

    /**
     * Get a temporary URL (Signed) for the file at the given path.
     * @param string $path
     * @param \DateTimeInterface|int $expiration Specifies when the URL
     *        will expire. May provide an instance of [http://php.net/datetimeimmutable](`\DateTimeImmutable`),
     *        or a UNIX timestamp as an integer.
     * @param array $options {
     *     Configuration Options.
     *
     *     @type string $method One of `GET`, `PUT` or `DELETE`.
     *           **Defaults to** `GET`.
     *     @type string $cname The CNAME for the bucket, for instance
     *           `https://cdn.example.com`. **Defaults to**
     *           `https://storage.googleapis.com`.
     *     @type string $contentMd5 The MD5 digest value in base64. If you
     *           provide this, the client must provide this HTTP header with
     *           this same value in its request. If provided, take care to
     *           always provide this value as a base64 encoded string.
     *     @type string $contentType If you provide this value, the client must
     *           provide this HTTP header set to the same value.
     *     @type array $headers If these headers are used, the server will check
     *           to make sure that the client provides matching values. Provide
     *           headers as a key/value array, where the key is the header name,
     *           and the value is an array of header values.
     *     @type string $saveAsName The filename to prompt the user to save the
     *           file as when the signed url is accessed. This is ignored if
     *           `$options.responseDisposition` is set.
     *     @type string $responseDisposition The
     *           [`response-content-disposition`](http://www.iana.org/assignments/cont-disp/cont-disp.xhtml)
     *           parameter of the signed url.
     *     @type string $responseType The `response-content-type` parameter of the
     *           signed url.
     *     @type array $keyFile Keyfile data to use in place of the keyfile with
     *           which the client was constructed. If `$options.keyFilePath` is
     *           set, this option is ignored.
     *     @type string $keyFilePath A path to a valid Keyfile to use in place
     *           of the keyfile with which the client was constructed.
     *     @type bool $forceOpenssl If true, OpenSSL will be used regardless of
     *           whether phpseclib is available. **Defaults to** `false`.
     * }
     * @return string
     */
    public function getTemporaryUrl($path, $expiration, $options = [])
    {
        $object = $this->getObject($path);
        $signedUrl = $object->signedUrl($expiration, $options);

        if ($this->getStorageApiUri() !== self::STORAGE_API_URI_DEFAULT) {
            list($url, $params) = explode('?', $signedUrl, 2);
            $signedUrl = $this->getUrl($path) . '?' . $params;
        }

        return $signedUrl;
    }

    /**
     * @param string $path
     *
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
     *
     * @return \Google\Cloud\Storage\StorageObject
     */
    protected function getObject($path)
    {
        $path = $this->applyPathPrefix($path);
        return $this->bucket->object($path);
    }

    /**
     * @param string $visibility
     *
     * @return string
     */
    protected function getPredefinedAclForVisibility($visibility)
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'publicRead' : 'projectPrivate';
    }
}
