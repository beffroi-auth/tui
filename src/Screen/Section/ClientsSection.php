<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\ChangeAnswer;
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
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function count;
use function rawurlencode;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The clients of the tenant in a table, by their name, their identifier, where each came from and when it was
 * registered and last used, and the client Enter opens beside it or under it: what it registered (RFC 7591 §2), what
 * the registration policy assigned it and what the initial access token that registered it gave it, every value of it
 * written by whoever registered the client and passed through `TerminalText` as such. `x` asks to delete the client
 * chosen, made once `y` confirms it: nothing it was issued can be spent any more.
 *
 * `i` shows the initial access tokens of the tenant in place of the clients, read each time it is pressed, with what
 * each has served and whether it still authorises a registration, and `c` the clients again. `n` opens the form of a
 * new token beside them, which `s` issues: its value then takes the place of the tokens and of the form, once, as wide
 * as the section so that it is never cut, until Enter closes it or the focus leaves it, and nothing shows it again. `x` asks to revoke the token chosen. Each change is an act of the
 * tenant that names the administrator (decision 0038), and the tables say what the API answered without reading again.
 */
final class ClientsSection implements SectionInterface
{
    private const string CLIENTS = 'clients';

    private const string TOKENS = 'tokens';

    private readonly SectionFrame $frame;

    private readonly MessageWidget $notice;

    private readonly Panel $list;

    private readonly MessageWidget $listMessage;

    private readonly Reading $listReading;

    private readonly Confirmation $confirmation;

    /**
     * @var array<string, TableWidget> the table of each view of the list
     */
    private readonly array $tables;

    private readonly Panel $detail;

    private readonly MessageWidget $detailMessage;

    private readonly DefinitionsWidget $definitions;

    private readonly Reading $detailReading;

    private readonly Panel $issuance;

    private readonly MessageWidget $issuanceStatus;

    private readonly Reading $issuanceReading;

    private readonly MessageWidget $issuanceMessage;

    private readonly TableWidget $formTable;

    private readonly FieldWidget $formField;

    private readonly TableWidget $valueTable;

    private string $view = self::CLIENTS;

    /**
     * @var list<array{id: string, cells: list<string>}> each client listed, by its identifier, in the order of its row
     */
    private array $clients = [];

    /**
     * @var list<array{id: string, name: string, cells: list<string>}> each initial access token listed, the most recently issued first
     */
    private array $tokens = [];

    /**
     * The identifier of the client opened beside the list, null until one is.
     */
    private ?string $opened = null;

    /**
     * The form of a new initial access token, null while it is not on the screen.
     */
    private ?InitialAccessTokenForm $form = null;

    /**
     * The member whose value the field of the form is typing.
     */
    private ?string $editing = null;

    /**
     * Whether the value of a token just issued is on the screen, which nothing shows again once it leaves.
     */
    private bool $valueShown = false;

    /**
     * Whether the keys are moved between the widgets of the section by the section itself, which is not leaving them.
     */
    private bool $handing = false;

    private ?SectionContext $context = null;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->notice = new MessageWidget();
        $this->listMessage = new MessageWidget();
        $this->listReading = new Reading($this->listMessage);
        $this->confirmation = new Confirmation($this->listMessage);
        $this->tables = [
            self::CLIENTS => new TableWidget([
                new TableColumn('name', 12, 8),
                new TableColumn('client_id', 12, 9, middle: true),
                new TableColumn('source', 8, 4),
                new TableColumn('registered', 16, 2),
                new TableColumn('last used', 16, 6),
            ]),
            self::TOKENS => new TableWidget([
                new TableColumn('name', 12, 9),
                new TableColumn('state', 12, 8),
                new TableColumn('registrations', 8, 6, right: true),
                new TableColumn('expires', 16, 5),
                new TableColumn('administration', 6, 4),
            ]),
        ];
        foreach ($this->tables as $table) {
            $table->onInput(fn (string $data): bool => $this->asked($data));
            $table->onLeave(function (): void {
                $this->confirmation->forget();
            });
        }
        $this->tables[self::TOKENS]->addStyleClass(Shell::HIDDEN);
        $this->tables[self::CLIENTS]->onChoose(function (int $row): void {
            if ($this->context !== null && isset($this->clients[$row])) {
                $this->open($this->context, $this->clients[$row]['id']);
            }
        });
        $this->detailMessage = new MessageWidget();
        $this->definitions = new DefinitionsWidget(true);
        $this->detailReading = new Reading($this->detailMessage);
        $this->issuanceStatus = new MessageWidget();
        $this->issuanceReading = new Reading($this->issuanceStatus);
        $this->issuanceMessage = new MessageWidget();
        $this->formTable = new TableWidget([
            new TableColumn('member', 14, 9),
            new TableColumn('value', 12, 8),
        ]);
        $this->formTable->onInput(fn (string $data): bool => $this->answeredInTheForm($data));
        $this->formTable->onChoose(function (int $row): void {
            if ($this->context !== null) {
                $this->changeRow($this->context, $row);
            }
        }, 'keys.change');
        $this->formTable->onLeave(function (): void {
            $this->leftTheIssuance();
        });
        $this->formField = new FieldWidget('keys.keep');
        $this->formField->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->kept($this->context, $event->getValue());
            }
        });
        $this->formField->onLeave(function (): void {
            $this->leftTheIssuance();
        });
        $this->formField->addStyleClass(Shell::HIDDEN);
        $this->valueTable = new TableWidget([new TableColumn('value', 16, 9)]);
        $this->valueTable->onChoose(function (): void {
            if ($this->context !== null) {
                $this->closeIssuance($this->context);
            }
        }, 'keys.close');
        $this->valueTable->onLeave(function (): void {
            $this->leftTheIssuance();
        });
        $this->valueTable->addStyleClass(Shell::HIDDEN);
        $this->list = new Panel([$this->listMessage, ...array_values($this->tables)], weight: 3);
        // as tall as its line until a client is opened, the list taking the rest
        $this->detail = new Panel([$this->detailMessage, $this->definitions], false, 2);
        $this->issuance = new Panel([$this->issuanceStatus, $this->issuanceMessage, $this->formTable, $this->formField, $this->valueTable], weight: 2);
        $this->issuance->widget->addStyleClass(Shell::HIDDEN);
        $this->frame->add($this->notice);
        $this->frame->add(Panel::split([$this->list, $this->detail, $this->issuance]));
    }

    public function title(): string
    {
        return 'section.clients';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $this->context = $context;
        $this->titled($context);
        $this->frame->reading->once($context, fn (AdministrationApi $api) => $this->readClients($api, $context));
    }

    public function reload(SectionContext $context): void
    {
        $this->context = $context;
        $this->closeIssuance($context);
        $this->titled($context);
        if ($this->view === self::TOKENS) {
            $this->readTokens($context);

            return;
        }
        $this->frame->reading->again($context, fn (AdministrationApi $api) => $this->readClients($api, $context));
    }

    public function focusables(): array
    {
        $focusables = [$this->tables[$this->view]];
        if ($this->form !== null) {
            $focusables[] = $this->formTable;
        }
        if ($this->valueShown) {
            $focusables[] = $this->valueTable;
        }

        return $focusables;
    }

    private function titled(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.clients'));
        $this->list->title(
            $context->translate($this->view === self::TOKENS ? 'initial_access_tokens.list' : 'clients.list'),
            $context->translate('clients.views'),
        );
        $this->detail->title(
            $this->opened === null
                ? $context->translate('clients.detail')
                : $context->translate('clients.detail_of', ['client' => TerminalText::line($this->opened)->value]),
        );
        if ($this->definitions->isEmpty()) {
            $this->detailMessage->say($context->translate('clients.choose'));
        }
        $this->tables[self::CLIENTS]->setColumnLabels([
            $context->translate('client.client_name'),
            $context->translate('client.client_id'),
            $context->translate('client.source'),
            $context->translate('client.registered_at'),
            $context->translate('client.last_used_at'),
        ])->setSectionKeys([['x', $context->translate('keys.delete')], ['i', $context->translate('keys.initial_access_tokens')]]);
        $this->tables[self::TOKENS]->setColumnLabels([
            $context->translate('initial_access_token.name'),
            $context->translate('initial_access_token.state'),
            $context->translate('initial_access_token.registrations'),
            $context->translate('initial_access_token.expires_at'),
            $context->translate('initial_access_token.administration'),
        ])->setSectionKeys([
            ['n', $context->translate('keys.new_token')],
            ['x', $context->translate('keys.revoke')],
            ['c', $context->translate('keys.clients')],
        ]);
        $this->formTable->setColumnLabels([
            $context->translate('token_form.member'),
            $context->translate('token_form.value'),
        ])->setSectionKeys([['s', $context->translate('keys.issue')]]);
        $this->valueTable->setColumnLabels([$context->translate('initial_access_token.value')]);
    }

    private function readClients(AdministrationApi $api, SectionContext $context): void
    {
        $this->clients = array_map(self::client(...), $api->read('/clients')->documents('clients'));
        $this->tables[self::CLIENTS]->setRows(array_column($this->clients, 'cells'), $context->translate('clients.none'));
        $this->frame->title(
            $context->translate('section.clients'),
            $context->translate('clients.count', ['count' => count($this->clients), 'time' => Moment::time($context->clock->now())]),
        );
    }

    private function readTokens(SectionContext $context): void
    {
        $this->listReading->again($context, function (AdministrationApi $api) use ($context): void {
            $this->tokens = array_map(
                static fn (Document $token): array => self::token($token, $context),
                $api->read('/initial-access-tokens')->documents('initial_access_tokens'),
            );
            $this->tables[self::TOKENS]->setRows(array_column($this->tokens, 'cells'), $context->translate('initial_access_tokens.none'));
        });
    }

    /**
     * The keys of the tables: `i` the initial access tokens, `c` the clients, `n` the form of a new token, and `x` the
     * deletion of the client chosen or the revocation of the token chosen, confirmed by `y` and forgotten by any other
     * key.
     */
    private function asked(string $data): bool
    {
        $context = $this->context;
        if ($context === null) {
            return false;
        }
        if ($this->confirmation->answer($context, $data)) {
            return true;
        }
        if ($data === 'i' || $data === 'c') {
            $this->showView($context, $data === 'i' ? self::TOKENS : self::CLIENTS);

            return true;
        }
        if ($data === 'x') {
            if ($this->view === self::TOKENS) {
                $this->askToRevoke($context);
            } else {
                $this->askToDelete($context);
            }

            return true;
        }
        if ($data === 'n' && $this->view === self::TOKENS) {
            $this->openForm($context);

            return true;
        }

        return false;
    }

    /**
     * The view of the list the key asked for: the clients as they were read, or the initial access tokens, read again
     * each time they are asked for, which take the width of the client opened beside them.
     */
    private function showView(SectionContext $context, string $view): void
    {
        $this->confirmation->forget();
        $this->forgetIssuance();
        $previous = $this->tables[$this->view];
        $focused = $previous->isFocused();
        foreach ($this->tables as $name => $table) {
            if ($name === $view) {
                $table->removeStyleClass(Shell::HIDDEN);
            } else {
                $table->addStyleClass(Shell::HIDDEN);
            }
        }
        if ($view === self::TOKENS) {
            $this->detail->widget->addStyleClass(Shell::HIDDEN);
        } else {
            $this->detail->widget->removeStyleClass(Shell::HIDDEN);
        }
        $this->view = $view;
        $this->titled($context);
        if ($focused) {
            $context->handOver($previous, $this->tables[$view]);
        }
        if ($view === self::TOKENS) {
            $this->readTokens($context);
        }
        $context->render();
    }

    private function open(SectionContext $context, string $clientId): void
    {
        $this->opened = $clientId;
        $this->detail->title($context->translate('clients.detail_of', ['client' => TerminalText::line($clientId)->value]));
        $this->detailReading->again($context, function (AdministrationApi $api) use ($context, $clientId): void {
            $this->definitions->setDefinitions(
                self::definitions($api->read('/clients/' . rawurlencode($clientId)), $context),
                $context->translate('value.more'),
            );
            // once a client is opened, what it registered takes the larger share, the list keeping what its columns need
            $this->detail->widget->expandVertically(true);
            $this->detail->weigh(3);
            $this->list->weigh(2);
        });
    }

    /**
     * The client deleted leaves the place beside the list, which says again what Enter does.
     */
    private function closeClient(SectionContext $context): void
    {
        $this->opened = null;
        $this->definitions->setDefinitions([]);
        $this->detail->title($context->translate('clients.detail'));
        $this->detailMessage->say($context->translate('clients.choose'));
        $this->detail->widget->expandVertically(false);
        $this->detail->weigh(2);
        $this->list->weigh(3);
    }

    private function askToDelete(SectionContext $context): void
    {
        $client = $this->clients[$this->tables[self::CLIENTS]->selected() ?? -1] ?? null;
        if ($client === null) {
            return;
        }
        $this->confirmation->ask(
            $context,
            $context->translate('clients.delete_asked', ['client' => $client['cells'][1]]),
            function (SectionContext $context) use ($client): void {
                $this->delete($context, $client);
            },
        );
    }

    /**
     * The client is deleted, and leaves the rows and the place beside them: the API answers the client it deleted.
     *
     * @param array{id: string, cells: list<string>} $client
     */
    private function delete(SectionContext $context, array $client): void
    {
        $this->listReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $client): void {
                $path = '/clients/' . rawurlencode($client['id']);
                if (ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->delete($path)) === null) {
                    return;
                }
                $this->clients = array_values(array_filter($this->clients, static fn (array $listed): bool => $listed['id'] !== $client['id']));
                $this->tables[self::CLIENTS]->setRows(array_column($this->clients, 'cells'), $context->translate('clients.none'));
                if ($this->opened === $client['id']) {
                    $this->closeClient($context);
                }
                $this->notice->say($context->translate('clients.deleted_now', ['client' => $client['cells'][1]]));
            },
            'api.writing',
        );
    }

    private function askToRevoke(SectionContext $context): void
    {
        $token = $this->tokens[$this->tables[self::TOKENS]->selected() ?? -1] ?? null;
        if ($token === null) {
            return;
        }
        $this->confirmation->ask(
            $context,
            $context->translate('initial_access_tokens.revoke_asked', ['name' => $token['name']]),
            function (SectionContext $context) use ($token): void {
                $this->revoke($context, $token);
            },
        );
    }

    /**
     * The token is revoked, and its row says so, as the API answers it.
     *
     * @param array{id: string, name: string, cells: list<string>} $token
     */
    private function revoke(SectionContext $context, array $token): void
    {
        $this->listReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $token): void {
                $path = '/initial-access-tokens/' . rawurlencode($token['id']);
                $revoked = ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->delete($path));
                if ($revoked === null) {
                    return;
                }
                foreach ($this->tokens as $index => $listed) {
                    if ($listed['id'] === $token['id']) {
                        $this->tokens[$index] = self::token($revoked, $context);
                    }
                }
                $this->tables[self::TOKENS]->setRows(array_column($this->tokens, 'cells'), $context->translate('initial_access_tokens.none'));
                $this->notice->say($context->translate('initial_access_tokens.revoked_now', ['name' => $token['name']]));
            },
            'api.writing',
        );
    }

    /**
     * The form of a new initial access token beside the tokens, its name to type and the rest prefilled.
     */
    private function openForm(SectionContext $context): void
    {
        $this->confirmation->forget();
        $this->forgetIssuance();
        $form = new InitialAccessTokenForm();
        $this->form = $form;
        $this->formTable->setRows($form->rows($context));
        $this->issuance->title($context->translate('token_form.title'));
        $this->issuanceMessage->say($context->translate('token_form.notice'));
        $this->formTable->removeStyleClass(Shell::HIDDEN);
        $this->issuance->widget->removeStyleClass(Shell::HIDDEN);
        $this->hand($context, $this->tables[self::TOKENS], $this->formTable);
        $context->render();
    }

    /**
     * Enter on a member of the form: a yes or a no is turned over, and any other value is typed in the field under the
     * table, which starts with the value the form holds.
     */
    private function changeRow(SectionContext $context, int $row): void
    {
        $form = $this->form;
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
     * The value typed is kept for the member, and the keys go back to the table of the form.
     */
    private function kept(SectionContext $context, string $typed): void
    {
        $form = $this->form;
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
     * The key of the form beside the arrows and Enter: `s` issues the token.
     */
    private function answeredInTheForm(string $data): bool
    {
        if ($data !== 's' || $this->context === null) {
            return false;
        }
        $this->issue($this->context);

        return true;
    }

    /**
     * The token asked for is issued: its row joins the tokens, and its value takes their place and the place of the
     * form, even when the focus left the form while the API answered, since nothing can show it again.
     */
    private function issue(SectionContext $context): void
    {
        $form = $this->form;
        if ($form === null) {
            return;
        }
        $asked = $form->asked();
        $this->issuanceReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $asked): void {
                $issued = ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->post('/initial-access-tokens', $asked));
                if ($issued === null) {
                    return;
                }
                $token = self::token($issued, $context);
                $this->tokens = [$token, ...$this->tokens];
                $this->tables[self::TOKENS]->setRows(array_column($this->tokens, 'cells'), $context->translate('initial_access_tokens.none'));
                $this->notice->say('');
                $this->showValue($context, $token['name'], TerminalText::line($issued->string('value'))->value);
            },
            'api.writing',
        );
    }

    private function showValue(SectionContext $context, string $name, string $value): void
    {
        $this->form = null;
        $this->editing = null;
        $this->valueShown = true;
        $this->issuance->title($context->translate('initial_access_tokens.issued_title', ['name' => $name]));
        $this->issuanceMessage->say($context->translate('initial_access_tokens.issued_shown'));
        $this->valueTable->setRows([[$value]]);
        $this->formTable->addStyleClass(Shell::HIDDEN);
        $this->formField->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->valueTable->removeStyleClass(Shell::HIDDEN);
        $this->issuance->widget->removeStyleClass(Shell::HIDDEN);
        $this->list->widget->addStyleClass(Shell::HIDDEN);
        $this->hand($context, $this->formTable, $this->valueTable);
        $this->hand($context, $this->formField, $this->valueTable);
    }

    /**
     * The form or the value leaves the screen, and the keys go back to the tokens.
     */
    private function closeIssuance(SectionContext $context): void
    {
        $this->forgetIssuance();
        foreach ([$this->formTable, $this->formField, $this->valueTable] as $widget) {
            $this->hand($context, $widget, $this->tables[self::TOKENS]);
        }
    }

    /**
     * The focus left the form, its field or the value for another widget than the section moved it to: what was typed
     * goes with it, and the value of a token leaves the screen for good.
     */
    private function leftTheIssuance(): void
    {
        if (! $this->handing) {
            $this->forgetIssuance();
        }
    }

    private function forgetIssuance(): void
    {
        $this->form = null;
        $this->editing = null;
        $this->valueShown = false;
        $this->issuanceMessage->say('');
        $this->formField->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->valueTable->setRows([]);
        $this->valueTable->addStyleClass(Shell::HIDDEN);
        $this->issuance->widget->addStyleClass(Shell::HIDDEN);
        $this->list->widget->removeStyleClass(Shell::HIDDEN);
    }

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
     * @return array{id: string, cells: list<string>}
     */
    private static function client(Document $client): array
    {
        $clientId = $client->string('client_id');

        return [
            'id' => $clientId,
            'cells' => [
                TerminalText::line($client->nullableString('client_name') ?? '-')->value,
                TerminalText::line($clientId)->value,
                TerminalText::line($client->nullableString('source') ?? '-')->value,
                ApiMoment::short($client->nullableString('registered_at')),
                ApiMoment::short($client->nullableString('last_used_at')),
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, cells: list<string>}
     */
    private static function token(Document $token, SectionContext $context): array
    {
        $name = TerminalText::line($token->string('name'))->value;

        return [
            'id' => $token->string('id'),
            'name' => $name,
            'cells' => [
                $name,
                self::state($token, $context),
                $context->translate('initial_access_token.served_of', [
                    'served' => $token->nullableInt('registrations_served') ?? 0,
                    'allowed' => $token->nullableInt('registrations_allowed') ?? 0,
                ]),
                ApiMoment::short($token->nullableString('expires_at')),
                $context->yesOrNo($token->nullableBool('administration')),
            ],
        ];
    }

    /**
     * What a token still does, from what the API answered of it: revoked, spent, expired once it is neither, or good
     * for a registration.
     */
    private static function state(Document $token, SectionContext $context): string
    {
        $revokedAt = $token->nullableString('revoked_at');
        if ($revokedAt !== null) {
            return $context->translate('initial_access_token.revoked_on', ['time' => ApiMoment::short($revokedAt)]);
        }
        if (($token->nullableInt('registrations_served') ?? 0) >= ($token->nullableInt('registrations_allowed') ?? 0)) {
            return $context->translate('initial_access_token.spent');
        }

        return $context->translate($token->isTrue('authorises_a_registration') ? 'initial_access_token.authorises' : 'initial_access_token.expired');
    }

    /**
     * @return list<Definition>
     */
    private static function definitions(Document $client, SectionContext $context): array
    {
        $definitions = [
            new Definition($context->translate('client.client_id'), TerminalText::line($client->nullableString('client_id') ?? '-')->value),
            new Definition($context->translate('client.client_name'), TerminalText::line($client->nullableString('client_name') ?? '-')->value),
            new Definition($context->translate('client.source'), TerminalText::line($client->nullableString('source') ?? '-')->value),
            new Definition($context->translate('client.registered_at'), ApiMoment::of($client->nullableString('registered_at'))),
            new Definition($context->translate('client.last_used_at'), ApiMoment::of($client->nullableString('last_used_at'))),
            new Definition($context->translate('client.administration'), $context->yesOrNo($client->nullableBool('administration'))),
        ];
        foreach (['metadata' => 'client.metadata', 'assigned' => 'client.assigned', 'granted' => 'client.granted'] as $member => $heading) {
            $values = $client->nested($member);
            if ($values === null || $values->members === []) {
                continue;
            }
            $definitions[] = Definition::heading($context->translate($heading));
            foreach ($values->members as $name => $value) {
                $definitions[] = new Definition(TerminalText::line((string) $name)->value, TerminalText::line(JsonText::of($value))->value);
            }
        }

        return $definitions;
    }
}
