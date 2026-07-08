#!/bin/sh
# eSolutions - one-command update.
# Run in cPanel Terminal AFTER "Update from Remote" in Git Version Control:
#     sh /home/salononl/esolutions-nmp/deploy.sh
#
# Copies ONLY code (src, config, templates, public, migrations) from the
# git clone into the live site. Never deletes anything. Never touches your
# database, .env / .env.local, config/secrets, uploaded logo (var), or
# installed libraries (vendor). 100% data-safe.
SRC=/home/salononl/esolutions-nmp
DEST=/home/salononl/esolutions.website

cp -Rf "$SRC/src/." "$DEST/src/"
cp -Rf "$SRC/config/." "$DEST/config/"
cp -Rf "$SRC/templates/." "$DEST/templates/"
cp -Rf "$SRC/public/." "$DEST/public/"
cp -Rf "$SRC/migrations/." "$DEST/migrations/"
rm -rf "$DEST/var/cache/prod"

echo "UPDATE DONE - eSolutions is now running the latest code."
