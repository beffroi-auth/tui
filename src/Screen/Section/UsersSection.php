<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\JsonText;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\Panel;
use Beffroi\Tui\Screen\Reading;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Shell;
use Beffroi\Tui\Screen\Widget\Definition;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function array_map;
use function implode;
use function rawurlencode;
use function sprintf;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use function trim;

/**
 * A person of the tenant, looked up by their username: who they are on one side, with the claims their account holds
 * and their groups, and on the other their credentials, or, when the administrator asks for them, their open sessions
 * or their last acts. Each of those reads is an act in the journal of the person that names the administrator
 * (decisions 0022 and 0038), which the screen says before the first one, and the sessions and the journal are read only
 * when asked for.
 */
final class UsersSection implements SectionInterface
{
    private const string CREDENTIALS = 'credentials';

    private const string SESSIONS = 'sessions';

    private const string JOURNAL = 'journal';

    private readonly SectionFrame $frame;

    private readonly InputWidget $input;

    private readonly MessageWidget $notice;

    private readonly Panel $person;

    private readonly Panel $access;

    private readonly DefinitionsWidget $definitions;

    private readonly MessageWidget $accessMessage;

    private readonly Reading $accessReading;

    /**
     * @var array<string, TableWidget> the table of each view of the access of the person
     */
    private readonly array $tables;

    private string $view = self::CREDENTIALS;

    /**
     * The identifier of the person shown, null while the username looked up named nobody.
     */
    private ?string $shown = null;

    private ?SectionContext $context = null;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->input = new InputWidget();
        $this->notice = new MessageWidget();
        $this->definitions = new DefinitionsWidget(true);
        $this->accessMessage = new MessageWidget();
        $this->accessReading = new Reading($this->accessMessage);
        $this->tables = [
            self::CREDENTIALS => new TableWidget([
                new TableColumn('kind', 8, 9),
                new TableColumn('name', 10, 8),
                new TableColumn('authenticator', 12, 4),
                new TableColumn('created', 16, 3),
                new TableColumn('last used', 16, 6),
                new TableColumn('revoked', 16, 5),
            ]),
            self::SESSIONS => new TableWidget([
                new TableColumn('proof', 10, 9),
                new TableColumn('authenticated', 16, 8),
                new TableColumn('last seen', 16, 7),
                new TableColumn('expires', 16, 3),
                new TableColumn('address', 10, 6),
                new TableColumn('place', 10, 5),
                new TableColumn('user agent', 12, 2),
            ]),
            self::JOURNAL => new TableWidget([
                new TableColumn('when', 16, 9),
                new TableColumn('act', 14, 8),
                new TableColumn('client', 10, 5),
                new TableColumn('author', 10, 6),
                new TableColumn('address', 10, 4),
                new TableColumn('place', 10, 3),
            ]),
        ];
        $this->person = new Panel([$this->definitions], weight: 2);
        $access = [$this->accessMessage];
        foreach ($this->tables as $view => $table) {
            $access[] = $view === self::CREDENTIALS ? $table : $table->addStyleClass(Shell::HIDDEN);
            $table->onInput(fn (string $data): bool => $this->asked($data));
        }
        $this->access = new Panel($access, weight: 3);
        $this->person->widget->addStyleClass(Shell::HIDDEN);
        $this->access->widget->addStyleClass(Shell::HIDDEN);
        $this->frame->add($this->input);
        $this->frame->add($this->notice);
        $this->frame->add(Panel::split([$this->person, $this->access]));
        $this->input->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->lookUp($this->context, trim($event->getValue()));
            }
        });
    }

    public function title(): string
    {
        return 'section.users';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $this->context = $context;
        $this->frame->title($context->translate('section.users'));
        $this->input->setPrompt($context->translate('users.prompt'));
        if ($this->shown === null) {
            $this->notice->say($context->translate('users.notice'));
        }
    }

    public function reload(SectionContext $context): void
    {
        $this->context = $context;
        if ($this->shown !== null) {
            $this->read($context, $this->view);
        }
    }

    public function focusables(): array
    {
        return $this->shown === null ? [$this->input] : [$this->input, $this->tables[$this->view]];
    }

    /**
     * The keys of a view of the access of the person: `c` its credentials, `s` its open sessions, `j` its journal.
     */
    private function asked(string $data): bool
    {
        $view = match ($data) {
            'c' => self::CREDENTIALS,
            's' => self::SESSIONS,
            'j' => self::JOURNAL,
            default => null,
        };
        if ($view === null || $this->context === null || $this->shown === null) {
            return false;
        }
        $this->read($this->context, $view);

        return true;
    }

    private function lookUp(SectionContext $context, string $username): void
    {
        if ($username === '') {
            return;
        }
        $this->frame->reading->again($context, function (AdministrationApi $api) use ($context, $username): void {
            try {
                $person = $api->read('/users', [
                    'username' => $username,
                ]);
            } catch (ProviderRefusalException $refusal) {
                if ($refusal->status !== 404) {
                    throw $refusal;
                }
                $this->shown = null;
                $this->person->widget->addStyleClass(Shell::HIDDEN);
                $this->access->widget->addStyleClass(Shell::HIDDEN);
                $this->notice->say($context->translate('users.none', ['username' => TerminalText::line($username)->value]));

                return;
            }
            $this->shown = $person->string('id');
            $this->notice->say('');
            $this->person->title($context->translate('users.person', [
                'username' => TerminalText::line($person->nullableString('username') ?? $username)->value,
            ]));
            $this->definitions->setDefinitions(self::person($person, $context), $context->translate('value.more'));
            $this->person->widget->removeStyleClass(Shell::HIDDEN);
            $this->access->widget->removeStyleClass(Shell::HIDDEN);
            $this->showView(self::CREDENTIALS, $context);
            $this->tables[self::CREDENTIALS]->setColumnLabels([
                $context->translate('credential.kind'),
                $context->translate('credential.name'),
                $context->translate('credential.authenticator'),
                $context->translate('credential.created_at'),
                $context->translate('credential.last_used_at'),
                $context->translate('credential.revoked_at'),
            ])->setRows(array_map(
                static fn (Document $credential): array => [
                    TerminalText::line($credential->nullableString('kind') ?? '-')->value,
                    TerminalText::line($credential->nullableString('name') ?? '-')->value,
                    TerminalText::line($credential->nullableString('authenticator') ?? '-')->value,
                    ApiMoment::short($credential->nullableString('created_at')),
                    ApiMoment::short($credential->nullableString('last_used_at')),
                    ApiMoment::short($credential->nullableString('revoked_at')),
                ],
                $person->documents('credentials'),
            ), $context->translate('user.no_credential'));
            $this->frame->title($context->translate('section.users'), $context->translate('section.read_at', ['time' => Moment::time($context->clock->now())]));
            $context->handOver($this->input, $this->tables[self::CREDENTIALS]);
        });
    }

    /**
     * A view of the access of the person, read again: the credentials come with the person, the sessions and the
     * journal are read on their own, each read an act in the journal of the person.
     */
    private function read(SectionContext $context, string $view): void
    {
        $id = $this->shown;
        if ($id === null) {
            return;
        }
        if ($view === self::CREDENTIALS) {
            $this->showView($view, $context);

            return;
        }
        $this->showView($view, $context);
        $this->accessReading->again($context, function (AdministrationApi $api) use ($context, $view, $id): void {
            $answer = $api->read(sprintf('/users/%s/%s', rawurlencode($id), $view));
            if ($view === self::SESSIONS) {
                $this->tables[self::SESSIONS]->setColumnLabels([
                    $context->translate('user_sessions.proof'),
                    $context->translate('user_sessions.authenticated_at'),
                    $context->translate('user_sessions.last_seen_at'),
                    $context->translate('user_sessions.expires_at'),
                    $context->translate('provenance.address'),
                    $context->translate('provenance.place'),
                    $context->translate('provenance.user_agent'),
                ])->setRows(array_map(
                    self::session(...),
                    $answer->documents('sessions'),
                ), $context->translate('user_sessions.none'));

                return;
            }
            $this->tables[self::JOURNAL]->setColumnLabels([
                $context->translate('journal.when'),
                $context->translate('journal.act'),
                $context->translate('journal.client'),
                $context->translate('journal.author'),
                $context->translate('provenance.address'),
                $context->translate('provenance.place'),
            ])->setRows(array_map(static fn (Document $act): array => self::act(
                $act,
                $context,
            ), $answer->documents('acts')), $context->translate('journal.none'));
        });
    }

    private function showView(string $view, SectionContext $context): void
    {
        $focused = $this->tables[$this->view]->isFocused();
        foreach ($this->tables as $name => $table) {
            if ($name === $view) {
                $table->removeStyleClass(Shell::HIDDEN);
            } else {
                $table->addStyleClass(Shell::HIDDEN);
            }
        }
        $previous = $this->tables[$this->view];
        $this->view = $view;
        if ($focused) {
            $context->handOver($previous, $this->tables[$view]);
        }
        $this->access->title(
            $context->translate('users.view.' . $view),
            $context->translate('users.views'),
        );
    }

    /**
     * @return list<Definition>
     */
    private static function person(Document $person, SectionContext $context): array
    {
        $definitions = [
            new Definition($context->translate('user.username'), TerminalText::line($person->nullableString('username') ?? '-')->value),
            new Definition($context->translate('user.enabled'), $context->yesOrNo($person->nullableBool('enabled'))),
            new Definition($context->translate('user.source'), TerminalText::line($person->nullableString('source') ?? '-')->value),
            new Definition($context->translate('user.created_at'), ApiMoment::of($person->nullableString('created_at'))),
            new Definition($context->translate('user.groups'), self::groups($person, $context)),
            new Definition($context->translate('user.subject'), TerminalText::line($person->nullableString('subject') ?? '-')->value),
            new Definition($context->translate('user.id'), TerminalText::line($person->string('id'))->value),
        ];
        $claims = $person->nested('claims')->members ?? [];
        if ($claims !== []) {
            $definitions[] = Definition::heading($context->translate('user.claims'));
            foreach ($claims as $claim => $value) {
                $definitions[] = new Definition(TerminalText::line((string) $claim)->value, TerminalText::line(JsonText::of($value))->value);
            }
        }

        return $definitions;
    }

    private static function groups(Document $person, SectionContext $context): string
    {
        $groups = array_map(
            static fn (Document $group): string => sprintf('%s (%s)', $group->nullableString('name') ?? '-', $group->nullableString('source') ?? '-'),
            $person->documents('groups'),
        );

        return $groups === [] ? $context->translate('value.none') : TerminalText::line(implode(', ', $groups))->value;
    }

    /**
     * @return list<string>
     */
    private static function session(Document $session): array
    {
        $provenance = $session->nested('provenance');

        return [
            TerminalText::line(sprintf('%s (%s)', $session->nullableString('acr') ?? '-', implode(' ', $session->strings('methods'))))->value,
            ApiMoment::short($session->nullableString('authenticated_at')),
            ApiMoment::short($session->nullableString('last_seen_at')),
            ApiMoment::short($session->nullableString('expires_at')),
            TerminalText::line($provenance?->nullableString('address') ?? '-')->value,
            TerminalText::line($provenance?->nullableString('place') ?? '-')->value,
            TerminalText::line($provenance?->nullableString('user_agent') ?? '-')->value,
        ];
    }

    /**
     * @return list<string>
     */
    private static function act(Document $act, SectionContext $context): array
    {
        $provenance = $act->nested('provenance');
        $name = $act->nullableString('act');

        return [
            ApiMoment::short($act->nullableString('occurred_at')),
            $name === null ? '-' : $context->act($name),
            TerminalText::line($act->nullableString('client') ?? '-')->value,
            TerminalText::line($act->nullableString('author') ?? '-')->value,
            TerminalText::line($provenance?->nullableString('address') ?? '-')->value,
            TerminalText::line($provenance?->nullableString('place') ?? '-')->value,
        ];
    }
}
