<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use RuntimeException;

/**
 * The redirect URI of the client cannot be listened on: it is not one of the loopback interface, or something
 * else holds its port.
 */
final class CannotListenException extends RuntimeException
{
}
