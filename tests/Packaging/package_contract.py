#!/usr/bin/env python3
"""Exercise the sole builder, including executable package-leak controls."""
import pathlib
import ast
import gettext
import io
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix="egress-eye-package-test-") as temp:
    fixture = pathlib.Path(temp) / "source"
    fixture.mkdir()
    for name in ("egress-eye.php", "uninstall.php", "readme.txt", "LICENSE"):
        shutil.copy2(ROOT / name, fixture / name)
    for name in ("src", "assets", "languages", "build"):
        shutil.copytree(ROOT / name, fixture / name, symlinks=True)
    if (ROOT / "CHANGELOG.md").exists():
        shutil.copy2(ROOT / "CHANGELOG.md", fixture / "CHANGELOG.md")
    out = pathlib.Path(temp) / "output"

    def build(timezone="UTC"):
        environment = os.environ.copy()
        environment["TZ"] = timezone
        return subprocess.run(
            ["bash", str(fixture / "build/package-plugin.sh"), str(out)],
            capture_output=True, text=True, check=False, env=environment,
        )

    clean = build()
    assert clean.returncode == 0, clean.stderr
    reference = (out / "egress-eye.zip").read_bytes()
    shifted = build("Europe/Berlin")
    assert shifted.returncode == 0, shifted.stderr
    assert (out / "egress-eye.zip").read_bytes() == reference, "Timezone changed production ZIP bytes"
    with zipfile.ZipFile(out / "egress-eye.zip") as archive:
        names = archive.namelist()
        assert all(name.startswith("egress-eye/") for name in names)
        assert not any(part in {"tests", "docs", ".github", "build", "tools", "vendor", ".git"}
                       for name in names for part in pathlib.PurePosixPath(name).parts)
        assert not any(name.endswith(".map") for name in names)
        assert "egress-eye/languages/egress-eye-fa_IR.mo" in names
        php = shutil.which("php")
        assert php, "PHP is required for the ZIP direct-access release gate"
        direct_root = pathlib.Path(temp) / "direct"
        archive.extractall(direct_root)
        for name in names:
            if not name.endswith(".php"):
                continue
            direct = subprocess.run(
                [php, "-d", "display_errors=1", "-r",
                 "require $argv[1]; echo 'UNGUARDED';", str(direct_root / name)],
                capture_output=True, text=True, check=False,
            )
            assert direct.returncode == 0 and not direct.stdout and not direct.stderr, (
                f"Direct-access guard failed for packaged {name}: "
                f"exit={direct.returncode}, stdout={direct.stdout}, stderr={direct.stderr}"
            )
        if "egress-eye/languages/egress-eye.pot" in names:
            def messages(text):
                result = {}
                key = value = ""
                field = None
                for line in (text + "\n\n").splitlines():
                    if line.startswith("msgid "):
                        key = ast.literal_eval(line[6:])
                        field = "key"
                    elif line.startswith("msgstr "):
                        value = ast.literal_eval(line[7:])
                        field = "value"
                    elif line.startswith('"'):
                        if field == "key":
                            key += ast.literal_eval(line)
                        elif field == "value":
                            value += ast.literal_eval(line)
                    elif not line.strip():
                        if key:
                            result[key] = value
                        key = value = ""
                        field = None
                return result

            pot = messages(archive.read("egress-eye/languages/egress-eye.pot").decode())
            po = messages(archive.read("egress-eye/languages/egress-eye-fa_IR.po").decode())
            mo = gettext.GNUTranslations(io.BytesIO(archive.read("egress-eye/languages/egress-eye-fa_IR.mo")))
            source_strings = set()
            for name in names:
                if name.endswith(".php"):
                    source_strings.update(re.findall(
                        r"(?:__|esc_html__|esc_attr__)\(\s*'([^']*)'\s*,\s*'egress-eye'",
                        archive.read(name).decode(),
                    ))
            assert source_strings == set(pot), "Source and POT messages differ"
            assert set(po) == set(pot), "PO and POT messages differ"
            assert all(po[key] and mo.gettext(key) == po[key] for key in pot), "Persian MO/PO is incomplete"

    for relative, content in (
        ("assets/leak.map", "private build map"),
        ("assets/banner-772x250.png", "directory banner"),
        ("src/leak.txt", "development-only data"),
        ("languages/private.log", "private log"),
    ):
        poison = fixture / relative
        poison.write_text(content)
        result = build()
        poison.unlink()
        assert result.returncode != 0, f"Builder accepted forbidden file: {relative}"

    link = fixture / "assets/private-link.css"
    link.symlink_to(fixture / "readme.txt")
    result = build()
    link.unlink()
    assert result.returncode != 0, "Builder accepted a symlink"
print("Package contract: clean production build and five leak controls passed")
