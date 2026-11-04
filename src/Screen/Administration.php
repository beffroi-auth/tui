<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use function Amp\async;
use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Provider\UnsuitableProviderException;
use Beffroi\Tui\SignIn\AdministratorSession;
use Beffroi\Tui\SignIn\CannotListenException;
use Beffroi\Tui\SignIn\SignIn;
use function register_shutdown_function;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;

/**
 * The administration of one instance in the terminal: the home page comes up, and the sign-in starts when the
 * administrator asks for it, then runs beside the screen on the event loop of symfony/tui (every request it makes and
 * the wait for the browser suspend it and hold up nothing the screen does). The sections are shown once the
 * administrator is signed in; a sign-in that failed leaves the home page up, and can be started again from it.
 *
 * Quitting gives up a sign-in that still waits for the browser. One that is past it, exchanging its code, is let
 * finish for a few seconds, so that the tokens it obtains are ended with the session
 * rather than left valid at the provider; the session is handed back to whoever ran the screen, to be ended. The
 * terminal is given back as it was however the process ends, an error that stops PHP included.
 */
final readonly class Administration
{
    private const float FINISHING = 10.0;

    public function __construct(
        private SignIn $signIn,
        private Shell $shell,
        private string $locale,
    ) {
    }

    /**
     * @param string $name the name the profile was kept under
     */
    public function run(Tui $tui, Profile $profile, string $name): ?AdministratorSession
    {
        $session = null;
        $signingIn = null;
        $cancellation = new DeferredCancellation();
        $keys = new Keybindings([
            'quit' => ['ctrl+c', 'escape'],
            // a letter, which a field the administrator types in takes for itself
            'quit_unless_typing' => ['q'],
            'sign_in' => ['enter'],
        ]);
        $shell = $this->shell;
        $signIn = function () use ($tui, $profile, $cancellation, &$session): void {
            try {
                $this->shell->reading($profile);
                $tui->requestRender();
                $pending = $this->signIn->start($profile, $this->locale);
                $this->shell->signingIn($profile, $pending->authorizationUrl);
                $tui->requestRender();
                $session = $pending->complete($cancellation->getCancellation());
                if ($tui->isRunning()) {
                    $this->shell->signedIn($session, $tui);
                }
            } catch (CancelledException) {
                return;
            } catch (ProtocolException|UnsuitableProviderException|CannotListenException $exception) {
                $this->shell->failed($exception);
            }
            $tui->requestRender();
        };
        $tui->addListener(static function (InputEvent $event) use ($tui, $keys, $shell, $cancellation, $signIn, &$signingIn, &$session): void {
            if ($keys->matches($event->getData(), 'quit') || $keys->matches($event->getData(), 'quit_unless_typing') && ! $shell->typing($tui)) {
                $event->stopPropagation();
                $cancellation->cancel();
                $tui->stop();

                return;
            }
            if ($session === null && $keys->matches($event->getData(), 'sign_in') && ! self::running($signingIn)) {
                $event->stopPropagation();
                $signingIn = async($signIn);
            }
        });
        register_shutdown_function(static function () use ($tui): void {
            $tui->stop();
        });
        $this->shell->attachTo($tui);
        $this->shell->home($profile, $name);
        // once the screen has started, which is when the loop runs what is deferred
        EventLoop::defer(function () use ($tui): void {
            $this->shell->askTheBackground($tui);
        });
        $tui->run();
        $cancellation->cancel();

        try {
            $signingIn?->await(new TimeoutCancellation(self::FINISHING));
        } catch (CancelledException) {
            // a sign-in still running after that is abandoned with the process
        }

        return $session;
    }

    /**
     * @param ?Future<void> $signingIn
     */
    private static function running(?Future $signingIn): bool
    {
        return $signingIn !== null && ! $signingIn->isComplete();
    }
}
