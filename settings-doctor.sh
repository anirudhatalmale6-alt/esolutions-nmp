#!/bin/sh
# eSolutions - why is a Settings tab missing?
#
# Run in cPanel Terminal:
#     sh ~/esolutions-nmp/settings-doctor.sh
#
# The Settings page shows one tab per group of settings that exist in the
# database FOR THE BUSINESS YOU ARE IN. When a new feature is added its settings
# are created for businesses made from then on; the ones already there need a
# database update to fill them in, and if that did not happen the tab is just
# absent, with nothing on screen to say why.
#
# This lists what every business has, names anything missing, creates it, and
# then says which database updates this site has recorded. Nothing is deleted
# and nothing already saved is changed - it only adds rows that should have been
# there from the start.
DEST=/home/salononl/esolutions.website

if [ ! -f "$DEST/bin/console" ]; then
    echo "bin/console is not on the live site yet."
    echo "Run the update first - sh ~/esolutions-nmp/deploy.sh - then run this again."
    exit 1
fi

# Report first, so the "before" picture is in the output I get sent, and only
# then repair. Two separate runs of the same command: the first is the evidence,
# the second is the fix.
echo "========== BEFORE =========="
( cd "$DEST" && php bin/console app:settings-doctor --env=prod --no-debug )

echo ""
echo "========== FIXING =========="
( cd "$DEST" && php bin/console app:settings-doctor --fix --env=prod --no-debug ) \
    || echo "WARNING: that did not finish. Send everything above to Anirudha."

echo ""
echo "Done. Please send Anirudha everything printed above, then reload Settings."
