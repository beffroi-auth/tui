<?php

declare(strict_types=1);

namespace Beffroi\Tui\Profile;

use RuntimeException;

/**
 * A profile that cannot be found, read or written, or whose file others may open; the message says which and
 * what to do about it.
 */
final class ProfileException extends RuntimeException
{
}
