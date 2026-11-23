<?php

declare(strict_types=1);

namespace Beffroi\Tui;

use Beffroi\Tui\Console\AdministerCommand;
use Beffroi\Tui\Console\RegisterCommand;
use Beffroi\Tui\Console\SelfUpdateCommand;
use Beffroi\Tui\Profile\ProfileStore;
use Beffroi\Tui\Screen\Section\ClientsSection;
use Beffroi\Tui\Screen\Section\GroupsSection;
use Beffroi\Tui\Screen\Section\InstanceSection;
use Beffroi\Tui\Screen\Section\KeysSection;
use Beffroi\Tui\Screen\Section\MailSigningKeysSection;
use Beffroi\Tui\Screen\Section\MonitoringSection;
use Beffroi\Tui\Screen\Section\SessionSection;
use Beffroi\Tui\Screen\Section\TenantSection;
use Beffroi\Tui\Screen\Section\UsersSection;
use Beffroi\Tui\Screen\SectionInterface;
use Beffroi\Tui\SignIn\SystemBrowser;
use Beffroi\Tui\Text\Catalogue;
use Beffroi\Tui\Text\TerminalColours;
use Beffroi\Tui\Text\TerminalLocale;
use Beffroi\Tui\Update\AssetDownload;
use Beffroi\Tui\Update\GitHubReleases;
use Beffroi\Tui\Update\GpgvSignature;
use Beffroi\Tui\Update\ProgramReplacement;
use Beffroi\Tui\Update\RunningProgram;
use Beffroi\Tui\Update\SelfUpdate;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Application;

/**
 * The program, put together from the environment it runs in: where its profiles are kept, the language of the
 * terminal, the browser of the system, and the sections of the screen in the order they are listed. There is no
 * container: a handful of objects built once.
 */
final readonly class Wiring
{
    /**
     * @param array<array-key, mixed> $environment
     */
    public static function application(array $environment): Application
    {
        $profiles = ProfileStore::inConfigurationHome($environment);
        $locale = TerminalLocale::of($environment);
        $program = RunningProgram::current();
        $application = new Application('beffroi-tui', $program->version->tag ?? 'UNKNOWN');
        $application->addCommand(new RegisterCommand($profiles, $environment));
        $application->addCommand(new AdministerCommand(
            $profiles,
            Catalogue::translator($locale),
            new NativeClock(),
            SystemBrowser::of($environment),
            $locale,
            self::sections(),
            TerminalColours::allowed($environment),
        ));
        $application->addCommand(new SelfUpdateCommand(self::selfUpdate($environment), $program));
        $application->setDefaultCommand(AdministerCommand::NAME);

        return $application;
    }

    /**
     * The update of the program from the releases of its repository: their list read with the client of every
     * other request, their files downloaded with the one of large files, under the key the releases are signed with.
     *
     * @param array<array-key, mixed> $environment
     */
    public static function selfUpdate(array $environment): SelfUpdate
    {
        return new SelfUpdate(
            new GitHubReleases(HttpClients::create(null)),
            new AssetDownload(HttpClients::releaseAssets()),
            GpgvSignature::ofTheReleases($environment),
            new ProgramReplacement(),
        );
    }

    /**
     * What the navigation lists: the instance and the session this program holds, then what the administration API of
     * the instance serves (decision 0038), the tenant, its clients, its signing keys, the keys its mail is signed with,
     * its users with their sessions and their journal, its groups with their members, and what the instance knows of
     * itself. Each call builds them anew, for one screen.
     *
     * @return list<SectionInterface>
     */
    public static function sections(): array
    {
        return [
            new InstanceSection(),
            new SessionSection(),
            new TenantSection(),
            new ClientsSection(),
            new KeysSection(),
            new MailSigningKeysSection(),
            new UsersSection(),
            new GroupsSection(),
            new MonitoringSection(),
        ];
    }
}
