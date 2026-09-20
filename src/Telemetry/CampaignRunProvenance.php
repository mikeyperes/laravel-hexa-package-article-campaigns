<?php

namespace hexa_package_article_campaigns\Telemetry;

use InvalidArgumentException;

/** Builds a normalized, tamper-evident identity for one campaign run. */
final class CampaignRunProvenance
{
    public const ORIGINS = [
        'site',
        'codex',
        'claude',
        'scheduler',
        'api',
        'artisan',
    ];

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public function issue(array $facts, string $signingKey): array
    {
        $origin = strtolower(trim((string) ($facts['origin'] ?? '')));
        if (! in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException('Campaign run origin is invalid.');
        }
        if ($signingKey === '') {
            throw new InvalidArgumentException('Campaign run provenance requires a signing key.');
        }

        $record = array_filter([
            'version' => 1,
            'origin' => $origin,
            'site_native' => $origin === 'site',
            'actor_type' => $this->bounded($facts['actor_type'] ?? null, 40),
            'actor_id' => $this->bounded($facts['actor_id'] ?? null, 160),
            'actor_label' => $this->bounded($facts['actor_label'] ?? null, 160),
            'client' => $this->bounded($facts['client'] ?? null, 80),
            'session_fingerprint' => $this->fingerprintValue($facts['session_id'] ?? null),
            'request_fingerprint' => $this->fingerprintValue($facts['request_id'] ?? null),
            'configuration_fingerprint' => $this->sha256($facts['configuration_fingerprint'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $fingerprint = hash('sha256', $this->canonicalJson($record));

        return $record + [
            'fingerprint' => $fingerprint,
            'signature_algorithm' => 'hmac-sha256',
            'signature' => hash_hmac('sha256', $fingerprint, $signingKey),
        ];
    }

    /** @param array<string, mixed> $record */
    public function verify(array $record, string $signingKey): bool
    {
        $fingerprint = trim((string) ($record['fingerprint'] ?? ''));
        $signature = trim((string) ($record['signature'] ?? ''));
        if ($fingerprint === '' || $signature === '' || $signingKey === '') {
            return false;
        }

        $unsigned = array_diff_key($record, array_flip(['fingerprint', 'signature_algorithm', 'signature']));
        $expectedFingerprint = hash('sha256', $this->canonicalJson($unsigned));

        return hash_equals($expectedFingerprint, $fingerprint)
            && hash_equals(hash_hmac('sha256', $fingerprint, $signingKey), $signature);
    }

    private function bounded(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function fingerprintValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash('sha256', $value);
    }

    private function sha256(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
