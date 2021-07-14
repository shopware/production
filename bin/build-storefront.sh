#!/usr/bin/env bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$CWD")"}"
STOREFRONT_ROOT="${STOREFRONT_ROOT:-"${PROJECT_ROOT}/vendor/shopware/storefront"}"

# build storefront
[[ ${CI} ]] || "${CWD}/console" bundle:dump

if [[ `command -v jq` ]]; then
    OLDPWD=$(pwd)
    cd $PROJECT_ROOT

    jq -c '.[]' "var/plugins.json" | while read config; do
        srcPath=$(echo $config | jq -r '(.basePath + .storefront.path)')

        # the package.json files are always one upper
        path=$(dirname $srcPath)
        name=$(echo $config | jq -r '.technicalName' )

        if [[ -f "$packageJsonPath/package.json" && ! -f "$packageJsonPath/node_modules" && $name != "storefront" ]]; then
            echo "=> Installing npm dependencies for ${name}"

            npm install --prefix "$packageJsonPath"
        fi
    done
    cd "$OLDPWD"
else
    echo "Cannot check extensions for required npm installations as jq is not installed"
fi


npm --prefix "${STOREFRONT_ROOT}"/Resources/app/storefront clean-install
node "${STOREFRONT_ROOT}"/Resources/app/storefront/copy-to-vendor.js
npm --prefix "${STOREFRONT_ROOT}"/Resources/app/storefront run production
[[ ${CI} ]] || "${CWD}/console" asset:install
[[ ${CI} ]] || "${CWD}/console" theme:compile
