<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\Definition;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Text\TerminalText;
use function implode;
use function max;
use function sprintf;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The administrator this program signed in: who the ID token names, by which proof (the "acr" and the "amr",
 * OIDC Core §2, RFC 8176), and what the access token was granted and until when. It reads nothing of the API, and `r`
 * says again how long the token has left.
 */
final readonly class SessionSection implements SectionInterface
{
    private SectionFrame $frame;

    private DefinitionsWidget $definitions;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->definitions = new DefinitionsWidget(true);
        $this->frame->add($this->definitions);
    }

    public function title(): string
    {
        return 'section.session';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $session = $context->session;
        $identity = $session->identity;
        $expiresAt = $session->expiresAt();
        $this->frame->title($context->translate('section.session'), $context->translate('section.read_at', ['time' => Moment::time($context->clock->now())]));
        $this->definitions->setDefinitions([
            new Definition($context->translate('session.administrator'), TerminalText::line($session->administratorName())->value),
            new Definition($context->translate('session.subject'), TerminalText::line($identity->subject)->value),
            new Definition(
                $context->translate('session.authentication'),
                TerminalText::line(sprintf('%s (%s)', $identity->authenticationClass, implode(', ', $identity->methods)))->value,
            ),
            new Definition(
                $context->translate('session.authenticated_at'),
                $identity->authenticatedAt === null ? '-' : Moment::ofSeconds($identity->authenticatedAt),
            ),
            new Definition($context->translate('session.scope'), TerminalText::line($session->scope() ?? '-')->value),
            new Definition(
                $context->translate('session.expires'),
                $expiresAt === null
                    ? $context->translate('session.no_expiry')
                    : $context->translate('session.expires_in', [
                        'minutes' => max(0, (int) (($expiresAt->getTimestamp() - $context->clock->now()->getTimestamp()) / 60)),
                        'time' => Moment::of($expiresAt),
                    ]),
            ),
        ], $context->translate('value.more'));
    }

    public function reload(SectionContext $context): void
    {
        $this->show($context);
    }

    public function focusables(): array
    {
        return [];
    }
}
