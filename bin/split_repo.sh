#!/bin/bash

set -e
set -x

PLATFORM_REPO=$1
PREFIX=$2

PKG=$(basename ${PREFIX})
PKG_REPO=repos/${PKG,,}

SPLITSH=${SPLITSH:-'bin/splitsh-lite'}

if [[ ! -f $SPLITSH ]]; then
  if [[ "$OSTYPE" == "darwin"* ]]; then
    wget https://github.com/splitsh/lite/releases/download/v1.0.1/lite_darwin_amd64.tar.gz
    tar xvfz lite_darwin_amd64.tar.gz
    rm lite_darwin_amd64.tar.gz
    HASH_CHECK_LINE="b9da62bdf77919f4c6ce6c44d38e9a3c14e0eff99d2866124c5061b628dec92f  splitsh-lite"
  else
    wget https://github.com/splitsh/lite/releases/download/v1.0.1/lite_linux_amd64.tar.gz
    tar xvfz lite_linux_amd64.tar.gz
    rm lite_linux_amd64.tar.gz
    HASH_CHECK_LINE="ec46c5a409422bf55b26f7de1faab67c2320362934947f984872b3aedc4a9d99  splitsh-lite"
  fi

  echo "${HASH_CHECK_LINE}" | sha256sum -c -

  chmod +x splitsh-lite

  mv splitsh-lite ${SPLITSH}
fi


if [[ ! -d ${PLATFORM_REPO} ]]; then
    echo 'platform.git not found'
    exit 1
fi

if [[ -d ${PKG_REPO} ]]; then
    rm -Rf ${PKG_REPO} || true
fi

mkdir -p ${PKG_REPO} || true

echo "Splitting ${PKG}"

tmpFolder=$(mktemp -d)
git init --bare ${tmpFolder}/

${SPLITSH} --path ${PLATFORM_REPO} --prefix=${PREFIX} --target=refs/heads/${PKG}


git -C ${PLATFORM_REPO} remote remove ${PKG} || true
git -C ${PLATFORM_REPO} remote add ${PKG} ${tmpFolder}/

git -C ${PLATFORM_REPO} push -u ${PKG} ${PKG}:master -f

git clone ${tmpFolder}/ ${PKG_REPO}

rm -rf "${tmpFolder}"
