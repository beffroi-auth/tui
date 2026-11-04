<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use RuntimeException;

/**
 * What an exchange with the provider ends in when it does not end in what was asked: the provider said no
 * (`ProviderRefusalException`), this program refused what it answered (`RejectedAnswerException`), or nothing
 * answered (`UnreachableEndpointException`).
 */
abstract class ProtocolException extends RuntimeException
{
}
