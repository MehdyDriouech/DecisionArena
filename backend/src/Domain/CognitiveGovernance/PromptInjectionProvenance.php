<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

/**
 * Provenance des segments injectés dans les prompts (hash du contenu UTF-8 réellement assemblé).
 *
 * Règle : le hash couvre le texte **après** dédup + budget/troncature — ce qui est concaténé au message
 * utilisateur / envoyé au routeur — pas le brouillon amont.
 */
final class PromptInjectionProvenance
{
    /**
     * SHA-256 déterministe du segment injecté (normalisation \r\n → \n via DeterministicHash).
     */
    public static function computeInjectedContentHash(string $injectedUtf8Content): string
    {
        return DeterministicHash::sha256($injectedUtf8Content);
    }
}
