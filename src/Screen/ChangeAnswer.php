<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Text\TerminalText;
use Closure;
use function in_array;

/**
 * What a change asked of the administration API answered, or nothing once its refusal is said on the notice of the
 * section in the words of the instance: a request malformed, naming nothing the tenant holds, or one the tenant refuses
 * as it stands. Any other failure is left to the reading that asked for the change.
 */
final readonly class ChangeAnswer
{
    /**
     * @var list<int>
     */
    private const array SAID = [400, 404, 409];

    /**
     * @param Closure(): Document $write
     */
    public static function of(SectionContext $context, MessageWidget $notice, Closure $write): ?Document
    {
        try {
            return $write();
        } catch (ProviderRefusalException $refusal) {
            if (! in_array($refusal->status, self::SAID, true)) {
                throw $refusal;
            }
            $notice->say($context->translate('change.refused', [
                'reason' => TerminalText::line($refusal->description ?? $refusal->error ?? '-')->value,
            ]), true);

            return null;
        }
    }
}
