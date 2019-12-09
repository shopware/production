#!/bin/bash

set -e
set -x

BIN_DIR="$(dirname $(readlink -f "$0"))"
export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$BIN_DIR")"}"

composer install -d ${PROJECT_ROOT} --no-interaction --optimize-autoloader --no-suggest
composer install -d ${PROJECT_ROOT}/vendor/shopware/recovery --no-interaction --optimize-autoloader --no-suggest

$SHELL "$BIN_DIR/build-js.sh"

set +x

# clean up

composer clearcache
npm cache clean --force

rm -Rf vendor/shopware/{core,administration,storefront,elasticsearch}/.git
rm -Rf vendor/shopware/administration/Resources/{,app/administration/,app/common/webpack-plugin-injector/}/node_modules
rm -Rf vendor/shopware/storefront/Resources/app/storefront/node_modules
