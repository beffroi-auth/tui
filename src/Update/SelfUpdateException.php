<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use RuntimeException;

/**
 * Why this program was not replaced: it does not run from a release, no release could be read, or what a release
 * served is not what its signature vouches for. The program that runs is left as it was.
 */
final class SelfUpdateException extends RuntimeException
{
}
