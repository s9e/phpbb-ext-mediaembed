#!/bin/bash

echo $(dirname $(dirname $(dirname "$0")))

git clone --branch=$PHPBB_BRANCH --depth=1 "git://github.com/phpbb/phpbb.git"
mkdir phpbb/phpBB/ext/s9e
ln -s $(pwd -P) phpbb/phpBB/ext/s9e/mediaembed
cd phpbb
cp -pr travis phpBB/ext/s9e/mediaembed/

travis/setup-database.sh "$DB" "$TRAVIS_PHP_VERSION"
travis/setup-phpbb.sh "$DB" "$TRAVIS_PHP_VERSION"
