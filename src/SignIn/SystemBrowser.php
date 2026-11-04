<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Amp\Process\Process;
use function Amp\async;
use function is_string;
use const PHP_OS_FAMILY;
use Throwable;

/**
 * The browser the system opens a URL with: the program `$BROWSER` names, then `open` on macOS and `xdg-open`
 * elsewhere. The URL is one argument of the command and never a word of a shell. The command is started
 * without being waited for, on the event loop of the screen, which a browser that takes its time to come up
 * does not hold.
 */
final readonly class SystemBrowser implements BrowserInterface
{
    public function __construct(
        private ?string $browser,
    ) {
    }

    /**
     * @param array<array-key, mixed> $environment
     */
    public static function of(array $environment): self
    {
        $browser = $environment['BROWSER'] ?? null;

        return new self(is_string($browser) && $browser !== '' ? $browser : null);
    }

    public function open(string $url): void
    {
        $command = $this->browser ?? (PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open');

        try {
            $process = Process::start([$command, $url]);
            async(static function () use ($process): void {
                try {
                    $process->join();
                } catch (Throwable) {
                    // the URL is on the screen
                }
            });
        } catch (Throwable) {
            // the URL is on the screen
        }
    }
}
