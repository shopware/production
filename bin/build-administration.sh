#!/bin/bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $CWD)"}"
ADMIN_ROOT="${ADMIN_ROOT:-"${PROJECT_ROOT}/vendor/shopware/administration"}"

# build admin
npm clean-install --prefix ${ADMIN_ROOT}/Resources
npm run --prefix ${ADMIN_ROOT}/Resources lerna -- bootstrap
npm run --prefix ${ADMIN_ROOT}/Resources/app/administration/ build
