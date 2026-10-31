#!/bin/sh
set -e

if [ -d /app/data ]; then
    owner="$(stat -c '%u:%g' /app/data)"
    chown -R "$owner" /app/data
    exec su-exec "$owner" "$@"
fi

exec "$@"
