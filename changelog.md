# Changelog

## 7.2.2 - 2019-10-10

* Generate rfc 3986 compliant urls
* Add support for temporary URLs (Signed)

## 7.2.1 - 2019-02-21

* Fix bug where recursive delete would not delete directories (Issue #96)

## 7.2.0 - 2019-02-07

* Delete now recursivley deletes all objects inside the directory before deleting the directory itself

## 7.1.0 - 2018-09-20

* Allow the use of any 1.* google/cloud-storage version

## 7.0.0 - 2018-02-07

* Move from google/cloud to google/cloud-storage package to simplify updating dependencies

## 6.0.0 - 2018-01-08

* Bump supported google/cloud versions
* fix: remove bucket name prepending when using a custom storage uri
* simplify google/cloud composer version pinning

## 5.0.3 - 2017-07-18

* Allow for google/cloud ^0.31.0|^0.32.0|^0.33.0|^0.34.0|^0.35.0

## 5.0.2 - 2017-07-04

* Fix broken move/rename operations (@maksimru)

## 5.0.1 - 2017-05-19

* Allow for google/cloud ^0.22.0|^0.23.0|^0.24.0|^0.25.0|^0.26.0|^0.27.0|^0.28.0|^0.29.0|^0.30.0

## 5.0.0 - 2017-04-03

* Fix to readStream incorrectly returning a `StreamInterface` instead of a `resource` (@andris-sevcenko)

## 4.0.2 - 2017-02-10

* Allow metadata to be passed as a config option for file uploads (@MikeyVelt)
* Fix 'undefined index' bug when objects come back with no contentType
* Remove path prefix, if any, from object dirname and path when normalising objects (@andris-sevcenko)

## 4.0.1 - 2017-01-03

* Allow for google/cloud ^0.20.0

## 4.0.0 - 2017-01-03

* Add support for reading from streams (implemented in google/cloud 0.10.2)

## 3.0.4 - 2017-01-03

* Allow for google/cloud ^0.10.0|^0.11.0|^0.12.0|^0.13.0
* Use https in generated urls to storage bucket objects (@jorisvaesen)

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
