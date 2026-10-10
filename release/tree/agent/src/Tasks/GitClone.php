<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * git.clone — `<home>/git/<dir>` me repo clone karta hai (cPanel "Git Version
 * Control" → Clone a Repository). URL sirf http(s)/git/ssh scheme ka; `git clone --`
 * se option-injection band; target pehle se maujood ho to reject (overwrite nahi).
 *
 * @acp-task git.clone
 */
final class GitClone extends GitTask
{
    private const SCHEMES = ['https', 'http', 'git', 'ssh'];

    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $dir = $this->dir((string) ($payload['dir'] ?? ''));
        $url = $this->url((string) ($payload['url'] ?? ''));

        $fs = $this->fs($ctx);
        $base = \Alphacp\Agent\Git::base($this->home($ctx, $username));
        $fs->mkdir($base, 0755);
        $path = $this->path($ctx, $username, $dir);
        if ($fs->exists($path)) {
            throw new TaskRejectedException("repo dir already exists: {$dir}");
        }

        $r = $this->git($ctx)->cloneRepo($url, $path, 120);
        if (!$r->ok()) {
            throw new TaskRejectedException('git clone failed: ' . substr(trim($r->stderr), 0, 300));
        }
        // repo files account ke naam (clone root se hua hai)
        $fs->chownName($path, $username, $username);

        return [
            'username' => $username,
            'dir'      => $dir,
            'url'      => $url,
            'path'     => $path,
            'status'   => 'ok',
        ];
    }

    /** URL validate: proper URL, scheme whitelist, koi control-char/space nahi. */
    private function url(string $raw): string
    {
        $url = trim($raw);
        if ($url === '' || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            throw new TaskRejectedException('invalid git URL');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new TaskRejectedException('invalid git URL (scheme+host chahiye)');
        }
        if (!in_array(strtolower($parts['scheme']), self::SCHEMES, true)) {
            throw new TaskRejectedException('git URL scheme sirf https/http/git/ssh ho sakta hai');
        }

        return $url;
    }
}
