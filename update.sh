#!/usr/bin/env bash

tags=$(curl -s --fail https://api.github.com/repos/shopware/core/tags)

for tag in $(echo "${tags}" | jq -r '.[].name'); do
    # check for the existence of tag in git
    if ! git rev-parse "${tag}" >/dev/null 2>&1; then
        # update .packages.shopware/core to $tag
        echo "Updating .packages.shopware/core to ${tag}"
        jq --arg tag "${tag}" '.require."shopware/core" = $tag' composer.json > composer.json.tmp
        mv composer.json.tmp composer.json

exit 1
    fi
done