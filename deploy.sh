#!/bin/sh
# eSolutions - one-command update (SAFE).
# Run in cPanel Terminal AFTER "Update from Remote" in Git Version Control:
#     sh /home/salononl/esolutions-nmp/deploy.sh
#
# Copies ONLY application code from the git clone into the live site.
#
# It NEVER touches anything that holds your data or settings:
#   - MySQL database                (not in the code at all)
#   - .env / .env.local             (your DB connection details)
#   - config/env/  + config/secrets (the encrypted config vault)
#   - var/  (logs, uploaded logo)   - vendor/ (libraries)
SRC=/home/salononl/esolutions-nmp
DEST=/home/salononl/esolutions.website

# Application source code (src/) is 100% owned by the repo - no runtime files
# live in there - so we MIRROR it with rsync --delete. This removes files that
# were deleted in the repo, not just adds new ones. (A plain cp leaves deleted
# classes behind on the live site; a stale auto-discovered menu/service can then
# 500 the app - which is exactly what happened once. rsync --delete prevents it.)
if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete "$SRC/src/" "$DEST/src/"
else
    # Fallback if rsync is unavailable: wipe then copy so deletions still apply.
    rm -rf "$DEST/src" && mkdir -p "$DEST/src" && cp -Rf "$SRC/src/." "$DEST/src/"
fi

# templates / front controller / DB migrations: additive copy is fine (these are
# not auto-discovered, so a leftover file is inert). Migrations are never removed.
cp -Rf "$SRC/templates/."  "$DEST/templates/"
cp -Rf "$SRC/public/."     "$DEST/public/"
cp -Rf "$SRC/migrations/." "$DEST/migrations/"

# Config: ONLY the code-level config sub-folders.
# We deliberately DO NOT copy config/env or config/secrets (the vault
# that stores your database connection) - those belong to the live site.
for d in packages routes translations; do
    if [ -d "$SRC/config/$d" ]; then
        cp -Rf "$SRC/config/$d/." "$DEST/config/$d/"
    fi
done
# Top-level config php files (bundles.php, services.php, ...) - never the vault
cp -f "$SRC/config/"*.php "$DEST/config/" 2>/dev/null

# Rebuild the compiled cache so template/route changes take effect.
# We clear AND warm it here via CLI (the live folder has vendor/), so the
# first web request never has to build a cold cache - that half-built state
# was what caused a brief 500 after a past deploy.
rm -rf "$DEST/var/cache/prod"
if [ -f "$DEST/bin/console" ]; then
    ( cd "$DEST" && php bin/console cache:clear --env=prod --no-debug ) \
        || echo "WARNING: cache rebuild reported an issue - if the site shows 500, run: cd $DEST && php bin/console cache:clear --env=prod"
fi

echo "UPDATE DONE - eSolutions is now running the latest code (database untouched)."
