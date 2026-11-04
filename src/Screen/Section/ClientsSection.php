<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\JsonText;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\Panel;
use Beffroi\Tui\Screen\Reading;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\Definition;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function count;
use function rawurlencode;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The clients of the tenant in a table, by their name, their identifier, where each came from and when it was
 * registered and last used, and the client Enter opens beside it or under it: what it registered (RFC 7591 §2), every
 * value of it written by whoever registered the client and passed through `TerminalText` as such.
 */
final class ClientsSection implements SectionInterface
{
    private readonly SectionFrame $frame;

    private readonly Panel $list;

    private readonly Panel $detail;

    private readonly TableWidget $table;

    private readonly MessageWidget $detailMessage;

    private readonly DefinitionsWidget $definitions;

    private readonly Reading $detailReading;

    /**
     * @var list<string> the identifier of the client of each row
     */
    private array $clientIds = [];

    private ?SectionContext $context = null;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->table = new TableWidget([
            new TableColumn('name', 12, 8),
            new TableColumn('client_id', 12, 9, middle: true),
            new TableColumn('source', 8, 4),
            new TableColumn('registered', 16, 2),
            new TableColumn('last used', 16, 6),
        ]);
        $this->detailMessage = new MessageWidget();
        $this->definitions = new DefinitionsWidget(true);
        $this->detailReading = new Reading($this->detailMessage);
        $this->list = new Panel([$this->table], weight: 3);
        // as tall as its line until a client is opened, the list taking the rest
        $this->detail = new Panel([$this->detailMessage, $this->definitions], false, 2);
        $this->frame->add(Panel::split([$this->list, $this->detail]));
        $this->table->onChoose(function (int $row): void {
            if ($this->context !== null && isset($this->clientIds[$row])) {
                $this->open($this->context, $this->clientIds[$row]);
            }
        });
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
        $this->frame->reading->once($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function reload(SectionContext $context): void
    {
        $this->context = $context;
        $this->titled($context);
        $this->frame->reading->again($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function focusables(): array
    {
        return $this->clientIds === [] ? [] : [$this->table];
    }

    private function titled(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.clients'));
        $this->list->title($context->translate('clients.list'));
        $this->detail->title($context->translate('clients.detail'));
        if ($this->definitions->isEmpty()) {
            $this->detailMessage->say($context->translate('clients.choose'));
        }
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $rows = [];
        $this->clientIds = [];
        foreach ($api->read('/clients')->documents('clients') as $client) {
            $clientId = $client->string('client_id');
            $this->clientIds[] = $clientId;
            $rows[] = [
                TerminalText::line($client->nullableString('client_name') ?? '-')->value,
                TerminalText::line($clientId)->value,
                TerminalText::line($client->nullableString('source') ?? '-')->value,
                ApiMoment::short($client->nullableString('registered_at')),
                ApiMoment::short($client->nullableString('last_used_at')),
            ];
        }
        $this->table->setColumnLabels([
            $context->translate('client.client_name'),
            $context->translate('client.client_id'),
            $context->translate('client.source'),
            $context->translate('client.registered_at'),
            $context->translate('client.last_used_at'),
        ]);
        $this->table->setRows($rows, $context->translate('clients.none'));
        $this->frame->title(
            $context->translate('section.clients'),
            $context->translate('clients.count', ['count' => count($rows), 'time' => Moment::time($context->clock->now())]),
        );
    }

    private function open(SectionContext $context, string $clientId): void
    {
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
        foreach (['metadata' => 'client.metadata', 'assigned' => 'client.assigned'] as $member => $heading) {
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
