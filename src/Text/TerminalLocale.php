<?php

declare(strict_types=1);

namespace Beffroi\Tui\Text;

use function in_array;
use function is_string;
use function strcspn;
use function strtolower;
use function substr;

/**
 * The language of this terminal, which the screen speaks and the pages of the sign-in are asked in: the first
 * of `LC_ALL`, `LC_MESSAGES` and `LANG` that is set, as POSIX reads them, by its language alone (`fr_FR.UTF-8`
 * is French), and English for a language the catalogue does not hold, `C` and `POSIX` included.
 */
final readonly class TerminalLocale
{
    /**
     * @var list<string>
     */
    public const array SUPPORTED = ['en', 'fr'];

    private const string FALLBACK = 'en';

    /**
     * @param array<array-key, mixed> $environment
     */
    public static function of(array $environment): string
    {
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $variable) {
            $value = $environment[$variable] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $language = strtolower(substr($value, 0, strcspn($value, '_.@')));

            return in_array($language, self::SUPPORTED, true) ? $language : self::FALLBACK;
        }

        return self::FALLBACK;
    }
}
