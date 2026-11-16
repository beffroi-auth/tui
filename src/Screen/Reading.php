<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Amp\Future;
use function Amp\async;
use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\UnreadableDocumentException;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Text\TerminalText;
use Closure;

/**
 * A read of the administration API for a section: a line says it is being read, then what was read is handed to the
 * widgets of the section and the line goes, or the line says why there is nothing to show, the words of the instance
 * passed through `TerminalText`. The read runs on the event loop, beside the screen, which it asks to be drawn once it
 * has an answer.
 *
 * One read is made at a time: a read asked for while another is under way is not made, the one under way giving the
 * answer both asked for. A read that never was is made when the section is first shown, and is not made again until
 * the administrator asks for it.
 */
final class Reading
{
    /**
     * @var Future<void>|null
     */
    private ?Future $reading = null;

    private bool $asked = false;

    public function __construct(
        private readonly MessageWidget $message,
    ) {
    }

    /**
     * @param Closure(AdministrationApi): void $read what is read, and handed to the widgets of the section
     */
    public function once(SectionContext $context, Closure $read): void
    {
        if (! $this->asked) {
            $this->again($context, $read);
        }
    }

    /**
     * @param Closure(AdministrationApi): void $read what is read, and handed to the widgets of the section
     * @param string                           $while the string of the catalogue the line says while the API is asked, another one for a request that changes something
     */
    public function again(SectionContext $context, Closure $read, string $while = 'api.reading'): void
    {
        if ($this->reading !== null && ! $this->reading->isComplete()) {
            return;
        }
        $this->asked = true;
        $this->message->say($context->translate($while));
        $context->render();
        $this->reading = async(function () use ($context, $read): void {
            try {
                $read($context->api);
                $this->message->say('');
            } catch (ProtocolException $exception) {
                $this->message->say(self::refused($context, $exception), true);
            } catch (UnreadableDocumentException $exception) {
                $this->message->say($context->translate('api.unreadable', [
                    'reason' => TerminalText::of($exception->getMessage())->value,
                ]), true);
            }
            $context->render();
        });
    }

    /**
     * Why the API gave nothing to show, and what to do about it when this program knows: the hint of a refusal is the
     * string of the catalogue named after its "error" (`api.refusal.<error>`).
     */
    private static function refused(SectionContext $context, ProtocolException $exception): string
    {
        $text = $context->translate('api.failed', ['reason' => TerminalText::of($exception->getMessage())->value]);
        $hint = $exception instanceof ProviderRefusalException ? $context->named('api.refusal', $exception->error) : null;

        return $hint === null ? $text : $hint . ' ' . $text;
    }
}
