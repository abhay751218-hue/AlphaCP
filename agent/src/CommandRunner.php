<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/**
 * Array-exec only. Never shell strings, never interpolation.
 *
 * Defence in depth:
 *  - argv[0] must resolve to a binary inside the allowlist,
 *  - each argument is passed as its own argv element (no shell involved),
 *  - hard timeout with SIGTERM → SIGKILL escalation,
 *  - stdout/stderr captured, never inherited by the panel.
 */
final class CommandRunner implements CommandExecutor
{
    /** Binaries the agent is allowed to execute today (grows per step, reviewed). */
    private const BIN_ALLOWLIST = [
        '/usr/bin/systemctl',
        '/bin/systemctl',
        '/usr/bin/hostname',
        '/bin/hostname',
        '/usr/bin/uptime',
        '/usr/bin/df',
        '/bin/df',
        '/usr/bin/free',
        '/usr/bin/id',
        '/usr/bin/getent',
        '/usr/bin/stat',
        '/bin/stat',
        '/usr/sbin/useradd',
        '/usr/sbin/userdel',
        '/usr/sbin/usermod',
        '/usr/sbin/setquota',
        '/usr/bin/setquota',
        '/usr/bin/crontab',
        '/usr/bin/openssl',
        '/usr/bin/certbot',
        '/usr/bin/tar',
        '/bin/tar',
        '/usr/bin/mariadb',
        '/usr/bin/mysql',
        // S10 remote pull + remote backup destinations (openssh-client + optional
        // sshpass). argv-only; the agent never builds a shell string, so
        // scp/ssh/ssh-keyscan cannot be tricked into running something else.
        // `ssh` is here because a destination test/push/browse has to RUN a
        // command on the far side (mkdir/checksum/ls) — it was missing in 0.73.0
        // and every destination action failed with "binary not in allowlist".
        '/usr/bin/ssh-keyscan',
        '/usr/bin/ssh-keygen',
        '/usr/bin/scp',
        '/usr/bin/ssh',
        '/bin/ssh',
        '/usr/bin/sshpass',
        // S9 BIND9 (dns.bind): config check, zone check, reload aur asli dig jawab.
        // Dono (/usr/sbin + /usr/bin + /usr/local) isliye: distro ke hisaab se
        // binary kahin bhi ho sakta hai, aur allowlist me na ho to task chup-chaap
        // fail ho jata hai (0.73.1 wali `ssh` bhool dobara na ho).
        '/usr/sbin/named-checkconf',
        '/usr/bin/named-checkconf',
        '/usr/local/sbin/named-checkconf',
        '/usr/local/bin/named-checkconf',
        '/usr/sbin/named-checkzone',
        '/usr/bin/named-checkzone',
        '/usr/local/sbin/named-checkzone',
        '/usr/local/bin/named-checkzone',
        '/usr/sbin/rndc',
        '/usr/bin/rndc',
        '/usr/local/sbin/rndc',
        '/usr/local/bin/rndc',
        '/usr/bin/dig',
        '/usr/sbin/dig',
        '/bin/dig',
        '/usr/local/bin/dig',
        // S7 mail (mail.server): config generate/validate, IMAP/POP3, user lookup.
        // Dono (/usr/sbin + /usr/bin) — 0.73.1 wali `ssh` bhool dobara na ho.
        '/usr/sbin/exim4',
        '/usr/bin/exim4',
        '/usr/sbin/exim',
        '/usr/local/sbin/exim4',
        '/usr/sbin/dovecot',
        '/usr/bin/dovecot',
        '/usr/local/sbin/dovecot',
        '/usr/bin/doveadm',
        '/usr/sbin/doveadm',
        '/usr/local/bin/doveadm',
        '/usr/sbin/doveconf',
        '/usr/bin/doveconf',
        '/usr/local/sbin/doveconf',
        '/usr/sbin/update-exim4.conf',
        '/usr/bin/update-exim4.conf',
        '/usr/bin/openssl',
        '/usr/local/bin/openssl',
        // S6 FTP (ftp.add/ftp.passwd/ftp.del): Pure-FTPd virtual-user management.
        // Web FPM proc_open disabled hai (B1), isliye pure-pw sirf agent (root)
        // chalata hai — argv-only, password stdin par, `-m` se PureDB rebuild.
        '/usr/bin/pure-pw',
        '/usr/sbin/pure-pw',
        '/usr/local/bin/pure-pw',
        '/usr/local/sbin/pure-pw',
    ];

    public function __construct(private readonly int $defaultTimeout = 30)
    {
    }

    /**
     * @param  list<string> $argv full argv, argv[0] must be a real path in the allowlist
     * @return CommandResult
     */
    public function run(array $argv, ?int $timeout = null, ?string $stdin = null, ?string $stdinFile = null): CommandResult
    {
        if ($argv === []) {
            throw new RuntimeException('empty argv');
        }

        $bin = realpath($argv[0]) ?: '';
        if ($bin === '' || !in_array($bin, self::BIN_ALLOWLIST, true)) {
            throw new RuntimeException("binary not in agent allowlist: {$argv[0]}");
        }
        $argv[0] = $bin;

        $timeout ??= $this->defaultTimeout;
        $started  = hrtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($argv, $descriptors, $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed for: ' . implode(' ', $argv));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        } elseif ($stdinFile !== null) {
            // Streamed, never buffered: SQL dumps can be hundreds of MB.
            $in = @fopen($stdinFile, 'rb');
            if ($in === false) {
                fclose($pipes[0]);
                proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                throw new RuntimeException("cannot read stdinFile: {$stdinFile}");
            }
            while (!feof($in)) {
                $chunk = fread($in, 262_144);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '' && @fwrite($pipes[0], $chunk) === false) {
                    break; // the client closed stdin early (its exit code tells the story)
                }
            }
            fclose($in);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        $finalExit = -1;
        while (true) {
            $status  = proc_get_status($proc);
            $read    = array_filter([$pipes[1], $pipes[2]], static fn ($p) => is_resource($p) && !feof($p));
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $finalExit = (int) $status['exitcode']; // proc_get_status() reaps — remember it
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
                usleep(500_000);
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
                }
                break;
            }
            if ($read === []) {
                usleep(20_000);
            } else {
                usleep(5_000);
            }
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $finalExit >= 0 ? $finalExit : proc_close($proc);
        if ($finalExit >= 0) {
            proc_close($proc); // already reaped, just release the handle
        }
        if ($timedOut) {
            $exitCode = 124; // convention: timeout
        }

        return new CommandResult(
            $argv,
            $exitCode,
            $stdout,
            $stderr,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $timedOut,
        );
    }
}
