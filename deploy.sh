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
# cp never deletes, so nothing on the live site is removed - only updated.
SRC=/home/salononl/esolutions-nmp
DEST=/home/salononl/esolutions.website

# Code bundles / templates / front controller / DB migrations
cp -Rf "$SRC/src/."        "$DEST/src/"
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

# Rebuild the compiled cache so template/route changes take effect
rm -rf "$DEST/var/cache/prod"

echo "UPDATE DONE - eSolutions is now running the latest code (database untouched)."
