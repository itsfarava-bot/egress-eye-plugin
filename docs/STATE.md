# Technical state

- Plugin version: 1.0.0; schema version is independent.
- WordPress.org contributor: sam465.
- Repository visibility: public, verified from GitHub REST metadata on 2026-10-12.
- Production code and all tests are unchanged from the imported snapshot.
- No previous Git history, branches, PRs, artifacts or execution reports were imported.
- Workflow commit: `8286ce5e1c1dc73ce267179cddbf9a435a7240e4`.
- All four workflows and seven jobs completed successfully on that commit:
  - [Quality](https://github.com/itsfarava-bot/egress-eye-plugin/actions/runs/38227122599): PHP 7.4 and 8.3;
    101 unit tests / 2486 assertions per job; PHPCS and PHPStan passed.
  - [Integration](https://github.com/itsfarava-bot/egress-eye-plugin/actions/runs/38227122725):
    WordPress 6.6.9 / PHP 7.4 / MariaDB 10.11; WordPress 7.1.3 / PHP 8.3 /
    MariaDB 10.11 and MySQL 8.0. Each environment passed 101 unit tests / 2486
    assertions and 122 integration tests / 11928 assertions.
    The PHP 8.3 / MariaDB job additionally passed actual Query Monitor collection
    ON/OFF checks (3 tests / 56 assertions) and the benchmark (1 test / 17 assertions).
  - [Package](https://github.com/itsfarava-bot/egress-eye-plugin/actions/runs/38227122546):
    direct-access, translation and package-leak controls passed.
  - [Plugin Check](https://github.com/itsfarava-bot/egress-eye-plugin/actions/runs/38227122540):
    official Plugin Check 2.1.0 including runtime checks, WordPress 7.1.3 / PHP 8.3.35;
    zero errors and five warnings. The Contributors warning is absent; the remaining
    warnings match the previous result by file, line, column, type and code:
    load_plugin_textdomain at src/Plugin.php:102 and direct-database parameter analysis
    at src/Persistence/Database.php:278,280,282,284.
- Production artifact: `11736814061`; checked-package artifact: `11736814181`.
- Inner installable ZIP SHA256:
  `4639ef6985f4b93ac057eca545f324f93791d94428e09cc57a4910a3e4b4bc20`.
- All 50 packaged file paths and SHA256 values in both CI artifacts match the
  approved ZIP byte-for-byte. The older audit JSON describes the preceding ZIP
  (399529314b82...); its only file difference is the readme Contributors field.
- The sole builder uses fixed 1980-01-01 UTC timestamps, independent of commit time.
- Benchmark on the PHP 8.3 / MariaDB job: 20 warmup pairs, 100 measured pairs;
  baseline median 653.104 us / p95 683.857 us; active median 672.7015 us /
  p95 726.22 us; warm queries 0, cold queries 2, writes 0; transport calls 241,
  contexts remaining 0. JSON artifact: `11736983809`.
- Full CI runs on main pushes and PRs targeting main; other branch pushes run
  PHP 8.3 quality checks (unit, PHPCS, PHPStan). Manual dispatch is retained.
  Docs-only changes are ignored; contents permission is read-only; there are no
  secret references or pull_request_target events. Superseded runs are cancelled;
  artifact retention is 14 days.
- Final review was performed by the implementer without subagents. YAML, shell
  syntax and preservation of all existing workflow steps were checked locally;
  main-push execution is verified. PR, non-main push and manual-dispatch events
  were not separately exercised in this task.
- Manual staging acceptance and WordPress.org submission remain separate owner tasks.
- No submission, tag or release was created. This documentation-only update does
  not require another full CI run; production and workflow bytes remain unchanged.
