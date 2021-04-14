#!/usr/bin/env bash

set -euo pipefail

set -x

ONLY_DELETED=

case "${1:-""}" in
    --deleted) # print deleted instead of changed files
        ONLY_DELETED=1
        shift;;
    "")
        ;;
    *)
        echo "Unknown option $1"
        exit 1
        ;;
esac

MINIMUM_VERSION=${MINIMUM_VERSION:-"6.3.0.0"}
SPLIT_REPOS="${SPLIT_REPOS:-"Administration Storefront Core Elasticsearch Recovery"}"

minimum_version_tag="v${MINIMUM_VERSION#"v"}"
temp_file=$(mktemp)

git -C .platform log --pretty='format:' --name-only "${minimum_version_tag}"..@ \
    | sort \
    | uniq \
    | awk NF > "$temp_file"

set +x

only_include=
for pkg in $SPLIT_REPOS ; do
    # example: src/Storefront with vendor/shopware/storefront
    sed -i -e "s|src/$pkg|vendor/shopware/${pkg,,}|" "$temp_file"
    only_include="${only_include}|vendor/shopware/${pkg,,}/"
done

# only include the files of the many repos and filter deleted files
grep -E "${only_include#|}" "$temp_file" \
    | while read -r filename; do
        if [[ -r "$filename" && -z "$ONLY_DELETED" ]]; then
            echo "$filename"
        elif [[ ! -r "$filename" && -n "$ONLY_DELETED" ]]; then
            echo "$filename"
        fi
    done

rm "$temp_file"
