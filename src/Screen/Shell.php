<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\Widget\BarWidget;
use Beffroi\Tui\Screen\Widget\KeysWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\NavigationWidget;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\SignIn\AdministratorSession;
use Beffroi\Tui\Text\Catalogue;
use Beffroi\Tui\Text\TerminalText;
use function array_map;
use function array_search;
use function count;
use function is_int;
use Psr\Clock\ClockInterface;
use function sprintf;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The screen of the administration, as tall and as wide as the terminal and never more, so that nothing pushes it up.
 * Until the administrator is signed in it is the home page: the symbol, the task as its title, the instance and the
 * profile it is read from, and where the sign-in stands. Once signed in, a header line names the instance and who is
 * signed in, the sections are listed in a column beside the one shown or in tabs above it, whichever the width allows
 * (`Theme`), and the last line says the keys the screen answers to there, following the widget that has the focus.
 *
 * The symbol is drawn mono until the terminal says the colour of its background, then in the colourway the brand
 * gives that background (`TerminalBackground`), as are the accents of the screen; a terminal that does not answer, or
 * a screen without colours, keeps both as they were.
 *
 * The widgets of symfony/tui write what they are given to the terminal as it is: every value that did not come from
 * this program reaches them through `TerminalText`, and the labels are the catalogue's.
 */
final class Shell
{
    /**
     * The lines of the home page besides the symbol: its padding above and below, the gap and the footer.
     */
    private const int BESIDES_THE_SYMBOL = 4;

    /**
     * The class of a widget that is not drawn and takes no room. A section that is not shown is hidden rather than
     * taken off the screen, as is a widget of a section that has nothing to show yet: symfony/tui 8.1 forgets the
     * listeners of a widget it takes off, and a widget put back would answer no key.
     */
    public const string HIDDEN = 'hidden';

    /**
     * Above the keys of the administration (0), which leave the screen on Esc: Esc goes back to the navigation first.
     */
    private const int KEYS_PRIORITY = 10;

    private readonly ContainerWidget $page;

    private readonly ContainerWidget $home;

    private readonly TextWidget $symbol;

    private readonly TextWidget $instance;

    private readonly MessageWidget $status;

    private readonly BarWidget $header;

    private readonly ContainerWidget $body;

    private readonly NavigationWidget $navigation;

    private readonly ContainerWidget $main;

    private readonly KeysWidget $footer;

    private readonly Keybindings $keys;

    /**
     * @var list<array{string, string}> the keys of the home page, as it stands
     */
    private array $homeKeys = [];

    private ?SectionContext $context = null;

    private ?Tui $tui = null;

    /**
     * @param list<SectionInterface> $sections in the order the navigation lists them
     * @param bool                   $colours  whether the screen may draw in colours (https://no-color.org)
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly array $sections,
        private readonly bool $colours,
    ) {
        $this->page = new ContainerWidget()
            ->addStyleClass('page')
            ->expandVertically(true);
        $this->instance = new TextWidget()->addStyleClass('instance');
        $this->status = new MessageWidget(true);
        $welcome = new ContainerWidget()
            ->addStyleClass('welcome')
            ->expandVertically(true);
        $welcome->add(new TextWidget($this->translate('home.title'), true)->addStyleClass('title'));
        $welcome->add($this->instance);
        $welcome->add($this->status);
        $this->home = new ContainerWidget()
            ->addStyleClass('home')
            ->expandVertically(true);
        $this->symbol = new TextWidget('', true)->addStyleClass('symbol');
        $this->home->add($this->symbol);
        $this->home->add($welcome);
        $this->header = new BarWidget()->addStyleClass('header');
        $this->navigation = new NavigationWidget(Theme::WIDE)->addStyleClass('navigation');
        $this->main = new ContainerWidget()
            ->addStyleClass('main')
            ->expandVertically(true);
        $this->body = new ContainerWidget()
            ->addStyleClass('body')
            ->expandVertically(true);
        $this->body->add($this->navigation);
        $this->body->add($this->main);
        $this->footer = new KeysWidget()->addStyleClass('footer');
        $this->footer->follow(fn (): array => $this->keysShown());
        $this->keys = new Keybindings([
            // F6 as well, which symfony/tui would otherwise send round every widget, the hidden ones included
            'next' => ['tab', 'f6'],
            'previous' => ['shift+tab', 'shift+f6'],
            'back' => ['escape'],
            'reload' => ['r'],
        ]);
        $this->navigation->onMove(function (int $section): void {
            $this->show($section);
        });
    }

    public static function styleSheet(bool $colours = false): StyleSheet
    {
        return Theme::styleSheet($colours);
    }

    /**
     * The symbol is the largest cut of the kit the height of the terminal leaves room for besides the padding of the
     * home page, its gap and its footer.
     */
    public function attachTo(Tui $tui): void
    {
        $this->tui = $tui;
        $this->navigation->followTheTerminal(static fn (): int => $tui->getTerminal()->getColumns());
        $tui->add($this->page);
        $tui->addListener(function (InputEvent $event) use ($tui): void {
            $this->answer($event, $tui);
        }, self::KEYS_PRIORITY);
        // Enter on a section goes into it, as Tab does
        $this->navigation->onEnter(function () use ($tui): void {
            $this->move($tui, 1);
        });
        $symbol = Symbol::fitting($tui->getTerminal()->getRows(), self::BESIDES_THE_SYMBOL);
        $this->symbol->setText($symbol->drawn(Colourway::Mono));
        if (! $this->colours) {
            return;
        }
        $tui->addListener(function (InputEvent $event) use ($symbol, $tui): void {
            $colourway = TerminalBackground::colourwayOf($event->getData());
            if ($colourway === null) {
                return;
            }
            $event->stopPropagation();
            $this->symbol->setText($symbol->drawn($colourway));
            $tui->addStyleSheet(Theme::forBackground($colourway));
            self::invalidate($this->page);
            $tui->requestRender(true);
        });
    }

    /**
     * The terminal is asked the colour of its background once it reads its input raw, which is after the screen has
     * started: asked before, it would echo the answer.
     */
    public function askTheBackground(Tui $tui): void
    {
        if ($this->colours) {
            $tui->getTerminal()->write(TerminalBackground::QUERY);
        }
    }

    /**
     * Whether the keys go to a field the administrator types in, which takes the letters the screen otherwise answers
     * to.
     */
    public function typing(Tui $tui): bool
    {
        return $tui->getFocus() instanceof InputWidget;
    }

    /**
     * The home page, before any sign-in: the instance the profile names, and the key that starts the sign-in.
     */
    public function home(Profile $profile, string $name): void
    {
        $this->instance->setText($this->translate('home.instance', [
            'issuer' => TerminalText::line($profile->issuer)->value,
            'profile' => TerminalText::line($name)->value,
        ]));
        $this->status->say($this->translate('home.invitation'));
        $this->homeKeys = [['⏎', $this->translate('keys.sign_in')], ['q', $this->translate('keys.quit')]];
        $this->page->clear();
        $this->page->add($this->home);
        $this->page->add($this->footer);
    }

    /**
     * The sign-in started: the metadata of the instance are being read.
     */
    public function reading(Profile $profile): void
    {
        $this->status->say($this->translate('signin.reading', ['issuer' => TerminalText::line($profile->issuer)->value]));
        $this->homeKeys = [['q', $this->translate('keys.quit')]];
        $this->footer->invalidate();
    }

    /**
     * The browser was sent to the authorization endpoint, and the response is awaited on the loopback interface.
     * The URL is also a link (OSC 8) whose target is the whole of it, which a terminal that reads links opens
     * however the screen wraps the text, and which one that does not shows as the text.
     */
    public function signingIn(Profile $profile, string $authorizationUrl): void
    {
        $url = TerminalText::line($authorizationUrl)->value;
        $this->status->say($this->translate('signin.waiting', [
            'url' => sprintf("\e]8;;%s\e\\%s\e]8;;\e\\", $url, $url),
            'redirect_uri' => TerminalText::line($profile->redirectUri)->value,
        ]));
        $this->homeKeys = [['q', $this->translate('keys.quit')]];
        $this->footer->invalidate();
    }

    /**
     * Why the sign-in failed, and, for a refusal of the provider this program knows what to do about, what to do:
     * a hint is a string of the catalogue named after the "error" of the refusal (`refusal.<error>`), so the hint of
     * another refusal is a string added there. The home page stays, and its key starts the sign-in again.
     */
    public function failed(Throwable $exception): void
    {
        $text = $this->translate('signin.failed', ['reason' => TerminalText::of($exception->getMessage())->value]);
        $hint = $exception instanceof ProviderRefusalException ? Hint::identifier('refusal', $exception->error) : null;
        if ($hint !== null && $this->translate($hint) !== $hint) {
            $text = $this->translate($hint) . "\n\n" . $text;
        }
        $this->status->say($text, true);
        $this->homeKeys = [['⏎', $this->translate('keys.again')], ['q', $this->translate('keys.quit')]];
        $this->footer->invalidate();
    }

    public function signedIn(AdministratorSession $session, Tui $tui): void
    {
        $this->context = new SectionContext($session, $session->api(), $this->translator, $this->clock, $this->colours, $tui);
        $this->header->set(
            ' ' . $this->translate('header.title', ['issuer' => TerminalText::line($session->provider->issuer)->value]),
            $this->translate('header.administrator', [
                'administrator' => TerminalText::line($session->administratorName())->value,
                'acr' => TerminalText::line($session->identity->authenticationClass)->value,
            ])
                . ' ',
        );
        $this->navigation->setLabels(array_map(fn (SectionInterface $section): string => $this->translate($section->title()), $this->sections));
        $this->main->clear();
        foreach ($this->sections as $section) {
            $this->main->add($section->widget()->addStyleClass(self::HIDDEN));
        }
        $this->page->clear();
        $this->page->add($this->header);
        $this->page->add($this->body);
        $this->page->add($this->footer);
        $this->show($this->navigation->shown());
        $tui->setFocus($this->navigation);
    }

    /**
     * A change of the style sheet reaches widgets whose drawing is kept: each one is told to draw itself again.
     */
    private static function invalidate(AbstractWidget $widget): void
    {
        $widget->invalidate();
        if ($widget instanceof ParentInterface) {
            foreach ($widget->all() as $child) {
                self::invalidate($child);
            }
        }
    }

    /**
     * The keys the last line says, for where the screen stands and the widget that has the focus.
     *
     * @return list<array{string, string}>
     */
    private function keysShown(): array
    {
        $tui = $this->tui;
        if ($this->context === null || $tui === null) {
            return $this->homeKeys;
        }
        $focus = $tui->getFocus();
        if ($focus === $this->navigation) {
            $keys = [[$this->navigation->isHorizontal() ? '←→' : '↑↓', $this->translate('keys.choose')]];
            if ($this->shownSection()?->focusables() !== []) {
                $keys[] = ['⏎', $this->translate('keys.enter')];
            }

            return [...$keys, ['r', $this->translate('keys.reload')], ['q', $this->translate('keys.quit')]];
        }
        if ($focus instanceof InputWidget) {
            return [['⏎', $this->translate('keys.search')], ['tab', $this->translate('keys.next')], ['esc', $this->translate('keys.back')]];
        }

        $keys = [['↑↓', $this->translate('keys.browse')]];
        if ($focus instanceof TableWidget && $focus->isChoosable()) {
            $keys[] = ['⏎', $this->translate('keys.open')];
        }
        if ($focus instanceof TableWidget) {
            $keys = [...$keys, ...$focus->sectionKeys()];
        }

        return [
            ...$keys,
            ['tab', $this->translate('keys.next')],
            ['esc', $this->translate('keys.back')],
            ['r', $this->translate('keys.reload')],
            ['q', $this->translate('keys.quit')],
        ];
    }

    /**
     * The keys of the screen once signed in, before the widget that has the focus is given them.
     */
    private function answer(InputEvent $event, Tui $tui): void
    {
        $context = $this->context;
        if ($context === null) {
            return;
        }
        $data = $event->getData();
        if ($this->keys->matches($data, 'next') || $this->keys->matches($data, 'previous')) {
            $event->stopPropagation();
            $this->move($tui, $this->keys->matches($data, 'next') ? 1 : -1);

            return;
        }
        if ($this->keys->matches($data, 'back') && $tui->getFocus() !== $this->navigation) {
            $event->stopPropagation();
            $tui->setFocus($this->navigation);

            return;
        }
        if ($this->keys->matches($data, 'reload') && ! $this->typing($tui)) {
            $event->stopPropagation();
            $this->shownSection()?->reload($context);
            $tui->requestRender();
        }
    }

    /**
     * The keys go round the navigation and the widgets of the section shown, in that order.
     */
    private function move(Tui $tui, int $step): void
    {
        $round = [$this->navigation, ...($this->shownSection()?->focusables() ?? [])];
        $at = array_search($tui->getFocus(), $round, true);
        $next = ((is_int($at) ? $at : 0) + $step + count($round)) % count($round);
        $tui->setFocus($round[$next]);
    }

    private function shownSection(): ?SectionInterface
    {
        return $this->sections[$this->navigation->shown()] ?? null;
    }

    private function show(int $index): void
    {
        $context = $this->context;
        if ($context === null) {
            return;
        }
        foreach ($this->sections as $position => $section) {
            if ($position === $index) {
                $section->widget()->removeStyleClass(self::HIDDEN);
            } else {
                $section->widget()->addStyleClass(self::HIDDEN);
            }
        }
        ($this->sections[$index] ?? null)?->show($context);
        $context->render();
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, Catalogue::DOMAIN);
    }
}
