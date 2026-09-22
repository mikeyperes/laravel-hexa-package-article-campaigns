<?php

namespace hexa_package_article_campaigns\Data;

use InvalidArgumentException;
use JsonException;

/** Immutable, application-neutral configuration captured for one campaign run. */
final class CampaignConfigurationSnapshot
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $identity
     * @return array{version:int,identity:array<string,mixed>,configuration:array<string,mixed>,fingerprint:string}
     */
    public static function issue(array $configuration, array $identity = []): array
    {
        $payload = [
            'version' => self::VERSION,
            'identity' => self::canonicalizeArray($identity),
            'configuration' => self::canonicalizeArray($configuration),
        ];

        return $payload + ['fingerprint' => self::fingerprint($payload)];
    }

    public static function valid(array $snapshot): bool
    {
        if (($snapshot['version'] ?? null) !== self::VERSION
            || ! is_array($snapshot['identity'] ?? null)
            || ! is_array($snapshot['configuration'] ?? null)
            || ! is_string($snapshot['fingerprint'] ?? null)) {
            return false;
        }

        try {
            $expected = self::issue($snapshot['configuration'], $snapshot['identity']);
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }

        return hash_equals($expected['fingerprint'], $snapshot['fingerprint']);
    }

    /** @return array<string, mixed> */
    public static function configuration(array $snapshot): array
    {
        if (! self::valid($snapshot)) {
            throw new InvalidArgumentException('Campaign configuration snapshot is missing, unsupported, or has changed.');
        }

        return $snapshot['configuration'];
    }

    /** @param array<string, mixed> $payload */
    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            self::canonicalizeArray($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonicalizeArray(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::canonicalizeArray($value);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Campaign configuration snapshots may contain only arrays and scalar values.');
    }
}
