<?php

declare(strict_types=1);

namespace Beffroi\Tui\Console;

use Beffroi\Tui\Update\RunningProgram;
use Beffroi\Tui\Update\SelfUpdate;
use Beffroi\Tui\Update\SelfUpdateException;
use function sprintf;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The PHAR or the executable that runs replaced by the same file of the newest release of its line, once the
 * signature of the release vouches for it. It has no option: nothing of the verification can be skipped
 * (decision 0006), and another line is moved to by hand, a minor of a `0.x` version being allowed to break.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Replace this program by the newest release of its line, once the signature of that release vouches for it.',
)]
final readonly class SelfUpdateCommand
{
    public const string NAME = 'self-update';

    public function __construct(
        private SelfUpdate $selfUpdate,
        private RunningProgram $program,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $release = $this->selfUpdate->update($this->program);
        } catch (SelfUpdateException $exception) {
            $io->error(ConsoleText::inBlock($exception->getMessage()));

            return Command::FAILURE;
        }
        if ($release === null) {
            $io->success(sprintf('This program, %s, is the newest release of its line.', $this->program->version->tag ?? ''));

            return Command::SUCCESS;
        }
        $io->success(sprintf('This program is now the release %s, which its signature vouches for.', $release->tag));

        return Command::SUCCESS;
    }
}
