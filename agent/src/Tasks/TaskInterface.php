<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * A task handler. Handlers MUST be:
 *  - idempotent (safe to re-run after a crash),
 *  - side-effect-explicit (no hidden writes),
 *  - honest (throw on failure; the runner records the error).
 */
interface TaskInterface
{
    /**
     * @param  array<string, mixed> $payload already schema-validated
     * @return array<string, mixed> result stored in tasks.result
     */
    public function handle(array $payload, TaskContext $ctx): array;
}
