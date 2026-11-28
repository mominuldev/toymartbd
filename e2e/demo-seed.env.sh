#!/bin/sh
# Demo-seed shape shared by every browser harness that seeds the real app
# (e2e/admin/serve.sh, e2e/admin-a11y/serve.sh). Sourced, not run.
#
# When a seeder default changes (config/demo.php), the shape the specs depend on is set here
# once, so the harnesses cannot drift apart: the 2026-11-18 single-shop seed reached only one
# of them and the a11y branch dialogs hung CI for 30 minutes.

# The demo catalogue without its Figma photos: image encodes would slow every run.
export DEMO_SEED_IMAGES=false
# POS, transfer, branch-setup and branch-dialog specs need the demo branches (a normal seed
# is a single shop with multi-branch off).
export DEMO_SEED_BRANCHES=true
# The inbox spec drives the demo conversations (a normal seed has none: the Inbox shows real messages only).
export DEMO_SEED_INBOX=true
