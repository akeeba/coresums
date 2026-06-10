# Core Sums

Generate checksums of core CMS files for Joomla! and WordPress

This application locates available versions of Joomla! and WordPress, downloads their installation archive files, and computes the MD5, SHA-1, SHA-256, and SHA-512 checksums of the files container there. The checksums are calculated both on the original file, and on a file whose repeating whitespace has been squashed to a single space (so that the always safe modifications UNIX / Windows line ending conversions, space / tab conversions, and adding / removing blank lines can be ignored).

Kindly note that this application is already running on our server and automatically updates publicly available checksum files. If you just want to use the checksums for your own purpose, please read the Published Checksums section below.

There are still a few good reasons this application is available under a FOSS license.

**Transparency**. You can examine and audit our code deriving core CMS file checksums. 

**Reproducibility**. You can run this application yourself and compare the generated checksums with those we ship to make sure they have not been tampered with.

**Self-hosting**. You can host this code and the compiled checksums yourself, on your own server, and use it with your own self-hosted installation of Panopticon.

## Quick Start

> [!WARNING]
> The first run of this application will download all Joomla! and WordPress versions ever published to process their files' checksums. This downloads several Gigabytes of data from the network and takes several hours to complete.

Check out this repository and run: 

```bash
composer install
php ./coresums.php init
php ./coresums.php sources --process
php ./coresums.php dump --sources
```

## Testing

The test suite uses PHPUnit 11 and targets PHP 8.4+. Install dev dependencies and run:

```bash
composer install
vendor/bin/phpunit
```

You can also use the Composer script alias:

```bash
composer test
```

### Test layout

Tests live under `tests/` and are split into two suites declared in `phpunit.xml.dist`:

- `tests/Unit/` — fast, isolated tests of pure helpers (CmsNamesTrait, `Generate::squashContents`, the file-extension allowlist, `getDownloadURL`, container providers, the HTTP factory's cache middleware, …).
- `tests/Integration/` — tests that exercise commands end-to-end against a temp SQLite database (Init, Dump, Versions, Sources, and a golden-fixtures pipeline test for Generate).

Run a single suite:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

Run a single test class or filter:

```bash
vendor/bin/phpunit --filter GeneratePipeline
```

### Fixtures

Test fixtures live in `tests/fixtures/`. The Generate pipeline test builds its archive fixtures programmatically the first time it runs (via `tests/fixtures/build-fixtures.php`); the generated archives are gitignored.

### Notes

- The suite must run **offline**. All HTTP is stubbed with `GuzzleHttp\Handler\MockHandler`, and GitHub API calls are mocked. Do not introduce tests that hit the network.
- `failOnWarning` and `failOnRisky` are enabled. `failOnDeprecation` is intentionally disabled because some upstream dependencies emit deprecation notices under newer PHP runtimes; these are not bugs in this project.
- When inserting rows via `Joomla\Database\DatabaseDriver::insertObject`, the second argument is passed by reference — always assign your object to a variable first.

## Published Checksums and Download URLs

The precompiled checksums and download URLs for each CMS version can always be found on [the Panopticon site](https://getpanopticon.com/checksums/index.html).

### Public checksum endpoints

The endpoint URL for the checksums is `https://getpanopticon.com/checksums/cms/version/checksum_type.json.gz`

The `cms` parameter is either `joomla` or `wordpress`.

The version parameter is the CMS version, e.g. `5.0.0`.

The checksum_type is one of `md5`, `sha1`, `sha256`, `sha512`, `md5_squash`, `sha1_squash`, `sha256_squash`, or `sha512_squash`. The `*_squash` versions have the corresponding checksum of the “squashed” version of the file. You can get the squashed version of a file using this function:

```php
$squashed = preg_replace('#[\n\r\t\s\v]+#ms', ' ', $contents);
```

“Squashing” the contents of a PHP or JavaScript file ensures that the checksum will not change when a safe change takes place on the file: conversion between UNIX (LF) and Windows (CRLF) line endings, conversion between tabs and spaces, added/removed whitespace, added/removed blank lines.

If the `cms`, `version`, or `checksum` type you are looking for does not exist you will receive an HTTP 404 Not Found response.

The returned file is a GZip–encoded JSON file. You can convert its contents to a PHP array with:

```php
$array = json_decode(gzdecode($fileContents), true);
```

The result is a string-indexed array where the key is the filename relative to the site's root, and the value is the checksum.

**Only PHP, JavaScript, and INI files are included in the checksum results.**

### Public download URL endpoints

The endpoint `https://getpanopticon.com/checksums/sources.json.gz` serves a GZip-compressed JSON file with the download URLs of every Joomla! and WordPress version published to date. The format is as follows:

```json
[
    {
        "cms":"joomla",
        "version":"1.0.0",
        "url":"https:\/\/downloads.joomla.org\/cms\/joomla10\/1-0-0\/joomla_1-0-0-stable-tar-gz?format=gz"
    },
    ⋮
]
```
> [!IMPORTANT]
> You must check the value of the `cms` key. This file contains download URLs for both Joomla! and WordPress.

### Why not use WordPress' checksums endpoint?

WordPress offers an API endpoint with core file checksums. It is URLs similar to this: `https://api.wordpress.org/core/checksums/1.0/?version=6.5.3&locale=en_US`. This endpoint returns a JSON file with MD5 sums for every core file for that version/locale combination. It is what WP-CLI's `wp core verify-checksums` uses under the hood.

While this is a very good feature to have, it is also not great. We say this with the utmost respect to the WordPress developers; what they came up with is fine for more than 90% of their users, but there are objective issues with their design choices, which could be a problem for the other 10% of users who are running something more involved than a small blog or niche web shop.

The endpoint only provides MD5 checksums. MD5 is a weak hashing algorithm. Both MD5 and the slightly newer SHA-1 hashing algorithms have been “broken”, i.e. an attacker can conceivably generate content which results in the same MD5 / SHA-1 checksum even though the contents are significantly different. While this has not been seen in a real-world attack just yet, it's firmly within the realm of practical possibility.

The other problem is that these checksums assume perfect binary copies of the files are installed on your site. If you are transferring site files over FTP using Auto mode (default setting in FileZilla) using a Windows computer it will automatically convert line endings between CRLF and LF. While functionally identical, the uploaded files will have a drastically different MD5 sum.

The same thing applies if you open a file with a code editor such as Notepad++ and save the file without making any changes to the actual code. The encoding of the file (ASCII, UTF-8, UTF-16, ...) or the whitespace (tabs vs spaces) may change. Again, while functionally identical, the saved file will have a drastically different MD5 sum.

The former produces false negatives: malicious files may be reported as being intact. The latter produces false positives: benign files may be reported as being tampered with. In both cases, users are in danger, either because malicious files are not caught or because the deluge of false positives desensitises them to the warnings.

Our checksums avoid this pitfall in two ways. First, we produce checksums also using the more robust SHA-256 (used by default by our software) and SHA-512 hashing algorithms. Second, we also produce checksums for the “squashed” versions of files which work around the binary-different-but-functionally-identical pitfall. These choices prevent both false negatives and false positives, giving you a higher level of assurance that any files reported as tampered with are actually tampered with, and files reported intact are actually intact.

## License

Core Sums — Generate checksums of core CMS files for Joomla!

Copyright (C) 2023-2026  Akeeba Ltd

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or(at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a [copy of the GNU Affero General Public License](LICENSE.md) along with this program.  If not, see <https://www.gnu.org/licenses/>.