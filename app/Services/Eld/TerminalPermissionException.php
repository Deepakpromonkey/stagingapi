<?php

namespace App\Services\Eld;

/**
 * Terminal refused a resource because the account is not entitled to it.
 *
 * Separate from its parent because the two need opposite handling. A transient
 * failure should fail the sync so the next run retries the same window; a
 * missing permission will refuse identically every time, and treating it as an
 * error means one resource an account cannot see stops every resource it can.
 */
class TerminalPermissionException extends TerminalRequestException {}
