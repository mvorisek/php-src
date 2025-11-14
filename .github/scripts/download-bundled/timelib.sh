#!/bin/sh
set -ex
cd "$(dirname "$0")/../../.."

tmp_dir=/tmp/php-src-download-bundled/timelib
rm -rf "$tmp_dir"

revision=refs/tags/2022.16

git clone --depth 1 --revision="$revision" https://github.com/derickr/timelib.git "$tmp_dir"

rm -rf ext/date/lib
cp -R "$tmp_dir" ext/date/lib

cd ext/date/lib

# clone timezonedb.h versioned independently
git fetch origin master
git restore -s f89fd25403f4e5e4c7548af39b3b0676c60b3793 -- timezonedb.h

# remove unneeded files
rm -r docs
rm -r tests
rm -r zones
rm gettzmapping.php
rm parse_zoneinfo.c
rm win_dirent.h
