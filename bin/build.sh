#!/bin/bash

set -e
set -x

PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $(dirname $(readlink -f "$0")))"}"
ADMIN_ROOT="${PROJECT_ROOT}/vendor/shopware/administration"
STOREFRONT_ROOT="${PROJECT_ROOT}/vendor/shopware/storefront"

composer install -d ${PROJECT_ROOT} --no-interaction --optimize-autoloader --no-suggest

# build admin
npm clean-install --prefix ${ADMIN_ROOT}/Resources
npm run --prefix ${ADMIN_ROOT}/Resources lerna -- bootstrap
npm run --prefix ${ADMIN_ROOT}/Resources/app/administration/ build

# build storefront
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront clean-install
node ${STOREFRONT_ROOT}/Resources/app/storefront/copy-to-vendor.js
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront run production

set +x

# clean up

composer clearcache
npm cache clean --force

rm -Rf vendor/shopware/{core,administration,storefront,elasticsearch}/.git
rm -Rf vendor/shopware/administration/Resources/{,app/administration/,common/webpack-plugin-injector/}/node_modules
rm -Rf vendor/shopware/storefront/Resources/app/storefront/node_modules
