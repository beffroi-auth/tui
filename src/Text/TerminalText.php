<?php

declare(strict_types=1);

namespace Beffroi\Tui\Text;

use function mb_scrub;
use function preg_replace;
use Stringable;

/**
 * What the terminal is handed of a string this program did not write: a claim of the administrator, a
 * member of the provider's metadata, whatever the administration API answers, the `client_name` a third party
 * registered (decision 0009) among it. The widgets of symfony/tui write their text to the terminal as it
 * is, escape sequences included, and leave the sanitizing to whoever calls them.
 *
 * It is to this screen what escaping is to a page: decision 0038 keeps the administration off the origin of
 * the pages because a name a third party chose is shown there, and a terminal runs what it is sent just as a
 * browser does. An escape sequence can set the title, write the clipboard (OSC 52), move the cursor over
 * what was written before or hide what follows; a bidirectional control reorders what is shown, so that a
 * name reads as another one (CVE-2021-42574). A string becomes well-formed UTF-8 first, so that removing a
 * byte cannot splice two others into a control, then loses every C0 control but the tab and the line feed,
 * DEL, every C1 control and every bidirectional control. What followed an introducer stays, as visible text.
 */
final readonly class TerminalText implements Stringable
{
    /**
     * C0 but TAB and LF, DEL, C1 (U+0080 to U+009F), and the bidirectional embeddings, overrides and isolates
     * with the marks that steer them (U+061C, U+200E, U+200F, U+202A to U+202E, U+2066 to U+2069).
     */
    private const string CONTROLS = '/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private function __construct(
        public string $value,
    ) {
    }

    public static function of(string $untrusted): self
    {
        return new self(preg_replace(self::CONTROLS, '', mb_scrub($untrusted, 'UTF-8')) ?? '');
    }

    /**
     * One line of it, for a label or a value of a list: a line break or a tab would move what comes after.
     */
    public static function line(string $untrusted): self
    {
        return new self(preg_replace('/[\t\n]+/', ' ', self::of($untrusted)->value) ?? '');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
