#!/usr/bin/env bash
# AlphaCP — Standalone error pages installer  v2.0
# Error pages ab NA layout extend karti hain NA DB/auth use karti hain —
# galat URL / 405 / 500 kabhi layout-crash se 500 nahi denge.
set -euo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
echo "=================================================="
echo " AlphaCP Standalone Error Pages installer  v2.0"
echo "=================================================="
mkdir -p "$PANEL/resources/views/errors"
cat > "$PANEL/resources/views/errors/403.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>403 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>403</h1>
<p>Is action ki permission nahi hai.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/403.blade.php"
cat > "$PANEL/resources/views/errors/404.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>404 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>404</h1>
<p>Ye page panel me nahi hai.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/404.blade.php"
cat > "$PANEL/resources/views/errors/405.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>405 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>405</h1>
<p>Is URL par ye method allowed nahi hai.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/405.blade.php"
cat > "$PANEL/resources/views/errors/419.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>419 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>419</h1>
<p>Session expire ho gaya — dobara login karein.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/419.blade.php"
cat > "$PANEL/resources/views/errors/429.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>429 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>429</h1>
<p>Bahut saari koshishein — thodi der ruk kar try karein.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/429.blade.php"
cat > "$PANEL/resources/views/errors/500.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>500 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>500</h1>
<p>Server error — dobara koshish karein.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/500.blade.php"
cat > "$PANEL/resources/views/errors/503.blade.php" <<'ACP_FILE_EOF'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>503 · AlphaCP</title>
<style>
body{background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#1e293b;padding:2.5rem 3.5rem;border-radius:14px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{font-size:3.2rem;margin:0 0 .4rem;color:#60a5fa}
p{margin:.3rem 0}
a{color:#60a5fa;text-decoration:none}
small{color:#64748b}
</style>
</head>
<body>
{{-- Standalone error page: NO layout, NO database, NO auth — kabhi crash nahi hogi. --}}
<div class="card">
<h1>503</h1>
<p>Panel abhi maintenance me hai — jald wapas.</p>
<p><a href="{{ url('/') }}">← Panel login</a></p>
<small>AlphaCP</small>
</div>
</body>
</html>
ACP_FILE_EOF
echo "  + errors/503.blade.php"

cd "$PANEL"
php artisan view:clear || true
echo "=================================================="
echo " ==> STANDALONE ERROR PAGES v2.0 APPLIED"
echo "=================================================="
alphacp-sync || true
