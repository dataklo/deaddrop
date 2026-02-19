#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

INDEX_URL="https://deaddrops.com/deutsch/wie-mache-ich-einen-dead-drop/"
MANIFEST_URL="https://deaddrops.com/deutsch/manifest/"

curl -fsSL "$INDEX_URL" -o "$TMP_DIR/index.html"
curl -fsSL "$MANIFEST_URL" -o "$TMP_DIR/manifest.html"

python3 - "$TMP_DIR/index.html" "$TMP_DIR/manifest.html" "$REPO_ROOT/webroot" <<'PY'
import pathlib
import sys

idx = pathlib.Path(sys.argv[1]).read_text(encoding='utf-8', errors='ignore')
man = pathlib.Path(sys.argv[2]).read_text(encoding='utf-8', errors='ignore')
out = pathlib.Path(sys.argv[3])

banner = '''<div style="max-width:980px;margin:1rem auto;padding:1rem;border:1px solid #a33;background:#401313;color:#fff;">
<strong>Wireless DeadDrop Hinweis:</strong> Diese Seite läuft als Wireless DeadDrop auf 172.16.0.1. Alle Uploads sind öffentlich sichtbar. Es wird keine Verantwortung für Inhalte übernommen.
</div>'''

if '<body' in idx:
    idx = idx.replace('<body', '<body', 1)
    idx = idx.replace('>', '>\n' + banner, 1)
if '<body' in man:
    man = man.replace('<body', '<body', 1)
    man = man.replace('>', '>\n' + banner, 1)

(out / 'index.html').write_text(idx, encoding='utf-8')
(out / 'manifest.html').write_text(man, encoding='utf-8')
PY

echo "Reference pages fetched into webroot/."
