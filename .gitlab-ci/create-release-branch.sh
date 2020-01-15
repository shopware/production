#!/bin/sh

#TAG=
#CI_PIPELINE_IID=
NIGHTLY_TAG="v6.1.0-alpha.${CI_PIPELINE_IID}"
RELEASE_BRANCH="release/${NIGHTLY_TAG}-as-${TAG}"
RELEASE_REMOTE=git@gitlab.shopware.com:shopware/6/product/many-repositories
#BOT_API_TOKEN=

SPLIT_REPOS="Administration Storefront Core Elasticsearch Recovery"

set -e
set -x

[ -n ${TAG} ]

cp repos/core/PLATFORM_COMMIT_SHA PLATFORM_COMMIT_SHA
git add PLATFORM_COMMIT_SHA

for repo in $SPLIT_REPOS ; do
  repo=$(echo "${repo}" | tr '[:upper:]' '[:lower:]')
  git -C repos/$repo tag ${TAG} -a -m "Release ${TAG}" || true
  git -C repos/$repo remote add release $RELEASE_REMOTE/$repo.git
  git -C repos/$repo push release refs/tags/${TAG}
done

sleep 30
git checkout -b $RELEASE_BRANCH

case $TAG in
  *-rc*) STABILITY=rc;;
  *-beta*) STABILITY=beta;;
  *-alpha*) STABILITY=alpha;;
  *-dev*) STABILITY=dev;;
  *) STABILITY=stable;;
esac

sed -i -e 's/"minimum-stability"\s*:\s*"[^"]*"/"minimum-stability": "'$STABILITY'"/' composer.json

git add composer.json

rm -Rf vendor/shopware/* composer.lock || true
composer install --ignore-platform-reqs --no-interaction --no-scripts

VALID=1
i=0;
max=10;

while [ $i -lt $max ]; do
    i=$((i+1));

    VALID=1
    for repo in $SPLIT_REPOS ; do
      repo=$(echo "${repo}" | tr '[:upper:]' '[:lower:]')
      REPO_VERSION=$(jq -c '.packages[] | select(.name | contains("shopware/'${repo}'")) | .version' composer.lock)
      # TODO: better check sha/reference
      if [ "${REPO_VERSION}" != "\"${TAG}\"" ]; then
        echo "invalid version in $repo/composer.lock '${REPO_VERSION}' != '${TAG}'";
        VALID=
        break;
      fi
    done

    if [ -n "$VALID" ]; then break; fi

    sleep 15

    composer update shopware/* --ignore-platform-reqs --no-interaction --no-scripts
done

if [ -z "$VALID" ]; then
    echo "Max count reached. Failed to update to ${TAG}"
    exit 1
fi

git add composer.lock
git commit -m "Release ${TAG}"
git tag ${TAG} -a -m "Release ${TAG}"
git remote add release git@gitlab.shopware.com:shopware/6/product/production.git
git push --tags release
git log -1

curl -X POST \
  --header "Content-Type: application/json" \
  --header "Private-Token: $BOT_API_TOKEN" \
  --data "{\"id\": 184, \"source_branch\": \"${RELEASE_BRANCH}\", \"target_branch\": \"6.1\", \"title\": \"Release ${TAG}\"}" \
  https://gitlab.shopware.com/api/v4/projects/184/merge_requests
