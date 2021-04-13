#!/usr/bin/env sh

set -e

if [ -z "$1" ]; then
    bin/console theme:compile || true
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    exec bin/console "$@"
fi
