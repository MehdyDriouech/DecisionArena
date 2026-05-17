<?php
namespace Domain\Providers;

/**
 * Détection BYOK pour la requête HTTP courante (après loadFromRequestBody sur provider_runtime).
 */
final class RuntimeBillingContext {
  private static bool $geminiByok = false;
  private static bool $anyByok = false;

  public static function clear(): void {
    self::$geminiByok = false;
    self::$anyByok = false;
  }

  public static function loadFromRequestBody(array $body): void {
    self::clear();
    $raw = $body['provider_runtime'] ?? null;
    if (!is_array($raw)) {
      return;
    }
    foreach ($raw as $idRaw => $cfg) {
      if (!is_array($cfg)) {
        continue;
      }
      $enabled = $cfg['enabled'] ?? true;
      if ($enabled === false || $enabled === 0 || $enabled === '0') {
        continue;
      }
      $key = trim((string)($cfg['api_key'] ?? ''));
      if ($key === '') {
        continue;
      }
      self::$anyByok = true;
      if (strtolower(trim((string)$idRaw)) === ProviderSecretResolver::GEMINI_PROVIDER_ID) {
        self::$geminiByok = true;
      }
    }
  }

  public static function usesGeminiByok(): bool {
    return self::$geminiByok;
  }

  public static function usesAnyByok(): bool {
    return self::$anyByok;
  }
}
