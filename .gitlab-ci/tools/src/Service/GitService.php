<?php


namespace Shopware\CI\Service;


class GitService
{
    public function listTags(): array
    {

    }

    public function createReleaseCommit(): void
    {
        /**
         * git add composer.lock
        git commit -m "Release ${TAG}"
        git tag ${TAG} -a -m "Release ${TAG}"
        git remote add release git@gitlab.shopware.com:shopware/6/product/production.git
        #git push --tags release
         */

    }

    public function push(): void
    {
        // #git push --tags release
    }

    public function commit(): void
    {

    }
}