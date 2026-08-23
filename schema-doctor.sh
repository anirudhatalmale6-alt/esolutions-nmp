#!/bin/sh
# B2B Network - "Error 500" everywhere, or nobody can log in?
#
# Run in cPanel Terminal:
#     sh ~/esolutions-nmp/schema-doctor.sh
#
# An update copies the new code first and changes the database a moment later. If
# that database step did not finish, the site is left running code that asks for
# something the database does not have - and then every page that loads the
# affected record fails with Error 500. When it is the users table, that is
# everybody locked out, including you.
#
# This says which columns the code needs that the database does not have, adds
# the ones that are safe to add, and leaves everything else alone. It only ever
# ADDS an empty column. Nothing is deleted, no invoice, client, stock item or
# setting is touched, and running it twice does nothing the second time.
DEST=/home/salononl/esolutions.website

if [ ! -f "$DEST/bin/console" ]; then
    echo "bin/console is not on the live site yet."
    echo "Run the update first - sh ~/esolutions-nmp/deploy.sh - then run this again."
    exit 1
fi

# Report first so the "before" picture is in the output I get sent, then repair.
echo "========== BEFORE =========="
( cd "$DEST" && php bin/console app:schema-doctor --env=prod --no-debug )

echo ""
echo "========== FIXING =========="
( cd "$DEST" && php bin/console app:schema-doctor --fix --env=prod --no-debug ) \
    || echo "WARNING: that did not finish. Send everything above to Anirudha."

echo ""
echo "Done. Try logging in again, then send Anirudha everything printed above."
