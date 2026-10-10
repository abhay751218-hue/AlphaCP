<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/** Immutable result of one array-exec command. */
final class CommandResult
{
    /** @param list<string> $argv */
    public function __construct(
        public readonly array $argv,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function stdoutTrimmed(): string
    {
        return trim($this->stdout);
    }

    /** Redact the actual values for logs when the argv may hold secrets. */
    public function printable(): string
    {
        return implode(' ', $this->argv);
    }
}
