<?php

declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * ports.apply — OWNER-CTRL: port↔panel map ko nginx par lagana.
 *
 * "Ek panel = ek port" rule:
 *   whm    → alphacp-whm.conf    (sirf WHM view; app-side PortGuard lock)
 *   cpanel → alphacp-cpanel.conf (sirf cPanel view + /internal/ SSO loc)
 *   link   → alphacp-link.conf   (static link-page, PHP nahi; owner-disableable)
 *   webmail→ alphacp-webmail.conf (Roundcube; listen port sync hota hai)
 *
 * Template source: etc/panel-vhost.template — pehli run par served panel vhost
 * se banta hai (listen/internal lines strip karke); baad me wahi source of
 * truth. Har apply: backup → render → nginx -t → reload; fail par restore.
 */
final class PortsNginx
{
    public const DEFAULTS = [
        'whm'          => 2087,
        'cpanel'       => 2083,
        'webmail'      => 2096,
        'link'         => 8090,
        'link_enabled' => true,
    ];

    /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} */
    private array $map;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
        private readonly string $home = '/usr/local/alphacp',
        private readonly string $ngxSys = '/etc/nginx',
        private readonly string $rcPlugins = '/usr/share/roundcube/plugins',
    ) {
        $this->map = self::DEFAULTS;
        $raw       = @file_get_contents($this->home . '/etc/ports.json');
        if ($raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j) && ! isset($j['ssl'])) {
                foreach (['whm', 'cpanel', 'webmail', 'link'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k])) {
                        $this->map[$k] = (int) $j[$k];
                    }
                }
                if (array_key_exists('link_enabled', $j)) {
                    $this->map['link_enabled'] = (bool) $j['link_enabled'];
                }
            }
        }
    }

    /** @return array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} */
    public function map(): array
    {
        return $this->map;
    }

    /**
     * Full apply pass — idempotent, kabhi bhi dobara chalao.
     *
     * @return array{applied:bool, ports:array<string,int|bool>, nginx:string}
     */
    /**
     * nginx binary ka absolute path (CommandRunner realpath() use karta hai,
     * PATH lookup NAHI — isliye bare name kabhi nahi chalega).
     */
    private function nginxBin(): string
    {
        foreach (['/usr/sbin/nginx', '/usr/bin/nginx', '/usr/local/sbin/nginx', '/usr/local/bin/nginx'] as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }
        throw new TaskRejectedException('nginx binary nahi mila (allowlist paths check kiye)');
    }
    public function apply(): array
    {
        $avail = $this->ngxSys . '/sites-available';
        $en    = $this->ngxSys . '/sites-enabled';
        @mkdir($avail, 0755, true);
        @mkdir($en, 0755, true);

        $tpl = $this->ensureTemplate();

        $stamp = date('YmdHis');
        $bak   = $this->home . '/releases/ports-ctrl-' . $stamp;
        @mkdir($bak, 0755, true);
        $touched = [];
        foreach (['alphacp-whm.conf', 'alphacp-cpanel.conf', 'alphacp-link.conf', 'alphacp-webmail.conf'] as $f) {
            if (is_file("$avail/$f")) {
                @copy("$avail/$f", "$bak/$f");
                $touched[] = $f;
            }
        }

        $standalone = $this->http2Standalone();
        $h2  = $standalone ? "    http2 on;\n" : '';
        $suf = $standalone ? '' : ' http2';

        file_put_contents("$avail/alphacp-whm.conf", $this->renderAppVhost($tpl, $this->map['whm'], $h2, $suf, false));
        file_put_contents("$avail/alphacp-cpanel.conf", $this->renderAppVhost($tpl, $this->map['cpanel'], $h2, $suf, true));
        $this->enable("$avail/alphacp-whm.conf", "$en/alphacp-whm.conf");
        $this->enable("$avail/alphacp-cpanel.conf", "$en/alphacp-cpanel.conf");

        // link-page (default 8090): static html, PHP nahi — owner band kar sake
        if ($this->map['link_enabled']) {
            file_put_contents("$avail/alphacp-link.conf", $this->renderLinkVhost($h2, $suf));
            $this->enable("$avail/alphacp-link.conf", "$en/alphacp-link.conf");
        } else {
            @unlink("$en/alphacp-link.conf");
            @unlink("$avail/alphacp-link.conf");
        }

        // purana 8090 app-vhost retire (ab link-page ya kuch nahi)
        if (is_file("$avail/alphacp-panel.conf")) {
            @copy("$avail/alphacp-panel.conf", "$bak/alphacp-panel.conf");
            @unlink("$en/alphacp-panel.conf");
            @rename("$avail/alphacp-panel.conf", "$bak/alphacp-panel.conf.retired");
            $touched[] = 'alphacp-panel.conf';
        }

        // webmail vhost listen port map ke saath sync
        $wconf = "$avail/alphacp-webmail.conf";
        if (is_file($wconf)) {
            $w = (string) file_get_contents($wconf);
            $w = preg_replace('/listen\s+\d+(\s+ssl)/', 'listen ' . $this->map['webmail'] . '$1', $w, 1) ?? $w;
            file_put_contents($wconf, $w);
        }

        // Roundcube plugin ke liye internal SSO URL (loopback-only, cpanel port)
        @file_put_contents(
            $this->home . '/etc/webmail-internal.url',
            'https://127.0.0.1:' . $this->map['cpanel'] . '/internal/webmail-sso',
        );
        @chmod($this->home . '/etc/webmail-internal.url', 0644);

        // Roundcube plugin config ka internal URL bhi cpanel port par sync karo
        $pc = $this->rcPlugins . '/acp_sso/config.inc.php';
        if (is_file($pc)) {
            $cs = (string) file_get_contents($pc);
            $cs = preg_replace(
                "/acp_sso_internal_url'\s*\]\s*=\s*'[^']*'/",
                "acp_sso_internal_url'] = 'https://127.0.0.1:" . $this->map['cpanel'] . "/internal/webmail-sso'",
                $cs,
            ) ?? $cs;
            file_put_contents($pc, $cs);
        }

        $t = $this->cmd->run([$this->nginxBin(), '-t'], 30);
        if (! $t->ok()) {
            foreach ($touched as $f) {
                if (is_file("$bak/$f")) {
                    @copy("$bak/$f", "$avail/$f");
                }
            }
            $msg = substr(trim($t->stdout . $t->stderr), 0, 300);
            $this->log->warning('ports.apply: nginx -t FAIL — restore kiya: ' . $msg);

            return ['applied' => false, 'ports' => $this->map, 'nginx' => $msg];
        }
        $r = $this->cmd->run([$this->nginxBin(), '-s', 'reload'], 30);
        if (!$r->ok()) {
            $r = $this->cmd->run(['systemctl', 'reload', 'nginx'], 30);
        }
        $this->log->info('ports.apply: vhosts likhe + reload (whm=' . $this->map['whm']
            . ' cpanel=' . $this->map['cpanel']
            . ' link=' . ($this->map['link_enabled'] ? (string) $this->map['link'] : 'off') . ')');

        return ['applied' => true, 'ports' => $this->map, 'nginx' => substr(trim($r->stdout . $r->stderr), 0, 200)];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $v = $this->cmd->run([$this->nginxBin(), '-v'], 10);

        return [
            'ports'    => $this->map,
            'template' => is_file($this->home . '/etc/panel-vhost.template'),
            'nginx'    => trim($v->stdout . $v->stderr),
        ];
    }

    // ---- internals -------------------------------------------------------

    /**
     * Pehli run: served panel vhost se template (listen + internal loc strip).
     * Baad ki runs me existing template hi source of truth hai.
     */
    private function ensureTemplate(): string
    {
        $tplPath = $this->home . '/etc/panel-vhost.template';
        if (is_file($tplPath)) {
            return (string) file_get_contents($tplPath);
        }

        $src = '';
        foreach ([$this->ngxSys . '/sites-enabled', $this->ngxSys . '/sites-available', $this->ngxSys . '/conf.d'] as $dir) {
            $c = "$dir/alphacp-panel.conf";
            if (is_file($c) && str_contains((string) file_get_contents($c), 'fastcgi_pass')) {
                $src = (string) file_get_contents($c);
                break;
            }
        }
        if ($src === '') {
            throw new TaskRejectedException('panel vhost template nahi mila (alphacp-panel.conf me fastcgi_pass nahi)');
        }

        $src = preg_replace('/^[ \t]*listen[ \t]+[^\n]*\n/m', '', $src) ?? $src;
        $src = preg_replace('/^[ \t]*http2[ \t]+on;\n/m', '', $src) ?? $src;
        $src = preg_replace('/[ \t]*# ACP_INTERNAL_START[^\n]*\n[ \t]*location \/internal\/ \{[^\n]*\}\n[ \t]*# ACP_INTERNAL_END[ \t]*\n?/', '', $src) ?? $src;

        file_put_contents($tplPath, $src);
        @chmod($tplPath, 0644);

        return $src;
    }

    private function renderAppVhost(string $tpl, int $port, string $h2, string $suf, bool $withInternal): string
    {
        $internal = $withInternal
            ? "    # ACP_INTERNAL_START — SSO endpoint sirf loopback se\n"
            . "    location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files \$uri /index.php?\$args; }\n"
            . "    # ACP_INTERNAL_END\n"
            : '';

        return preg_replace_callback(
            '/server\s*\{/',
            fn (array $m): string => $m[0] . "\n    listen " . $port . ' ssl' . $suf . ";\n    listen [::]:" . $port . ' ssl' . $suf . ";\n" . $h2 . $internal,
            $tpl,
            1,
        ) ?? $tpl;
    }

    /** Static link-page: nginx $host request-time substitute karta hai. */
    private function renderLinkVhost(string $h2, string $suf): string
    {
        $m    = $this->map;
        $html = '<!doctype html><meta charset="utf-8"><title>AlphaCP</title>'
            . '<body style="font-family:sans-serif;background:#1c2733;color:#eef1f4;display:grid;place-items:center;height:100vh;margin:0">'
            . '<div style="text-align:center"><h1>AlphaCP</h1>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['whm'] . '/">WHM &mdash; root / reseller</a></p>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['cpanel'] . '/">cPanel &mdash; customer</a></p>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['webmail'] . '/">Webmail</a></p>'
            . '</div></body>';

        return "server {\n"
            . '    listen ' . $m['link'] . ' ssl' . $suf . ";\n"
            . '    listen [::]:' . $m['link'] . ' ssl' . $suf . ";\n"
            . $h2
            . "    server_name _;\n"
            . $this->sslLines()
            . "    location / { default_type text/html; return 200 '" . str_replace("'", "\\'", $html) . "'; }\n}\n";
    }

    /** Template se ssl_certificate lines (link-page vhost ke liye). */
    private function sslLines(): string
    {
        $tpl = @file_get_contents($this->home . '/etc/panel-vhost.template') ?: '';
        $out = '';
        foreach (explode("\n", $tpl) as $ln) {
            if (str_contains($ln, 'ssl_certificate')) {
                $out .= '    ' . trim($ln) . "\n";
            }
        }

        return $out;
    }

    private function enable(string $avail, string $en): void
    {
        if (! is_link($en) && ! is_file($en)) {
            @symlink($avail, $en);
        }
    }

    private function http2Standalone(): bool
    {
        $v = $this->cmd->run([$this->nginxBin(), '-v'], 10);
        if (preg_match('/nginx\/([\d.]+)/', $v->stdout . $v->stderr, $m) !== 1) {
            return false;
        }

        return version_compare($m[1], '1.25.1', '>=');
    }
}
