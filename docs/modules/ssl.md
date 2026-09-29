# Module: SSL/TLS
- **Step:** S5   **Status:** in-progress (self-signed + status table)

## Purpose
cPanel SSL/TLS Status. Customer issues a self-signed cert; paneld writes
`~/ssl/<domain>/` + Apache `:443` vhost. Let's Encrypt AutoSSL next.

## Agent
| type | payload |
|---|---|
| `ssl.issue` | username, domain, document_root, mode=selfsigned |
| `ssl.remove` | username, domain |

## Permissions
ssl.view / ssl.manage — customer + reseller (not mail)
