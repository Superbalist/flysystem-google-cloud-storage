# Changelog

## 3.0.3 - 2016-10-05

* Fix visibility setting not using fallback config (@cristianobaptista)

## 3.0.2 - 2016-09-27

* Allow for google/cloud ^0.9.0

## 3.0.1 - 2016-09-27

* Add `getUrl($path)` method for Laravel 5.3 Filesystem to hook into

## 3.0.0 - 2016-09-26

* Switch to google/cloud package
* Add support for Flysystem path prefix
* Add unit tests

## 2.0.0-RC1 - 2016-05-19

* Switch to 2.0.0RC of google/apiclient

## 1.0.4 - 2016-05-19

* Remove support for 2.0.0RC of google/apiclient - not backwards compatible
* Fix to incorrect classification of files vs folders (@paulcanning)

## 1.0.3 - 2016-04-12

* Fix to now return null instead of false when a file is empty (@paulcanning)

## 1.0.1 - 2016-03-29

* Change composer.json to support both v1.1 and 2.0.0RC of google/apiclient (@cedricziel)
* Fix to handling of private / public permissions (@cedricziel)

## 1.0.0 - 2015-10-16

* Initial release
