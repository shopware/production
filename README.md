# How to install and update

This document describes who to install and update shopware 6.


## Install - Composer scripts



```bash
CHANNEL=stable-6.1

git clone https://github.com/shopware/production --branch=$CHANNEL shopware
cd shopware

# Follow instructions
composer install
```


## Update - native

```bash 
git pull origin

# update shopware according to new composer.lock and run preInstall and postInstall scripts
composer install
```



## Install - Manuel

If you need more control, you do each step manually.

```bash

CHANNEL=stable-6.1

git clone https://github.com/shopware/production --branch=$CHANNEL shopware
cd shopware

# create .env
bin/setup

# could call the following commands in post-install-cmd
composer install --no-scripts

bin/console system:check

bin/console system:install --create-database


```

## Update - Manuel

```bash 
git pull

bin/console system:check

# deactivates plugins and enable maintenance mode. Could be used to backup
bin/console system:update:prepare

# do your stuff like backup

# update shopware according to new composer.lock
composer install --no-scripts

bin/console system:update:finish --no-stop-maintenance

# do your manuel checks or maybe a rollback

bin/console system:maintenance stop
```




## Install - Docker