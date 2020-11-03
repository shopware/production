#!/usr/bin/env bash

MINIMUM_VERSION=${MINIMUM_VERSION:-"6.3.0.0"}
SPLIT_REPOS="${SPLIT_REPOS:-"Administration Storefront Core Elasticsearch Recovery"}"

minimum_version_tag="v${MINIMUM_VERSION#"v"}"
temp_file=$(mktemp)

git -C .platform log --pretty='format:' --name-only ${minimum_version_tag}..@ \
    | sort \
    | uniq \
    | awk NF > $temp_file

only_include=
for pkg in $SPLIT_REPOS ; do
    # example: src/Storefront with vendor/shopware/storefront
    sed -i -e "s|src/$pkg|vendor/shopware/${pkg,,}|" $temp_file
    only_include="${only_include}|vendor/shopware/${pkg,,}/"
done

# only include the files of the many repos and filter deleted files
grep -E "${only_include#|}" $temp_file \
    | while read filename; do
        if [[ -r "$filename" ]]; then
            echo $filename
        fi
    done

rm $temp_file