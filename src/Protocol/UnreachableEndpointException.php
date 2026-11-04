<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

/**
 * An endpoint that answered nothing: the network, the name, the certificate or the time a request is given.
 */
final class UnreachableEndpointException extends ProtocolException
{
}
