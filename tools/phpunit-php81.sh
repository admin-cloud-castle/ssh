#!/usr/bin/env bash
set -euo pipefail
# Опциональный wrapper для локального Infection при нескольких PHP в PATH.
# В CI Infection использует vendor/bin/phpunit напрямую (см. infection.json.dist).
PHP_BIN="${PHP_BIN:-php}"
exec "${PHP_BIN}" "$(dirname "$0")/../vendor/bin/phpunit" "$@"
