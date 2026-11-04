<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function sprintf;

/**
 * The provider refused a request, with the "error" and the "error_description" it answered (RFC 6749 §5.2,
 * §4.1.2.1), both of them its words and not this program's: a screen hands them to the terminal through
 * `TerminalText` like anything else it did not write. A refusal of the authorization endpoint comes back
 * through the browser, and has no status.
 */
final class ProviderRefusalException extends ProtocolException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?int $status,
        public readonly ?string $error,
        public readonly ?string $description,
    ) {
        parent::__construct(sprintf(
            'The %s refused the request (%s)%s',
            $endpoint,
            $error ?? ($status === null ? 'no error named' : sprintf('HTTP %d', $status)),
            $description === null ? '.' : sprintf(': %s', $description),
        ));
    }
}
