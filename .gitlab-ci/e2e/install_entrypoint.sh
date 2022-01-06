#!/bin/bash

SCRIPT_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DATA_DIR=${SCRIPT_DIR}/data

set -x
set -e

#E2E_INSTALL_PACKAGE_URL=https://releases.shopware.com/sw6/install_6.1.6_1589441426.zip
E2E_INSTALL_PACKAGE_FILE=$(basename "${E2E_INSTALL_PACKAGE_URL}")

if [[ -n ${E2E_INSTALL_PACKAGE_USE_LATEST:-""} ]]; then
    major="$(echo "$REFERENCE_INSTALLER_URL" | sed -n -e 's/.*install_v\([0-9]\.[0-9]\).*/\1/p')"
    major="${major:-6}"

    latest="$(php "${SCRIPT_DIR}/find_latest_release.php" "$major")"

    if [[ -z "${latest}" ]]; then
        echo "Latest release matching major ${major} not found"
        exit 1
    fi

    echo "Changed package url to ${latest}"
    E2E_INSTALL_PACKAGE_URL="${latest}"
fi

if [[ -z ${E2E_INSTALL_PACKAGE_URL} ]]; then
    echo "please define the variable E2E_INSTALL_PACKAGE_URL"
    exit 1
fi

if [[ ! -e ${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE} ]]; then
    gosu application curl "${E2E_INSTALL_PACKAGE_URL}" --silent --output "${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE}"
fi


cd /app

extractAndDeleteArchive() {
    cp "$1" archive
    if [[ ${1: -4} == ".zip" ]]; then
        unzip -qqo archive
    else
        tar -xf archive
    fi
    rm archive
}

extractAndDeleteArchive "${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE}"

chown application:application -R /app

HTTP_PORT=${HTTP_PORT:-8009}

if ! grep -z "Listen $HTTP_PORT" /etc/apache2/ports.conf > /dev/null; then
    echo "Listen $HTTP_PORT" >> /etc/apache2/ports.conf
fi

/entrypoint supervisord
