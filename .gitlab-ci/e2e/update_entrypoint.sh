#!/bin/bash

SCRIPT_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DATA_DIR=${SCRIPT_DIR}/data

set -x
set -e

#E2E_INSTALL_PACKAGE_URL=https://releases.shopware.com/sw6/install_v6.1.6-dev.tar.xz
E2E_INSTALL_PACKAGE_FILE=$(basename ${E2E_INSTALL_PACKAGE_URL})
# E2E_TEST_DATA_FILE=v6.1.6_test_data.tar.xz
E2E_TEST_DATA_URL="${E2E_TEST_DATA_BASE_URL}/${E2E_TEST_DATA_FILE}"

if [[ -z ${E2E_INSTALL_PACKAGE_URL} || -z ${E2E_TEST_DATA_BASE_URL} || -z ${E2E_TEST_DATA_FILE} ]]; then
    echo "please define the variables E2E_INSTALL_PACKAGE_URL, E2E_TEST_DATA_BASE_URL and E2E_TEST_DATA_FILE"
    exit 1
fi

if [[ ! -e ${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE} ]]; then
    gosu application curl ${E2E_INSTALL_PACKAGE_URL} --silent --output ${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE}
fi

if [[ ! -e ${DATA_DIR}/${E2E_TEST_DATA_FILE} ]]; then
    gosu application curl ${E2E_TEST_DATA_URL} --silent --output ${DATA_DIR}/${E2E_TEST_DATA_FILE}
fi

cd /app

extractAndDeleteArchive() {
    cp $1 archive
    if [[ ${1: -4} == ".zip" ]]; then
        unzip -qqo archive
    else
        tar -xf archive
    fi
    rm archive
}

extractAndDeleteArchive ${DATA_DIR}/${E2E_INSTALL_PACKAGE_FILE}
extractAndDeleteArchive ${DATA_DIR}/${E2E_TEST_DATA_FILE}

DB_NAME=${cypress_dbName:-sw6_e2e_update}

mysql -h mysql -u root -proot -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`; use \`${DB_NAME}\`; source database.sql;"
mysql -h mysql -u root -proot ${DB_NAME} -e 'UPDATE sales_channel_domain SET url = REPLACE(url, "localhost:8000", "localhost:8008")'
mysql -h mysql -u root -proot ${DB_NAME} -e 'UPDATE system_config SET configuration_value = "{\"_value\": \"http://localhost:3000\"}" WHERE configuration_key IN("core.store.apiUri", "core.update.apiUri")';

sed -ie "s/sw6_e2e_test/${DB_NAME}/g" /app/.env

node ${SCRIPT_DIR}/update-api-mock.js &

chown application:application -R /app

gosu application bin/console database:migrate --all Shopware\\ || true
gosu application bin/console database:migrate --all core || true

gosu application bin/console system:generate-jwt-secret
gosu application bin/console assets:install
gosu application bin/console cache:clear


HTTP_PORT=${HTTP_PORT:-8009}

if ! grep -z "Listen $HTTP_PORT" /etc/apache2/ports.conf > /dev/null; then
    echo "Listen $HTTP_PORT" >> /etc/apache2/ports.conf
fi

/entrypoint supervisord
