#!/usr/bin/env bash

set -euo pipefail

composer validate --strict
vendor/bin/phpunit tests/Unit/SmokeTest.php
vendor/bin/phpcs
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress
php -l egress-eye.php
php -l src/Plugin.php
php -l tests/bootstrap.php
php -l tests/Unit/SmokeTest.php
