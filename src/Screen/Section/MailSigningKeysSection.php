<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use function array_map;
use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Key\PemFile;
use Beffroi\Tui\Key\PemFileException;
use Beffroi\Tui\Screen\ApiMoment;
use Beffroi\Tui\Screen\ChangeAnswer;
use Beffroi\Tui\Screen\Confirmation;
use Beffroi\Tui\Screen\Moment;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Screen\SectionFrame;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Shell;
use Beffroi\Tui\Screen\Widget\FieldWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\TableColumn;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Text\TerminalText;
use function count;
use function implode;
use function rawurlencode;
use function rtrim;
use SensitiveParameter;
use function sprintf;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use function trim;

/**
 * The keys the mail of the tenant is signed with (decision 0051), one row each: whether it signs, by a mark and a word,
 * never a colour alone (Charte Graphique, p. 22), a full square for the current key of its format and a hatched one for
 * a key it replaced, then its format, its fingerprint, the senders it signs for and its dates.
 *
 * `p` asks to draw an OpenPGP key, made once `y` confirms it. `s` asks for the file of an S/MIME private key, then for
 * the file of its certificate followed by its chain, both read on this machine and sent, the key never shown. `x` asks
 * to remove the key chosen, made once `y` confirms it. A key set replaces the current one of its format, so the list
 * is read again after each change, and each change is an act that names the administrator.
 */
final class MailSigningKeysSection implements SectionInterface
{
    private const string KEYS = '/tenant/mail-signing-keys';

    private const string PGP = 'pgp';

    private readonly SectionFrame $frame;

    private readonly MessageWidget $notice;

    private readonly FieldWidget $keyFile;

    private readonly FieldWidget $certificatesFile;

    private readonly TableWidget $table;

    private readonly Confirmation $confirmation;

    /**
     * @var list<array{format: string, fingerprint: string, current: bool, cells: list<string>}> in the order the API answered them
     */
    private array $keys = [];

    /**
     * The private key read from the file the first field named, held until the certificates are sent or the fields leave.
     */
    private ?PemFile $privateKey = null;

    /**
     * The field on the screen, null when none is.
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
        $this->keyFile = new FieldWidget('keys.next');
        $this->certificatesFile = new FieldWidget('keys.set');
        $this->table = new TableWidget([
            new TableColumn('state', 10, 9),
            new TableColumn('format', 7, 8),
            new TableColumn('fingerprint', 12, 7, middle: true),
            new TableColumn('senders', 12, 6),
            new TableColumn('set', 16, 5),
            new TableColumn('not after', 16, 4),
            new TableColumn('replaced', 16, 3),
        ]);
        $this->confirmation = new Confirmation($this->notice);
        $this->keyFile->addStyleClass(Shell::HIDDEN);
        $this->certificatesFile->addStyleClass(Shell::HIDDEN);
        $this->frame->add($this->notice);
        $this->frame->add($this->keyFile);
        $this->frame->add($this->certificatesFile);
        $this->frame->add($this->table);
        $this->table->onInput(fn (string $data): bool => $this->asked($data));
        $this->table->onLeave(function (): void {
            $this->confirmation->forget();
        });
        $this->keyFile->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->keyNamed($this->context, trim($event->getValue()));
            }
        });
        $this->certificatesFile->onSubmit(function (SubmitEvent $event): void {
            if ($this->context !== null) {
                $this->certificatesNamed($this->context, trim($event->getValue()));
            }
        });
        $this->keyFile->onLeave(function (): void {
            $this->leftAField();
        });
        $this->certificatesFile->onLeave(function (): void {
            $this->leftAField();
        });
    }

    public function title(): string
    {
        return 'section.mail_signing_keys';
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
    }

    public function focusables(): array
    {
        return $this->asking === null ? [$this->table] : [$this->table, $this->asking];
    }

    private function titled(SectionContext $context): void
    {
        $this->frame->title($context->translate('section.mail_signing_keys'));
        $this->keyFile->setPrompt($context->translate('mail_keys.key_file_prompt'));
        $this->certificatesFile->setPrompt($context->translate('mail_keys.certificates_file_prompt'));
        $this->table->setColumnLabels([
            $context->translate('mail_key.state'),
            $context->translate('mail_key.format'),
            $context->translate('mail_key.fingerprint'),
            $context->translate('mail_key.senders'),
            $context->translate('mail_key.set_at'),
            $context->translate('mail_key.not_after'),
            $context->translate('mail_key.replaced_at'),
        ]);
        $this->table->setSectionKeys([
            ['p', $context->translate('keys.draw_pgp')],
            ['s', $context->translate('keys.set_smime')],
            ['x', $context->translate('keys.remove')],
        ]);
    }

    private function read(AdministrationApi $api, SectionContext $context): void
    {
        $this->keys = array_map(static fn (Document $key): array => self::key($key, $context), $api->read(self::KEYS)->documents('keys'));
        $this->table->setRows(array_map(static fn (array $key): array => $key['cells'], $this->keys), $context->translate('mail_keys.none'));
        $this->frame->title(
            $context->translate('section.mail_signing_keys'),
            $context->translate('mail_keys.count', ['count' => count($this->keys), 'time' => Moment::time($context->clock->now())]),
        );
    }

    /**
     * The keys of the table: `p` an OpenPGP key to draw and `x` the key chosen to remove, each confirmed by `y` and
     * forgotten by any other key, `s` the files of an S/MIME key to set.
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
        if ($data === 'p') {
            $this->askToDraw($context);

            return true;
        }
        if ($data === 's') {
            $this->askFor($context, $this->table, $this->keyFile);

            return true;
        }
        if ($data === 'x') {
            $this->askToRemove($context);

            return true;
        }

        return false;
    }

    private function askToDraw(SectionContext $context): void
    {
        $current = $this->currentOf(self::PGP);
        $this->confirmation->ask(
            $context,
            $current === null
                ? $context->translate('mail_keys.draw_asked')
                : $context->translate('mail_keys.draw_replacing_asked', ['fingerprint' => $current['cells'][2]]),
            function (SectionContext $context): void {
                $this->draw($context);
            },
        );
    }

    /**
     * The OpenPGP key the provider draws, and where the tenant serves it to whoever verifies what it signs.
     */
    private function draw(SectionContext $context): void
    {
        $this->frame->reading->again(
            $context,
            function (AdministrationApi $api) use ($context): void {
                $drawn = ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->post(self::KEYS . '/' . self::PGP));
                if ($drawn === null) {
                    return;
                }
                $this->read($api, $context);
                $this->notice->say($context->translate('mail_keys.pgp_drawn', [
                    'fingerprint' => TerminalText::line($drawn->nullableString('fingerprint') ?? '-')->value,
                    'url' => TerminalText::line(rtrim($context->session->provider->issuer, '/') . '/mail/pgp-keys')->value,
                ]));
            },
            'api.writing',
        );
    }

    /**
     * The private key of the file named, held for the certificates the next field asks for; a file refused is said, and
     * the field stays.
     */
    private function keyNamed(SectionContext $context, string $path): void
    {
        if ($path === '') {
            return;
        }
        try {
            $key = PemFile::privateKey($path);
        } catch (PemFileException $refusal) {
            $this->refused($context, $refusal);

            return;
        }
        $this->notice->say('');
        $this->askFor($context, $this->keyFile, $this->certificatesFile);
        $this->privateKey = $key;
    }

    private function certificatesNamed(SectionContext $context, string $path): void
    {
        $key = $this->privateKey;
        if ($path === '' || $key === null) {
            return;
        }
        try {
            $certificates = PemFile::certificates($path);
        } catch (PemFileException $refusal) {
            $this->refused($context, $refusal);

            return;
        }
        $this->set($context, $key, $certificates);
    }

    private function set(SectionContext $context, #[SensitiveParameter] PemFile $key, PemFile $certificates): void
    {
        $this->frame->reading->again(
            $context,
            function (AdministrationApi $api) use ($context, $key, $certificates): void {
                $set = ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->put(self::KEYS . '/smime', [
                    'private_key' => $key->contents,
                    'certificates' => $certificates->contents,
                ]));
                if ($set === null) {
                    return;
                }
                $this->hand($context, $this->certificatesFile, $this->table);
                $this->hideFields();
                $this->read($api, $context);
                $this->notice->say($context->translate('mail_keys.smime_set', [
                    'fingerprint' => TerminalText::line($set->nullableString('fingerprint') ?? '-')->value,
                ]));
            },
            'api.writing',
        );
    }

    private function askToRemove(SectionContext $context): void
    {
        $key = $this->keys[$this->table->selected() ?? -1] ?? null;
        if ($key === null) {
            return;
        }
        $this->confirmation->ask(
            $context,
            $context->translate($key['current'] ? 'mail_keys.remove_current_asked' : 'mail_keys.remove_asked', [
                'format' => $key['cells'][1],
                'fingerprint' => $key['cells'][2],
            ]),
            function (SectionContext $context) use ($key): void {
                $this->remove($context, $key);
            },
        );
    }

    /**
     * @param array{format: string, fingerprint: string, current: bool, cells: list<string>} $key
     */
    private function remove(SectionContext $context, array $key): void
    {
        $this->frame->reading->again(
            $context,
            function (AdministrationApi $api) use ($context, $key): void {
                $path = sprintf('%s/%s/%s', self::KEYS, rawurlencode($key['format']), rawurlencode($key['fingerprint']));
                if (ChangeAnswer::of($context, $this->notice, static fn (): Document => $api->delete($path)) === null) {
                    return;
                }
                $this->read($api, $context);
                $this->notice->say($context->translate('mail_keys.removed', ['format' => $key['cells'][1], 'fingerprint' => $key['cells'][2]]));
            },
            'api.writing',
        );
    }

    /**
     * @return array{format: string, fingerprint: string, current: bool, cells: list<string>}|null
     */
    private function currentOf(string $format): ?array
    {
        foreach ($this->keys as $key) {
            if ($key['format'] === $format && $key['current']) {
                return $key;
            }
        }

        return null;
    }

    private function refused(SectionContext $context, PemFileException $refusal): void
    {
        $this->notice->say($context->translate('pem_file.' . $refusal->reason, ['file' => TerminalText::line($refusal->path)->value]), true);
        $context->render();
    }

    private function askFor(SectionContext $context, AbstractWidget $from, FieldWidget $field): void
    {
        $this->hideFields();
        $field->setValue('');
        $field->removeStyleClass(Shell::HIDDEN);
        $this->asking = $field;
        $this->hand($context, $from, $field);
        $context->render();
    }

    /**
     * The focus left a field for another widget than the section moved it to: what was typed, and the key read, go
     * with it.
     */
    private function leftAField(): void
    {
        if (! $this->handing) {
            $this->hideFields();
        }
    }

    private function hideFields(): void
    {
        $this->keyFile->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->certificatesFile->setValue('')->addStyleClass(Shell::HIDDEN);
        $this->asking = null;
        $this->privateKey = null;
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
     * @return array{format: string, fingerprint: string, current: bool, cells: list<string>}
     */
    private static function key(Document $key, SectionContext $context): array
    {
        $format = $key->string('format');
        $current = $key->isTrue('current');
        $senders = $key->strings('senders');

        return [
            'format' => $format,
            'fingerprint' => $key->string('fingerprint'),
            'current' => $current,
            'cells' => [
                $current ? '■ ' . $context->translate('mail_key.current') : '▨ ' . $context->translate('mail_key.replaced'),
                $context->named('mail_key.format', $format) ?? TerminalText::line($format)->value,
                TerminalText::line($key->string('fingerprint'))->value,
                TerminalText::line($senders === [] ? '-' : implode(', ', $senders))->value,
                ApiMoment::short($key->nullableString('set_at')),
                ApiMoment::short($key->nullableString('not_after')),
                ApiMoment::short($key->nullableString('replaced_at')),
            ],
        ];
    }
}
