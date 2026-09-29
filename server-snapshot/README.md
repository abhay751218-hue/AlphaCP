# server-snapshot/ — live server ki copy (automatic)

`alphacp-sync` (installer/alphacp-sync.sh) is folder ko server se banata hai. **Haath se edit mat karo.**

| File | Kya hai |
|---|---|
| `STATE.md` | versions, services, ports, migrations, routes, license files, secret files ke sirf naam |
| `files/` | server par jo code/config abhi install hai (vendor/storage/secrets ke bina) |
| `db-schema.sql` | DB structure (koi data nahi) |
| `MANIFEST.txt` | server par files ki list |
| `LAST-SYNC.md` | aakhri sync kab hua |
