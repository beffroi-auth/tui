<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

/**
 * How this client proves who it is to an endpoint of the provider that authenticates clients: the pushed
 * authorization request, the token and the revocation endpoints. Decision 0038 names two ways, a key the
 * client signs with (`private_key_jwt`) and a certificate (RFC 8705 §2), and never a shared secret; each one
 * is a class of this interface.
 */
interface ClientAuthenticationInterface
{
    /**
     * The parameters one request carries to authenticate the client, drawn again for every request: what
     * proves the client once does not prove it twice.
     *
     * @return array<string, string>
     */
    public function parameters(): array;
}
