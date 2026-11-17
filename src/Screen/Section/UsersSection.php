<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\Confirmation;
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
use Beffroi\Tui\Screen\Widget\FieldWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\QrCodeWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
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
 *
 * The administrator revokes a credential or ends an open session from its row, once they confirmed it: each change is
 * an act of the person that names them too, and the row says what the API answered without reading the person again.
 * They create a person from a field of their own, and issue the link that lets the person shown pose a passkey or a new
 * password, which the section shows as a QR code in place of the person until the focus leaves it (decision 0026).
 *
 * The form of the account of the person shown takes the place of their access until the focus leaves it: Enter changes
 * the member chosen, and `s` writes every change in one merge patch, an act of the person that names the administrator.
 */
final class UsersSection implements SectionInterface
{
    private const string CREDENTIALS = 'credentials';

    private const string SESSIONS = 'sessions';

    private const string JOURNAL = 'journal';

    /**
     * The key that asks for the link of each kind of ticket (decision 0026): the segment of its route under the person,
     * and the prefix of what the screen says of it.
     *
     * @var array<string, array{string, string}>
     */
    private const array TICKETS = [
        't' => ['enrolment-ticket', 'ticket'],
        'p' => ['password-ticket', 'password_ticket'],
        'o' => ['totp-ticket', 'totp_ticket'],
    ];

    private readonly SectionFrame $frame;

    private readonly InputWidget $input;

    private readonly FieldWidget $create;

    private readonly MessageWidget $notice;

    private readonly Panel $person;

    private readonly Panel $access;

    private readonly DefinitionsWidget $definitions;

    private readonly MessageWidget $accessMessage;

    private readonly Reading $accessReading;

    private readonly Confirmation $confirmation;

    private readonly Panel $ticket;

    private readonly MessageWidget $ticketMessage;

    private readonly QrCodeWidget $code;

    private readonly Panel $form;

    private readonly MessageWidget $formMessage;

    private readonly Reading $formReading;

    private readonly TableWidget $formTable;

    private readonly FieldWidget $formField;

    /**
     * The form of the account of the person shown, null while it is not on the screen.
     */
    private ?AccountForm $account = null;

    /**
     * The member whose value the field of the form is typing.
     */
    private ?string $editing = null;

    /**
     * Whether the keys are going from one widget of the form to the other, which is not leaving the form.
     */
    private bool $handing = false;

    /**
     * The person shown, as the API last answered them.
     */
    private ?Document $document = null;

    /**
     * Whether the link of a ticket is on the screen, in place of the person.
     */
    private bool $ticketShown = false;

    /**
     * @var array<string, TableWidget> the table of each view of the access of the person
     */
    private readonly array $tables;

    private string $view = self::CREDENTIALS;

    /**
     * The identifier of the person shown, null while the username looked up named nobody.
     */
    private ?string $shown = null;

    /**
     * The username of the person shown, as the API wrote it.
     */
    private string $username = '';

    /**
     * @var list<array{id: string, revocable: bool, cells: list<string>}> each credential shown, in the order of its row: whether a revocation applies to it now, and its cells
     */
    private array $credentials = [];

    /**
     * @var list<array{id: string, cells: list<string>}> each open session shown, in the order of its row
     */
    private array $sessions = [];

    private ?SectionContext $context = null;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->input = new InputWidget();
        $this->create = new FieldWidget('keys.create');
        $this->notice = new MessageWidget();
        $this->definitions = new DefinitionsWidget(true);
        $this->accessMessage = new MessageWidget();
        $this->accessReading = new Reading($this->accessMessage);
        $this->confirmation = new Confirmation($this->accessMessage);
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
            $table->onLeave(function (): void {
                $this->confirmation->forget();
            });
        }
        $this->access = new Panel($access, weight: 3);
        $this->ticketMessage = new MessageWidget();
        $this->code = new QrCodeWidget();
        $this->code->onInput(fn (string $data): bool => $this->asked($data));
        $this->code->onClose(function (): void {
            if ($this->context !== null) {
                $this->closeTicket($this->context);
            }
        });
        $this->code->onLeave(function (): void {
            $this->forgetTicket();
        });
        $this->ticket = new Panel([$this->ticketMessage, $this->code], weight: 3);
        $this->formMessage = new MessageWidget();
        $this->formReading = new Reading($this->formMessage);
        $this->formTable = new TableWidget([
            new TableColumn('member', 16, 9),
            new TableColumn('value', 12, 8),
            new TableColumn('change', 10, 7),
        ]);
        $this->formTable->onInput(fn (string $data): bool => $this->answeredInTheForm($data));
        $this->formTable->onChoose(function (int $row): void {
            if ($this->context !== null) {
                $this->changeRow($this->context, $row);
            }
        }, 'keys.change');
        $this->formTable->onLeave(function (): void {
            $this->leftTheForm();
        });
        $this->formField = new FieldWidget('keys.keep');
        $this->formField->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->written($this->context, $event->getValue());
            }
        });
        $this->formField->onLeave(function (): void {
            $this->leftTheForm();
        });
        $this->formField->addStyleClass(Shell::HIDDEN);
        $this->form = new Panel([$this->formMessage, $this->formTable, $this->formField], weight: 3);
        $this->form->widget->addStyleClass(Shell::HIDDEN);
        $this->person->widget->addStyleClass(Shell::HIDDEN);
        $this->access->widget->addStyleClass(Shell::HIDDEN);
        $this->ticket->widget->addStyleClass(Shell::HIDDEN);
        $this->frame->add($this->input);
        $this->frame->add($this->create);
        $this->frame->add($this->notice);
        $this->frame->add(Panel::split([$this->person, $this->access, $this->form]));
        $this->frame->add($this->ticket->widget);
        $this->input->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->lookUp($this->context, trim($event->getValue()));
            }
        });
        $this->create->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->createPerson($this->context, trim($event->getValue()));
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
        $this->create->setPrompt($context->translate('users.create_prompt'));
        $tickets = [];
        foreach (self::TICKETS as $key => [, $speech]) {
            $tickets[] = [$key, $context->translate('keys.' . $speech)];
        }
        $edit = ['e', $context->translate('keys.edit')];
        $this->tables[self::CREDENTIALS]->setSectionKeys([['x', $context->translate('keys.revoke')], $edit, ...$tickets]);
        $this->tables[self::SESSIONS]->setSectionKeys([['x', $context->translate('keys.end_session')], $edit, ...$tickets]);
        $this->tables[self::JOURNAL]->setSectionKeys([$edit, ...$tickets]);
        $this->formTable->setSectionKeys([['s', $context->translate('keys.save')]]);
        $this->formTable->setColumnLabels([
            $context->translate('account_form.member'),
            $context->translate('account_form.value'),
            $context->translate('account_form.change'),
        ]);
        $this->code->setSectionKeys([['⏎', $context->translate('keys.close')]]);
        if ($this->shown === null) {
            $this->notice->say($context->translate('users.notice'));
        }
    }

    public function reload(SectionContext $context): void
    {
        $this->context = $context;
        $this->closeTicket($context);
        $this->closeForm($context);
        if ($this->shown !== null) {
            $this->read($context, $this->view);
        }
    }

    public function focusables(): array
    {
        if ($this->shown === null) {
            return [$this->input, $this->create];
        }

        return [
            $this->input,
            $this->create,
            match (true) {
                $this->account !== null => $this->formTable,
                $this->ticketShown => $this->code,
                default => $this->tables[$this->view],
            },
        ];
    }

    /**
     * The keys of a view of the access of the person: `c` its credentials, `s` its open sessions, `j` its journal, `x`
     * the change of the row chosen, `e` the form of their account, `t` a link that lets the person pose a passkey, `p`
     * one that lets them pose a new password and `o` one that lets them set up an authenticator app, each change but the
     * form confirmed by `y` and forgotten by any other key.
     */
    private function asked(string $data): bool
    {
        $context = $this->context;
        if ($context === null || $this->shown === null) {
            return false;
        }
        if ($this->confirmation->answer($context, $data)) {
            return true;
        }
        $ticket = self::TICKETS[$data] ?? null;
        if ($ticket !== null) {
            $this->closeTicket($context);
            $this->askForATicket($context, $ticket[0], $ticket[1]);

            return true;
        }
        if ($data === 'e') {
            $this->closeTicket($context);
            $this->openForm($context);

            return true;
        }
        if ($data === 'x') {
            if (! $this->ticketShown) {
                $this->askToChange($context);
            }

            return true;
        }
        $view = match ($data) {
            'c' => self::CREDENTIALS,
            's' => self::SESSIONS,
            'j' => self::JOURNAL,
            default => null,
        };
        if ($view === null) {
            return false;
        }
        $this->read($context, $view);

        return true;
    }

    /**
     * The change of the row chosen, asked to be confirmed: an open session is ended, a credential a revocation applies
     * to is revoked, and an act of the journal changes nothing.
     */
    private function askToChange(SectionContext $context): void
    {
        $person = $this->shown;
        $row = $this->tables[$this->view]->selected();
        if ($person === null || $row === null) {
            return;
        }
        if ($this->view === self::SESSIONS && isset($this->sessions[$row])) {
            $session = $this->sessions[$row];
            $this->confirmation->ask(
                $context,
                $context->translate('user_sessions.end_asked', [
                    'username' => $this->username,
                    'time' => $session['cells'][1],
                    'address' => $session['cells'][4],
                ]),
                function (SectionContext $context) use ($person, $session): void {
                    $this->endSession($context, $person, $session['id']);
                },
            );

            return;
        }
        if ($this->view !== self::CREDENTIALS || ! isset($this->credentials[$row])) {
            return;
        }
        $credential = $this->credentials[$row];
        if (! $credential['revocable']) {
            $this->accessMessage->say($context->translate('credential.not_revocable'));
            $context->render();

            return;
        }
        $this->confirmation->ask(
            $context,
            $context->translate('credential.revoke_asked', [
                'username' => $this->username,
                'kind' => $credential['cells'][0],
                'name' => $credential['cells'][1],
            ]),
            function (SectionContext $context) use ($person, $credential): void {
                $this->revokeCredential($context, $person, $credential['id']);
            },
        );
    }

    /**
     * The session ends, and leaves the rows: the API answers the session it ended.
     */
    private function endSession(SectionContext $context, string $person, string $session): void
    {
        $this->accessReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $person, $session): void {
                $api->delete(sprintf('/users/%s/sessions/%s', rawurlencode($person), rawurlencode($session)));
                $this->sessions = array_values(array_filter($this->sessions, static fn (array $shown): bool => $shown['id'] !== $session));
                $this->tables[self::SESSIONS]->setRows(array_column($this->sessions, 'cells'), $context->translate('user_sessions.none'));
                $this->notice->say($context->translate('user_sessions.ended_now', ['username' => $this->username]));
            },
            'api.writing',
        );
    }

    /**
     * The credential is revoked, and its row says when, as the API answers it.
     */
    private function revokeCredential(SectionContext $context, string $person, string $credential): void
    {
        $this->accessReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $person, $credential): void {
                $revoked = $api->delete(sprintf('/users/%s/credentials/%s', rawurlencode($person), rawurlencode($credential)));
                foreach ($this->credentials as $index => $shown) {
                    if ($shown['id'] === $credential) {
                        $this->credentials[$index] = [
                            'id' => $shown['id'],
                            'revocable' => false,
                            'cells' => [...array_slice($shown['cells'], 0, 5), ApiMoment::short($revoked->nullableString('revoked_at'))],
                        ];
                        $this->notice->say($context->translate('credential.revoked_now', [
                            'username' => $this->username,
                            'kind' => $shown['cells'][0],
                            'name' => $shown['cells'][1],
                        ]));
                    }
                }
                $this->tables[self::CREDENTIALS]->setRows(array_column($this->credentials, 'cells'), $context->translate('user.no_credential'));
            },
            'api.writing',
        );
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
            $this->showPerson($context, $person, $this->input);
        });
    }

    /**
     * The person the API answered, a read of them or their creation, with their credentials, in place of whoever was
     * shown before: the focus goes from the field the administrator typed in to the table of the credentials.
     */
    private function showPerson(SectionContext $context, Document $person, AbstractWidget $from): void
    {
        $this->forgetTicket();
        $this->forgetForm();
        $this->shown = $person->string('id');
        $this->document = $person;
        $this->username = TerminalText::line($person->nullableString('username') ?? '-')->value;
        $this->notice->say('');
        $this->person->title($context->translate('users.person', [
            'username' => $this->username,
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
        ]);
        $this->credentials = array_map(self::credential(...), $person->documents('credentials'));
        $this->tables[self::CREDENTIALS]->setRows(array_column($this->credentials, 'cells'), $context->translate('user.no_credential'));
        $this->sessions = [];
        $this->tables[self::SESSIONS]->setRows([], $context->translate('user_sessions.none'));
        $this->frame->title($context->translate('section.users'), $context->translate('section.read_at', ['time' => Moment::time($context->clock->now())]));
        $context->handOver($from, $this->tables[self::CREDENTIALS]);
    }

    /**
     * A person created with the username typed, holding no credential yet: the API answers them as a read of them does,
     * so that nothing reads them again.
     */
    private function createPerson(SectionContext $context, string $username): void
    {
        if ($username === '') {
            return;
        }
        $this->frame->reading->again(
            $context,
            function (AdministrationApi $api) use ($context, $username): void {
                try {
                    $person = $api->post('/users', [
                        'username' => $username,
                    ]);
                } catch (ProviderRefusalException $refusal) {
                    if ($refusal->status !== 409) {
                        throw $refusal;
                    }
                    $this->notice->say($context->translate('users.taken', ['username' => TerminalText::line($username)->value]));

                    return;
                }
                $this->create->setValue('');
                $this->showPerson($context, $person, $this->create);
                $this->notice->say($context->translate('users.created', ['username' => $this->username]));
            },
            'api.writing',
        );
    }

    /**
     * The link of a ticket asked to be confirmed: a link of the kind issued before for the person opens nothing once it
     * is.
     *
     * @param string $route  the segment of the route of the kind under the person
     * @param string $speech the prefix of what the screen says of the kind
     */
    private function askForATicket(SectionContext $context, string $route, string $speech): void
    {
        $person = $this->shown;
        if ($person === null) {
            return;
        }
        $this->confirmation->ask(
            $context,
            $context->translate($speech . '.issue_asked', ['username' => $this->username]),
            function (SectionContext $context) use ($person, $route, $speech): void {
                $this->issueTicket($context, $person, $route, $speech);
            },
        );
    }

    private function issueTicket(SectionContext $context, string $person, string $route, string $speech): void
    {
        $this->accessReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $person, $route, $speech): void {
                $ticket = $api->post(sprintf('/users/%s/%s', rawurlencode($person), $route));
                $this->showTicket($context, TerminalText::line($ticket->string('link'))->value, $ticket->nullableString('expires_at'), $speech);
            },
            'api.writing',
        );
    }

    /**
     * The link in place of the person, as a code the device that will hold the passkey scans and as the text it is, the
     * code holding what the text says.
     */
    private function showTicket(SectionContext $context, string $link, ?string $expiresAt, string $speech): void
    {
        $this->code->setText($link);
        [$columns, $rows] = $this->code->room();
        $this->code->setTooSmall($context->translate('ticket.too_small', ['columns' => $columns, 'rows' => $rows]));
        $this->ticket->title($context->translate($speech . '.title', ['username' => $this->username]));
        $this->ticketMessage->say($context->translate($speech . '.shown', ['link' => $link, 'time' => ApiMoment::of($expiresAt)]));
        $this->person->widget->addStyleClass(Shell::HIDDEN);
        $this->access->widget->addStyleClass(Shell::HIDDEN);
        $this->ticket->widget->removeStyleClass(Shell::HIDDEN);
        $this->ticketShown = true;
        $context->handOver($this->tables[$this->view], $this->code);
    }

    /**
     * The link leaves the screen, and the focus goes back to the table of the person.
     */
    private function closeTicket(SectionContext $context): void
    {
        if (! $this->ticketShown) {
            return;
        }
        $this->forgetTicket();
        $context->handOver($this->code, $this->tables[$this->view]);
    }

    /**
     * The link leaves the screen, the person coming back in its place.
     */
    private function forgetTicket(): void
    {
        if (! $this->ticketShown) {
            return;
        }
        $this->ticketShown = false;
        $this->code->forget();
        $this->ticketMessage->say('');
        $this->ticket->widget->addStyleClass(Shell::HIDDEN);
        $this->person->widget->removeStyleClass(Shell::HIDDEN);
        $this->access->widget->removeStyleClass(Shell::HIDDEN);
    }

    /**
     * The form of the account of the person shown takes the place of their access, prefilled from the person as the API
     * last answered them.
     */
    private function openForm(SectionContext $context): void
    {
        $person = $this->document;
        if ($this->shown === null || $person === null) {
            return;
        }
        $this->confirmation->forget();
        $this->account = new AccountForm($person);
        $this->formTable->setRows($this->account->rows($context));
        $this->form->title($context->translate('account_form.title', ['username' => $this->username]));
        $this->formMessage->say('');
        $this->access->widget->addStyleClass(Shell::HIDDEN);
        $this->form->widget->removeStyleClass(Shell::HIDDEN);
        $context->handOver($this->tables[$this->view], $this->formTable);
    }

    /**
     * Enter on a member of the form: a yes or a no is turned over, and any other value is typed in the field under the
     * table, which starts with the value the form holds.
     */
    private function changeRow(SectionContext $context, int $row): void
    {
        $form = $this->account;
        $member = $form?->member($row);
        if ($form === null || $member === null) {
            return;
        }
        if ($form->isFlag($member)) {
            $form->turnOver($member);
            $this->formTable->setRows($form->rows($context));
            $context->render();

            return;
        }
        $this->editing = $member;
        $this->formField->setPrompt($member . ': ')->setValue($form->text($member));
        $this->formField->removeStyleClass(Shell::HIDDEN);
        $this->hand($context, $this->formTable, $this->formField);
    }

    /**
     * The value typed is kept for the member, and the keys go back to the table, which shows it as a change not saved
     * yet.
     */
    private function written(SectionContext $context, string $typed): void
    {
        $form = $this->account;
        $member = $this->editing;
        if ($form === null || $member === null) {
            return;
        }
        $form->write($member, $typed);
        $this->editing = null;
        $this->formTable->setRows($form->rows($context));
        $this->formField->addStyleClass(Shell::HIDDEN);
        $this->hand($context, $this->formField, $this->formTable);
    }

    /**
     * The key of the form beside the arrows and Enter: `s` writes what was changed.
     */
    private function answeredInTheForm(string $data): bool
    {
        if ($data !== 's' || $this->context === null) {
            return false;
        }
        $this->save($this->context);

        return true;
    }

    /**
     * What was changed is written in one merge patch (RFC 7396): the person is then shown as the API answered them, and
     * a refusal leaves the changes in the form.
     */
    private function save(SectionContext $context): void
    {
        $form = $this->account;
        $person = $this->shown;
        if ($form === null || $person === null) {
            return;
        }
        if (! $form->isChanged()) {
            $this->formMessage->say($context->translate('account_form.unchanged'));
            $context->render();

            return;
        }
        $patch = $form->patch();
        $this->formReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $person, $patch): void {
                try {
                    $account = $api->patch(sprintf('/users/%s', rawurlencode($person)), $patch);
                } catch (ProviderRefusalException $refusal) {
                    if ($refusal->status !== 400 && $refusal->status !== 409) {
                        throw $refusal;
                    }
                    $this->notice->say($context->translate('account_form.refused', [
                        'reason' => TerminalText::line($refusal->description ?? $refusal->error ?? '-')->value,
                    ]), true);

                    return;
                }
                if ($this->shown === $person) {
                    $this->saved($context, $account);
                }
            },
            'api.writing',
        );
    }

    /**
     * The person as the API answered them once changed, their account and claims in place of those last read beside
     * their groups and credentials, and the keys back on their access.
     */
    private function saved(SectionContext $context, Document $account): void
    {
        $person = new Document([...($this->document->members ?? []), ...$account->members]);
        $this->document = $person;
        $this->username = TerminalText::line($person->nullableString('username') ?? '-')->value;
        $this->person->title($context->translate('users.person', [
            'username' => $this->username,
        ]));
        $this->definitions->setDefinitions(self::person($person, $context), $context->translate('value.more'));
        $this->closeForm($context);
        $this->notice->say($context->translate('account_form.saved', ['username' => $this->username]));
    }

    /**
     * The form leaves the screen, and the keys go back to the access of the person.
     */
    private function closeForm(SectionContext $context): void
    {
        $this->forgetForm();
        $this->hand($context, $this->formTable, $this->tables[$this->view]);
    }

    /**
     * The focus left the form for another widget than its own: what was changed and not saved goes with it.
     */
    private function leftTheForm(): void
    {
        if (! $this->handing) {
            $this->forgetForm();
        }
    }

    /**
     * The form leaves the screen with what was changed and not saved, the access of the person coming back in its place.
     */
    private function forgetForm(): void
    {
        if ($this->account === null) {
            return;
        }
        $this->account = null;
        $this->editing = null;
        $this->formMessage->say('');
        $this->formField->setValue('');
        $this->formField->addStyleClass(Shell::HIDDEN);
        $this->form->widget->addStyleClass(Shell::HIDDEN);
        $this->access->widget->removeStyleClass(Shell::HIDDEN);
    }

    /**
     * The keys go from one widget of the form to another, which is not leaving the form.
     */
    private function hand(SectionContext $context, AbstractWidget $from, AbstractWidget $to): void
    {
        $this->handing = true;
        try {
            $context->handOver($from, $to);
        } finally {
            $this->handing = false;
        }
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
                ]);
                $this->sessions = array_map(self::session(...), $answer->documents('sessions'));
                $this->tables[self::SESSIONS]->setRows(array_column($this->sessions, 'cells'), $context->translate('user_sessions.none'));

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
        $this->closeTicket($context);
        $this->confirmation->forget();
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
     * @return array{id: string, revocable: bool, cells: list<string>}
     */
    private static function credential(Document $credential): array
    {
        $revokedAt = $credential->nullableString('revoked_at');

        return [
            'id' => $credential->string('id'),
            'revocable' => $credential->isTrue('revocable') && $revokedAt === null,
            'cells' => [
                TerminalText::line($credential->nullableString('kind') ?? '-')->value,
                TerminalText::line($credential->nullableString('name') ?? '-')->value,
                TerminalText::line($credential->nullableString('authenticator') ?? '-')->value,
                ApiMoment::short($credential->nullableString('created_at')),
                ApiMoment::short($credential->nullableString('last_used_at')),
                ApiMoment::short($revokedAt),
            ],
        ];
    }

    /**
     * @return array{id: string, cells: list<string>}
     */
    private static function session(Document $session): array
    {
        $provenance = $session->nested('provenance');

        return [
            'id' => $session->string('id'),
            'cells' => [
                TerminalText::line(sprintf('%s (%s)', $session->nullableString('acr') ?? '-', implode(' ', $session->strings('methods'))))->value,
                ApiMoment::short($session->nullableString('authenticated_at')),
                ApiMoment::short($session->nullableString('last_seen_at')),
                ApiMoment::short($session->nullableString('expires_at')),
                TerminalText::line($provenance?->nullableString('address') ?? '-')->value,
                TerminalText::line($provenance?->nullableString('place') ?? '-')->value,
                TerminalText::line($provenance?->nullableString('user_agent') ?? '-')->value,
            ],
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
