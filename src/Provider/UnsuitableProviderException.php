<?php

declare(strict_types=1);

namespace Beffroi\Tui\Provider;

use RuntimeException;

/**
 * A provider this program does not talk to: its metadata cannot be read, or they do not announce what an
 * administrator's sign-in is held to. The message names what is missing.
 */
final class UnsuitableProviderException extends RuntimeException
{
}
