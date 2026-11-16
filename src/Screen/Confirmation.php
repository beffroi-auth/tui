<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Screen\Widget\MessageWidget;
use Closure;

/**
 * A change the administrator asked for, said on a line of the section and made only when the next key is `y`: any
 * other key forgets it, so that a key pressed later confirms nothing.
 */
final class Confirmation
{
    /**
     * @var (Closure(SectionContext): void)|null
     */
    private ?Closure $change = null;

    public function __construct(
        private readonly MessageWidget $message,
    ) {
    }

    /**
     * @param Closure(SectionContext): void $change
     */
    public function ask(SectionContext $context, string $question, Closure $change): void
    {
        $this->change = $change;
        $this->message->say($question);
        $context->render();
    }

    /**
     * The key pressed after the question: `y` makes the change, and whatever the key the question is forgotten.
     *
     * @return bool whether the key confirmed a change, which nothing else then reads
     */
    public function answer(SectionContext $context, string $key): bool
    {
        $change = $this->change;
        $this->forget();
        if ($change === null || $key !== 'y') {
            return false;
        }
        $change($context);

        return true;
    }

    public function forget(): void
    {
        if ($this->change !== null) {
            $this->change = null;
            $this->message->say('');
        }
    }
}
