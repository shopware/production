#!/bin/bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $CWD)"}"
export ENV_FILE=${ENV_FILE:-"${PROJECT_ROOT}/.env"}

source ${ENV_FILE}
export APP_URL
export STOREFRONT_PROXY_PORT
export ESLINT_DISABLE

${CWD}/console theme:dump
npm --prefix vendor/shopware/storefront/Resources/app/storefront/ run-script hot-proxy