# Repo Verification Report — 5 Oct 2026

**Scope:** poora repo — `main` (d2cbe3e), live server snapshot (16:09Z), aur saare khule PR branches.
**Method:** sandbox me system PHP nahi hai, isliye repo ke apne `tools/sim/` runners (php-wasm 8.5 + SQLite) se
sab kuch chala kar verify kiya. Git clone shallow thi → pehle `git fetch --unshallow` (168 commits) kiya,
warna `update-sim` me jhoothi failures aati hain (neeche F5).
**Session branch:** `arena/01a10ce8-alphacp` (main se branched).

---

## 1. TL;DR (seedha jawab)

| Sawaal | Jawab |
|---|---|
| Repo ke tests pass hote hain? | **Haan — 0 fail.** main: panel 42/0, doctor 21/21, update 54/54, sync **60/60** (fix ke baad) |
| Code 100% error-free hai? | Syntax/lint/hashes/secrets — **haan** (0 error). Par **repo structure me 2 bade issue** hain (F1, F2) |
| Sabse badi baat? | **`main` par code 0.3.2 hai, par live server par 0.74.0 chal raha hai.** 0.74.0 ka source **sirf ek unmerged PR branch me** hai |
| Kya kar na chahiye? | F1/F2 fix kiye bina `main` ko "single source of truth" maan lena — server chal raha hai, par uska source merge nahi hua |

---

## 2. Verified results (evidence)

### 2a. Static checks — sab clean

| Check | Scope | Result |
|---|---|---|
| PHP syntax (`php -l`, php-wasm 8.5.10) | main ke **656** PHP files | **0 error** |
| PHP syntax | PR3 ke changed **484** PHP files | **0 error** |
| Shell syntax (`bash -n`) | 20 scripts (main) / 17 (PR3) | **0 error** |
| Python (`py_compile`) / JSON parse | tools/*.py, saare *.json | **0 error** |
| Secrets scan | tracked files, `.env`/keys/private-key patterns | **koi secret nahi**; `.env`, keys, license.json tracked **nahi** |
| TODO/FIXME/debug leftovers (`var_dump`, `console.log`) | code | **0** |

### 2b. main ke test suites (docs ke claims se match)

| Suite | Mera result | Docs claim | Match |
|---|---|---|---|
| `tools/sim/panel-tests.sh` (php-wasm, SQLite) | **42 pass, 0 fail, 6 wasm-skip** | 42/0/6 | ✅ |
| `tools/sim/doctor-sim.sh` (sudo) | **21/21** | 21/21 | ✅ |
| `tools/sim/update-sim.sh` (sudo) | **54/54** | 54/54 | ✅ |
| `tools/sim/sync-sim.sh` (sudo) | 56/60 → **60/60 (fix ke baad, F4)** | 60/60 | ✅ (fix ke baad) |

wasm-skip 6 = php-wasm ki known limit (Mockery console mock + child `php` process) — code ka bug nahi.

### 2c. Artifacts / pins / hashes — sab sahi

| Item | Result |
|---|---|
| `installer/panel-update.sh` ka `BUNDLE_SHA256` vs `artifacts/panel-code-0.3.2.tar.gz` | **match** ✅ |
| `SYNC_TOOL_SHA256` vs `installer/alphacp-sync.sh` | **match** ✅ |
| Pinned commits (6001033f, 4b4573f9, aa2091d, 0c90863a, 1d61cb8, d5ae8d2, d741f79, da353902, ebdbd75) | GitHub par **reachable + file maujood** ✅ |
| COMMANDS.md ke commit-pins | sab resolve hote hain ✅ (ek `01a0ea0d` sirf branch ka naam hai, commit nahi) |
| PR5: BUNDLE_SHA256 vs artifact 0.67.0 | **match** ✅; commit `f66527b7` par artifact maujood ✅ |
| PR3: BUNDLE_SHA256 vs artifact 0.74.0 | **match** ✅; commit `2654506a` par artifact maujood ✅ |

### 2d. Live server (snapshot 5 Oct 16:09Z — sync healthy, 1 ghante pehle)

```
panel code : 0.74.0     ACP_VERSION : 0.81.0     AGENT_VERSION : 0.81.0
panel http : 200        Laravel 13.33.0          PHP 8.4.26 / MariaDB 10.11
license    : local_trial (20 accounts)  expires_at 2026-10-13 22:44Z  -> valid
```
main ka source is snapshot se **356 files** alag hai (main purana hai).

### 2e. Khule PR branches (compare `main...branch`)

| PR | Branch | Panel | Ahead/Behind | **Code** conflicts | Snapshot conflicts |
|---|---|---|---|---|---|
| #2 | `arena/01a0ea98-alphacp` | 0.64.0 | +183 / -143 | **0** | 0 (clean merge) |
| **#3** | `arena/01a10111-alphacp` | **0.74.0** | +117 / -47 | **0** | 64 paths (auto-gen) |
| #4 | `arena/01a10292-alphacp` | 0.66.0 | +11 / -43 | **0** | 0 (clean merge) |
| #5 | `arena/01a1029e-alphacp` | 0.67.0 | +12 / -43 | **0** | 20 paths (auto-gen) |

PR branches ke apne test results (jo maine chala kar verify kiye):

| Branch | Check | Result |
|---|---|---|
| PR #5 (0.67.0) | panel PHPUnit (407 tests) | **407 pass, 0 fail, 6 wasm-skip** ✅ (docs se match) |
| PR #5 | provision-sim / backup-tar-sim | **PASS** ✅ |
| PR #3 (0.74.0) | panel PHPUnit (457 test methods) | **451 pass, 0 fail, 6 wasm-skip** ✅ |
| PR #3 (0.74.0) | provision-sim (agent tests) | **207 pass, 0 fail** ✅ |
| PR #3 (0.74.0) | update-sim (updater 0.81.1) | **247 pass, 0 fail** ✅ (docs se match) |

Branch activity (head commit date) — sabse taaza lineage kaun hai:

| Branch | Head commit | Panel |
|---|---|---|
| **`arena/01a10111` (PR #3)** | **5 Oct 15:46** | 0.74.0 |
| `arena/01a1029e` (PR #5) | 3 Oct 20:42 | 0.67.0 |
| `arena/01a10292` (PR #4) | 3 Oct 17:38 | 0.66.0 |
| `arena/01a0ea98` (PR #2) | 3 Oct 13:09 | 0.64.0 |

PR #3 ka parity checklist: **61 ✅ / 61 🟡 / 94 ⏳** (main par sirf 15 ✅ / 22 🟡 / 174 ⏳).

---

## 3. Findings (severity ke hisaab se)

### 🔴 F1 — `main` par code bahut purana hai; live 0.74.0 ka source sirf PR #3 me hai

- `main` ke `refs/panel-2b-bundle/` me **panel 0.3.2** hai. Live server par **0.74.0** (MANIFEST + `.env`) chal raha hai.
- PR #3 (`arena/01a10111-alphacp`) ka source live se **almost byte-for-byte match** karta hai:
  MANIFEST 0.74.0 dono; live panel me **aisi koi file nahi** jo PR #3 ke source me na ho (live ⊆ source).
  Delta sirf itna: PR #3 me 6 files extra hain (views `backup/`, `backup-destinations/`, `ssl/`, `transfer-tool/`
  + `SshTest.php`, `TransferToolTest.php`) aur agent live 0.81.0 vs source 0.81.1 (`AccountOs.php`,
  `MysqlServer.php` extra) — yaani server thoda **peeche** hai, aage nahi. PR #3 ka head commit
  (5 Oct 15:46) sab branches me sabse naya hai.
- **Risk:** PR #3 agar band/delete ho jaye to deployed product ka source `main` me kahin nahi hai.

### 🔴 F2 — 4 PR khule pade hain, aur wo main se diverge hain

- Chaaro PR (0.64.0 → 0.74.0) **unmerged** hain; main sirf `server-snapshot/` sync commits leta rehta hai.
- **Achhi khabar:** koi bhi **code conflict nahi** hai. PR #3 me 64 aur PR #5 me 20 conflicting paths hain,
  lekin **100% `server-snapshot/`** (auto-generated) files me — inhe main ki nayi snapshot se replace kar dena chahiye.
- PRs ek doosre ke upar nahi, balki alag-alag lineage hain. Comparison se saaf hai:
  **PR #3 (0.74.0) baaki sab ka superset hai** — 70 controllers vs 68-69 (baaki me `LoginController`/
  `TwoFactorController` purani jagah `app/Http/Controllers/` me hain, PR #3 me naye `Auth/` folder me),
  aur PR #5/PR #4/PR #2 ke features (MultiPHP, S10 real home restore, transfer/restore, backup destinations) PR #3 me maujood hain.

### 🟠 F3 — PR #3 ke andar docs drift

- `START-HERE.md` (PR #3 branch) panel **0.72.0** aur **442/0** tests claim karta hai, lekin branch me
  artifact **0.74.0** hai aur **457** test methods hain. Release docs ke claims thoda peeche hain.

### 🟡 F4 — `sync-sim.sh` ka harness bug (is session me FIX kiya) 

`tools/sim/sync-sim.sh` ki Run 8 (`alphacp-sync get`) line:

```bash
GC="$(git -C "${REMOTE}" rev-parse refs/heads/arena/01a0ea3e-alphacp 2>/dev/null || git -C "${REMOTE}" rev-parse main)"
```

`git rev-parse <bad-ref>` (git ≥ 2.39) **fail hone se pehle ref ka naam STDOUT par** likh deta hai, isliye `GC`
me kachra (`refs/heads/arena/01a0ea3e-alphacp` + fallback SHA) chala jata tha → 4 tests (`get basic`,
`sha mismatch`, `missing path`, `no key`) **har us checkout me FAIL** hote the jisme local branch ka naam
`arena/01a0ea3e-alphacp` na ho — yaani aaj ke sabhi `arena/*` sessions me.

**Fix:** `rev-parse --verify --quiet` + fallback chain (`PR-ref → main → HEAD`).
**Verify:** sync-sim **56/60 → 60/60** ✅ (docs ka claim ab sach me reproducible hai).

### 🟡 F5 — sims ko poora git history chahiye (shallow clone me jhoothi failure)

Shallow clone me `update-sim` **49/54** deta hai: `install_old_sync()` `aa2091dc:installer/alphacp-sync.sh`
`git show` karta hai, jo shallow clone me nahi milta → U4/U5 cascade fail. `git fetch --unshallow` ke baad
**54/54** ✅. Fix unnecessary hai, par runbook me "full clone" likhna chahiye.

### ℹ️ F6 — Live trial 13 Oct 2026 ko expire hoga

`expires_at 2026-10-13 22:44Z` — yaani **8 din**. License-server API (Step 15) abhi nahi bana. Rules ke
mutabakat license fail par customer websites/email band nahi hote — panel degrade hota hai. Time par decide
karna hoga: license-server, ya license key, ya trial extend.

### ℹ️ F7 — By-design pending cheezein (bug nahi)

`license-server/` sirf README hai (Step 15), `panel/` purana scaffold hai (source ab `refs/panel-2b-bundle/`),
`parity checklist` par main me 15 ✅ / 22 🟡 / 174 ⏳ — kyunki main purane code ka checklist hai.

---

## 4. Recommended next steps (is order me)

1. **PR #3 ko merge karo** (live 0.74.0 ka source) — pehle `server-snapshot/` conflicts ko **main ki nayi
   snapshot se replace** karo (auto-generated hai, hand-merge ki zaroorat nahi). Iske baad main = live source.
2. **PR #4 / #5 / #2 ko close karo (superseded)** ya unke useful commits PR #3 me already hain ye confirm
   karke band karo — warna reviewer/agent confusion bana rahega aur main ka "latest" hamesha do jagah rahega.
3. `main` par **branch protection / merge discipline**: sync commits ke saath code lineage bhi merge karo, warna
   main har hafte aur peeche chala jayega.
4. PR #3 branch me **docs ko 0.74.0 / 457 tests** par update karo (F3).
5. **Trial expiry (13 Oct)** ka decision lo (F6).
6. Naye sandbox me test chalane se pehle `git fetch --unshallow` (F5) — runbook me likho.

---

## 5. Is session branch me kya badla

| File | Change |
|---|---|
| `tools/sim/sync-sim.sh` | Run 8 ka `GC=` parsing fix (F4) — test harness only, product code nahi |
| `CHANGELOG.md` | Fix ka entry (test counts ke saath) |
| `docs/10-repo-verification-2026-10-05.md` | Yahi report |

Product code (panel/agent/installer) me **kuch nahi badla** — koi bug nahi mila jo product code me fix maange.

---

## 6. PR #3 (live lineage) — final results

| Suite | Result |
|---|---|
| `tools/sim/panel-tests.sh` (panel 0.74.0) | **451 pass, 0 fail, 6 wasm-skip** ✅ |
| `tools/sim/provision-sim.sh` (agent) | **207 pass, 0 fail** ✅ |
| `tools/sim/update-sim.sh` (updater 0.81.1) | **247 pass, 0 fail** ✅ |
| Static: 484 changed PHP files lint + 17 shell scripts `bash -n` | **0 error** ✅ |
| Hash/pin: `BUNDLE_SHA256` = artifact, commit `2654506a` par artifact maujood | ✅ |

Yaani jo code **live server par chal raha hai**, uska source bhi poori tarah green hai — problem
code ki quality ki nahi, **merge/workflow ki hai** (F1, F2).

> Note: PR #3 ki `tools/sim/sync-sim.sh` me bhi wahi GC bug (F4) hai (line 163) — us branch me bhi
> 4 tests fail honge jab tak ye fix merge na ho. Product code par koi asar nahi.
