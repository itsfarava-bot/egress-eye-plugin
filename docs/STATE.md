# Technical state

- Plugin version: 1.0.0; schema version is independent.
- WordPress.org contributor: sam465.
- Snapshot includes production code, unchanged tests, locked development dependencies,
  static-analysis configuration, test support tools, translations and the sole ZIP builder.
- No prior Git history, branches, PRs, Actions artifacts or execution reports are imported.
- CI workflows are intentionally absent until public visibility is confirmed.
- Snapshot import and exposure audit do not constitute a fresh test run.
- Full CI and official Plugin Check on the new repository remain pending.
- Packaging uses a strict production allowlist and fixed UTC file timestamps
  (1980-01-01), independent of commit time.
- Release verification must compare every packaged file with the approved reference;
  an overall ZIP digest alone is insufficient evidence of runtime correctness.
- No submission, tag or release is authorized at this stage.
