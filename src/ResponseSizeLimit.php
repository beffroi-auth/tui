<?php

declare(strict_types=1);

namespace Beffroi\Tui;

use function sprintf;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * How much of one answer this program reads: every document a provider answers it is a few kilobytes, and an
 * answer that goes on is cut rather than held in memory until PHP stops, which would leave the terminal as the
 * screen had set it. It is the `on_progress` of every request (`HttpClients`), and stops one as soon as it says
 * or receives more.
 */
final readonly class ResponseSizeLimit
{
    public const int OCTETS = 1_048_576;

    public function __construct(
        private int $octets = self::OCTETS,
    ) {
    }

    /**
     * The `on_progress` of Symfony HttpClient: what was received so far, and what the answer said it would be.
     *
     * @throws TransportException when the answer is or says it is larger
     */
    public function __invoke(int $downloaded, int $announced): void
    {
        if ($downloaded > $this->octets || $announced > $this->octets) {
            throw new TransportException(sprintf('The answer is larger than the %d octets this program reads of one.', $this->octets));
        }
    }
}
