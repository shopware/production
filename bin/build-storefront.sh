#!/bin/bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $CWD)"}"
STOREFRONT_ROOT="${STOREFRONT_ROOT:-"${PROJECT_ROOT}/vendor/shopware/storefront"}"

# build storefront
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront clean-install
node ${STOREFRONT_ROOT}/Resources/app/storefront/copy-to-vendor.js
npm --prefix ${STOREFRONT_ROOT}/Resources/app/storefront run production
