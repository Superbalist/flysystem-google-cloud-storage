# Flysystem Adapter for Google Cloud Storage

## Installation

```bash
composer require superbalist/flysystem-google-storage
```

## Usage

```php
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter
use League\Flysystem\Filesystem;

$credentials = new \Google_Auth_AssertionCredentials(
    '[your service account]',
    [\Google_Service_Storage::DEVSTORAGE_FULL_CONTROL],
    file_get_contents('[[path to the p12 key file]]'),
    '[[your secret]]'
);

$client = new \Google_Client();
$client->setAssertionCredentials($credentials);
$client->setDeveloperKey('[[your developer key]]');

$service = new \Google_Service_Storage($client);

$adapter = new GoogleStorageAdapter($service, '[[your bucket name]]')

$filesystem = new Filesystem($adapter);
```

## TODO

* Unit tests to be written
* writeStream() to be implemented
* updateStream() to be implemented
* readStream() to be implemented