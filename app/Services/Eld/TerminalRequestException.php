<?php

namespace App\Services\Eld;

use RuntimeException;

/**
 * A Terminal call that failed in a way a sync cannot carry on through.
 *
 * Thrown rather than returned so a half-read fleet never gets written as if it
 * were the whole fleet — the job fails, the checkpoint is left where it was,
 * and the next run picks up from the same place.
 */
class TerminalRequestException extends RuntimeException {}
