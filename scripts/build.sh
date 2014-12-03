#!/bin/bash
git submodule update --remote

tmpdir="/tmp"
extdir="$tmpdir/s9e"
version="$(date -u +%Y%m%d)$1"
releasename="s9e-mediaembed-$version"
dir="$extdir/mediaembed"

rootdir="$(realpath $(dirname $(dirname $0)))"
cd "$rootdir"

sed -i "s/\"version\": *\"[^\"]*/\"version\": \"$version/" composer.json

files="
	LICENSE
	README.md
	composer.json
	config/services.yml
	event/subscriber.php
	ext.php
	vendor/s9e/TextFormatter/LICENSE
	vendor/s9e/TextFormatter/src/Bundle.php
	vendor/s9e/TextFormatter/src/Bundles/MediaPack.php
	vendor/s9e/TextFormatter/src/Bundles/MediaPack/Renderer.php
	vendor/s9e/TextFormatter/src/Parser.php
	vendor/s9e/TextFormatter/src/Parser/BuiltInFilters.php
	vendor/s9e/TextFormatter/src/Parser/Logger.php
	vendor/s9e/TextFormatter/src/Parser/Tag.php
	vendor/s9e/TextFormatter/src/Plugins/MediaEmbed/Parser.php
	vendor/s9e/TextFormatter/src/Plugins/ParserBase.php
	vendor/s9e/TextFormatter/src/Renderer.php
	vendor/s9e/TextFormatter/src/Unparser.php
	vendor/s9e/TextFormatter/src/autoloader.php
";

rm -rf "$extdir"
for file in $files;
do
	targetdir="$dir/$(dirname $file)"
	mkdir -p "$targetdir"
	cp "$file" "$targetdir"
done

cd "$tmpdir"
kzip -r -y "$rootdir/releases/$releasename.zip" s9e
advzip -z4 "$rootdir/releases/$releasename.zip"

rm -rf "$extdir"