# Shopware 6 production template

This repository contains the production template that enables you to build,
package and deploy Shopware 6 to production shops. This template is also used
to build the official packages distributed by shopware at [https://www.shopware.com/en/download](https://www.shopware.com/en/download).

## Branches and stability

In each commit a composer.lock is contained to ensure that the version being
deployed is the version that was tested in our CI. We currently provide two
branches:
- `6.1`: stable patch releases (`v6.1.0-rc2`, `v6.1.0`, `v6.1.19`, `v6.1.*`, but not `v6.2.0`)
- `master`: stable minor+patch releases (`v6.1.0-rc2`, `v6.1.3`, `v6.1.15`, `v6.2.0`, `v6.3.0`...)

The `6.1` branch contains all the 6.1 releases. Because it's not released yet,
it only contains RC releases for now. But after that final release, this branch will
be stable and only get non-breaking bug fixes. (security issues are an exception).

The `master` branch contains the newest stable minor release (after the first final 6.1 release).
That may result in plugins being incompatible, so be careful.

## Requirements

See [https://docs.shopware.com/en/shopware-platform-dev-en/getting-started/requirements](https://docs.shopware.com/en/shopware-platform-dev-en/getting-started/requirements)

NPM and Node won't be required in the future. Expect for building the 
javascript applications.

## Setup and install

To setup the environment and install with a basic setup run the following commands:

```bash
# clone newest 6.1 patch version from github 
git clone --branch=6.1 https://github.com/shopware/production shopware
cd shopware

# install shopware and dependencies according to the composer.lock 
composer install

# setup the environment
bin/console system:setup
# create database with a basic setup (admin user and storefront sales channel)
bin/console system:install --create-database --basic-setup

# or use the interactive installer in the browser: /recovery/install/index.php
```

## Update

To update Shopware 6 just run this:

```bash
# pull newest changes from origin
git pull origin

# the (pre|post)-(install|update)-cmd will execute all steps automatically
composer install
```

## Docker

The `DOCKERFILE` should work but is still experimental.


## Customization

This project is called production template because it can be used to 
create project specific configurations. The template provides a basic setup
that is equivalent to the official distribution. If you need customization
the workflow could look like this:
* Fork template
* Make customization
* Add dependencies
* Add plugins
* Update composer.json and composer.lock
* Commit changes

### Template overview

This directory tree should give an overview of the template structure.

```txt
├── bin/                  # binaries to setup, build and run symfony console commands 
├── composer.json         # defines dependencies and setups autoloading
├── composer.lock         # pins all dependencies to allow for reproducible installs
├── config                # contains application configuration
│   ├── bundles.php       # defines static symfony bundles - use plugins for dynamic bundles
│   ├── etc/              # contains the configuration of the docker image
│   ├── jwt/              # secrets for generating jwt tokens - DO NOT COMMIT these secrets
│   ├── packages/         # configure packages - see: config/README.md
│   ├── secrets/          # symfony secrets store - DO NOT COMMIT these secrets
│   ├── services/         # contains some default overrides
│   ├── services.xml      # just imports the default overrides - this file should not change
│   └── services_test.xml # just imports the default overrides for tests
├── custom                # contains custom files
│   ├── plugins           # custom plugins
├── docker-compose.yml    # example docker-compose
├── Dockerfile            # minimal docker image
├── phpunit.xml.dist      # phpunit config
├── public                # should be the web root
│   ├── index.php         # main entrypoint for the web application
├── README.md             # this file
├── src
│   ├── Command/*
│   ├── Kernel.php        # our kernel extension
│   └── TestBootstrap.php # required to run unit tests
└── var
    ├── log/              # log dir
    |── cache/            # cache directory for symfony
    └── plugins.json
```

### Configuration

See [config/README.md](config/README.md)

### Composer

You only need to require the things you want. If you only want to run shopware 6 in headless mode, your composer.json could look like this:

```json
{
    "name": "acme/shopware-production",
    "type": "project",
    "license": "MIT",
    "config": {
        "optimize-autoloader": true
    },
    "prefer-stable": true,
    "minimum-stability": "stable",
    "autoload": {
        "psr-4": {
            "Shopware\\Production\\": "src/"
        }
    },
    "require": {
        "php": "~7.2",
        "ocramius/package-versions": "1.4.0",
        "shopware/core": "~v6.1.0"
    }
}
```

### Update shopware packages

Run the following command, to update all shopware dependencies:
```bash
composer update shopware/*
```

