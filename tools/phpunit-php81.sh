#!/usr/bin/env bash
set -euo pipefail
exec php8.1 "$(dirname "$0")/../vendor/bin/phpunit" "$@"
