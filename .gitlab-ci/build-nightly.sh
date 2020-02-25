#!/bin/bash

export COMPOSE_PROJECT_NAME=sw6_ci

set -e
set -x

[[ -n ${TAG} ]]

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname $CWD)"}"

export ADMIN_ROOT=repos/administration/
export STOREFRONT_ROOT=repos/storefront/

cd $PROJECT_ROOT
cp ${CWD}/plugins.json var/plugins.json

composer install --ignore-platform-reqs --no-interaction

${PROJECT_ROOT}/bin/build-js.sh

find ${ADMIN_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;
find ${STOREFRONT_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;

COMMIT_MSG=${COMMIT_MSG:-"Release $TAG"}

prepare_repo() {
    APP_PATH=repos/${1}/Resources/app/${1}
    if [[ -f "$APP_PATH/package.json" ]]; then
        npm --prefix ${APP_PATH} version --no-git-tag-version ${TAG}
    fi

    git -C repos/${1} add .
    git -C repos/${1} commit -m  "${COMMIT_MSG}" || true

    git -C repos/${1} tag -d ${TAG} || true
    git -C repos/${1} tag ${TAG} -a -m "${COMMIT_MSG}"
    git -C repos/${1} checkout ${TAG}
}

cd ${PROJECT_ROOT}

prepare_repo "core"
prepare_repo "recovery"
prepare_repo "elasticsearch"

sed -i -E '/[/]?public([/]?|.*)/d' ${ADMIN_ROOT}/Resources/.gitignore
prepare_repo "administration"

sed -i -E '/[/]?Resources[/]app[/]storefront[/]vendor([/]?|.*)/d' ${STOREFRONT_ROOT}/.gitignore
sed -i -E '/[/]?app[/]storefront[/]dist([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
sed -i -E '/[/]?public([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
prepare_repo "storefront"


jq -s add composer.json ${CWD}/composer.nightly_override.json > composer.json.new
mv composer.json.new composer.json

rm -Rf composer.lock vendor/shopware/* vendor/autoload.php
composer install --ignore-platform-reqs --no-interaction

