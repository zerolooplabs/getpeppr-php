<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * An error result whose remediation is `none`, such as `501 provider.not_supported`.
 *
 * Terminal: do not resend.
 */
class UnsupportedOperationException extends GetpepprException
{
}
