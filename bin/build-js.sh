#!/usr/bin/env bash

BIN_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

set -e

"${BIN_DIR}/build-administration.sh"
"${BIN_DIR}/build-storefront.sh"
