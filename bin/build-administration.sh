#!/usr/bin/env bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

set -e

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$CWD")"}"
ADMIN_ROOT="${ADMIN_ROOT:-"${PROJECT_ROOT}/vendor/shopware/administration"}"

# build admin
[[ ${CI} ]] || "${CWD}/console" bundle:dump
(cd "${ADMIN_ROOT}"/Resources/app/administration && npm clean-install && npm run build)
[[ ${CI} ]] || "${CWD}/console" asset:install
