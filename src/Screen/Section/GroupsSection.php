<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Protocol\ProviderRefusalException;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\Confirmation;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\Panel;
use Beffroi\Tui\Screen\Reading;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Shell;
use Beffroi\Tui\Screen\Widget\FieldWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use Closure;
use function count;
use function in_array;
use function rawurlencode;
use function sprintf;
use function strcmp;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use function trim;
use function usort;

/**
 * The groups of the tenant in a table, by their name, with where each came from, how many members it holds and
 * whether its members administer the tenant, which delivers nobody. Enter reads the members of a group beside it or
 * under it: each member read is an act in their journal that names the administrator (decisions 0022 and 0038), which
 * the screen says before the first one.
 *
 * `n` asks for the name of a group to create, `a` for the username of a member to add to the group chosen, and `x`
 * asks to take the member chosen out, made once `y` confirms it. Each change is an act that names the administrator,
 * and the tables say what the API answered without reading again.
 */
final class GroupsSection implements SectionInterface
{
    private readonly SectionFrame $frame;

    private readonly MessageWidget $notice;

    private readonly FieldWidget $name;

    private readonly FieldWidget $member;

    private readonly Panel $list;

    private readonly Panel $members;

    private readonly TableWidget $groupsTable;

    private readonly TableWidget $membersTable;

    private readonly MessageWidget $membersMessage;

    private readonly Reading $membersReading;

    private readonly Confirmation $confirmation;

    /**
     * @var list<array{id: string, name: string, source: string, members: int, administers: bool, created: ?string}> in the order of their rows, by name
     */
    private array $groups = [];

    /**
     * @var list<array{id: string, cells: list<string>}> each member of the group shown, by their person, in the order of their rows
     */
    private array $shownMembers = [];

    /**
     * The identifier of the group whose members are shown, null until one is opened.
     */
    private ?string $shown = null;

    /**
     * The identifier of the group a member is added to while the field asks for their username.
     */
    private ?string $joining = null;

    /**
     * The field on the screen, asking for the name of a group or the username of a member, null when none is.
     */
    private ?FieldWidget $asking = null;

    /**
     * Whether the keys are moved between the widgets of the section by the section itself, which no field leaves for.
     */
    private bool $handing = false;

    private ?SectionContext $context = null;

    public function __construct()
    {
        $this->frame = new SectionFrame();
        $this->notice = new MessageWidget();
        $this->name = new FieldWidget('keys.create');
        $this->member = new FieldWidget('keys.add');
        $this->groupsTable = new TableWidget([
            new TableColumn('name', 12, 9),
            new TableColumn('source', 8, 4),
            new TableColumn('members', 7, 8, right: true),
            new TableColumn('administers', 11, 7),
            new TableColumn('created', 16, 2),
        ]);
        $this->membersTable = new TableWidget([
            new TableColumn('username', 12, 9),
            new TableColumn('source', 8, 5),
            new TableColumn('added', 16, 4),
        ]);
        $this->membersMessage = new MessageWidget();
        $this->membersReading = new Reading($this->membersMessage);
        $this->confirmation = new Confirmation($this->membersMessage);
        $this->list = new Panel([$this->groupsTable], weight: 3);
        $this->members = new Panel([$this->membersMessage, $this->membersTable], weight: 2);
        $this->name->addStyleClass(Shell::HIDDEN);
        $this->member->addStyleClass(Shell::HIDDEN);
        $this->frame->add($this->notice);
        $this->frame->add($this->name);
        $this->frame->add($this->member);
        $this->frame->add(Panel::split([$this->list, $this->members]));
        $this->groupsTable->onChoose(function (int $row): void {
            if ($this->context !== null) {
                $this->open($this->context, $row);
            }
        }, 'keys.members');
        $this->groupsTable->onInput(fn (string $data): bool => $this->asked($data, $this->groupsTable));
        $this->membersTable->onInput(fn (string $data): bool => $this->asked($data, $this->membersTable));
        $this->membersTable->onLeave(function (): void {
            $this->confirmation->forget();
        });
        $this->name->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->create($this->context, trim($event->getValue()));
            }
        });
        $this->member->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->add($this->context, trim($event->getValue()));
            }
        });
        $this->name->onLeave(function (): void {
            $this->leftAField();
        });
        $this->member->onLeave(function (): void {
            $this->leftAField();
        });
    }

    public function title(): string
    {
        return 'section.groups';
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
        $this->hideFields();
        $this->frame->reading->again($context, fn (AdministrationApi $api) => $this->read($api, $context));
        if ($this->shown !== null) {
            $this->readMembers($context, $this->shown);
        }
    }

    public function focusables(): array
    {
        $focusables = [$this->groupsTable];
        if ($this->shown !== null) {
            $focusables[] = $this->membersTable;
        }
        if ($this->asking !== null) {
            $focusables[] = $this->asking;
        }

        return $focusables;
    }

    private function titled(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.groups'));
        $this->list->title($context->translate('groups.list'));
        $this->name->setPrompt($context->translate('groups.name_prompt'));
        $this->groupsTable->setColumnLabels([
            $context->translate('group.name'),
            $context->translate('group.source'),
            $context->translate('group.members'),
            $context->translate('group.administers'),
            $context->translate('group.created_at'),
        ]);
        $this->membersTable->setColumnLabels([
            $context->translate('member.username'),
            $context->translate('member.source'),
            $context->translate('member.added_at'),
        ]);
        $this->groupsTable->setSectionKeys([['n', $context->translate('keys.new_group')], ['a', $context->translate('keys.add_member')]]);
        $this->membersTable->setSectionKeys([['x', $context->translate('keys.take_out')], ['a', $context->translate('keys.add_member')]]);
        if ($this->shown === null) {
            $this->members->title($context->translate('groups.members'));
            $this->notice->say($context->translate('groups.notice'));
        }
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $this->groups = array_map(self::group(...), $api->read('/groups')->documents('groups'));
        $this->listed($context);
        $this->frame->title(
            $context->translate('section.groups'),
            $context->translate('groups.count', ['count' => count($this->groups), 'time' => Moment::time($context->clock->now())]),
        );
    }

    /**
     * The keys of the tables: `n` a group to create, `a` a member to add to the group chosen or shown, `x` the member
     * chosen to take out, confirmed by `y` and forgotten by any other key.
     */
    private function asked(string $data, TableWidget $table): bool
    {
        $context = $this->context;
        if ($context === null) {
            return false;
        }
        if ($this->confirmation->answer($context, $data)) {
            return true;
        }
        if ($data === 'n') {
            $this->askFor($context, $table, $this->name);

            return true;
        }
        if ($data === 'a') {
            $group = $table === $this->membersTable ? $this->shown : $this->groups[$table->selected() ?? -1]['id'] ?? null;
            if ($group !== null) {
                $this->member->setPrompt($context->translate('groups.member_prompt', ['group' => $this->nameOf($group)]));
                $this->askFor($context, $table, $this->member);
                $this->joining = $group;
            }

            return true;
        }
        if ($data === 'x' && $table === $this->membersTable) {
            $this->askToTakeOut($context);

            return true;
        }

        return false;
    }

    private function askFor(SectionContext $context, TableWidget $table, FieldWidget $field): void
    {
        $this->hideFields();
        $field->setValue('');
        $field->removeStyleClass(Shell::HIDDEN);
        $this->asking = $field;
        $this->hand($context, $table, $field);
        $context->render();
    }

    /**
     * The members of the group of that row, read: each one delivered is an act of theirs that names the administrator.
     */
    private function open(SectionContext $context, int $row): void
    {
        $group = $this->groups[$row] ?? null;
        if ($group === null) {
            return;
        }
        $this->confirmation->forget();
        $this->shown = $group['id'];
        $this->shownMembers = [];
        $this->membersTable->setRows([], $context->translate('groups.no_member'));
        $this->members->title($context->translate('groups.members_of', ['group' => $group['name']]));
        $this->readMembers($context, $group['id']);
    }

    private function readMembers(SectionContext $context, string $group): void
    {
        $this->membersReading->again($context, function (AdministrationApi $api) use ($context, $group): void {
            $members = array_map(self::member(...), $api->read(sprintf('/groups/%s/members', rawurlencode($group)))->documents('members'));
            if ($this->shown !== $group) {
                return;
            }
            $this->shownMembers = $members;
            $this->membersTable->setRows(array_column($members, 'cells'), $context->translate('groups.no_member'));
            $this->notice->say('');
            if ($members !== []) {
                $this->hand($context, $this->groupsTable, $this->membersTable);
            }
        });
    }

    /**
     * A group created with the name typed, which takes its row from what the API answered.
     */
    private function create(SectionContext $context, string $name): void
    {
        if ($name === '') {
            return;
        }
        $this->frame->reading->again(
            $context,
            function (AdministrationApi $api) use ($context, $name): void {
                $created = $this->written($context, static fn (): Document => $api->post('/groups', ['name' => $name]));
                if ($created === null) {
                    return;
                }
                $this->groups[] = self::group($created);
                usort($this->groups, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
                $this->listed($context);
                $this->doneWith($context, $this->name);
                $this->notice->say($context->translate('groups.created', ['group' => TerminalText::line($name)->value]));
            },
            'api.writing',
        );
    }

    /**
     * The person of the username typed made a member of the group the field was asked for: the count of the group
     * follows, and the members shown take the row the API answered.
     */
    private function add(SectionContext $context, string $username): void
    {
        $group = $this->joining;
        if ($username === '' || $group === null) {
            return;
        }
        $this->membersReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $group, $username): void {
                $added = $this->written($context, static fn (): Document => $api->post(sprintf('/groups/%s/members', rawurlencode($group)), [
                    'username' => $username,
                ]));
                if ($added === null) {
                    return;
                }
                $this->counted($context, $group, 1);
                if ($this->shown === $group) {
                    $this->shownMembers[] = self::member($added);
                    $this->membersTable->setRows(array_column($this->shownMembers, 'cells'), $context->translate('groups.no_member'));
                }
                $this->doneWith($context, $this->member);
                $this->notice->say($context->translate('groups.member_added', [
                    'username' => TerminalText::line($username)->value,
                    'group' => $this->nameOf($group),
                ]));
            },
            'api.writing',
        );
    }

    /**
     * Taking a member out of a group that administers the tenant ends their role, so the question says it.
     */
    private function askToTakeOut(SectionContext $context): void
    {
        $group = $this->shown;
        $member = $this->shownMembers[$this->membersTable->selected() ?? -1] ?? null;
        if ($group === null || $member === null) {
            return;
        }
        $administers = in_array(true, array_map(static fn (array $listed): bool => $listed['id'] === $group && $listed['administers'], $this->groups), true);
        $this->confirmation->ask(
            $context,
            $context->translate($administers ? 'groups.take_out_administrator_asked' : 'groups.take_out_asked', [
                'username' => $member['cells'][0],
                'group' => $this->nameOf($group),
            ]),
            function (SectionContext $context) use ($group, $member): void {
                $this->takeOut($context, $group, $member);
            },
        );
    }

    /**
     * @param array{id: string, cells: list<string>} $member
     */
    private function takeOut(SectionContext $context, string $group, array $member): void
    {
        $this->membersReading->again(
            $context,
            function (AdministrationApi $api) use ($context, $group, $member): void {
                $path = sprintf('/groups/%s/members/%s', rawurlencode($group), rawurlencode($member['id']));
                if ($this->written($context, static fn (): Document => $api->delete($path)) === null) {
                    return;
                }
                $this->counted($context, $group, -1);
                $this->shownMembers = array_values(array_filter($this->shownMembers, static fn (array $shown): bool => $shown['id'] !== $member['id']));
                $this->membersTable->setRows(array_column($this->shownMembers, 'cells'), $context->translate('groups.no_member'));
                $this->notice->say($context->translate('groups.taken_out', ['username' => $member['cells'][0], 'group' => $this->nameOf($group)]));
            },
            'api.writing',
        );
    }

    /**
     * What a change answered, or null once a refusal of the request is said in the words of the instance: a request
     * malformed, naming nothing the tenant holds, or one the tenant refuses as it stands.
     *
     * @param Closure(): Document $write
     */
    private function written(SectionContext $context, Closure $write): ?Document
    {
        try {
            return $write();
        } catch (ProviderRefusalException $refusal) {
            if (! in_array($refusal->status, [400, 404, 409], true)) {
                throw $refusal;
            }
            $this->notice->say($context->translate('groups.refused', [
                'reason' => TerminalText::line($refusal->description ?? $refusal->error ?? '-')->value,
            ]), true);

            return null;
        }
    }

    private function counted(SectionContext $context, string $group, int $change): void
    {
        foreach ($this->groups as $index => $listed) {
            if ($listed['id'] === $group) {
                $this->groups[$index]['members'] = $listed['members'] + $change;
            }
        }
        $this->listed($context);
    }

    private function listed(SectionContext $context): void
    {
        $this->groupsTable->setRows(array_map(static fn (array $group): array => [
            $group['name'],
            $group['source'],
            (string) $group['members'],
            $context->yesOrNo($group['administers']),
            ApiMoment::short($group['created']),
        ], $this->groups), $context->translate('groups.none'));
    }

    private function nameOf(string $group): string
    {
        foreach ($this->groups as $listed) {
            if ($listed['id'] === $group) {
                return $listed['name'];
            }
        }

        return '-';
    }

    /**
     * The field leaves the screen, and the keys go back to the groups.
     */
    private function doneWith(SectionContext $context, FieldWidget $field): void
    {
        $this->hand($context, $field, $this->groupsTable);
        $this->hideFields();
    }

    /**
     * The focus left a field for another widget than the section moved it to: what was typed goes with it.
     */
    private function leftAField(): void
    {
        if (! $this->handing) {
            $this->hideFields();
        }
    }

    private function hideFields(): void
    {
        $this->name->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->member->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->asking = null;
        $this->joining = null;
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
     * @return array{id: string, name: string, source: string, members: int, administers: bool, created: ?string}
     */
    private static function group(Document $group): array
    {
        return [
            'id' => $group->string('id'),
            'name' => TerminalText::line($group->string('name'))->value,
            'source' => TerminalText::line($group->nullableString('source') ?? '-')->value,
            'members' => $group->nullableInt('members') ?? 0,
            'administers' => $group->isTrue('administers'),
            'created' => $group->nullableString('created_at'),
        ];
    }

    /**
     * @return array{id: string, cells: list<string>}
     */
    private static function member(Document $member): array
    {
        $person = $member->nested('person') ?? new Document();

        return [
            'id' => $person->string('id'),
            'cells' => [
                TerminalText::line($person->nullableString('username') ?? '-')->value,
                TerminalText::line($member->nullableString('source') ?? '-')->value,
                ApiMoment::short($member->nullableString('added_at')),
            ],
        ];
    }
}
