<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Key\SigningKey;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\Definition;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Text\TerminalText;
use function sprintf;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The instance this program administers and the client it is there: the issuer and the administration API, the
 * identifier and the redirect URI the client registered, the key it authenticates with, the key the tokens of this run
 * are bound to, the endpoints a sign-in went through, then the build of this program and whether a newer release is
 * out. It reads nothing of the API.
 */
final readonly class InstanceSection implements SectionInterface
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
        return 'section.instance';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $session = $context->session;
        $this->frame->title($context->translate('section.instance'), $context->translate('instance.note'));
        $this->definitions->setDefinitions([
            Definition::heading($context->translate('instance.provider')),
            new Definition($context->translate('instance.issuer'), TerminalText::line($session->provider->issuer)->value),
            new Definition($context->translate('instance.administration'), TerminalText::line($session->profile->administration)->value),
            Definition::heading($context->translate('instance.this_client')),
            new Definition($context->translate('instance.client'), TerminalText::line($session->profile->clientId)->value),
            new Definition($context->translate('instance.redirect_uri'), TerminalText::line($session->profile->redirectUri)->value),
            new Definition(
                $context->translate('instance.client_key'),
                sprintf('%s (%s, private_key_jwt)', $session->profile->clientKey->thumbprint(), SigningKey::ALGORITHM),
            ),
            new Definition($context->translate('instance.bound_key'), sprintf('%s (DPoP, %s)', $session->proofs->thumbprint(), SigningKey::ALGORITHM)),
            Definition::heading($context->translate('instance.endpoints')),
            new Definition($context->translate('instance.pushed_requests'), TerminalText::line($session->provider->pushedAuthorizationRequestEndpoint)->value),
            new Definition($context->translate('instance.token_endpoint'), TerminalText::line($session->provider->tokenEndpoint)->value),
            ...self::program($context),
        ], $context->translate('value.more'));
    }

    /**
     * @return list<Definition>
     */
    private static function program(SectionContext $context): array
    {
        $definitions = [
            Definition::heading($context->translate('instance.this_program')),
            new Definition($context->translate('instance.version'), $context->translate(...$context->program->version())),
        ];
        $update = $context->program->update();
        if ($update !== null) {
            $definitions[] = new Definition($context->translate('instance.update'), $context->translate(...$update));
        }

        return $definitions;
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
