<?php

declare(strict_types=1);

namespace Beffroi\Tui\Console;

use Beffroi\Tui\HttpClients;
use Beffroi\Tui\Key\SigningKey;
use Beffroi\Tui\Profile\AdministrationApi;
use Beffroi\Tui\Profile\CertificateAuthorityFile;
use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Profile\ProfileException;
use Beffroi\Tui\Profile\ProfileStore;
use Beffroi\Tui\Protocol\ClientRegistrationRequest;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\Registration;
use Beffroi\Tui\Provider\ProviderMetadataReader;
use Beffroi\Tui\Provider\UnsuitableProviderException;
use Beffroi\Tui\SignIn\CannotListenException;
use Beffroi\Tui\SignIn\LoopbackListener;
use function gethostname;
use function is_string;
use function sprintf;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This program becomes a client of a tenant: a key is drawn on this machine, the client registers itself with
 * the public half (RFC 7591), and what it was registered as is kept in a profile with the private half, which
 * never leaves this machine. One registration per installation, so that a key is never shared between two
 * machines and one can be revoked without the others (RFC 8252 §8.4).
 *
 * The initial access token the tenant issued for this registration (`beffroi:client:token:issue`, decision
 * 0009) is read from the environment or typed without being echoed, and never from the command line, where the
 * history of the shell and the list of processes would keep it.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Register this program as a client of a tenant, under a key drawn on this machine.',
)]
final readonly class RegisterCommand
{
    public const string NAME = 'register';

    public const string INITIAL_ACCESS_TOKEN = 'BEFFROI_TUI_INITIAL_ACCESS_TOKEN';

    private const string CALLBACK = 'http://127.0.0.1:%d/callback';

    /**
     * @param array<array-key, mixed> $environment
     */
    public function __construct(
        private ProfileStore $profiles,
        private array $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The issuer of the tenant, exactly as its metadata name it.')]
        string $issuer,
        #[Argument(description: 'The identifier of the administration API of the tenant, as its deployment names it (BEFFROI_TENANT_ADMINISTRATION_RESOURCE).')]
        string $administration,
        #[Option(description: 'The name this registration is kept under on this machine.')]
        string $profile = 'default',
        #[Option(description: 'The port of the loopback interface the browser comes back to; 0 takes one the system has free now, kept from then on.')]
        int $port = 0,
        #[Option(description: 'A file of certificate authorities the instance is trusted under, in place of those of the system.')]
        ?string $cafile = null,
        #[Option(description: 'The name the tenant shows this client under.')]
        ?string $clientName = null,
    ): int {
        try {
            if ($this->profiles->exists($profile)) {
                throw new ProfileException(sprintf('The profile "%s" exists already (%s).', $profile, $this->profiles->path($profile)));
            }
            $api = AdministrationApi::identifier($administration);
            $certificateAuthority = $cafile === null ? null : CertificateAuthorityFile::at($cafile);
            $http = HttpClients::create($certificateAuthority);
            $provider = new ProviderMetadataReader($http)->read($issuer);
            if ($provider->registrationEndpoint === null) {
                $io->error('The tenant registers no client by itself: its deployment chooses a registration mode first (BEFFROI_REGISTRATION_MODE, decision 0009).');

                return Command::FAILURE;
            }
            $key = SigningKey::generate();
            $host = gethostname();
            $request = new ClientRegistrationRequest(
                $clientName ?? sprintf('Beffroi TUI on %s', $host === false ? 'this machine' : $host),
                sprintf(self::CALLBACK, self::portToRegister($port)),
                $key,
                $provider->idTokenSigningAlgorithms[0],
            );
            $registered = new Registration($http)->register($provider->registrationEndpoint, $request, $this->initialAccessToken($io));
        } catch (ProfileException|UnsuitableProviderException|ProtocolException|CannotListenException $exception) {
            $io->error(ConsoleText::inBlock($exception->getMessage()));

            return Command::FAILURE;
        }

        try {
            $path = $this->profiles->save($profile, new Profile(
                $issuer,
                $registered->clientId,
                $request->redirectUri,
                $key,
                $request->idTokenSigningAlgorithm,
                $api,
                $registered->registrationClientUri,
                $registered->registrationAccessToken,
                $certificateAuthority,
            ));
        } catch (ProfileException $exception) {
            $io->error(ConsoleText::inBlock(sprintf(
                '%s The client "%s" is registered and nothing holds its key: the tenant deletes it (beffroi:client:delete).',
                $exception->getMessage(),
                $registered->clientId,
            )));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Client' => ConsoleText::of($registered->clientId)],
            ['Redirect URI' => $request->redirectUri],
            ['Administration API' => ConsoleText::of($api)],
            ['Key' => sprintf('%s (%s)', $key->thumbprint(), SigningKey::ALGORITHM)],
            ['Profile' => ConsoleText::of($path)],
        );
        $io->success('This program is a client of the tenant. Its private key is in the profile, readable by you alone, and nowhere else.');

        return Command::SUCCESS;
    }

    /**
     * The port the client registers and keeps: the one given, once it is known that it can be listened on, or one
     * the system has free now.
     *
     * @throws CannotListenException when the port is not one, or something else holds it
     */
    private static function portToRegister(int $port): int
    {
        $listener = LoopbackListener::on(sprintf(self::CALLBACK, $port), '');
        $bound = $listener->port();
        $listener->close();

        return $bound;
    }

    private function initialAccessToken(SymfonyStyle $io): ?string
    {
        $token = $this->environment[self::INITIAL_ACCESS_TOKEN] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $typed = $io->askHidden(
            'The initial access token the tenant issued for this registration (empty when the tenant registers anyone)',
            static fn (mixed $value): mixed => $value,
        );

        return is_string($typed) && $typed !== '' ? $typed : null;
    }
}
