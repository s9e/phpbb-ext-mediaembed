#!/bin/bash
tmpdir="/tmp"
extdir="$tmpdir/ext"
releasename="s9e-mediaembed-$(date -u +%Y%m%d)"
dir="$extdir/s9e/mediaembed"

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

rootdir="$(realpath $(dirname $(dirname $0)))"
cd "$rootdir"

rm -rf "$extdir"
for file in $files;
do
	targetdir="$dir/$(dirname $file)"
	mkdir -p "$targetdir"
	cp "$file" "$targetdir"
done

cd "$tmpdir"
kzip -r -y "$rootdir/releases/$releasename" ext

rm -rf "$extdir"