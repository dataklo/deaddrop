#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausführen: sudo bash scripts/update.sh"
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NON_INTERACTIVE=0
GIT_REF=""

usage() {
  cat <<USAGE
Usage: sudo bash scripts/update.sh [--git-ref <branch|tag|commit>] [--non-interactive]
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --git-ref) GIT_REF="${2:-}"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unbekanntes Argument: $1" >&2; usage; exit 1 ;;
  esac
done

if ! command -v git >/dev/null 2>&1; then
  echo "git fehlt. Bitte zuerst installieren: apt-get install -y git"
  exit 1
fi

pushd "$REPO_ROOT" >/dev/null

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
if [[ -n "$GIT_REF" ]]; then
  git fetch --all --tags
  git checkout "$GIT_REF"
elif [[ "$CURRENT_BRANCH" != "HEAD" && -n "$CURRENT_BRANCH" ]]; then
  git pull --ff-only origin "$CURRENT_BRANCH"
else
  echo "Kein Branch erkannt (detached HEAD). Nutze --git-ref <branch|tag|commit>."
  exit 1
fi

WAN_IFACE="$(awk '
  $1=="ethernets:" {in_eth=1; next}
  in_eth && /^[[:space:]]{4}[A-Za-z0-9_.:-]+:/ {
    iface=$1; sub(":$", "", iface)
    current=iface
  }
  in_eth && $1=="dhcp4:" && $2=="true" {wan=current}
  END {print wan}
' /etc/netplan/99-deaddrop.yaml 2>/dev/null || true)"

LAN_IFACE="$(awk '
  $1=="ethernets:" {in_eth=1; next}
  in_eth && /^[[:space:]]{4}[A-Za-z0-9_.:-]+:/ {
    iface=$1; sub(":$", "", iface)
    current=iface
  }
  in_eth && $1=="addresses:" && $2 ~ /172\.16\.0\.1\/24/ {lan=current}
  END {print lan}
' /etc/netplan/99-deaddrop.yaml 2>/dev/null || true)"

if [[ -z "$WAN_IFACE" ]]; then
  [[ $NON_INTERACTIVE -eq 0 ]] && read -rp "WAN Interface (DHCP) für Re-Deploy: " WAN_IFACE
  WAN_IFACE="${WAN_IFACE:-eth0}"
fi

if [[ -z "$LAN_IFACE" ]]; then
  [[ $NON_INTERACTIVE -eq 0 ]] && read -rp "LAN Interface (DeadDrop) für Re-Deploy: " LAN_IFACE
  LAN_IFACE="${LAN_IFACE:-eth1}"
fi

bash "$REPO_ROOT/scripts/install.sh" --wan-if "$WAN_IFACE" --lan-if "$LAN_IFACE" --non-interactive

popd >/dev/null

echo "Update abgeschlossen (${CURRENT_BRANCH:-$GIT_REF})."
