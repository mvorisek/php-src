#!/bin/sh
set -ex
cd "$(dirname "$0")/../../.."

tmp_dir=/tmp/php-src-download-bundled/file-libmagic
rm -rf "$tmp_dir"

version_major=5
version_minor=46

revision=refs/tags/FILE"$version_major"_"$version_minor"

git clone --depth 1 --recurse-submodules --revision="$revision" https://github.com/file/file.git "$tmp_dir"

rm -rf ext/fileinfo/libmagic
cp -R "$tmp_dir"/src ext/fileinfo/libmagic

cd ext/fileinfo/libmagic

# remove unneeded files
rm .cvsignore
rm BNF
rm Makefile.am
rm asctime_r.c
rm asprintf.c
rm cdf.mk
rm ctime_r.c
rm dprintf.c
rm elfclass.h
rm file.c
rm file_opts.h
rm fmtcheck.c
rm getline.c
rm getopt_long.c
rm gmtime_r.c
rm localtime_r.c
rm memtest.c
rm mygetopt.h
rm pread.c
rm readelf.c
rm readelf.h
rm seccomp.c
rm strlcat.c
rm strlcpy.c
rm vasprintf.c

# move renamed files
mv magic.h.in magic.h

# add extra files
git restore LICENSE
git restore config.h

# patch customized files
sed -E "s/(#define\s*MAGIC_VERSION\s*)X\.YY/\1$version_major$version_minor/g" -i magic.h
git apply -v ../libmagic.patch
