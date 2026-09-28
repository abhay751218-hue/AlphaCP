#!/usr/bin/env bash
# Local end-to-end test of installer/step2b-setup.sh (container only)
set -u
export DEBIAN_FRONTEND=noninteractive
echo "== 1. packages =="
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq php-cli php-fpm php-curl php-mbstring php-xml php-zip php-intl php-bcmath php-mysql nginx mariadb-server unzip curl openssl >/dev/null 2>&1
php -v | head -1; nginx -v 2>&1

echo "== 2. mariadb =="
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
pgrep -x mariadbd >/dev/null || (mariadbd-safe >/tmp/mariadb.log 2>&1 &)
for i in $(seq 1 20); do mysql -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
mysql -N -B -e "SELECT VERSION();"

echo "== 3. composer 2.10.3 =="
if [ ! -f /tmp/composer210.phar ]; then
  curl -sS https://getcomposer.org/installer -o /tmp/ci.php >/dev/null 2>&1
  php /tmp/ci.php --version=2.10.3 --install-dir=/tmp --filename=composer210.phar >/dev/null 2>&1
fi
printf '#!/bin/sh\nexec /usr/bin/php /tmp/composer210.phar "$@"\n' > /usr/local/bin/composer
chmod 755 /usr/local/bin/composer
composer --version

echo "== 4. ACP home (Step 2A stand-in) =="
mkdir -p /usr/local/alphacp/etc /usr/local/alphacp/logs /usr/local/alphacp/var
cat > /usr/local/alphacp/etc/database.env <<'EOF'
ACP_DB_HOST=127.0.0.1
ACP_DB_PORT=3306
ACP_DB_NAME=alphacp
ACP_DB_USER=alphacp
ACP_DB_PASS=localtest123
ACP_SERVER_ID=1
EOF
# server jaisa trap: install.env me 8.3 likha hai aur /usr/bin/php8.3 ek DUMMY hai
# (artisan boot nahi kar sakta) — script ko khud sahi PHP chunni chahiye
printf 'ACP_PHP_PRIMARY=8.3\n' > /usr/local/alphacp/etc/install.env
printf '#!/bin/sh\necho "PHP 8.3 fake: extensions missing" >&2\nexit 1\n' > /usr/bin/php8.3
chmod 755 /usr/bin/php8.3
echo "(test setup: /usr/bin/php8.3 dummy = broken, install.env says 8.3)"
chmod 0600 /usr/local/alphacp/etc/database.env
id alphacp >/dev/null 2>&1 || useradd --system --home-dir /usr/local/alphacp/panel --shell /usr/sbin/nologin alphacp

echo "== 5. run step2b-setup.sh (the real test) =="
rm -rf /usr/local/alphacp/panel
bash /home/user/installer/step2b-setup.sh 2>&1 | tail -32

echo "== 6. post-check =="
curl -k -s -o /tmp/final.html -w "panel / -> HTTP %{http_code}\n" https://127.0.0.1:8090/
grep -oiE "<title>[^<]*</title>" /tmp/final.html | head -1
echo "-- credentials file --"
cat /root/.alphacp-admin-credentials 2>/dev/null
echo "-- fpm pool user --"
grep -E "^user|^listen" /etc/php/8.4/fpm/pool.d/alphacp.conf 2>/dev/null
