<?php

namespace Superbalist\Flysystem\GoogleStorage\Test;

use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use League\Flysystem\Config;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;

class GoogleStorageAdapterTests extends TestCase
{
    public function testDirectoryExists(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('dir_name/');

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/dir_name')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        self::assertTrue($adapter->directoryExists('dir_name'));
    }

    public function testGetStorageClient(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);
        $adapter = new GoogleStorageAdapter($storageClient, $bucket);

        $this->assertSame($storageClient, $adapter->getStorageClient());
    }

    public function testGetBucket(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);
        $adapter = new GoogleStorageAdapter($storageClient, $bucket);

        $this->assertSame($bucket, $adapter->getBucket());
    }

    /**
     * @dataProvider getDataForTestWriteContent
     */
    public function testWriteContent(
        array $expected,
        string $contents,
        string $predefinedAcl,
        ?string $visibility
    ): void {
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->exactly(2))
            ->method('name')
            ->willReturn('prefix/file1.txt');
        $storageObject
            ->expects($this->exactly(2))
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('upload')
            ->with(
                $contents,
                [
                    'name' => 'prefix/file1.txt',
                    'predefinedAcl' => $predefinedAcl,
                ],
            )
            ->willReturn($storageObject);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file1.txt', [])
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $configOptions = [];
        if ($visibility) {
            $configOptions['visibility'] = $visibility;
        }

        $adapter->write('file1.txt', 'This is the file contents.', new Config($configOptions));

        $this->assertEquals($expected, $adapter->getMetadata('file1.txt'));
    }

    public function testWriteStream(): void
    {
        $stream = tmpfile();

        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->exactly(2))
            ->method('name')
            ->willReturn('prefix/file1.txt');
        $storageObject
            ->expects($this->exactly(2))
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket->expects($this->once())
            ->method('upload')
            ->with(
                $stream,
                [
                    'name' => 'prefix/file1.txt',
                    'predefinedAcl' => 'projectPrivate',
                ],
            )
            ->willReturn($storageObject);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file1.txt', [])
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->writeStream('file1.txt', $stream, new Config());

        fclose($stream);

        $expected = [
            'type' => 'file',
            'dirname' => '',
            'path' => 'file1.txt',
            'timestamp' => 1474901082,
            'mimetype' => 'text/plain',
            'size' => 5,
        ];
        $this->assertEquals($expected, $adapter->getMetadata('file1.txt'));
    }

    public function testRename(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $oldStorageObjectAcl = $this->createMock(Acl::class);
        $oldStorageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_OWNER,
            ]);

        $newStorageObject = $this->createMock(StorageObject::class);
        $newStorageObject
            ->method('exists')
            ->willReturn(true);

        $oldStorageObject = $this->createMock(StorageObject::class);
        $oldStorageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($oldStorageObjectAcl);
        $oldStorageObject
            ->expects($this->once())
            ->method('copy')
            ->with(
                $bucket,
                [
                    'name' => 'prefix/new_file.txt',
                    'predefinedAcl' => 'projectPrivate',
                ],
            )
            ->willReturn($newStorageObject);
        $oldStorageObject
            ->expects($this->exactly(0))
            ->method('delete');

        $bucket
            ->expects($this->exactly(2))
            ->method('object')
            ->with('prefix/old_file.txt')
            ->willReturn($oldStorageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->move('old_file.txt', 'new_file.txt', new Config());
    }

    public function testDeleteOnRename(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $oldStorageObjectAcl = $this->createMock(Acl::class);
        $oldStorageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_OWNER,
            ]);

        $newStorageObject = $this->createMock(StorageObject::class);
        $newStorageObject
            ->method('exists')
            ->willReturn(false);

        $oldStorageObject = $this->createMock(StorageObject::class);
        $oldStorageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($oldStorageObjectAcl);
        $oldStorageObject
            ->expects($this->once())
            ->method('copy')
            ->with(
                $bucket,
                [
                    'name' => 'prefix/new_file.txt',
                    'predefinedAcl' => 'projectPrivate',
                ],
            )
            ->willReturn($newStorageObject);
        $oldStorageObject
            ->expects($this->once())
            ->method('delete');

        $bucket
            ->expects($this->exactly(3))
            ->method('object')
            ->with('prefix/old_file.txt')
            ->willReturn($oldStorageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->move('old_file.txt', 'new_file.txt', new Config());
    }

    public function testCopy(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $oldStorageObjectAcl = $this->createMock(Acl::class);
        $oldStorageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_OWNER,
            ]);

        $newStorageObject = $this->createMock(StorageObject::class);
        $newStorageObject
            ->method('exists')
            ->willReturn(true);

        $oldStorageObject = $this->createMock(StorageObject::class);
        $oldStorageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($oldStorageObjectAcl);
        $oldStorageObject
            ->expects($this->once())
            ->method('copy')
            ->with(
                $bucket,
                [
                    'name' => 'prefix/new_file.txt',
                    'predefinedAcl' => 'projectPrivate',
                ],
            )
            ->willReturn($newStorageObject);

        $bucket
            ->expects($this->exactly(2))
            ->method('object')
            ->with('prefix/old_file.txt')
            ->willReturn($oldStorageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->copy('old_file.txt', 'new_file.txt', new Config());
    }

    public function testCopyWhenOriginalFileIsPublic(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $oldStorageObjectAcl = $this->createMock(Acl::class);
        $oldStorageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_READER,
            ]);

        $newStorageObject = $this->createMock(StorageObject::class);
        $newStorageObject
            ->method('exists')
            ->willReturn(true);

        $oldStorageObject = $this->createMock(StorageObject::class);
        $oldStorageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($oldStorageObjectAcl);
        $oldStorageObject
            ->expects($this->once())
            ->method('copy')
            ->with(
                $bucket,
                [
                    'name' => 'prefix/new_file.txt',
                    'predefinedAcl' => 'publicRead',
                ],
            )
            ->willReturn($newStorageObject);

        $bucket
            ->expects($this->exactly(2))
            ->method('object')
            ->with('prefix/old_file.txt')
            ->willReturn($oldStorageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->copy('old_file.txt', 'new_file.txt', new Config());
    }

    public function testDelete(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('delete');

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->delete('file.txt');
    }

    /**
     * @dataProvider getDataForTestDeleteDirectory
     */
    public function testDeleteDirectory(string $path): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->exactly(3))
            ->method('delete');
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/dir_name/directory1/file1.txt');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->exactly(3))
            ->method('object')
            ->withConsecutive(['prefix/dir_name/directory1/file1.txt', []], ['prefix/dir_name/directory1/', []], ['prefix/dir_name/', []])
            ->willReturnOnConsecutiveCalls($storageObject, $storageObject, $storageObject);

        $bucket
            ->expects($this->once())
            ->method('objects')
            ->with([
                'prefix' => 'prefix/dir_name/',
            ])
            ->willReturn([$storageObject]);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->deleteDirectory($path);
    }

    public function testSetVisibilityPrivate(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $storageObjectAcl = $this->createMock(Acl::class);
        $storageObjectAcl
            ->expects($this->once())
            ->method('delete')
            ->with('allUsers');

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($storageObjectAcl);
        $storageObject
            ->expects($this->exactly(0))
            ->method('name')
            ->willReturn('prefix/file.txt');
        $storageObject
            ->expects($this->exactly(0))
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file1.txt')
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->setVisibility('file1.txt', Visibility::PRIVATE);
    }

    public function testSetVisibilityPublic(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $storageObjectAcl = $this->createMock(Acl::class);
        $storageObjectAcl
            ->expects($this->once())
            ->method('add')
            ->with(
                'allUsers',
                Acl::ROLE_READER,
            );

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($storageObjectAcl);
        $storageObject
            ->expects($this->exactly(0))
            ->method('name');
        $storageObject
            ->expects($this->exactly(0))
            ->method('info');

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file1.txt')
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $adapter->setVisibility('file1.txt', Visibility::PUBLIC);
    }

    public function testFileExists(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        self::assertTrue($adapter->fileExists('file.txt'));
    }

    public function testRead(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('downloadAsString')
            ->willReturn('This is the file contents.');
        $storageObject
            ->expects($this->exactly(0))
            ->method('name');
        $storageObject
            ->expects($this->exactly(0))
            ->method('info');

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $data = $adapter->read('file.txt');

        $this->assertEquals('This is the file contents.', $data);
    }

    public function testReadStream(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $stream = $this->createMock(StreamInterface::class);
        $stream
            ->expects($this->once())
            ->method('isReadable')
            ->willReturn(true);
        $stream
            ->expects($this->once())
            ->method('isWritable')
            ->willReturn(false);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('downloadAsStream')
            ->willReturn($stream);
        $storageObject
            ->expects($this->exactly(0))
            ->method('name');
        $storageObject
            ->expects($this->exactly(0))
            ->method('info');

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $data = $adapter->readStream('file.txt');

        $this->assertIsResource($data);
    }

    public function testListContents(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $prefix = 'prefix/';

        $bucket
            ->expects($this->once())
            ->method('objects')
            ->with([
                'prefix' => $prefix,
            ])
            ->willReturn($this->getMockDirObjects($prefix));

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $listing = $adapter->listContents();

        $expected = [
            [
                'type' => 'dir',
                'dirname' => '',
                'path' => 'directory1',
                'timestamp' => 1474901082,
                'mimetype' => 'application/octet-stream',
                'size' => 0,
            ],
            [
                'type' => 'file',
                'dirname' => 'directory1',
                'path' => 'directory1/file1.txt',
                'timestamp' => 1474901082,
                'mimetype' => 'text/plain',
                'size' => 5,
            ],
            [
                'type' => 'file',
                'dirname' => 'directory2',
                'path' => 'directory2/file1.txt',
                'timestamp' => 1474901082,
                'mimetype' => 'text/plain',
                'size' => 5,
            ],
            [
                'dirname' => '',
                'basename' => 'directory2',
                'filename' => 'directory2',
                'path' => 'directory2',
                'type' => 'dir',
            ],
        ];

        $this->assertEquals($expected, $listing);
    }

    /**
     * @return StorageObject[]
     */
    protected function getMockDirObjects(string $prefix): array
    {
        $dir1 = $this->createMock(StorageObject::class);
        $dir1
            ->expects($this->once())
            ->method('name')
            ->willReturn($prefix . 'directory1/');
        $dir1
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'application/octet-stream',
                'size' => 0,
            ]);

        $dir1file1 = $this->createMock(StorageObject::class);
        $dir1file1
            ->expects($this->once())
            ->method('name')
            ->willReturn($prefix . 'directory1/file1.txt');
        $dir1file1
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $dir2file1 = $this->createMock(StorageObject::class);
        $dir2file1
            ->expects($this->once())
            ->method('name')
            ->willReturn($prefix . 'directory2/file1.txt');
        $dir2file1
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        return [
            $dir1,
            $dir1file1,
            $dir2file1,
        ];
    }

    public function testGetMetadataForFile(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/file.txt');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $metadata = $adapter->getMetadata('file.txt');

        $expected = [
            'type' => 'file',
            'dirname' => '',
            'path' => 'file.txt',
            'timestamp' => 1474901082,
            'mimetype' => 'text/plain',
            'size' => 5,
        ];

        $this->assertEquals($expected, $metadata);
    }

    public function testGetMetadataForDir(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/directory/');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'application/octet-stream',
                'size' => 0,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/directory')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $metadata = $adapter->getMetadata('directory');

        $expected = [
            'type' => 'dir',
            'dirname' => '',
            'path' => 'directory',
            'timestamp' => 1474901082,
            'mimetype' => 'application/octet-stream',
            'size' => 0,
        ];

        $this->assertEquals($expected, $metadata);
    }

    public function testGetSize(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/file.txt');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $metadata = $adapter->getMetadata('file.txt');

        $this->assertArrayHasKey('size', $metadata);
        $this->assertEquals(5, $metadata['size']);
    }

    public function testGetMimetype(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/file.txt');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $metadata = $adapter->getMetadata('file.txt');

        $this->assertArrayHasKey('mimetype', $metadata);
        $this->assertEquals('text/plain', $metadata['mimetype']);
    }

    public function testGetTimestamp(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('name')
            ->willReturn('prefix/file.txt');
        $storageObject
            ->expects($this->once())
            ->method('info')
            ->willReturn([
                'updated' => '2016-09-26T14:44:42+00:00',
                'contentType' => 'text/plain',
                'size' => 5,
            ]);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $metadata = $adapter->getMetadata('file.txt');

        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertEquals(1474901082, $metadata['timestamp']);
    }

    public function testGetVisibilityWhenVisibilityIsPrivate(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $storageObjectAcl = $this->createMock(Acl::class);
        $storageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_OWNER,
            ]);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($storageObjectAcl);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $attributes = $adapter->visibility('file.txt');
        $this->assertEquals(Visibility::PRIVATE, $attributes->visibility());
    }

    public function testGetVisibilityWhenVisibilityIsPublic(): void
    {
        $bucket = $this->createMock(Bucket::class);

        $storageObjectAcl = $this->createMock(Acl::class);
        $storageObjectAcl
            ->expects($this->once())
            ->method('get')
            ->with(['entity' => 'allUsers'])
            ->willReturn([
                'role' => Acl::ROLE_READER,
            ]);

        $storageObject = $this->createMock(StorageObject::class);
        $storageObject
            ->expects($this->once())
            ->method('acl')
            ->willReturn($storageObjectAcl);

        $bucket
            ->expects($this->once())
            ->method('object')
            ->with('prefix/file.txt')
            ->willReturn($storageObject);

        $storageClient = $this->createMock(StorageClient::class);

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, 'prefix');

        $attributes = $adapter->visibility('file.txt');
        $this->assertEquals(Visibility::PUBLIC, $attributes->visibility());
    }

    public function testSetGetStorageApiUri(): void
    {
        $storageClient = $this->createMock(StorageClient::class);
        $bucket = $this->createMock(Bucket::class);
        $adapter = new GoogleStorageAdapter($storageClient, $bucket);

        $this->assertEquals('https://storage.googleapis.com', $adapter->getStorageApiUri());

        $adapter->setStorageApiUri('http://my.custom.domain.com');
        $this->assertEquals('http://my.custom.domain.com', $adapter->getStorageApiUri());

        $adapter = new GoogleStorageAdapter($storageClient, $bucket, null, 'http://this.is.my.base.com');
        $this->assertEquals('http://this.is.my.base.com', $adapter->getStorageApiUri());
    }

    public function testGetUrl(): void
    {
        $storageClient = $this->createMock(StorageClient::class);

        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->exactly(3))
            ->method('name')
            ->willReturn('my-bucket');

        $adapter = new GoogleStorageAdapter($storageClient, $bucket);
        $this->assertEquals('https://storage.googleapis.com/my-bucket/file.txt', $adapter->getUrl('file.txt'));
        $this->assertEquals('https://storage.googleapis.com/my-bucket/test%20folder/file%281%29.txt', $adapter->getUrl('test folder/file(1).txt'));

        $adapter->setPathPrefix('prefix');
        $this->assertEquals('https://storage.googleapis.com/my-bucket/prefix/file.txt', $adapter->getUrl('file.txt'));

        $adapter->setStorageApiUri('http://my-domain.com/');
        $adapter->setPathPrefix('another-prefix');
        // no bucket name on custom domain
        $this->assertEquals('http://my-domain.com/another-prefix/dir/file.txt', $adapter->getUrl('dir/file.txt'));
    }

    public function getDataForTestDeleteDirectory(): iterable
    {
        yield ['dir_name'];
        yield ['dir_name//'];
    }

    public function getDataForTestWriteContent(): iterable
    {
        $contents = 'This is the file contents.';
        $expected = [
            'type' => 'file',
            'dirname' => '',
            'path' => 'file1.txt',
            'timestamp' => 1474901082,
            'mimetype' => 'text/plain',
            'size' => 5,
        ];

        yield [$expected, $contents, 'projectPrivate', null];
        yield [$expected, $contents, 'projectPrivate', Visibility::PRIVATE];
        yield [$expected, $contents, 'publicRead', Visibility::PUBLIC];
    }
}
