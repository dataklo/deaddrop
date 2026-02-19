# Wireless DeadDrop (VM, offline-betriebsfähig)

## Kernziele

- **Nach Installation offline betreibbar** (alle benötigten Inhalte lokal auf der VM)
- **WAN Interface**: DHCP für Updates/Admin
- **LAN Interface**: isoliert (`172.16.0.1/24`), **IPv6 aus**, **keine Internet-Weiterleitung**
- DNS auf LAN löst alles lokal auf `172.16.0.1`
- Canonical URL + Redirect auf `http://deaddrop.internal`
- WebFTP + normaler FTP-Client (anonym)

## Sicherheit (wichtig)

- `nftables` setzt `forward` Policy auf `drop`
- `net.ipv4.ip_forward=0`
- `net.ipv6.conf.<LAN_IF>.disable_ipv6=1` + kein IPv6-RA/link-local auf LAN
- dadurch kein Routing LAN -> WAN
- LAN-Clients bekommen niemals Internet über diese VM

## Upload-Flow

1. Nutzer startet WebFTP unter `/webftp/` und landet im Root-View (`/daten`-Inhalte sichtbar)
2. Upload-Button oben rechts
3. Datei landet zuerst in `/upload` (write-only für anonyme FTP-Clients)
4. `clamscan` prüft Datei
5. saubere Datei wird nach `/daten` verschoben
6. auffällige Datei wird **ohne Kommentar gelöscht**

## Dateiregeln

Erlaubt sind nur dokument-/bildlastige Dateitypen (z. B. `jpg`, `png`, `pdf`, `txt`, `docx`, `xlsx` ...).

Ausführbare Typen (`exe`, `msi`, `bat`, `cmd`, `ps1`, `jar`, `vbs`, `js`, `sh` usw.) sind verboten.

## FTP-Rechte

- FTP ist anonym nutzbar (normaler FTP-Client)
- Web/FTP Root: read-only
- `/daten`: read-only
- `/upload`: write-only
- anonymer User darf nicht löschen (vsftpd + Rechte)

## Frische VM (Bootstrap)

Ja — das Projekt ist auf eine frische Debian/Ubuntu-VM ausgelegt.

```bash
sudo -i
apt update && apt dist-upgrade -y && apt install -y git
cd /opt
git clone https://github.com/dataklo/deaddrop.git
cd deaddrop
bash scripts/install.sh
```

## Installation

Interaktiv:

```bash
sudo bash scripts/install.sh
```

Nicht-interaktiv:

```bash
sudo bash scripts/install.sh --wan-if enp1s0 --lan-if enp7s0 --non-interactive
```

## URLs

- `http://172.16.0.1`
- `http://deaddrop.internal`
- `http://<WAN-IP-der-VM>`
- WebFTP: `http://deaddrop.internal/webftp/`

## Update-Funktion (für laufende Entwicklung)

Für Updates gibt es jetzt ein separates Script, das `git pull` ausführt und anschließend neu deployt:

```bash
sudo bash scripts/update.sh
```

Optional mit festem Stand (Branch/Tag/Commit):

```bash
sudo bash scripts/update.sh --git-ref main
```
