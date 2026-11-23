<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\Panel;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\ChartBar;
use Beffroi\Tui\Screen\Widget\ChartWidget;
use Beffroi\Tui\Screen\Widget\Check;
use Beffroi\Tui\Screen\Widget\ChecksWidget;
use Beffroi\Tui\Screen\Widget\Tile;
use Beffroi\Tui\Screen\Widget\TilesWidget;
use Beffroi\Tui\Text\TerminalText;
use function array_map;
use function in_array;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * What the instance knows of itself now (decision 0040): its figures in boxes, the people, the clients and the open
 * sessions of its tenant, its signing keys and what waits in the lanes of its worker; then the points its doctor looks
 * at, each marked and worded; then its acts over the window its journal keeps, counted by name, the failures drawn in
 * the colour of errors. Nothing of it is kept by this program: `r` reads it again.
 */
final readonly class MonitoringSection implements SectionInterface
{
    /**
     * The acts that say something failed or was refused, whose bars are drawn in the colour of errors, their label
     * saying what failed.
     */
    private const array FAILURES = [
        'authentication.failed',
        'client_authentication.failed',
        'authorization_code.replayed',
        'refresh_token.reuse_detected',
        'pushed_request.refused',
        'kms.unavailable',
        'load.budget_spent',
        'logout_token.given_up',
        'credential_change.not_told',
    ];

    private SectionFrame $frame;

    private TilesWidget $tiles;

    private Panel $health;

    private Panel $acts;

    private ChecksWidget $checks;

    private ChartWidget $chart;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->tiles = new TilesWidget();
        $this->checks = new ChecksWidget();
        $this->chart = new ChartWidget();
        $this->health = new Panel([$this->checks]);
        $this->acts = new Panel([$this->chart]);
        $this->frame->add($this->tiles);
        $this->frame->add(Panel::split([$this->health, $this->acts]));
    }

    public function title(): string
    {
        return 'section.monitoring';
    }

    public function widget(): AbstractWidget
    {
        return $this->frame->widget;
    }

    public function show(SectionContext $context): void
    {
        $this->titled($context);
        $this->frame->reading->once($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function reload(SectionContext $context): void
    {
        $this->titled($context);
        $this->frame->reading->again($context, fn (AdministrationApi $api) => $this->read($api, $context));
    }

    public function focusables(): array
    {
        return [];
    }

    private function titled(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.monitoring'));
        $this->health->title($context->translate('health.heading'));
        $this->acts->title($context->translate('statistics.acts'));
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $health = $api->read('/health');
        $statistics = $api->read('/statistics');
        $this->checks->setChecks(
            array_map(
                static fn (Document $check): Check => new Check(
                    $check->isTrue('green'),
                    TerminalText::line($check->nullableString('about') ?? '-')->value,
                    array_map(static fn (string $line): string => TerminalText::line($line)->value, $check->strings('lines')),
                ),
                $health->documents('checks'),
            ),
            $context->translate('health.holds'),
            $context->translate('health.fails'),
            $context->translate('health.none'),
            $context->translate('value.more'),
        );
        $this->tiles->setTiles(self::tiles($statistics, $context));
        $this->chart->setBars(
            array_map(
                static fn (Document $act): ChartBar => new ChartBar(
                    $context->act($act->nullableString('act') ?? '-'),
                    $act->nullableInt('count') ?? 0,
                    in_array($act->nullableString('act'), self::FAILURES, true),
                ),
                $statistics->documents('acts'),
            ),
            $context->translate('value.none'),
            $context->translate('value.more'),
        );
        $this->acts->title(
            $context->translate('statistics.acts'),
            $context->translate('statistics.since', ['since' => ApiMoment::short($statistics->nullableString('since'))]),
        );
        $this->frame->title(
            $context->translate('section.monitoring'),
            $context->translate('section.read_at', ['time' => Moment::time($context->clock->now())]),
        );
    }

    /**
     * @return list<Tile>
     */
    private static function tiles(Document $statistics, SectionContext $context): array
    {
        $keys = $statistics->nested('keys');
        $waiting = null;
        foreach ($statistics->documents('queues') as $queue) {
            $messages = $queue->nullableInt('messages');
            if ($messages !== null) {
                $waiting = ($waiting ?? 0) + $messages;
            }
        }

        return [
            new Tile(self::figure($statistics->nullableInt('users')), $context->translate('statistics.users')),
            new Tile(self::figure($statistics->nullableInt('clients')), $context->translate('statistics.clients')),
            new Tile(self::figure($statistics->nullableInt('open_sessions')), $context->translate('statistics.open_sessions')),
            new Tile(self::figure($keys?->nullableInt('active')), $context->translate('statistics.active_keys')),
            new Tile(self::figure($keys?->nullableInt('staged')), $context->translate('statistics.staged_keys')),
            new Tile(self::figure($waiting), $context->translate('statistics.waiting')),
        ];
    }

    private static function figure(?int $count): string
    {
        return $count === null ? '-' : (string) $count;
    }
}
