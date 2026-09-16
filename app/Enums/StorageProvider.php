<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A provider preset: the endpoint template, addressing style, and region a
 * given object storage provider expects.
 *
 * Providers differ in three ways that matter and nothing else: the endpoint
 * template, whether the bucket goes in the host or the path, and what region
 * string they expect. Encoding that as a preset is the difference between
 * "paste four fields" and "read three pages of provider docs". See plan Task 3.
 *
 * A preset is a default, not a cage: RuntimeConfigServiceProvider still lets
 * an explicit storage.endpoint setting win over whatever this derives.
 */
enum StorageProvider: string
{
    case Embedded = 'embedded';
    case S3 = 's3';
    case R2 = 'r2';
    case Spaces = 'spaces';
    case Wasabi = 'wasabi';
    case BackblazeB2 = 'backblaze_b2';
    case Custom = 'custom';
    case AzureBlob = 'azure_blob';

    /**
     * Derives the endpoint this provider expects, or null to leave it to the
     * operator -- plain S3 and Custom have no fixed endpoint, Embedded is
     * resolved from doccum.storage.endpoint rather than this preset, and
     * Azure Blob is reached through a connection string, not an endpoint URL.
     */
    public function endpointFor(?string $account, ?string $region): ?string
    {
        return match ($this) {
            self::R2 => $account !== null && $account !== '' ? "https://{$account}.r2.cloudflarestorage.com" : null,
            self::Spaces => $region !== null && $region !== '' ? "https://{$region}.digitaloceanspaces.com" : null,
            self::Wasabi => $region !== null && $region !== '' ? "https://s3.{$region}.wasabisys.com" : null,
            self::BackblazeB2 => $region !== null && $region !== '' ? "https://s3.{$region}.backblazeb2.com" : null,
            default => null,
        };
    }

    /**
     * Whether this provider needs path-style bucket addressing (the bucket
     * name in the path) rather than virtual-hosted addressing (the bucket
     * name in the host).
     *
     * Embedded MinIO requires path style; every hosted provider here is
     * reached through virtual-hosted addressing.
     */
    public function usesPathStyle(): bool
    {
        return $this === self::Embedded;
    }

    /**
     * A region this provider requires, or null to leave the choice to the
     * operator.
     */
    public function defaultRegion(): ?string
    {
        return match ($this) {
            // R2 rejects any region other than "auto".
            self::R2 => 'auto',
            default => null,
        };
    }

    /**
     * Whether this provider is reached through the S3 API at all. Azure Blob
     * is the one preset that is not -- it needs its own driver (Task 4).
     */
    public function isS3Compatible(): bool
    {
        return $this !== self::AzureBlob;
    }
}
