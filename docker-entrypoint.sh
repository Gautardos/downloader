#!/bin/bash
set -e

echo "[Entrypoint] Checking and fixing permissions..."

# 1. Fix app-specific folders (internal to container)
# We ensure var and vendor are owned by www-data only if owned by root
for dir in /var/www/html/var /var/www/html/vendor; do
    if [ -d "$dir" ]; then
        owner=$(stat -c '%U' "$dir")
        if [ "$owner" = "root" ]; then
            echo "[Entrypoint] Fixing permissions for $dir (currently owned by root)"
            chown -R www-data:www-data "$dir"
        fi
    fi
done

# 2. Dynamic mount detection and fix
# We read /proc/mounts, filter for "typical" user mounts, and chown them if owned by root
# Filtering out system paths and docker internal paths
mounts=$(awk '$2 ~ /^\/(nas|downloads|music|mnt|storage|media)/ {print $2}' /proc/mounts)

for mnt in $mounts; do
    if [ -d "$mnt" ]; then
        owner=$(stat -c '%U' "$mnt")
        if [ "$owner" = "root" ]; then
            echo "[Entrypoint] Fixing permissions for mount: $mnt (currently owned by root)"
            chown -R www-data:www-data "$mnt"
        else
            echo "[Entrypoint] Mount $mnt already owned by $owner, skipping."
        fi
    fi
done

echo "[Entrypoint] Permissions fixed. Starting supervisord..."

# Execute the command passed to CMD
exec "$@"
