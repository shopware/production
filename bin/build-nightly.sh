#!/bin/bash

export COMPOSE_PROJECT_NAME=sw6_ci

set -e
set -x

[[ -n ${TAG} ]]

cp .gitlab-ci/plugins.json var/plugins.json

IMAGE_NAME=${IMAGE_NAME:-"gitlab.shopware.com:5005/shopware/6/product/production"}

export ADMIN_ROOT=repos/administration/
export STOREFRONT_ROOT=repos/storefront/

$(dirname ${BASH_SOURCE[0]})/build-js.sh

find ${ADMIN_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;
find ${STOREFRONT_ROOT} -name 'node_modules' -type d -prune -print -exec rm -rf '{}' \;

COMMIT_MSG=${COMMIT_MSG:-"Nightly Release $TAG"}

prepare_repo() {
    git -C repos/${1} add .
    git -C repos/${1} commit -m  "${COMMIT_MSG}" || true
    git -C repos/${1} tag -d ${TAG} || true
    git -C repos/${1} tag ${TAG} -a -m "${COMMIT_MSG}"
    git -C repos/${1} checkout ${TAG}
}

prepare_repo "core"
prepare_repo "recovery"
prepare_repo "elasticsearch"

sed -i -E '/[/]?public([/]?|.*)/d' ${ADMIN_ROOT}/Resources/.gitignore
prepare_repo "administration"

sed -i -E '/[/]?Resources[/]app[/]storefront[/]vendor([/]?|.*)/d' ${STOREFRONT_ROOT}/.gitignore
sed -i -E '/[/]?app[/]storefront[/]dist([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
sed -i -E '/[/]?public([/]?|.*)/d' ${STOREFRONT_ROOT}/Resources/.gitignore
prepare_repo "storefront"


jq -s add composer.json .gitlab-ci/composer.nightly_override.json > composer.json.new
mv composer.json.new composer.json

rm -Rf composer.lock vendor/shopware/* vendor/autoload.php
composer install --ignore-platform-reqs --no-interaction

PLATFORM_COMMIT_SHA=${PLATFORM_COMMIT_SHA:-$(cat vendor/shopware/core/PLATFORM_COMMIT_SHA)}

docker build . -t "${IMAGE_NAME}:${PLATFORM_COMMIT_SHA}"
docker push "${IMAGE_NAME}:${PLATFORM_COMMIT_SHA}"

docker tag "${IMAGE_NAME}:${PLATFORM_COMMIT_SHA}" "${IMAGE_NAME}:${TAG}"
docker push "${IMAGE_NAME}:${TAG}"
