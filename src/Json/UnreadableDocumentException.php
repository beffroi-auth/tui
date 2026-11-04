<?php

declare(strict_types=1);

namespace Beffroi\Tui\Json;

use RuntimeException;

/**
 * A document that is not what its reader expected, refused where it was read rather than believed.
 */
final class UnreadableDocumentException extends RuntimeException
{
}
