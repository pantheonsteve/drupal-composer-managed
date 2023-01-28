#!/bin/bash

set -ex -o pipefail

echo "### Begin test execution"

DIR=$1
OUTDIR=$DIR/test-output

mkdir -p $OUTDIR

TEST1=$OUTDIR/01-require-ctools-pinned.txt
TEST2=$OUTDIR/02-update-with-pinned-ctools.txt
CHECK2=$OUTDIR/02-check-pinned-ctools.txt
TEST3=$OUTDIR/03-update-project-with-pinned-ctools.txt
CHECK3=$OUTDIR/03-check-update.txt
TEST4=$OUTDIR/04-unpin-ctools.txt
CHECK4=$OUTDIR/04-unpin-ctools.txt
TEST5=$OUTDIR/05-upstream-to-latest-ctools.txt
CHECK5=$OUTDIR/05-check-ctools-not-updated.txt
TEST6=$OUTDIR/06-update-to-latest-ctools.txt
CHECK6=$OUTDIR/06-check-latest-ctools.txt

# Install ctools locked to a specific version
composer --working-dir="$DIR" upstream-require drupal/ctools:4.0.2 2>&1 | tee "$TEST1"

# Create lock file so that ctools is locked in project
composer --working-dir="$DIR" update-upstream-dependencies 2>&1 | tee "$TEST2"
composer --working-dir="$DIR" info 2>&1 | tee "$CHECK2"

# Update the project so that it will include ctools at the locked version
composer --working-dir="$DIR" update 2>&1 | tee "$TEST3"
composer --working-dir="$DIR" info 2>&1 | tee "$CHECK3"

# Un-pin ctools
composer --working-dir="$DIR" upstream-require drupal/ctools:^4 -- --no-update 2>&1 | tee "$TEST4"
composer --working-dir="$DIR" info 2>&1 | tee "$CHECK4"

# Update upstream dependencies, locking ctools at the latest version
composer --working-dir="$DIR" update-upstream-dependencies 2>&1 | tee "$TEST5"
composer --working-dir="$DIR" info 2>&1 | tee "$CHECK5"

# Update project, brining ctools up to the latest version
composer --working-dir="$DIR" update 2>&1 | tee "$TEST6"
composer --working-dir="$DIR" info 2>&1 | tee "$CHECK6"

echo "### End test execution"
