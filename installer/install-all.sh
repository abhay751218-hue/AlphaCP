#!/usr/bin/env bash
# ============================================================================
# AlphaCP — MASTER "install-all" (saare features EK command se)  v1.0
# Fresh VPS/dedicated par: pehle base panel (alphacp-sync v1.5) install karo,
# phir YE ek script chalao — saare cPanel-parity features lag jayenge.
# Har feature commit-pinned + sha256-verified (wahi jo live test hue hain).
# ============================================================================
set -uo pipefail   # -e nahi: ek feature fail ho to baaki ruke, summary me dikhe

echo "=================================================="
echo " AlphaCP install-all  v1.0 (saare features)"
echo "=================================================="

run() { # run <commit> <file> <sha256>
  local c="$1" f="$2" s="$3"
  echo; echo ">> $f"
  if alphacp-sync get "$c" "installer/$f" "/tmp/$f" "$s"; then
    bash "/tmp/$f" || echo "[WARN] $f install me dikkat — baaki jaari"
  else
    echo "[WARN] $f fetch nahi hua — skip"
  fi
}

# 1) FTP Accounts (Pure-FTPd)
run dc72b2e48ba8de2592e9dbadc3517a3a6c19ff4b ftp-accounts.sh 21c7e6531e68d7b8fc14e18805142a6c78dcca1d79a18485ba36febeeba9e904
# 2) Metrics (Visitors/Errors/Bandwidth)
run 57234320bf6c0553570fb6e2f0146d2568471a3a metrics.sh 4882374e29d2ba6287fc5549b917c51dd62f23faf90e011bdb9f1867078b6367
# 3) License keep-alive (+365 din)
run 3f9ada2861c61c3410a695ee37f0d4e22bb0a1eb license-keepalive.sh c6589f0889243747a6f9673392af0ded759e0fc416b25b8657bf283eb5cde8d3
# 4) IP Blocker
run 24850f1a388dc0579e467eb091f753d53124d2ed ip-blocker.sh 041bd9c9fb36d6f699296bae09d73459c265db5456f5d0d5822b4251ac6ef34a
# 5) WAF (ModSecurity) + Virus Scanner (ClamAV)
run 996b7cee96516f0728e91077c24a737e10aaf64a waf.sh 4a6660104723a65500fa162aac735f783ec7220a9d600c47330d2addedf2a8ea
# 6) App Installer (WordPress one-click)
run 955ba3753443928e443d2f3d3401a359a847720d app-installer.sh 9882cc4172df9b9fcc3b9348cd881f7d4ee2397bb04c0365a4737d8b42bdd066
# 7) Monitoring (Resource Usage)
run 9aaa40e830f851df887e8e82232bf18b4ba908bb monitoring.sh ae111c38f5ade9c8221f0fd171155a044f5b6196172a404a2c10b0bc08bd5057
# 8) WHM API 1 (billing)
run 3ff4d8723100f2f9e0457120dec5543c56754b50 whm-api.sh 755c5fb3310f88bc7b3a2fe3141b6aea515057712174a307273c93d20e1c49b8
# 9) API Tokens UI (panel se token)
run 930aa166a64312414129af2c417b789d39b3601c api-tokens.sh b5b05f635cbfd7766dff8b5622c57162c31d8f9c8c97388b287f781529c65f9b
# 10) Reseller Center (brand-clean)
run ca6398ffbf661da5ca811e10eba8796ce00be65f resellers.sh c2b48bd6c7aa5a8aa515b11c8dcec016fa82e8a8099f87cd1319d29596f30bdd
# 11) G5 Git Version Control + Terminal (brand-clean)
run ca6398ffbf661da5ca811e10eba8796ce00be65f g5.sh 38f26d8c1eb72da5122ffc98106fa6c0a70aa61b47752f2b6d64a8d1267f37cb
# 12) License Server (sellable signed licenses)
run 0b6acfa24c54e7a149d7b4e436935d9467429439 license-server.sh 8964067df7aad6ef31531a18ee82a34d0b482891d682a887da0b08795b2b631d
# 13) REBRAND (legacy base words -> AlphaCP) — internal, hamesha last
run dfbd379474547fb1ba96547a7ac5b4493233fb7f rebrand.sh cad5d5c039b5800e2acd2c288f58700e8f5557ca9add5fbcaae843d6aafcca77

echo
echo "=================================================="
echo " ==> INSTALL-ALL v1.0 COMPLETE"
echo " Pages: /ftp /metrics /license /ip-blocker /security-tools /apps /monitoring /api-tokens /resellers"
echo " API:   /json-api/* (Bearer token)"
echo "=================================================="
alphacp-sync || true
