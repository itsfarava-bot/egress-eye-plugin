#!/usr/bin/env bash
# Sole production ZIP builder. Strict allowlist; deterministic bytes.
set -euo pipefail
export TZ=UTC
package_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
package_output="${1:-${package_root}/dist}"
mkdir -p -- "${package_output}"
package_output="$(cd -- "${package_output}" && pwd)"
package_stage="$(mktemp -d)"
trap 'rm -rf -- "${package_stage}"' EXIT
python3 - "${package_root}" "${package_stage}" <<'PY'
import pathlib, shutil
import os
import sys
root, stage = map(pathlib.Path, sys.argv[1:])
allowed = {
    "assets/admin.css",
    "languages/egress-eye-fa_IR.mo",
    "languages/egress-eye-fa_IR.po",
    "src/Admin/ActionNotice.php",
    "src/Admin/AdminMenu.php",
    "src/Admin/AdminPage.php",
    "src/Admin/AuditTableRenderer.php",
    "src/Admin/DomainTableRenderer.php",
    "src/Admin/ToggleDomainAction.php",
    "src/Contracts/AuditReader.php",
    "src/Contracts/BacktraceProvider.php",
    "src/Contracts/Clock.php",
    "src/Contracts/ComponentGuesser.php",
    "src/Contracts/ContextStore.php",
    "src/Contracts/DiagnosticDomains.php",
    "src/Contracts/DiagnosticEvents.php",
    "src/Contracts/DomainRepository.php",
    "src/Contracts/ManualBlocker.php",
    "src/Contracts/SlowRequestRecorder.php",
    "src/Diagnostics/ComponentGuess.php",
    "src/Diagnostics/NativeBacktraceProvider.php",
    "src/Diagnostics/SlowRequest.php",
    "src/Diagnostics/SlowRequestRecorderService.php",
    "src/Diagnostics/StackComponentGuesser.php",
    "src/Http/BlockedHostGuard.php",
    "src/Http/HookRegistrar.php",
    "src/Http/HostNormalizer.php",
    "src/Http/HttpObserver.php",
    "src/Http/InMemoryContextStore.php",
    "src/Http/LoopbackDetector.php",
    "src/Http/MonotonicClock.php",
    "src/Http/RequestContext.php",
    "src/Persistence/AuditRepository.php",
    "src/Persistence/Database.php",
    "src/Persistence/DomainRepository.php",
    "src/Persistence/EventRepository.php",
    "src/Persistence/RetentionService.php",
    "src/Persistence/SchemaManager.php",
    "src/Persistence/StorageRuntime.php",
    "src/Plugin.php",
    "src/Support/Capabilities.php",
    "src/Support/ErrorLogger.php",
    "src/Support/Nonces.php",
    "src/Support/Notifications.php",
}
for optional in ("CHANGELOG.md", "languages/egress-eye.pot"):
    if (root / optional).exists():
        allowed.add(optional)
allowed.update(("egress-eye.php", "uninstall.php", "readme.txt", "LICENSE"))
for directory in ("src", "assets", "languages"):
    for item in (root / directory).rglob("*"):
        path = item.relative_to(root).as_posix()
        if item.is_symlink() or (item.is_file() and path not in allowed):
            raise SystemExit("Forbidden production entry: " + path)
        if not item.is_dir() and not item.is_file():
            raise SystemExit("Unsupported production entry: " + path)
for path in sorted(allowed):
    source = root / path
    if source.is_symlink() or not source.is_file():
        raise SystemExit("Missing or unsafe production file: " + path)
    target = stage / "egress-eye" / path
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    target.chmod(0o644)
    os.utime(target, (315532800, 315532800))
PY
rm -f -- "${package_output}/egress-eye.zip"
(cd "${package_stage}" && LC_ALL=C find egress-eye -type f | LC_ALL=C sort | zip -X -q "${package_output}/egress-eye.zip" -@)
unzip -Z1 "${package_output}/egress-eye.zip" > "${package_output}/egress-eye-manifest.txt"
(cd "${package_output}" && sha256sum egress-eye.zip > egress-eye-sha256.txt)
printf 'Production package: %s\n' "${package_output}/egress-eye.zip"
