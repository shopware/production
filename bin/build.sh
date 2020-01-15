#!/bin/bash

set -e
set -x

BIN_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$BIN_DIR")"}"

composer install -d ${PROJECT_ROOT} --no-interaction --optimize-autoloader --no-suggest
composer install -d ${PROJECT_ROOT}/vendor/shopware/recovery --no-interaction --optimize-autoloader --no-suggest

"${BIN_DIR}/build-js.sh"

set +x
