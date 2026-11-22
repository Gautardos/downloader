#!/bin/bash
set -e

echo "[Entrypoint] Checking and fixing permissions..."

# 1. Fix app-specific folders (internal to container)
# We ensure var and vendor are owned by www-data only if owned by root.
# Docker volumes are bind mounts that can share the same device as $dir, so
# -xdev alone does not stop the walk. Mount points under $dir are pruned
# explicitly to avoid changing the group of host-managed data volumes.
for dir in /var/www/html/var /var/www/html/vendor; do
    if [ -d "$dir" ]; then
        owner=$(stat -c '%U' "$dir")
        if [ "$owner" = "root" ]; then
            echo "[Entrypoint] Fixing permissions for $dir (currently owned by root)"
            prune=()
            while IFS= read -r mount_point; do
                [ -n "$mount_point" ] || continue
                prune+=(-path "$mount_point" -prune -o)
            done < <(awk -v root="$dir/" 'index($2, root) == 1 {print $2}' /proc/mounts)
            find "$dir" -xdev "${prune[@]}" -exec chown www-data:www-data {} + || echo "[Entrypoint] WARNING: could not chown $dir, continuing"
        fi
    fi
done

# 2. Tighten sensitive application files, but never break dev bind mounts.
# Never change groups here: the host chooses the group on bind mounts.
storage_dir=/var/www/html/var/storage
db_dir=/var/www/html/var/db

if [ -d "$db_dir" ]; then
    owner=$(stat -c '%U' "$db_dir")
    if [ "$owner" = "www-data" ]; then
        echo "[Entrypoint] Tightening permissions for $db_dir"
        chmod 700 "$db_dir" 2>/dev/null || echo "[Entrypoint] WARNING: could not chmod $db_dir, continuing"
        while IFS= read -r -d '' subdir; do
            if [ "$(stat -c '%U' "$subdir")" = "www-data" ]; then
                chmod 700 "$subdir" 2>/dev/null || true
            fi
        done < <(find "$db_dir" -maxdepth 1 -mindepth 1 -type d -print0 2>/dev/null)
        while IFS= read -r -d '' file; do
            if [ "$(stat -c '%U' "$file")" = "www-data" ]; then
                chmod 600 "$file" 2>/dev/null || true
            fi
        done < <(find "$db_dir" -maxdepth 2 -type f -print0 2>/dev/null)
    else
        echo "[Entrypoint] $db_dir owned by $owner: skipping strict chmod (dev bind mount / non-app owner)"
    fi
fi

if [ -d "$storage_dir" ]; then
    # Supervisor writes worker stderr into var/storage/logs. On a fresh data
    # volume the directory does not exist, so create it before supervisord starts.
    if [ ! -d "$storage_dir/logs" ]; then
        mkdir -p "$storage_dir/logs" 2>/dev/null || true
        chown www-data:www-data "$storage_dir/logs" 2>/dev/null || true
        chmod 2750 "$storage_dir/logs" 2>/dev/null || true
    fi

    # Move legacy app logs into the host-visible logs directory when possible.
    # Existing permissions are preserved; the entrypoint never chmods here.
    if [ -d "$storage_dir/logs" ]; then
        shopt -s nullglob
        for legacy_log in "$storage_dir"/*.log; do
            legacy_name=$(basename "$legacy_log")
            if [ -f "$legacy_log" ] && [ ! -e "$storage_dir/logs/$legacy_name" ]; then
                mv "$legacy_log" "$storage_dir/logs/$legacy_name" 2>/dev/null || true
                chgrp --reference="$storage_dir/logs" "$storage_dir/logs/$legacy_name" 2>/dev/null || true
            fi
        done
        shopt -u nullglob
    fi

    owner=$(stat -c '%U' "$storage_dir")
    if [ "$owner" = "www-data" ]; then
        echo "[Entrypoint] Tightening permissions for $storage_dir"
        chmod 710 "$storage_dir" 2>/dev/null || echo "[Entrypoint] WARNING: could not chmod $storage_dir, continuing"
        while IFS= read -r -d '' subdir; do
            if [ "$(stat -c '%U' "$subdir")" = "www-data" ]; then
                chmod 700 "$subdir" 2>/dev/null || true
            fi
        done < <(find "$storage_dir" -maxdepth 1 -mindepth 1 -type d -print0 2>/dev/null)
        if [ -d "$storage_dir/logs" ] && [ "$(stat -c '%U' "$storage_dir/logs")" = "www-data" ]; then
            chmod 2750 "$storage_dir/logs" 2>/dev/null || true
            touch "$storage_dir/logs/worker_error.log" "$storage_dir/logs/automation_worker_error.log" "$storage_dir/logs/music_automations_worker_error.log" "$storage_dir/logs/maintenance_error.log" 2>/dev/null || true
            chown www-data "$storage_dir/logs/worker_error.log" "$storage_dir/logs/automation_worker_error.log" "$storage_dir/logs/music_automations_worker_error.log" "$storage_dir/logs/maintenance_error.log" 2>/dev/null || true
            chgrp --reference="$storage_dir/logs" "$storage_dir/logs/worker_error.log" "$storage_dir/logs/automation_worker_error.log" "$storage_dir/logs/music_automations_worker_error.log" "$storage_dir/logs/maintenance_error.log" 2>/dev/null || true
            chmod 640 "$storage_dir/logs/worker_error.log" "$storage_dir/logs/automation_worker_error.log" "$storage_dir/logs/music_automations_worker_error.log" "$storage_dir/logs/maintenance_error.log" 2>/dev/null || true
        fi
        while IFS= read -r -d '' file; do
            if [ "$(stat -c '%U' "$file")" = "www-data" ]; then
                chmod 600 "$file" 2>/dev/null || true
            fi
        done < <(find "$storage_dir" -maxdepth 2 -type f \( -name '*.json' -o -name '*.lock' -o -name '*.db*' \) -print0 2>/dev/null)
        while IFS= read -r -d '' file; do
            if [ "$(stat -c '%U' "$file")" = "www-data" ]; then
                chmod 640 "$file" 2>/dev/null || true
            fi
        done < <(find "$storage_dir" -type f -name '*.log' -print0 2>/dev/null)
    else
        echo "[Entrypoint] $storage_dir owned by $owner: skipping strict chmod (dev bind mount / non-app owner)"
    fi
fi

# 3. User mounts (/nas, /downloads, /music...): top-level folder only, never recursive.
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
