#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
php -S 0.0.0.0:8000 -t public
