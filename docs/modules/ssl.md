# Module: SSL/TLS
- **Step:** S5   **Status:** in-progress (AutoSSL Let's Encrypt + self-signed)

## Purpose
cPanel SSL/TLS Status. Customer runs AutoSSL (certbot HTTP-01 webroot) or
issues a self-signed fallback. paneld writes `~/ssl/<domain>/` + Apache `:443`.

## Agent
| type | payload |
|---|---|
| `ssl.issue` | username, domain, document_root, mode=letsencrypt\|selfsigned, email? |
| `ssl.remove` | username, domain |

Certbot config lives under `~/ssl/letsencrypt` (PathGuard `/home`). Live
fullchain/privkey are copied into `~/ssl/<slug>/` before the vhost is written.

## Permissions
ssl.view / ssl.manage — customer + reseller (not mail)
