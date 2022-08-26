#!/usr/bin/env sh

echo "$@"

BIN_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE:-${0}}")" && pwd -P)"
export PROJECT_ROOT="${PROJECT_ROOT:-"$(dirname "$BIN_DIR")"}"
export ARTIFACTS_DIR="${ARTIFACTS_DIR:-"$PROJECT_ROOT/artifacts"}"

DEFAULT_ENV_FILENAME=".env.defaults"
ADDITIONAL_UPDATE_FILES="$1"
ADDITIONAL_DELETED_FILES="$2"

set -o errexit

# cleanup

cd "${PROJECT_ROOT}"

rm -rf var/cache/* \
    var/log/* \
    var/queue/* \
    var/test/* \
    vendor/symfony/*/Tests \
    vendor/twig/twig/test \
    vendor/swiftmailer/swiftmailer/tests \
    vendor/google/auth/tests \
    vendor/monolog/monolog/tests \
    vendor/phenx/php-font-lib/sample-fonts \
    install.lock

CORE_TAG=$(php -r 'include_once "vendor/autoload.php"; echo ltrim(explode("@", Composer\InstalledVersions::getVersion("shopware/core"))[0], "v");')

if [ -n "$DEFAULT_ENV" ]; then
    echo "$DEFAULT_ENV" > "$DEFAULT_ENV_FILENAME"
fi

REFERENCE_INSTALLER_URL=${REFERENCE_INSTALLER_URL:-"https://releases.shopware.com/sw6/install_6.2.1_1592219982.zip"}
REFERENCE_INSTALLER_SHA256=${REFERENCE_INSTALLER_SHA256:-"721dc18ff8c8f14cfe38bc731b4627e94119be5c1a03396b3234d837895f1806"}
REFERENCE_INSTALLER_FILE="$ARTIFACTS_DIR/reference.zip"

# make update
if [ -n "$REFERENCE_INSTALLER_URL" ]; then
    if [ ! -f "$REFERENCE_INSTALLER_FILE" ]; then
        curl -sS "${REFERENCE_INSTALLER_URL}" -o "$REFERENCE_INSTALLER_FILE"
    fi

    HASH_CHECK_LINE="$REFERENCE_INSTALLER_SHA256  $REFERENCE_INSTALLER_FILE"
    echo "${HASH_CHECK_LINE}" | sha256sum -c -

    set -x

    REFERENCE_TEMP_DIR=$(mktemp -d)
    cd "$REFERENCE_TEMP_DIR"
    mv "$REFERENCE_INSTALLER_FILE" .
    unzip -qq ./*.zip

    UPDATE_TEMP_DIR=$(mktemp -d)

    # fix permissions of reference
    cp -f -a --attributes-only "$PROJECT_ROOT/." "$REFERENCE_TEMP_DIR"

    # copy files that changed between the reference and the new version
    rsync -rvcmq --compare-dest="$REFERENCE_TEMP_DIR" "$PROJECT_ROOT/" "$UPDATE_TEMP_DIR/"

    if [ -r "$ADDITIONAL_UPDATE_FILES" ]; then
        rsync -av --files-from="$ADDITIONAL_UPDATE_FILES" "$PROJECT_ROOT/" "$UPDATE_TEMP_DIR/"
    fi

    cd "$UPDATE_TEMP_DIR"

    # add update meta information
    mkdir update-assets
    echo "$CORE_TAG" > update-assets/version
    if [ -n "$DEFAULT_ENV" ]; then
        rm "$DEFAULT_ENV_FILENAME" || true
        echo "$DEFAULT_ENV" > "update-assets/$DEFAULT_ENV_FILENAME"
    fi

    "${PROJECT_ROOT}"/bin/deleted_files_vendor.sh -o"$REFERENCE_TEMP_DIR/vendor" -n"$PROJECT_ROOT/vendor" > update-assets/cleanup.txt

    if [ -r "$ADDITIONAL_DELETED_FILES" ]; then
        cat "$ADDITIONAL_DELETED_FILES" >> update-assets/cleanup.txt
    fi

    sort update-assets/cleanup.txt | uniq > update-assets/cleanup.txt.sorted
    mv update-assets/cleanup.txt.sorted update-assets/cleanup.txt

    cp update-assets/cleanup.txt "$ARTIFACTS_DIR"/cleanup.txt

    zip -qq -9 -r "$ARTIFACTS_DIR/update.zip" .
fi

# installer

cd "${PROJECT_ROOT}"

cp "${PROJECT_ROOT}/public/.htaccess.dist" "${PROJECT_ROOT}/public/.htaccess"

zip -qq -9 -r "$ARTIFACTS_DIR/install.zip" .

if command -v xz >/dev/null 2>&1; then
    tar -cf - . | xz -9 -z  > "${ARTIFACTS_DIR}"/install.tar.xz
fi
