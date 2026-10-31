<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The request was larger than the API accepts.
 *
 * The body is capped at 4 MiB and the document travels base64-encoded, so the
 * largest document that fits is about 3 MB. Terminal; nothing was sent. When
 * the hosting platform refused the request itself, only `vercelId` is set.
 */
class DocumentTooLargeException extends InvalidRequestException
{
}
