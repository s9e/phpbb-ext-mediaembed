#!/bin/bash
git submodule update --remote

tmpdir="/tmp"
version="$(date -u +%Y%m%d)$1"
releasename="s9e-mediaembed-$version"

rootdir="$(realpath $(dirname $(dirname $0)))"
cd "$rootdir"

rm -rf "$tmpdir/s9e"
mkdir -p "$tmpdir/s9e/mediaembed/config"
php scripts/generateFiles.php
sed -i "s/\"version\": *\"[^\"]*/\"version\": \"$version/" composer.json
php scripts/generateVersionCheck.php

files="
	LICENSE
	README.md
	bundle.php
	composer.json
	config/services.yml
	listener.php
	parsing.php
	rendering.php
";
for file in $files;
do
	cp "$file" "$tmpdir/s9e/mediaembed/$file"
done

cd "$tmpdir"
kzip -r -y "$rootdir/releases/$releasename.zip" s9e
advzip -z4 "$rootdir/releases/$releasename.zip"

rm -rf "$tmpdir/s9e"