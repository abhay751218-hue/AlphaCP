# AlphaCP — Theme Demo

`cpanel-theme-demo.html` ek **self-contained static preview** hai naye "Paper Lantern"
theme ka — cPanel company-grade look (signature orange `#FF6C2C`, light canvas,
icon-grid tools, right-rail info panels).

## Kaise dekhein

Option 1 — seedha file open karo (double-click):

```
demo/cpanel-theme-demo.html
```

Option 2 — local server se (search box fully live rahega):

```bash
cd /home/user/AlphaCP
python3 -m http.server 8000
# browser me khole: http://localhost:8000/demo/cpanel-theme-demo.html
```

## Demo me kya hai

| Section | Kya dikhata hai |
|---|---|
| **Palette** | Theme ke sab colors with hex values (cPanel Orange `#FF6C2C` first) |
| **Icon set** | 51 inline SVG stroke icons (no emoji, no icon font) |
| **cPanel login** | Light login card, orange accent button |
| **cPanel (client)** | Statistics cards + 9 sections / 82 tools icon grid + right rail (General information, Services, Recent activity) |
| **WHM (admin)** | Dark sidebar + categorized menu (49 items, parity rows) + Server information + Accounts + Services |
| **Reseller** | Reseller-scoped WHM shell (teal accent, 13-item menu) + My accounts + Usage overview |
| **Image previews** | 3 design preview images (`demo/assets/`) — cPanel client, WHM admin, login |

Top search box live hai — type karo ya `/` press karo (cPanel Jupiter jaisa).

## Preview images

`demo/assets/` me 3 images hain (design previews):

| File | Kya hai |
|---|---|
| `alphacp-cpanel-client.png` | cPanel client dashboard — Paper Lantern theme |
| `alphacp-whm-admin.png` | WHM admin panel — dark sidebar |
| `alphacp-login.png` | Login page — orange accent |

Ye images demo HTML me bhi embedded hain (`#previews` section).

## Regenerate

Demo real sources se generate hota hai (drift nahi hota):

```bash
python3 tools/sim/build-theme-demo.py   # demo banata hai
python3 tools/sim/theme-check.py       # 21/21 checks — no-error gate
```
