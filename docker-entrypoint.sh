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
            chown -R www-data:www-data "$dir" || echo "[Entrypoint] WARNING: could not chown $dir, continuing"
        fi
    fi
done

# 2. User mounts (/nas, /downloads, /music...): top-level folder only, never recursive.
# A recursive chown on a large network share (NFS/SMB) can take hours and is often refused:
# it must never block or crash the startup. Files inside the mounts are left untouched.
mounts=$(awk '$2 ~ /^\/(nas|downloads|music|mnt|storage|media)/ {print $2}' /proc/mounts)

for mnt in $mounts; do
    if [ -d "$mnt" ]; then
        owner=$(stat -c '%U' "$mnt")
        if [ "$owner" = "root" ]; then
            echo "[Entrypoint] Mount $mnt owned by root: fixing top-level folder only"
            chown www-data:www-data "$mnt" 2>/dev/null || echo "[Entrypoint] WARNING: could not chown $mnt (network share or read-only?), continuing"
        else
            echo "[Entrypoint] Mount $mnt already owned by $owner, skipping."
        fi
    fi
done

echo "[Entrypoint] Permissions checked. Starting supervisord..."

# Execute the command passed to CMD
exec "$@"
