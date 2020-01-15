#!/bin/bash

BIN_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

"${BIN_DIR}/build-administration.sh"
"${BIN_DIR}/build-storefront.sh"
