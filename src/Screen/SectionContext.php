<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\SignIn\AdministratorSession;
use Beffroi\Tui\Text\Catalogue;
use Beffroi\Tui\Text\TerminalText;
use function preg_match;
use Psr\Clock\ClockInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a section is given to show itself: the session of the administrator signed in, the administration API read
 * with its tokens, the catalogue, the clock, whether the screen may draw in colours, and the screen itself, which it
 * asks to be drawn again once what it shows changed and to hand the keys from one of its widgets to another.
 */
final readonly class SectionContext
{
    /**
     * @param bool $colours whether the screen may draw in colours (https://no-color.org)
     */
    public function __construct(
        public AdministratorSession $session,
        public AdministrationApi $api,
        private TranslatorInterface $translator,
        public ClockInterface $clock,
        public bool $colours,
        private Tui $tui,
    ) {
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function translate(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, Catalogue::DOMAIN);
    }

    /**
     * A string of the catalogue named after a word the instance answered, when there is one: the hint of a refusal,
     * named after its "error". A word that is not a name of lowercase letters and underscores names none.
     */
    public function named(string $prefix, ?string $word): ?string
    {
        $id = Hint::identifier($prefix, $word);
        if ($id === null) {
            return null;
        }
        $text = $this->translate($id);

        return $text === $id ? null : $text;
    }

    /**
     * An act of the journal as the screen names it: by the string of the catalogue named after it (`act.<name>`) when
     * there is one, and by the name the instance gives it otherwise, passed through `TerminalText`.
     */
    public function act(string $name): string
    {
        if (preg_match('/^[a-z_]{1,32}(?:\.[a-z_]{1,32}){0,3}$/D', $name) === 1) {
            $text = $this->translate('act.' . $name);
            if ($text !== 'act.' . $name) {
                return $text;
            }
        }

        return TerminalText::line($name)->value;
    }

    /**
     * A boolean the API answered, as the screen says it, and a dash for one it did not answer.
     */
    public function yesOrNo(?bool $value): string
    {
        return match ($value) {
            null => '-',
            true => $this->translate('value.yes'),
            false => $this->translate('value.no'),
        };
    }

    public function render(): void
    {
        $this->tui->requestRender();
    }

    /**
     * The keys go from one widget of the section to another, when the first one still has them: an answer that comes
     * back once the administrator went elsewhere moves nothing.
     */
    public function handOver(AbstractWidget $from, AbstractWidget $to): void
    {
        if ($this->tui->getFocus() === $from) {
            $this->tui->setFocus($to);
        }
    }
}
