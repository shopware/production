#!/usr/bin/env bash

export COMPOSE_PROJECT_NAME=sw6_ci

set -e
set -x

[[ -n ${TAG} ]]

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$CWD")"}"

export ADMIN_ROOT=repos/administration/
export STOREFRONT_ROOT=repos/storefront/

cd "$PROJECT_ROOT"
cp "${CWD}"/plugins.json var/plugins.json

composer install --no-interaction --no-scripts

"${PROJECT_ROOT}"/bin/build-js.sh

find ${ADMIN_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;
find ${STOREFRONT_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;

COMMIT_MSG=${COMMIT_MSG:-"Release $TAG"}

prepare_repo() {
    # TODO: NPM does not support 4 digit version numbers
    # APP_PATH=repos/${1}/Resources/app/${1}
    #if [[ -f "$APP_PATH/package.json" ]]; then
        # npm --prefix ${APP_PATH} version --no-git-tag-version ${TAG}
    #fi

    if [[ "${1}" != "core" ]]; then
        composer require "shopware/core:${TAG}" -d "repos/${1}" --no-update --no-install
    fi

    git -C "repos/${1}" add .
    git -C "repos/${1}" commit -m  "${COMMIT_MSG}" || true

    git -C "repos/${1}" tag -d "${TAG}" || true
    git -C "repos/${1}" tag "${TAG}" -a -m "${COMMIT_MSG}"
    git -C "repos/${1}" checkout "${TAG}"
}

cd "${PROJECT_ROOT}"

prepare_repo "core"
prepare_repo "recovery"
prepare_repo "elasticsearch"

sed -i -E '/[/]?public([/]?|.*)/d' ${ADMIN_ROOT}/Resources/.gitignore
prepare_repo "administration"

ADMIN_CHECK_FILES="\
${ADMIN_ROOT}/Resources/public/static/js/app.js
${ADMIN_ROOT}/Resources/public/static/js/commons.js
${ADMIN_ROOT}/Resources/public/static/js/runtime.js
${ADMIN_ROOT}/Resources/public/static/js/vendors-node.js
${ADMIN_ROOT}/Resources/public/static/css/app.css
${ADMIN_ROOT}/Resources/public/static/css/vendors-node.css
${STOREFRONT_ROOT}/Resources/public/administration/js/storefront.js
${STOREFRONT_ROOT}/Resources/public/administration/css/storefront.css
"

for CHECK_FILE in $ADMIN_CHECK_FILES; do
    if [[ ! -r $CHECK_FILE ]]; then
        echo "Build result $CHECK_FILE not found!"
        exit 1
    fi
done

sed -i -E '/[/]?Resources[/]app[/]storefront[/]vendor([/]?|.*)/d' ${STOREFRONT_ROOT}/.gitignore
sed -i -E '/[/]?app[/]storefront[/]dist([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
sed -i -E '/[/]?public([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
prepare_repo "storefront"

STOREFRONT_CHECK_FILES="\
${STOREFRONT_ROOT}/Resources/app/storefront/dist/js/runtime.js
${STOREFRONT_ROOT}/Resources/app/storefront/dist/js/vendor-node.js
${STOREFRONT_ROOT}/Resources/app/storefront/dist/js/vendor-shared.js
${STOREFRONT_ROOT}/Resources/app/storefront/dist/storefront/js/storefront.js
${STOREFRONT_ROOT}/Resources/public/administration/js/storefront.js
${STOREFRONT_ROOT}/Resources/public/administration/css/storefront.css
"

for CHECK_FILE in $STOREFRONT_CHECK_FILES; do
    if [[ ! -r $CHECK_FILE ]]; then
        echo "Build result $CHECK_FILE not found!"
        exit 1
    fi
done

jq -s add composer.json "${CWD}"/composer.nightly_override.json > composer.json.new
mv composer.json.new composer.json

rm -Rf composer.lock vendor/shopware/* vendor/autoload.php
composer install --no-interaction

SPLIT_REPOS=${SPLIT_REPOS:-"Administration Storefront Core Elasticsearch Recovery"}

for pkg in $SPLIT_REPOS; do
    composer require "shopware/${pkg,,}:${TAG}" --no-scripts
done
