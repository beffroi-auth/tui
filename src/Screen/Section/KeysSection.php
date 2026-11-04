<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function count;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The signing keys of the tenant in their four states (architecture §3.3), one row each: its state by a mark and a
 * word, never a colour alone (Charte Graphique, p. 22): a full square for the key that signs, an empty one for a key
 * published ahead of its turn, a hatched one for a key that still verifies, a dotted line for a key gone from the
 * JWK Set. Then its algorithm, its identifier, its dates, and whether an active key worked past its age.
 */
final readonly class KeysSection implements SectionInterface
{
    /**
     * The mark of each state the API names.
     */
    private const array MARKS = [
        'active' => '■',
        'staged' => '□',
        'retiring' => '▨',
        'retired' => '┄',
    ];

    private SectionFrame $frame;

    private TableWidget $table;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->table = new TableWidget([
            new TableColumn('state', 10, 9),
            new TableColumn('algorithm', 5, 8),
            new TableColumn('kid', 12, 7, middle: true),
            new TableColumn('use', 3, 1),
            new TableColumn('created', 16, 2),
            new TableColumn('activated', 16, 5),
            new TableColumn('retiring', 16, 4),
            new TableColumn('retired', 16, 3),
            new TableColumn('past its age', 6, 6),
        ]);
        $this->frame->add($this->table);
    }

    public function title(): string
    {
        return 'section.keys';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.keys'));
        $this->frame->reading->once($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function reload(SectionContext $context): void
    {
        $this->frame->reading->again($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function focusables(): array
    {
        return [$this->table];
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $keys = $api->read('/keys')->documents('keys');
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = self::row($key, $context);
        }
        $this->table->setColumnLabels([
            $context->translate('key.state'),
            $context->translate('key.algorithm'),
            $context->translate('key.kid'),
            $context->translate('key.use'),
            $context->translate('key.created_at'),
            $context->translate('key.activated_at'),
            $context->translate('key.retiring_at'),
            $context->translate('key.retired_at'),
            $context->translate('key.past_its_age'),
        ]);
        $this->table->setRows($rows, $context->translate('keys.none'));
        $this->frame->title(
            $context->translate('section.keys'),
            $context->translate('keys.count', ['count' => count($keys), 'time' => Moment::time($context->clock->now())]),
        );
    }

    /**
     * @return list<string>
     */
    private static function row(Document $key, SectionContext $context): array
    {
        $status = $key->nullableString('status');
        $mark = self::MARKS[$status ?? ''] ?? '?';

        return [
            $mark . ' ' . ($status === null ? '-' : $context->named('key.status', $status) ?? TerminalText::line($status)->value),
            TerminalText::line($key->nullableString('algorithm') ?? '-')->value,
            TerminalText::line($key->nullableString('kid') ?? '-')->value,
            TerminalText::line($key->nullableString('use') ?? '-')->value,
            ApiMoment::short($key->nullableString('created_at')),
            ApiMoment::short($key->nullableString('activated_at')),
            ApiMoment::short($key->nullableString('retiring_at')),
            ApiMoment::short($key->nullableString('retired_at')),
            $key->nullableBool('past_its_age') === true ? '⚠ ' . $context->translate('value.yes') : $context->yesOrNo($key->nullableBool('past_its_age')),
        ];
    }
}
