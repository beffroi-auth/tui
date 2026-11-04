<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

/**
 * What sends the administrator to the authorization endpoint: the browser of this machine, where the passkey
 * that signs them in is (RFC 8252 §6, which keeps the sign-in out of the program that asks for it).
 */
interface BrowserInterface
{
    /**
     * Opens the URL, or does nothing it can tell: the screen shows the URL either way, for a browser that is
     * opened by hand.
     */
    public function open(string $url): void;
}
