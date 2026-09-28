#!/usr/bin/env bash
# Final E2E test: step2b-setup.sh under worst-case conditions (deleted cwd, broken php8.3)
set -u
export DEBIAN_FRONTEND=noninteractive

echo "== packages =="
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq php-cli php-fpm php-curl php-mbstring php-xml php-zip php-intl php-bcmath php-mysql nginx mariadb-server unzip curl openssl >/dev/null 2>&1
php -v | head -1

echo "== mariadb =="
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
pgrep -x mariadbd >/dev/null || (mariadbd-safe >/tmp/mariadb.log 2>&1 &)
for i in $(seq 1 20); do mysql -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
mysql -N -B -e "SELECT VERSION();"

echo "== composer 2.10.3 =="
if [ ! -f /tmp/composer210.phar ]; then
  curl -sS https://getcomposer.org/installer -o /tmp/ci.php >/dev/null 2>&1
  php /tmp/ci.php --version=2.10.3 --install-dir=/tmp --filename=composer210.phar >/dev/null 2>&1
fi
printf '#!/bin/sh\nexec /usr/bin/php /tmp/composer210.phar "$@"\n' > /usr/local/bin/composer
chmod 755 /usr/local/bin/composer

echo "== ACP home (step2A stand-in) + broken php8.3 trap =="
mkdir -p /usr/local/alphacp/etc /usr/local/alphacp/logs /usr/local/alphacp/var
cat > /usr/local/alphacp/etc/database.env <<'EOF'
ACP_DB_HOST=127.0.0.1
ACP_DB_PORT=3306
ACP_DB_NAME=alphacp
ACP_DB_USER=alphacp
ACP_DB_PASS=localtest123
ACP_SERVER_ID=1
EOF
printf 'ACP_PHP_PRIMARY=8.3\n' > /usr/local/alphacp/etc/install.env
chmod 0600 /usr/local/alphacp/etc/database.env
id alphacp >/dev/null 2>&1 || useradd --system --home-dir /usr/local/alphacp/panel --shell /usr/sbin/nologin alphacp
printf '#!/bin/sh\necho "php8.3 fake" >&2\nexit 1\n' > /usr/bin/php8.3 && chmod 755 /usr/bin/php8.3

echo "== state dir cleanup (fresh test) =="
rm -rf /usr/local/alphacp/panel /usr/local/alphacp/var/*.txt

echo "== WORST CASE: shell cwd = panel dir (jo script delete karegi) =="
mkdir -p /usr/local/alphacp/panel
cd /usr/local/alphacp/panel || true
ADMIN_PASSWORD='AlphaCP@2026' bash /home/user/installer/step2b-setup.sh 2>&1 | tail -30

echo
echo "== post-check =="
curl -k -s -o /tmp/f.html -w "login page -> HTTP %{http_code}\n" https://127.0.0.1:8090/
grep -oiE "<title>[^<]*</title>" /tmp/f.html | head -1
echo "-- credentials --"
cat /root/.alphacp-admin-credentials 2>/dev/null
echo "-- state files --"
ls -l /usr/local/alphacp/var/ 2>/dev/null
