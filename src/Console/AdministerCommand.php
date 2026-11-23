<?php

declare(strict_types=1);

namespace Beffroi\Tui\Console;

use Beffroi\Tui\HttpClients;
use Beffroi\Tui\Profile\ProfileException;
use Beffroi\Tui\Profile\ProfileStore;
use Beffroi\Tui\Provider\ProviderMetadataReader;
use Beffroi\Tui\Screen\Administration;
use Beffroi\Tui\Screen\ClosingPage;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\Screen\Shell;
use Beffroi\Tui\Screen\ThisProgram;
use Beffroi\Tui\SignIn\BrowserInterface;
use Beffroi\Tui\SignIn\SignIn;
use Psr\Clock\ClockInterface;
use const STDIN;
use const STDOUT;
use function stream_isatty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Tui;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The administration of the instance a profile names, in this terminal: the administrator signs in through
 * the provider in the browser of this machine, the screen shows the sections, and leaving it hands the tokens
 * of the run back to the provider. Nothing of the session is written anywhere.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Sign in at the instance of a profile and open its administration.',
)]
final readonly class AdministerCommand
{
    public const string NAME = 'administer';

    /**
     * @param list<SectionInterface> $sections
     * @param bool $colours whether the screen may draw in colours
     */
    public function __construct(
        private ProfileStore $profiles,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private BrowserInterface $browser,
        private string $locale,
        private array $sections,
        private bool $colours,
        private ThisProgram $program,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The profile of the instance, as "register" kept it.')]
        string $profile = 'default',
    ): int {
        try {
            $loaded = $this->profiles->load($profile);
        } catch (ProfileException $exception) {
            $io->error(ConsoleText::inBlock($exception->getMessage()));

            return Command::FAILURE;
        }
        if (! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            $io->error('The administration needs a terminal to draw itself in.');

            return Command::FAILURE;
        }

        $http = HttpClients::create($loaded->certificateAuthority);
        $signIn = new SignIn(new ProviderMetadataReader($http), $http, $this->clock, $this->browser, ClosingPage::html($this->translator, $this->locale));
        $session = new Administration($signIn, new Shell($this->translator, $this->clock, $this->sections, $this->colours, $this->program), $this->locale)->run(
            new Tui(Shell::styleSheet($this->colours)),
            $loaded,
            $profile,
        );
        if ($session === null) {
            return Command::SUCCESS;
        }
        foreach ($session->end() as $refusal) {
            $io->note(ConsoleText::inBlock($refusal));
        }
        $io->writeln('The session is over. Its tokens were bound to a key that existed in this run alone.');

        return Command::SUCCESS;
    }
}
