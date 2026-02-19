#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausführen: sudo bash scripts/install.sh"
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WAN_IFACE=""
LAN_IFACE=""
NON_INTERACTIVE=0

usage() {
  cat <<USAGE
Usage: sudo bash scripts/install.sh [--wan-if <iface>] [--lan-if <iface>] [--non-interactive]
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --wan-if) WAN_IFACE="${2:-}"; shift 2 ;;
    --lan-if) LAN_IFACE="${2:-}"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unbekanntes Argument: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ $NON_INTERACTIVE -eq 0 ]]; then
  echo "Verfügbare Interfaces (mit aktueller IPv4):"
  while IFS= read -r iface; do
    iface="${iface%@*}"
    ipv4="$(ip -o -4 addr show dev "$iface" | awk '{print $4}' | paste -sd ',' -)"
    [[ -z "$ipv4" ]] && ipv4="keine IPv4"
    echo " - ${iface} (${ipv4})"
  done < <(ip -o link show | awk -F': ' '{print $2}')
  [[ -z "$WAN_IFACE" ]] && read -rp "WAN Interface (DHCP): " WAN_IFACE
  [[ -z "$LAN_IFACE" ]] && read -rp "LAN Interface (DeadDrop): " LAN_IFACE
fi

WAN_IFACE="${WAN_IFACE:-eth0}"
LAN_IFACE="${LAN_IFACE:-eth1}"
[[ "$WAN_IFACE" == "$LAN_IFACE" ]] && { echo "WAN und LAN dürfen nicht identisch sein."; exit 1; }

for iface in "$WAN_IFACE" "$LAN_IFACE"; do
  ip link show "$iface" >/dev/null 2>&1 || { echo "Interface '$iface' nicht gefunden."; exit 1; }
done

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y dnsmasq nginx php-fpm vsftpd rsync curl netplan.io clamav clamav-daemon file nftables

configure_lan_ssh_key_only() {
  local ssh_dropin_dir="/etc/ssh/sshd_config.d"
  local ssh_dropin_file="${ssh_dropin_dir}/50-deaddrop-lan-keyonly.conf"

  if [[ ! -d "$ssh_dropin_dir" ]] || ! command -v sshd >/dev/null 2>&1; then
    echo "Hinweis: OpenSSH-Server nicht gefunden, überspringe LAN-only SSH-Härtung."
    return
  fi

  mkdir -p "$ssh_dropin_dir"
  cat >"$ssh_dropin_file" <<SSHCFG
Match Address 172.16.0.0/24
    PubkeyAuthentication yes
    PasswordAuthentication no
    KbdInteractiveAuthentication no
SSHCFG

  if sshd -t; then
    systemctl restart ssh || systemctl restart sshd || true
  else
    echo "Warnung: sshd-Konfiguration ungültig, entferne ${ssh_dropin_file}." >&2
    rm -f "$ssh_dropin_file"
  fi
}

mkdir -p /etc/netplan
cat >/etc/netplan/99-deaddrop.yaml <<NETPLAN
network:
  version: 2
  renderer: networkd
  ethernets:
    ${WAN_IFACE}:
      dhcp4: true
      dhcp6: true
      optional: true
      dhcp4-overrides:
        route-metric: 100
      dhcp6-overrides:
        route-metric: 100
    ${LAN_IFACE}:
      dhcp4: false
      dhcp6: false
      accept-ra: false
      link-local: []
      addresses: [172.16.0.1/24]
      optional: true
NETPLAN
chmod 600 /etc/netplan/99-deaddrop.yaml
netplan generate
netplan apply || true

# Hard isolation: never forward LAN -> WAN internet
sysctl -w net.ipv4.ip_forward=0 >/dev/null
cat >/etc/sysctl.d/99-deaddrop.conf <<SYSCTL
net.ipv4.ip_forward = 0
net.ipv6.conf.${LAN_IFACE}.disable_ipv6 = 1
SYSCTL

cat >/etc/nftables.conf <<NFT
#!/usr/sbin/nft -f
flush ruleset

table inet filter {
  chain input {
    type filter hook input priority 0;
    policy accept;
  }

  chain forward {
    type filter hook forward priority 0;
    policy drop;
  }

  chain output {
    type filter hook output priority 0;
    policy accept;
  }
}
NFT
systemctl enable nftables
systemctl restart nftables
sysctl --system >/dev/null

# dnsmasq on LAN only
sed "s/LAN_IFACE/${LAN_IFACE}/g" "$REPO_ROOT/config/dnsmasq/deaddrop.conf" >/etc/dnsmasq.d/deaddrop.conf
systemctl enable dnsmasq
systemctl restart dnsmasq

# AV signatures once at install-time; afterwards system can run offline
freshclam || true
systemctl enable clamav-daemon || true
systemctl restart clamav-daemon || true

# Deploy web
rsync -a --delete "$REPO_ROOT/webroot/" /var/www/deaddrop/
mkdir -p /var/www/deaddrop/upload /var/www/deaddrop/daten /var/www/deaddrop/webftp

# Root and data read-only for anonymous ftp; upload write-only
chown -R root:root /var/www/deaddrop
chmod 0555 /var/www/deaddrop
chmod 0555 /var/www/deaddrop/daten
chown -R www-data:www-data /var/www/deaddrop/upload
chmod 0733 /var/www/deaddrop/upload
chown -R www-data:www-data /var/www/deaddrop/webftp
chmod -R 0755 /var/www/deaddrop/webftp
chmod 0644 /var/www/deaddrop/*.html /var/www/deaddrop/*.txt /var/www/deaddrop/*.md /var/www/deaddrop/*.css || true

# nginx
PHP_FPM_SOCK="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | head -n1 | xargs -r basename)"
[[ -z "${PHP_FPM_SOCK:-}" ]] && { echo "Kein php-fpm Socket gefunden."; exit 1; }
rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
sed "s|PHP_FPM_SOCK|$PHP_FPM_SOCK|g" "$REPO_ROOT/config/nginx/deaddrop.conf" >/etc/nginx/sites-available/deaddrop.conf
ln -sf /etc/nginx/sites-available/deaddrop.conf /etc/nginx/sites-enabled/deaddrop.conf

PHP_FPM_SERVICE="$(systemctl list-unit-files | awk '/php[0-9.]+-fpm.service/ {print $1; exit}')"
[[ -z "${PHP_FPM_SERVICE:-}" ]] && { echo "Kein php-fpm Service gefunden."; exit 1; }
systemctl enable "$PHP_FPM_SERVICE" nginx
systemctl restart "$PHP_FPM_SERVICE" nginx

# FTP anonymous
cp "$REPO_ROOT/config/vsftpd.conf" /etc/vsftpd.conf
mkdir -p /srv/ftp-upload
ln -sfn /var/www/deaddrop/upload /var/www/deaddrop/upload-link
systemctl enable vsftpd
systemctl restart vsftpd

# SSH-Härtung nur für das interne LAN (WAN bleibt bei System-Defaults)
configure_lan_ssh_key_only

cat <<MSG
Fertig.
- WAN: ${WAN_IFACE} via DHCP (VM bleibt updatefähig)
- LAN: ${LAN_IFACE} = 172.16.0.1/24, IPv6 deaktiviert, komplett ohne Internet-Forwarding
- SSH auf LAN (172.16.0.0/24): key-only; WAN unverändert (System-Defaults)
- DNS wildcard + Captive auf LAN
- WebFTP Root: http://deaddrop.internal/webftp/
- Upload-Flow: /upload (write-only) -> AV-Scan -> /daten (read-only)
- Auffällige Dateien werden kommentarlos gelöscht.
MSG
