#!/bin/sh
set -e

php /var/www/html/docker/generate-supervisor-daemons.php

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
