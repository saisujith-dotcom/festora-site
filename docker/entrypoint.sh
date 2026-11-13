#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be an integer from 1 through 65535." >&2
        exit 1
        ;;
esac
if [ "${#port}" -gt 5 ] || [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT must be an integer from 1 through 65535." >&2
    exit 1
fi

sed "s/@PORT@/$port/g" \
    /etc/apache2/sites-available/festora.conf.template \
    > /etc/apache2/sites-available/000-default.conf
printf 'Listen 0.0.0.0:%s\n' "$port" > /etc/apache2/ports.conf
a2ensite 000-default >/dev/null

exec "$@"
