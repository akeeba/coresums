# Core Sums

Generate checksums of core CMS files for Joomla!

This application locates available versions of Joomla!, downloads their installation archive files, and computes the MD5, SHA-1, SHA-256, and SHA-512 checksums of the files container there. The checksums are calculated both on the original file, and on a file whose repeating whitespace has been squashed to a single space (so that the always safe modifications UNIX / Windows line ending conversions, space / tab conversions, and adding / removing blank lines can be ignored).

Kindly note that this application is already running on our server and automatically updates publicly available checksum files. If you just want to use the checksums for your own purpose please read the Published Checksums section below.

The purpose of this application is to provide the _publicly verifiable source code_ of the software which produces the checksum files. As a result, the checksums can be reproduced by anyone, proving that the published checksum files are legitimate. Moreover, the code can be audited by anyone interested. We hate black boxes. We stand for transparency.

## Quick Start

**WARNING!** The instructions below will try to download all Joomla! versions ever published and process their files' checksums. This takes a few hours and downloads well over 7 GiB of data from the network. **You don't need to do that if you just need access to the checksums; read the Published Checksums section below.**

Check out this repository and run: 

```bash
composer install
php ./coresums.php init
php ./coresums.php sources --process
php ./coresums.php dump --sources
```

## Published Checksums

TO-DO

## License

Core Sums — Generate checksums of core CMS files for Joomla!

Copyright (C) 2023  Akeeba Ltd

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or(at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a [copy of the GNU Affero General Public License](LICENSE.md) along with this program.  If not, see <https://www.gnu.org/licenses/>.