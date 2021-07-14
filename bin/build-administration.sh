#!/usr/bin/env bash

CWD="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

set -e

export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$CWD")"}"
ADMIN_ROOT="${ADMIN_ROOT:-"${PROJECT_ROOT}/vendor/shopware/administration"}"

# build admin
[[ ${CI} ]] || "${CWD}/console" bundle:dump

if [[ `command -v jq` ]]; then
    OLDPWD=$(pwd)
    cd $PROJECT_ROOT

    jq -c '.[]' "var/plugins.json" | while read config; do
        srcPath=$(echo $config | jq -r '(.basePath + .administration.path)')

        # the package.json files are always one upper
        path=$(dirname $srcPath)
        name=$(echo $config | jq -r '.technicalName' )

        if [[ -f "$path/package.json" && ! -f "$path/node_modules" && $name != "administration" ]]; then
            echo "=> Installing npm dependencies for ${name}"

            npm install --prefix "$path"
        fi
    done
    cd "$OLDPWD"
else
    echo "Cannot check extensions for required npm installations as jq is not installed"
fi

(cd "${ADMIN_ROOT}"/Resources/app/administration && npm clean-install && npm run build)
[[ ${CI} ]] || "${CWD}/console" asset:install
