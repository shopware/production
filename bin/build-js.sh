#!/bin/bash

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $(dirname $(readlink -f "$0")))"}"
ADMIN_ROOT="${PROJECT_ROOT}/vendor/shopware/administration"
STOREFRONT_ROOT="${PROJECT_ROOT}/vendor/shopware/storefront"

# build admin
npm clean-install --prefix ${ADMIN_ROOT}/Resources
npm run --prefix ${ADMIN_ROOT}/Resources lerna -- bootstrap
npm run --prefix ${ADMIN_ROOT}/Resources/app/administration/ build

# build storefront
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront clean-install
node ${STOREFRONT_ROOT}/Resources/app/storefront/copy-to-vendor.js
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront run production