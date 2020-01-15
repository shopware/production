#!/bin/sh

set -e

if [ -z $1 ]; then
    bin/console theme:compile || true
    /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    bin/console $@
fi