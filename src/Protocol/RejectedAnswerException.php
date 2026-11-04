<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

/**
 * An answer this program does not believe: a response that names another issuer or no state, a token that is
 * not bound to its key, an ID token whose signature or claims do not hold, a document it cannot read. Nothing
 * that came with it is used.
 */
final class RejectedAnswerException extends ProtocolException
{
}
