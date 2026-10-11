# Egress Eye

WordPress HTTP API diagnostics for slow blocking requests (at least one second),
with administrator-controlled blocking of exact hosts. Probable source attribution
is a guess. Direct cURL, browser requests and redirect destinations are not covered.

Requires WordPress 6.6 or later, PHP 7.4 or later and a single-site installation.
Manual blocking requires InnoDB and simultaneous named-lock support. See
`readme.txt` for storage, compatibility, privacy and uninstall limits.

Install development dependencies with `composer install` using the committed lock
file and Composer security checks. Run `composer validate --strict`,
`vendor/bin/phpcs`, `vendor/bin/phpstan analyse -c phpstan.neon.dist` and
`vendor/bin/phpunit`. Integration tests require the WordPress test bootstrap,
an isolated test database and the environment variables read by `tests/bootstrap.php`
and the integration fixtures. Never use a production database for tests.

Build the production ZIP with `bash build/package-plugin.sh`; the output is
`dist/egress-eye.zip`. Tests, development tools and documentation are excluded.
Run official Plugin Check against the exact generated ZIP before submission.
See `docs/EXTENDING.md` and `docs/manual-test-guide-fa.md`.

License: GPL-2.0-or-later. See `LICENSE`.
