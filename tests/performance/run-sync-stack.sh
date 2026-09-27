#!/bin/sh
set -eu
export REDIS_PREFIX=mailcenter-m311-isolated-
export HORIZON_PREFIX=mailcenter-m311-horizon:
# Entry points all run tests/bootstrap.php before connecting; never source development credentials here.
# M311_RESET=1 recreates the schema; the bootstrap guard restricts it to postgres-test/mailcenter_test.
php tests/performance/sync-fixture.php ${M311_RESET:+--reset-test-fixture}
php tests/performance/idle-source.php > /tmp/m311-source.log 2>&1 & source_pid=$!
php tests/performance/sync-horizon.php horizon > /tmp/m311-horizon.log 2>&1 & horizon_pid=$!
PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=1 -S 0.0.0.0:8072 -t public tests/performance/sync-router.php > /tmp/m311-http.log 2>&1 & http_pid=$!
trap 'kill "$source_pid" "$horizon_pid" "$http_pid" 2>/dev/null || true' EXIT INT TERM
wait "$http_pid"
