<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

/**
 * What vouches for the `SHA256SUMS` of a release: its detached signature, by the key releases are signed with.
 */
interface SignatureVerifierInterface
{
    /**
     * @throws SelfUpdateException when the signature is not a good one by that key, or cannot be verified here
     */
    public function verify(string $signedFile, string $signatureFile): void;
}
