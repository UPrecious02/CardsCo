#!/usr/bin/env bash
# Everything that must pass before a zip goes out.
#
# Note on how the integration suites are run: an earlier version piped each
# suite into `tail` and tested the exit status of the pipeline, which is
# tail's, and tail always succeeds. Integration failures could not flip the
# flag and the gate printed ALL GREEN over a failing suite. Each suite's
# output is now captured first and its own exit status read, with a grep for
# FAIL as a second pair of eyes.
WORK="${1:?usage: run.sh /path/to/workdir}"
T=/home/claude/cardsco-tests
fail=0

# Suites leave cards behind; left unchecked the library grows past the window
# for_occasion() reads and unrelated suites begin to fail. Reset first.
cp "$T/reset-fixtures.php" "$WORK/reset-fixtures.php"
( cd "$WORK" && php reset-fixtures.php 2>&1 | grep -v sendmail )

run_int () {
  local label="$1" src="$2" dest="$3" out status
  cp "$T/$src" "$WORK/$dest"
  echo "--- integration: $label ---"
  out=$( cd "$WORK" && php "$dest" 2>&1 )
  status=$?
  echo "$out" | grep -v sendmail | tail -2
  if [ "$status" -ne 0 ] || echo "$out" | grep -q 'FAIL'; then
    echo "  ^^ $label did not pass"
    fail=1
  fi
}

echo "--- syntax ---"
for f in $(find /home/claude/cardsco -name '*.php'); do
  php -l "$f" | grep -v "^No syntax errors" && fail=1
done
echo "  clean"

echo "--- unit suites ---"
cd /home/claude/cardsco
shopt -s nullglob
for f in $T/unit-*.php; do
  n=$(basename "$f" .php)
  printf "  %-14s " "$n"
  if php "$f" >/dev/null 2>&1; then echo pass; else echo FAIL; fail=1; fi
done

maybe_int () {
  if [ -f "$T/$2" ]; then run_int "$1" "$2" "$3"; else echo "--- integration: $1 --- MISSING, not run"; missing=1; fi
}

missing=0
maybe_int "core objects"              integration.php            t-full.php
maybe_int "form submissions"          integration-forms.php      t-forms.php
maybe_int "programmes and categories" integration-programmes.php t-prog.php
maybe_int "scheduling and queue"      integration-scheduler.php  t-sched.php
maybe_int "occasion setup wizard"     integration-wizard.php     t-wizard.php
maybe_int "boxes and the two rhythms" integration-boxes.php      t-boxes.php
maybe_int "fulfilment screens"        integration-fulfilment.php t-ful.php
maybe_int "campaigns"                 integration-campaigns.php  t-camp.php
maybe_int "campaign builder"          integration-builder.php    t-build.php
maybe_int "front-end access scoping"  integration-access.php     t-access.php
maybe_int "accounts and sign-in"      integration-auth.php       t-auth.php
maybe_int "invites and settings"     integration-invites.php    t-invites.php
cp "$T/stub-woo.php" "$WORK/stub-woo.php"
maybe_int "catalogue and the shop"    integration-catalogue.php  t-cat.php
maybe_int "settings screen"           integration-settings.php   t-set.php
maybe_int "resuming a draft"          integration-drafts.php     t-draft.php
maybe_int "category backfill"         integration-backfill.php   t-back.php
maybe_int "shop boot order"          integration-shop-boot.php  t-shopboot.php
maybe_int "adding a person by hand"  integration-add-person.php t-addp.php
maybe_int "the card writer"           integration-writer.php     t-writer.php
maybe_int "where the wizard lands"    integration-setup-redirect.php t-redir.php
maybe_int "template and upload"       integration-people-upload.php t-upload.php
maybe_int "occasions with no date"    integration-person-nodate.php t-nodate.php
maybe_int "wizard preview"            integration-wizard-preview.php t-wpv.php
maybe_int "starter messages"          integration-starter-messages.php t-starter.php
maybe_int "step 6 timing"             integration-setup-timing.php t-timing.php
maybe_int "a refused message"         integration-writer-refused.php t-refused.php
maybe_int "wizard radio wiring"       integration-wizard-radios.php t-radios.php
maybe_int "bulk pricing"              integration-bulk-price.php t-bulk.php
maybe_int "orders"                    integration-orders.php t-orders.php
maybe_int "in-place steps"            integration-swap.php t-swap.php
maybe_int "portal urls"               integration-portal-urls.php t-urls.php
maybe_int "company logo"              integration-logo.php t-logo.php
maybe_int "team access"               integration-team.php t-team.php
# The checkout suite needs the basket and orders stub as well as the base one.
cp "$T/stub-woo-shop.php" "$WORK/stub-woo-shop.php"
maybe_int "checkout"                  integration-checkout.php t-checkout.php
maybe_int "send"                      integration-send.php     t-send.php
maybe_int "step fields"              integration-step-fields.php t-fields.php

# Browser-side scripts, checked with node rather than against WordPress.
if command -v node >/dev/null 2>&1; then
	echo "--- unit: scripts without boot.js ---"
	if node "$T/unit-boot-missing.js" /home/claude/cardsco/assets; then :; else fail=1; fi
else
	echo "NOTE: node is not here, so the browser-script suite did not run."
	missing=1
fi

[ $missing -eq 1 ] && echo "NOTE: some suites are missing and were not run."

[ $fail -eq 0 ] && echo "ALL GREEN" || echo "SOMETHING FAILED — do not ship"
exit $fail
