<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/**
 * A task was refused before (or during) execution: unknown type, invalid
 * payload, unsafe path, destructive task without confirmation, etc.
 * The runner records it as a failed task + a `security.agent.rejected`
 * audit event instead of retrying it.
 */
final class TaskRejectedException extends RuntimeException
{
}
