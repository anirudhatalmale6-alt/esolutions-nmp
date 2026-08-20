#!/bin/sh
# eSolutions - one-command update (SAFE).
# Run in cPanel Terminal AFTER "Update from Remote" in Git Version Control:
#     sh /home/salononl/esolutions-nmp/deploy.sh
#
# Copies ONLY application code from the git clone into the live site.
#
# It NEVER touches anything that holds your data or settings:
#   - .env / .env.local             (your DB connection details)
#   - config/env/  + config/secrets (the encrypted config vault)
#   - var/  (logs, uploaded logo)   - vendor/ (libraries)
#
# It DOES apply pending database migrations at the end (it used to say it never
# touched the database, which stopped being true the day a feature needed a new
# column). Migrations only ADD structure and seed rows; your invoices, clients,
# stock and settings are never deleted or rewritten by this script. Anything
# already applied is skipped, so running it twice is safe.
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
#
# There used to be an "rm -rf var/cache/prod" on the line above this, and it was
# a mistake. cache:clear ALREADY builds the new cache in a separate folder and
# then renames it into place (see Symfony's CacheClearCommand: it warms up into
# a "_" suffixed directory and rename()s it over the real one). Deleting the
# live cache first defeats that: it leaves the site with NO compiled container
# for the whole length of the rebuild, which on this app is the best part of a
# minute. Every web request landing in that window has to compile the container
# itself, racing the CLI build that is running at the same time - which is a
# 500 for whoever clicks during a deploy, and it is the reason the site would
# briefly break right after an update and then be fine again with nothing
# conclusive in the log.
#
# So: no delete. Let cache:clear do the swap it was designed to do.
if [ -f "$DEST/bin/console" ]; then
    ( cd "$DEST" && php bin/console cache:clear --env=prod --no-debug ) \
        || echo "WARNING: cache rebuild reported an issue - if the site shows 500, run: cd $DEST && php bin/console cache:clear --env=prod"
else
    # No console to build with, so the cache has to go and be rebuilt on the
    # next request. Only in this fallback is deleting it the right thing.
    rm -rf "$DEST/var/cache/prod"
fi

# Database structure. New features sometimes ship a migration - a new column, or
# new settings rows for businesses that already exist. Without this step the code
# arrives but the thing it needs is not there, and the feature is silently
# missing rather than visibly broken.
#
# This ADDS structure and seed rows. It does not delete your data. Migrations are
# recorded, so anything already applied is skipped and running this twice is
# harmless.
if [ -f "$DEST/bin/console" ]; then
    ( cd "$DEST" && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod ) \
        || echo "WARNING: a database update did not finish. Send the lines above to Anirudha before using the site."
fi

echo "UPDATE DONE - eSolutions is now running the latest code."
