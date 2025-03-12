#!/bin/bash

# Default to all tests.
CUSTOM_MODULES="web/modules/custom"

# Make sure we use SQLite for the tests, not MySQL.
SIMPLETEST_DB=sqlite://localhost/sites/simpletest/.ht.sqlite

# Move to the project directory.
cd /var/www/html

# See if any arguments are passed.
if [ "$#" -eq 0 ]; then
    # No arguments, run all the tests.
    phpunit "$CUSTOM_MODULES"
else
    # Run the tests name by the operator.
    ARGS=()
    for ARG in "$@"; do
        ARGS+=("${CUSTOM_MODULES}/${ARG}")
    done
    phpunit "${ARGS[@]}"
fi
