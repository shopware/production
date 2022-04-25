#!/usr/bin/env bash

BIN_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

set -euo pipefail

"${BIN_DIR}/build-administration.sh"
"${BIN_DIR}/build-storefront.sh"
