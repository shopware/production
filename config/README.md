# Configuration

This README describes how to change the configuration.

## Overview

```text
config/
├── bundles.php       # defines static symfony bundles - use plugins for dynamic bundles
├── etc               # contains the configuration of the docker image
├── jwt               # secrets for generating jwt tokens - DO NOT COMMIT these secrets
├── packages/         # package configuration
├── README.md         # this file
├── services.xml      # service definition overrides
└── services_test.xml # overrides for test env
```

## `config/bundles.php`

The `bundles.php` defines all static bundles the kernel should load. If
you dont need our storefront or the administration you can remove the 
bundle from this file and it will stop being loaded. To completely remove
it you can also stop requiring the package in the `composer.json`.


## `config/packages/*.yml`

`.yml` files for packages contained in this directory are loaded automatically.

### Shopware config `config/packages/shopware.yml`

Define shopware specific configuration.

This file can be added to override the defaults defined in `vendor/shopware/core/Framework/Resources/config/packages/shopware.yaml`.

Example:

```yaml
shopware:
    api:
        max_limit: 1000 # change limit from 500 to 1000

    admin_worker:
        enable_admin_worker: false # disable admin worker - use a different one!

    auto_update:
        enabled: false # disable auto update

```

## `config/services.xml`

Defines some default parameters and services available in the di container.

