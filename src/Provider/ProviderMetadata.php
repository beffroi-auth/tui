<?php

declare(strict_types=1);

namespace Beffroi\Tui\Provider;

/**
 * What this program uses of the metadata of a provider, once `ProviderMetadataReader` has held the document
 * to everything it needs: every endpoint is the one the document named (FAPI 2.0 Security Profile §5.3.3.1,
 * item 9), over HTTPS.
 */
final readonly class ProviderMetadata
{
    /**
     * @param list<string> $idTokenSigningAlgorithms the algorithms the provider signs ID tokens with that this
     *                                               program verifies, in the order it prefers them
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $pushedAuthorizationRequestEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $idTokenSigningAlgorithms,
        public ?string $revocationEndpoint = null,
        public ?string $registrationEndpoint = null,
    ) {
    }
}
