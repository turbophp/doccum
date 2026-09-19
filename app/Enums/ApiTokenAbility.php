<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fixed set of ability scopes a personal access token may hold -- spec
 * §11's own list, verbatim: "Abilities are coarse scopes:
 * directories:read, directories:write, files:read, files:write,
 * files:delete, properties:read, properties:write, search."
 *
 * This is the ONLY place that list is written down. App\Models\
 * PersonalAccessToken::booted() reads values() to refuse any other string on
 * save, and App\Livewire\Settings\ApiTokens reads cases() to render the
 * checkbox list -- one enum, two callers, no risk of the two ever drifting
 * apart the way a copy-pasted array in each place would.
 *
 * item/api-sanctum-tokens (issue #22). A token's abilities are always a
 * NARROWING of what its owner may do, never a widening -- see spec §11's
 * "three gates" and this enum's own docblock is not the authorisation layer:
 * it only bounds which strings a token may carry at all. Spatie permissions
 * and directory_access still decide what the request behind the token may
 * actually reach.
 */
enum ApiTokenAbility: string
{
    case DirectoriesRead = 'directories:read';
    case DirectoriesWrite = 'directories:write';
    case FilesRead = 'files:read';
    case FilesWrite = 'files:write';
    case FilesDelete = 'files:delete';
    case PropertiesRead = 'properties:read';
    case PropertiesWrite = 'properties:write';
    case Search = 'search';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }

    /**
     * A human-readable label for the token page's checkbox list.
     */
    public function label(): string
    {
        return match ($this) {
            self::DirectoriesRead => __('Read directories'),
            self::DirectoriesWrite => __('Create, rename and move directories'),
            self::FilesRead => __('Read files'),
            self::FilesWrite => __('Upload, rename and move files'),
            self::FilesDelete => __('Delete (trash) files'),
            self::PropertiesRead => __('Read properties'),
            self::PropertiesWrite => __('Set properties'),
            self::Search => __('Search'),
        };
    }
}
