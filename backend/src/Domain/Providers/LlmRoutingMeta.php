<?php
namespace Domain\Providers;

/**
 * Enrichit meta_json message avec llm_routing (sans secrets).
 */
final class LlmRoutingMeta {
  public static function mergeIntoMetaJson(?string $promptMetaJson, array $routed): ?string {
    $meta = [];
    if ($promptMetaJson !== null && $promptMetaJson !== '') {
      $decoded = json_decode($promptMetaJson, true);
      if (is_array($decoded)) {
        $meta = $decoded;
      }
    }
    $existing = is_array($meta['llm_routing'] ?? null) ? $meta['llm_routing'] : [];
    $meta['llm_routing'] = array_merge($existing, self::routingSlice($routed));
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
    return $json === false ? $promptMetaJson : $json;
  }

  /** @return array<string,mixed> */
  public static function routingSlice(array $routed): array {
    $byok = (bool)($routed['byok_used'] ?? false);
    return [
      'billing_source' => (string)($routed['billing_source'] ?? ($byok ? 'byok' : 'server')),
      'byok_used' => $byok,
      'resolved_provider_id' => $routed['resolved_provider_id'] ?? $routed['provider_id'] ?? null,
      'resolved_model' => $routed['resolved_model'] ?? $routed['model'] ?? null,
      'routing_source' => $routed['routing_source'] ?? null,
    ];
  }
}
