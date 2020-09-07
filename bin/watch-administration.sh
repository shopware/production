#!/usr/bin/env bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$CWD")"}"
export ENV_FILE=${ENV_FILE:-"${PROJECT_ROOT}/.env"}

source "${ENV_FILE}"
export HOST=${HOST:-"localhost"}
export ESLINT_DISABLE
export PORT
export APP_URL

bin/console feature:dump || true

npm run --prefix vendor/shopware/administration/Resources/app/administration/ dev
