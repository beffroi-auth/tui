<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\Panel;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Widget\Definition;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function implode;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The tenant the administrator administers, as the instance holds it: its name and its issuer, the floor of its logins,
 * the groups that administer it, the administration API and how long its journal keeps an act, beside the classes of
 * authentication its policy names, each by the predicate it stands on (decisions 0010 and 0025).
 */
final readonly class TenantSection implements SectionInterface
{
    private SectionFrame $frame;

    private Panel $settings;

    private Panel $classes;

    private DefinitionsWidget $definitions;

    private TableWidget $table;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->definitions = new DefinitionsWidget(true);
        $this->table = new TableWidget([new TableColumn('acr', 6, 1), new TableColumn('predicate', 12)], false)->expandVertically(false);
        $this->settings = new Panel([$this->definitions], weight: 3);
        // as tall as the classes it lists, the settings taking the rest
        $this->classes = new Panel([$this->table], false, 2);
        $this->frame->add(Panel::split([$this->settings, $this->classes]));
    }

    public function title(): string
    {
        return 'section.tenant';
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
        $this->frame->title($context->translate('section.tenant'));
        $this->settings->title($context->translate('tenant.settings'));
        $this->classes->title($context->translate('tenant.classes'));
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $tenant = $api->read('/tenant');
        $administrators = $tenant->strings('administrators');
        $retention = $tenant->nullableInt('journal_retention_days');
        $this->definitions->setDefinitions([
            new Definition($context->translate('tenant.name'), TerminalText::line($tenant->nullableString('name') ?? '-')->value),
            new Definition($context->translate('tenant.slug'), TerminalText::line($tenant->nullableString('slug') ?? '-')->value),
            new Definition($context->translate('tenant.issuer'), TerminalText::line($tenant->nullableString('issuer') ?? '-')->value),
            new Definition($context->translate('tenant.created_at'), ApiMoment::of($tenant->nullableString('created_at'))),
            new Definition($context->translate('tenant.login_floor'), TerminalText::line($tenant->nullableString('login_floor') ?? '-')->value),
            new Definition(
                $context->translate('tenant.administrators'),
                $administrators === [] ? $context->translate('value.none') : TerminalText::line(implode(', ', $administrators))->value,
            ),
            new Definition($context->translate('tenant.administration_api'), TerminalText::line($tenant->nullableString('administration_api') ?? '-')->value),
            new Definition($context->translate('tenant.journal'), $retention === null ? '-' : $context->translate('value.days', ['days' => $retention])),
        ], $context->translate('value.more'));
        $rows = [];
        foreach ($tenant->documents('authentication_classes') as $class) {
            $rows[] = [TerminalText::line($class->nullableString('acr') ?? '-')->value, TerminalText::line($class->nullableString('predicate') ?? '-')->value];
        }
        $this->table->setColumnLabels([$context->translate('tenant.acr'), $context->translate('tenant.predicate')]);
        $this->table->setRows($rows, $context->translate('value.none'));
        $this->frame->title($context->translate('section.tenant'), $context->translate('section.read_at', ['time' => Moment::time($context->clock->now())]));
    }
}
