# Flysystem Adapter for Google Cloud Storage

[![Author](http://img.shields.io/badge/author-@superbalist-blue.svg?style=flat-square)](https://twitter.com/superbalist)
[![Build Status](https://img.shields.io/travis/Superbalist/flysystem-google-storage/master.svg?style=flat-square)](https://travis-ci.org/Superbalist/flysystem-google-storage)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/superbalist/flysystem-google-storage.svg?style=flat-square)](https://packagist.org/packages/superbalist/flysystem-google-storage)
[![Total Downloads](https://img.shields.io/packagist/dt/superbalist/flysystem-google-storage.svg?style=flat-square)](https://packagist.org/packages/superbalist/flysystem-google-storage)


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