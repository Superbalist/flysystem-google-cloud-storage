<?php

namespace Superbalist\Flysystem\GoogleStorage;

use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\Visibility;

class GoogleStorageAdapter implements FilesystemAdapter
{
    public const STORAGE_API_URI_DEFAULT = 'https://storage.googleapis.com';

    protected StorageClient $storageClient;
    protected Bucket $bucket;
    protected ?string $pathPrefix = null;
    protected string $pathSeparator = '/';
    protected string $storageApiUri;

    public function __construct(
        StorageClient $storageClient,
        Bucket $bucket,
        string $pathPrefix = null,
        string $storageApiUri = null
    ) {
        $this->storageClient = $storageClient;
        $this->bucket = $bucket;

        if ($pathPrefix) {
            $this->setPathPrefix($pathPrefix);
        }

        $this->storageApiUri = ($storageApiUri) ?: self::STORAGE_API_URI_DEFAULT;
    }

    /**
     * Prefix a path.
     *
     * The method grabbed from class \League\Flysystem\Adapter\AbstractAdapter of league/flysystem:dev-1.0.x.
     * It is public for the backward compatibility only.
     */
    public function applyPathPrefix(string $path): string
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

    public function directoryExists(string $path): bool
    {
        $object = $this->getObject($path);

        return str_ends_with($object->name(), '/') && $object->exists();
    }

    public function fileExists(string $path): bool
    {
        return $this->getObject($path)->exists();
    }

    public function fileSize(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);

        return new FileAttributes($metadata['path'], $metadata['size']);
    }

    public function getFileAttributes(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);

        return new FileAttributes(
            $metadata['path'],
            $metadata['size'],
            $this->getRawVisibility($path),
            $metadata['timestamp'],
            $metadata['mimetype'],
            [
                'dirname' => $metadata['dirname'],
                'type' => $metadata['type'],
            ],
        );
    }

    /**
     * The method grabbed from class \League\Flysystem\Adapter\AbstractAdapter of league/flysystem:dev-1.0.x.
     * It is public for the backward compatibility only.
     */
    public function getPathPrefix(): ?string
    {
        return $this->pathPrefix;
    }

    /**
     * The method grabbed from class \League\Flysystem\Adapter\AbstractAdapter of league/flysystem:dev-1.0.x.
     * It is public for the backward compatibility only.
     */
    public function setPathPrefix(?string $prefix): void
    {
        $prefix = (string) $prefix;

        if ($prefix === '') {
            $this->pathPrefix = null;

            return;
        }

        $this->pathPrefix = rtrim($prefix, '\\/') . $this->pathSeparator;
    }

    public function getStorageClient(): StorageClient
    {
        return $this->storageClient;
    }

    public function getBucket(): Bucket
    {
        return $this->bucket;
    }

    public function setStorageApiUri(string $uri): void
    {
        $this->storageApiUri = $uri;
    }

    public function getStorageApiUri(): string
    {
        return $this->storageApiUri;
    }

    public function lastModified(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);

        return new FileAttributes($metadata['path'], null, null, $metadata['timestamp']);
    }

    public function mimeType(string $path): FileAttributes
    {
        $metadata = $this->getMetadata($path);

        return new FileAttributes($metadata['path'], null, null, null, $metadata['mimetype']);
    }

    /**
     * The method grabbed from class \League\Flysystem\Adapter\AbstractAdapter of league/flysystem:dev-1.0.x.
     * It is public for the backward compatibility only.
     */
    public function removePathPrefix(string $path): string
    {
        return substr($path, strlen($this->getPathPrefix()));
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, $this->getRawVisibility($path));
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * @codeCoverageIgnore
     */
    public function update(string $path, $contents, Config $config): array
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * @codeCoverageIgnore
     */
    public function updateStream(string $path, $resource, Config $config): array
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Returns an array of options from the config.
     *
     * @return array<string,mixed>
     */
    protected function getOptionsFromConfig(Config $config): array
    {
        $options = [];
        // if a file is created without an acl, it isn't accessible via the console
        // we therefore default to private
        $visibility = $config->get('visibility') ?: Visibility::PRIVATE;
        $options['predefinedAcl'] = $this->getPredefinedAclForVisibility($visibility);

        if ($metadata = $config->get('metadata')) {
            $options['metadata'] = $metadata;
        }

        return $options;
    }

    /**
     * Uploads a file to the Google Cloud Storage service.
     *
     * @param string|resource $contents
     *
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    protected function upload(string $path, $contents, Config $config): array
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
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    protected function normaliseObject(StorageObject $object): array
    {
        $name = $this->removePathPrefix($object->name());
        $info = $object->info();

        $isDir = str_ends_with($name, '/');
        if ($isDir) {
            $name = rtrim($name, '/');
        }

        return [
            'type' => $isDir ? 'dir' : 'file',
            'dirname' => $this->dirname($name),
            'path' => $name,
            'timestamp' => strtotime($info['updated']),
            'mimetype' => $info['contentType'] ?? '',
            'size' => $info['size'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
        } catch (UnableToCopyFile $exception) {
            $this->delete($source);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $destination = $this->applyPathPrefix($destination);

        // we want the new file to have the same visibility as the original file
        $visibility = $this->getRawVisibility($source);

        $options = [
            'name' => $destination,
            'predefinedAcl' => $this->getPredefinedAclForVisibility($visibility),
        ];
        if (!$this->getObject($source)->copy($this->bucket, $options)->exists()) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        $this->getObject($path)->delete();
    }

    /**
     * @deprecated Use {@see self::deleteDirectory() }
     * @codeCoverageIgnore
     */
    public function deleteDir(string $dirname): void
    {
        @trigger_error(sprintf('Method "%s:deleteDir()" id deprecated. Use "%1$s:deleteDirectory()"', __CLASS__), \E_USER_DEPRECATED);

        $this->deleteDirectory($dirname);
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalizeDirPostfix($path);
        $objects = $this->listContents($path, true);

        // We first delete the file, so that we can delete
        // the empty folder at the end.
        uasort($objects, static function (array $a, array $b): int {
            return $b['type'] === 'file' ? 1 : -1;
        });

        // We remove all objects that should not be deleted.
        $filtered_objects = [];
        foreach ($objects as $object) {
            // normalise directories path
            if ($object['type'] === 'dir') {
                $object['path'] = $this->normalizeDirPostfix($object['path']);
            }

            if (str_contains($object['path'], $path)) {
                $filtered_objects[] = $object;
            }
        }

        // Execute deletion for each object.
        foreach ($filtered_objects as $object) {
            $this->delete($object['path']);
        }
    }

    /**
     * @deprecated Use {@see self::createDirectory() }
     * @codeCoverageIgnore
     *
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    public function createDir(string $dirname, Config $config): array
    {
        @trigger_error(sprintf('Method "%s:createDir()" id deprecated. Use "%1$s:createDirectory()"', __CLASS__), \E_USER_DEPRECATED);

        return $this->upload($this->normalizeDirPostfix($dirname), '', $config);
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->upload($this->normalizeDirPostfix($path), '', $config);
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $object = $this->getObject($path);

        if ($visibility === Visibility::PRIVATE) {
            $object->acl()->delete('allUsers');
        } elseif ($visibility === Visibility::PUBLIC) {
            $object->acl()->add('allUsers', Acl::ROLE_READER);
        }
    }

    /**
     * @deprecated Use {@see self::fileExists() }
     * @codeCoverageIgnore
     */
    public function has(string $path): bool
    {
        @trigger_error(sprintf('Method "%s:has()" id deprecated. Use "%1$s:fileExists()"', __CLASS__), \E_USER_DEPRECATED);

        return $this->getObject($path)->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return $this->getObject($path)->downloadAsString();
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $object = $this->getObject($path);

        return StreamWrapper::getResource($object->downloadAsStream());
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $path = $this->applyPathPrefix($path);

        $objects = $this->bucket->objects(['prefix' => $path]);

        $normalised = [];
        foreach ($objects as $object) {
            $normalised[] = $this->normaliseObject($object);
        }

        return $this->emulateDirectories($normalised);
    }

    /**
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    public function getMetadata(string $path): array
    {
        $object = $this->getObject($path);

        return $this->normaliseObject($object);
    }

    /**
     * @deprecated Use {@see self::fileSize() }
     * @codeCoverageIgnore
     *
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    public function getSize(string $path): array
    {
        @trigger_error(sprintf('Method "%s:getSize()" id deprecated. Use "%1$s:fileSize()"', __CLASS__), \E_USER_DEPRECATED);

        return $this->getMetadata($path);
    }

    /**
     * @deprecated Use {@see self::mimeType() }
     * @codeCoverageIgnore
     *
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    public function getMimetype(string $path): array
    {
        @trigger_error(sprintf('Method "%s:getMimetype()" id deprecated. Use "%1$s:mimeType()"', __CLASS__), \E_USER_DEPRECATED);

        return $this->getMetadata($path);
    }

    /**
     * @deprecated Use {@see self::lastModified() }
     * @codeCoverageIgnore
     *
     * @return array{
     *      type: string
     *      dirname: string
     *      path: string
     *      timestamp: int
     *      mimetype: string
     *      size: int
     * }
     */
    public function getTimestamp(string $path): array
    {
        @trigger_error(sprintf('Method "%s:getTimestamp()" id deprecated. Use "%1$s:lastModified()"', __CLASS__), \E_USER_DEPRECATED);

        return $this->getMetadata($path);
    }

    /**
     * @deprecated Use {@see self::lastModified() }
     * @codeCoverageIgnore
     *
     * @return array { visibility: string }
     */
    public function getVisibility(string $path): array
    {
        @trigger_error(sprintf('Method "%s:getVisibility()" id deprecated. Use "%1$s:visibility()"', __CLASS__), \E_USER_DEPRECATED);

        return [
            'visibility' => $this->getRawVisibility($path),
        ];
    }

    /**
     * Return a public url to a file.
     *
     * Note: The file must have `Visibility::PUBLIC` visibility.
     */
    public function getUrl(string $path): string
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
     *
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
     */
    public function getTemporaryUrl(string $path, $expiration, array $options = []): string
    {
        $object = $this->getObject($path);
        $signedUrl = $object->signedUrl($expiration, $options);

        if ($this->getStorageApiUri() !== self::STORAGE_API_URI_DEFAULT) {
            [, $params] = explode('?', $signedUrl, 2);
            $signedUrl = $this->getUrl($path) . '?' . $params;
        }

        return $signedUrl;
    }

    /**
     * The method grabbed from class \League\Flysystem\Util of league/flysystem:dev-1.0.x.
     */
    protected function basename(string $path): string
    {
        $separators = DIRECTORY_SEPARATOR === '/' ? '/' : '\/';

        $path = rtrim($path, $separators);

        $basename = preg_replace('#.*?([^' . preg_quote($separators, '#') . ']+$)#', '$1', $path);

        if (DIRECTORY_SEPARATOR === '/') {
            return $basename;
        }
        // @codeCoverageIgnoreStart
        // Extra Windows path munging. This is tested via AppVeyor, but code
        // coverage is not reported.

        // Handle relative paths with drive letters. c:file.txt.
        while (preg_match('#^[a-zA-Z]:[^\\\/]#', $basename)) {
            $basename = substr($basename, 2);
        }

        // Remove colon for standalone drive letter names.
        if (preg_match('#^[a-zA-Z]:$#', $basename)) {
            $basename = rtrim($basename, ':');
        }

        return $basename;
        // @codeCoverageIgnoreEnd
    }

    /**
     * The method grabbed from class \League\Flysystem\Util of league/flysystem:dev-1.0.x.
     */
    protected function dirname(string $path): string
    {
        return $this->normalizeDotName(dirname($path));
    }

    /**
     * The method grabbed from class \League\Flysystem\Util of league/flysystem:dev-1.0.x.
     */
    protected function emulateDirectories(array $listing): array
    {
        $directories = [];
        $listedDirectories = [];

        foreach ($listing as $object) {
            [$directories, $listedDirectories] = $this->emulateObjectDirectories($object, $directories, $listedDirectories);
        }

        $directories = array_diff(array_unique($directories), array_unique($listedDirectories));

        foreach ($directories as $directory) {
            $listing[] = $this->getPathInfo($directory) + ['type' => 'dir'];
        }

        return $listing;
    }

    /**
     * The method grabbed from class \League\Flysystem\Util of league/flysystem:dev-1.0.x.
     */
    protected function emulateObjectDirectories(array $object, array $directories, array $listedDirectories): array
    {
        if ($object['type'] === 'dir') {
            $listedDirectories[] = $object['path'];
        }

        if (!isset($object['dirname']) || trim($object['dirname']) === '') {
            return [$directories, $listedDirectories];
        }

        $parent = $object['dirname'];

        while ($parent && trim($parent) !== '' && !\in_array($parent, $directories, true)) {
            $directories[] = $parent;
            $parent = $this->dirname($parent);
        }

        if (isset($object['type']) && $object['type'] === 'dir') {
            $listedDirectories[] = $object['path'];

            return [$directories, $listedDirectories];
        }

        return [$directories, $listedDirectories];
    }

    protected function getRawVisibility(string $path): string
    {
        try {
            $acl = $this->getObject($path)->acl()->get(['entity' => 'allUsers']);

            return $acl['role'] === Acl::ROLE_READER ? Visibility::PUBLIC : Visibility::PRIVATE;
        } catch (NotFoundException $e) {
            // object may not have an acl entry, so handle that gracefully
            return Visibility::PRIVATE;
        }
    }

    /**
     * Returns a storage object for the given path.
     */
    protected function getObject(string $path): StorageObject
    {
        $path = $this->applyPathPrefix($path);

        return $this->bucket->object($path);
    }

    protected function getPredefinedAclForVisibility(string $visibility): string
    {
        return $visibility === Visibility::PUBLIC ? 'publicRead' : 'projectPrivate';
    }

    /**
     * The method grabbed from class \League\Flysystem\Util of league/flysystem:dev-1.0.x.
     */
    protected function getPathInfo(string $path): array
    {
        $pathinfo = compact('path');

        if ('' !== $dirname = dirname($path)) {
            $pathinfo['dirname'] = $this->normalizeDotName($dirname);
        }

        $pathinfo['basename'] = $this->basename($path);
        $pathinfo += pathinfo($pathinfo['basename']);

        return $pathinfo + ['dirname' => ''];
    }

    /**
     * Returns a normalised directory name from the given path.
     */
    protected function normalizeDirPostfix(string $dirname): string
    {
        return rtrim($dirname, '/') . '/';
    }

    protected function normalizeDotName(string $dirname): string
    {
        return $dirname === '.' ? '' : $dirname;
    }
}
