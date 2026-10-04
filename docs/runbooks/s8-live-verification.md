# Runbook — S8 live verification (real MariaDB)

**Kab chalao:** har S8 change ke baad, ya jab bhi "kya databases sach me ban rahe hain?" ka jawaab chahiye.
**Kahan:** asli server par (`root`), panel 0.70.0 + agent 0.62.0 hone chahiye.

```bash
sudo alphacp-sync get <COMMIT> tools/verify/s8-live-check.sh /tmp/acp-s8-live-check.sh <SHA256> && sudo bash /tmp/acp-s8-live-check.sh
```

## Ye kya karta hai
1. Panel DB (default `alphacp`) se ek **active account** chunta hai (`ACP_VERIFY_ACCOUNT=<user>` se override).
2. `paneld --run` se ASLI tasks chalata hai: `db.create` → `db.user.create` → `db.user.password` → `db.user.drop` → `db.drop`.
3. Har step MariaDB se verify karta hai:
   - database utf8mb4 ke saath bana (`information_schema.SCHEMATA`),
   - user bana + `ALL PRIVILEGES ON <acct>_acpverify.*` mila (`SHOW GRANTS`),
   - us password se **asli login** hota hai (password `MYSQL_PWD` env se — argv me kabhi nahi),
   - galat password reject hota hai,
   - password reset ke baad **purana fail** aur naya chalta hai,
   - task ke stored payload me password scrub (`***`) ho gaya,
   - `_confirm` ke bina `db.drop` refuse hota hai aur database bacha rehta hai,
   - drop ke baad database + user + grant teeno gayab (`mysql.user`, `mysql.db`).
4. Test objects hamesha hat jaate hain — script `trap` se cleanup karta hai, beech me Ctrl-C par bhi.

## Output kaise padho
- `=== S8 LIVE CHECK: N pass, M fail ===` — `M` **0** hona chahiye; exit code 0.
- `tasks: db.create#12, db.user.create#13, …` — panel ke **Task Queue** me yahi ids dikhenge.
- Ek task jaan-boojhkar `rejected` hoga: `db.drop` bina `_confirm` (guard proof) — ye expected hai.

## Offline pehle test karo (root nahi chahiye)
```bash
bash tools/sim/s8-live-check-sim.sh    # fake paneld + fake MariaDB: 8/0, aur ek broken run jo FAIL dena hi hoga
```
