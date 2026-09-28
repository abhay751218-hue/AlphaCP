# 👋 START HERE — naye AI / developer ke liye (user se kuch mat poochho, yahan sab hai)

> **AlphaCP** = cPanel/WHM jaisa custom web hosting control panel (India + global hosting business ke liye).
> Iska apna license system hai (15 din ka trial), aur ye billing software se WHM API 1 compatible banega.
> Server: AWS Lightsail Mumbai, Ubuntu 24.04, 4 GB / 2 vCPU / 80 GB, static IP `13.207.123.177`, panel `https://<ip>:8090`.

## 1. Isi order me padho
| # | File | Kyun |
|---|---|---|
| 1 | **`server-snapshot/STATE.md`** | ⭐ **Server par ABHI kya chal raha hai.** Ye file server se har ghante automatic aati hai: versions, services, ports, migrations, routes, license/trial files, DB schema. **Sabse bharosemand source yahi hai.** |
| 2 | `server-snapshot/LAST-SYNC.md` | Aakhri sync kab hua. Purana lage to user se sirf `sudo alphacp-sync` chalwao. |
| 3 | `COMMANDS.md` | Server par chalne wali live commands (commit-pinned). Purani commands ki "mat chalao" list bhi yahin hai. |
| 4 | `CHANGELOG.md` | Kya kab bana/fix hua |
| 5 | `ROADMAP.md`, `project-status.md` | Steps S0–S15 aur unka status |
| 6 | `AI_CONTEXT.md`, `AGENTS.md`, `docs/04-coding-standards.md`, `docs/08-module-blueprint.md` | Rules aur architecture |
| 7 | `docs/09-cpanel-parity-checklist.md` | 208 items ka parity contract. **Koi row delete mat karna.** |
| 8 | `AI-HANDOFF.md` | Purani history: 500 bug ki asli wajah, dead ends |

## 2. Code kahan hai
| Path | Kya |
|---|---|
| `server-snapshot/files/usr/local/alphacp/panel/` | **Server par jo panel code deployed hai** (Laravel 13.33.0). Isme license + 15-day trial bhi hai. Kisi aur AI ne ye kaam seedha server par kiya tha. |
| `server-snapshot/files/usr/local/alphacp/{agent,bin,...}` | paneld agent, CLI, baaki tools (jo server par hain) |
| `server-snapshot/files/etc/...` | nginx vhost, php-fpm pool, systemd drop-ins |
| `server-snapshot/db-schema.sql` | DB structure (data nahi) |
| `installer/` | Scripts jo server par chalti hain: `panel-doctor.sh`, `alphacp-sync.sh`, installers |
| `tools/sim/` | Local simulation tests. Har script user ko dene se pehle yahan test hoti hai. |
| `refs/panel-2b-bundle/`, `panel/` | Purane source copies. `server-snapshot` inse naya hai. |

`server-snapshot/` me **secrets nahi hain**, jaise `.env`, DB password, APP_KEY, license keys, admin password.
Unke sirf naam aur keys `STATE.md` me likhe hain. Values server par hi rehti hain.

## 3. User ke saath kaam karne ke rules (BINDING)
1. Jawab **Hindi/Hinglish** me do.
2. Ek message me **sirf EK command/step** do. Output maango, phir aage badho.
3. **Untested command kabhi mat do.** Pehle reproduce karo, phir fix, phir `tools/sim/` me verify karo, uske baad hi command do.
4. Har script apna **version banner** print kare. User scrollback se purani command chala deta hai.
5. Command hamesha **commit-pinned GitHub raw link** ho, is format me:
   `curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/<COMMIT>/installer/<script>.sh -o /tmp/<script>-<ver>.sh && sudo bash /tmp/<script>-<ver>.sh`
   Link dene se pehle `gh api .../contents/<path>?ref=<COMMIT>` se check karo ki GitHub copy wahi file hai jo test hui thi.
6. Server par kuch bhi badalne wali har script ke **end me `alphacp-sync` chalao**, taaki GitHub apne aap update ho jaye:
   `command -v alphacp-sync >/dev/null && alphacp-sync || true`
7. `COMMANDS.md` + `CHANGELOG.md` update karo. Parity checklist me jo row ho gayi ho use ✅/🟡 karo.

## 4. GitHub ↔ server sync kaise chalta hai
- `installer/alphacp-sync.sh` server par ek baar setup hota hai. Deploy key banti hai aur har ghante ek timer chalta hai.
- Sync secrets hata kar snapshot banata hai aur **`main` branch ke `server-snapshot/`** folder me push karta hai. Badlav na ho to commit nahi karta.
- Manual sync: `sudo alphacp-sync`. Status dekhna ho to: `sudo alphacp-sync --status`
- Server sirf `server-snapshot/` ko chhoota hai. Baaki repo AI/dev ka hai, isliye conflict nahi hota.

## 5. Abhi kahan hain (roadmap position)
Step 0 → 2B (panel + login + RBAC + 2FA) ✅, Step 2C (license + 15-day trial) ✅ (server par; code `server-snapshot` me).
**Next: Step 3 — Provisioning engine** (hosting account create/suspend/unsuspend/terminate: Linux user, home dir,
Apache vhost, PHP-FPM pool, quota). Uske baad S4 Packages & limits → … → S12 WHM API 1 billing layer.
Latest status ke liye hamesha `server-snapshot/STATE.md` + `CHANGELOG.md` dekho.
