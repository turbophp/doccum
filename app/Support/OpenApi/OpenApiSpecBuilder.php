<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

/**
 * item/openapi-reference (issue #25), spec §12: turns the live /api/v1 route
 * table into an OpenAPI 3.1 document.
 *
 * Deliberately framework-free -- no Illuminate\* import anywhere in this
 * file -- for one reason: it is the ONE piece of the generator that must run
 * byte-for-byte identically inside App\Console\Commands\GenerateOpenApi
 * (which feeds it real Illuminate\Routing\Route objects, reduced to plain
 * arrays first) and inside the scratchpad script this item's report
 * describes, which has no vendor/ to load Illuminate from at all. A class
 * that only ever touches arrays and strings can be `require`d directly in
 * both places with no autoloader and no risk of the two drifting apart.
 *
 * Input shape, one entry per registered route matching the `api.v1.` name
 * prefix (see GenerateOpenApi::routeDescriptors()):
 *
 *   ['name' => 'api.v1.files.show', 'uri' => 'api/v1/files/{file}',
 *    'methods' => ['GET', 'HEAD'], 'abilities' => ['files:read']]
 *
 * Everything this class cannot honestly derive from that shape alone --
 * request bodies, response schemas -- comes from self::operations(), a fixed
 * table hand-built from reading every Api\V1 controller and Resource once
 * (this item's report names each one). A route whose name is not in that
 * table still gets a full, honest operation: HTTP-verb-appropriate generic
 * request/response shapes with no invented field, never a crash and never a
 * silently-skipped path -- CLAUDE.md: "a schema that claims a field the API
 * does not return is worse than an absent schema."
 */
final class OpenApiSpecBuilder
{
    /**
     * Path parameters this API actually uses, and how to type each one.
     * Every placeholder in a route's URI is `{id}`-shaped and integer
     * EXCEPT trash/{type}/{id}/restore's `{type}`, which
     * routes/api.php constrains with `->whereIn('type', ['file',
     * 'directory'])` -- the one enum path parameter in the whole surface.
     * Named explicitly here rather than guessed at, since inventing an enum
     * for a parameter that is not actually one would be exactly the
     * fabrication CLAUDE.md warns against.
     *
     * @var array<string, array{type: string, enum?: list<string>}>
     */
    private const PATH_PARAM_TYPES = [
        'type' => ['type' => 'string', 'enum' => ['file', 'directory']],
    ];

    /**
     * Per-route-name enrichment: summary, query/body parameters, and
     * responses. Built by hand from every Api\V1 controller's own
     * `$request->validate([...])` array and every Api\V1 Resource's
     * `toArray()` -- see this item's report for the file-by-file trace.
     *
     * Method rather than a class constant: several entries call self::body(),
     * self::cursorPage() etc. to build their shape, and a class constant's
     * initializer must be a compile-time-constant expression -- it cannot
     * call a method, even a static one on the same class.
     *
     * @return array<string, array{
     *   summary: string,
     *   query?: list<array{name: string, required: bool, schema: array<string, mixed>}>,
     *   requestBody?: array<string, mixed>,
     *   responses: array<array-key, array<string, mixed>>,
     * }>
     *
     * `responses` is keyed array-key, not string, and that is PHP rather
     * than sloppiness: a numeric-string array key is cast to int on
     * assignment, so `'200' => [...]` below is stored under int 200 and
     * the map really is array<int, ...> by the time phpstan reads it --
     * writing the quotes does not keep them.
     *
     * OpenAPI requires those keys to be strings, and they are, but nothing
     * in this class converts them: json_encode() emits every array key as
     * a JSON string because a JSON object has no other kind of key. The
     * committed document reads "200", "401", "403", "429" -- checked
     * against docs/api/openapi.json rather than assumed.
     */
    private static function operations(): array
    {
        return [
            'api.v1.directories.index' => [
                'summary' => 'List directories, optionally by parent',
                'query' => [
                    ['name' => 'parent_id', 'required' => false, 'schema' => ['type' => ['integer', 'null']]],
                ],
                'responses' => [
                    '200' => ['description' => 'A page of directories.', 'content' => self::cursorPage('Directory')],
                ],
            ],
            'api.v1.directories.show' => [
                'summary' => 'Show one directory',
                'responses' => [
                    '200' => ['description' => 'The directory.', 'content' => self::jsonRef('Directory')],
                ],
            ],
            'api.v1.directories.files' => [
                'summary' => "List a directory's immediate files",
                'responses' => [
                    '200' => ['description' => 'A page of files.', 'content' => self::cursorPage('File')],
                ],
            ],
            'api.v1.directories.store' => [
                'summary' => 'Create a directory',
                'requestBody' => self::body([
                    'parent_id' => ['type' => ['integer', 'null']],
                    'name' => ['type' => 'string', 'maxLength' => 255],
                ], ['name']),
                'responses' => [
                    '201' => ['description' => 'The created directory.', 'content' => self::jsonRef('Directory')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.directories.update' => [
                'summary' => 'Rename and/or move a directory',
                'requestBody' => self::body([
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'parent_id' => ['type' => ['integer', 'null']],
                ], []),
                'responses' => [
                    '200' => ['description' => 'The updated directory.', 'content' => self::jsonRef('Directory')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.directories.destroy' => [
                'summary' => 'Trash a directory',
                'responses' => [
                    '200' => ['description' => 'The trashed directory.', 'content' => self::jsonRef('Directory')],
                ],
            ],
            'api.v1.files.update' => [
                'summary' => 'Rename and/or move a file',
                'requestBody' => self::body([
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'directory_id' => ['type' => 'integer'],
                ], []),
                'responses' => [
                    '200' => ['description' => 'The updated file.', 'content' => self::jsonRef('File')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.files.upload-url' => [
                'summary' => 'Mint a presigned upload URL for a new file (or a new version by name)',
                'requestBody' => self::body([
                    'directory_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'mime' => ['type' => 'string', 'maxLength' => 255],
                    'size' => ['type' => 'integer', 'minimum' => 0],
                ], ['directory_id', 'name', 'mime', 'size']),
                'responses' => [
                    '201' => ['description' => 'The presigned upload.', 'content' => self::jsonSchema(self::ref('#/components/schemas/UploadUrl'))],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.files.store' => [
                'summary' => 'Commit a staged upload as a new file or a new version',
                'requestBody' => self::body([
                    'directory_id' => ['type' => 'integer', 'description' => 'Exactly one of directory_id or file_id -- never both, never neither.'],
                    'file_id' => ['type' => 'integer', 'description' => 'Exactly one of directory_id or file_id -- never both, never neither.'],
                    'upload_id' => ['type' => 'string'],
                    'checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'SHA-256 of the uploaded object, case-insensitive hex.'],
                ], ['upload_id', 'checksum']),
                'responses' => [
                    '201' => ['description' => 'The created or appended-to file.', 'content' => self::jsonRef('File')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.files.versions.upload-url' => [
                'summary' => 'Mint a presigned upload URL for a new version of a known file',
                'requestBody' => self::body([
                    'mime' => ['type' => 'string', 'maxLength' => 255],
                    'size' => ['type' => 'integer', 'minimum' => 0],
                ], ['mime', 'size']),
                'responses' => [
                    '201' => ['description' => 'The presigned upload.', 'content' => self::jsonSchema(self::ref('#/components/schemas/UploadUrl'))],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.files.show' => [
                'summary' => 'Show one file',
                'responses' => [
                    '200' => ['description' => 'The file.', 'content' => self::jsonRef('File')],
                ],
            ],
            'api.v1.files.download-url' => [
                'summary' => "Mint a presigned GET URL for a file's current version",
                'responses' => [
                    '200' => ['description' => 'The presigned download.', 'content' => self::jsonSchema(self::ref('#/components/schemas/DownloadUrl'))],
                ],
            ],
            'api.v1.files.versions.index' => [
                'summary' => 'List a file\'s versions, newest first',
                'responses' => [
                    '200' => ['description' => 'A page of versions.', 'content' => self::cursorPage('FileVersion')],
                ],
            ],
            'api.v1.files.text' => [
                'summary' => "Show a file's extracted text and extraction status",
                'responses' => [
                    '200' => ['description' => 'The extraction record.', 'content' => self::jsonSchema(self::ref('#/components/schemas/FileText'))],
                ],
            ],
            'api.v1.files.destroy' => [
                'summary' => 'Trash a file',
                'responses' => [
                    '200' => ['description' => 'The trashed file.', 'content' => self::jsonRef('File')],
                ],
            ],
            'api.v1.property-definitions.index' => [
                'summary' => 'List property definitions',
                'responses' => [
                    '200' => ['description' => 'A page of property definitions.', 'content' => self::cursorPage('PropertyDefinition')],
                ],
            ],
            'api.v1.directories.properties.update' => [
                'summary' => "Replace a directory's property values",
                'requestBody' => self::body([
                    'values' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Property key to value; validated per-key against its own PropertyDefinition.'],
                ], ['values']),
                'responses' => [
                    '200' => ['description' => 'The directory, refreshed.', 'content' => self::jsonRef('Directory')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.files.properties.update' => [
                'summary' => "Replace a file's property values",
                'requestBody' => self::body([
                    'values' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Property key to value; validated per-key against its own PropertyDefinition.'],
                ], ['values']),
                'responses' => [
                    '200' => ['description' => 'The file, refreshed.', 'content' => self::jsonRef('File')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.search' => [
                'summary' => 'Search directories and files within the caller\'s reach',
                'query' => [
                    ['name' => 'q', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'type', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'mime', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'period', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^\\d{4}(-\\d{2})?$']],
                    ['name' => 'attr', 'required' => false, 'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'attr[key]=value -- exactly one key is honoured.']],
                ],
                'responses' => [
                    '200' => ['description' => 'Search hits, unpaginated (see App\\Http\\Controllers\\Api\\V1\\SearchController\'s own docblock).', 'content' => self::jsonArray('SearchHit')],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
            'api.v1.trash.index' => [
                'summary' => 'List trashed files and directories within the caller\'s reach',
                'responses' => [
                    '200' => ['description' => 'The trashed files and directories.', 'content' => self::jsonSchema(self::ref('#/components/schemas/TrashIndex'))],
                ],
            ],
            'api.v1.trash.restore' => [
                'summary' => 'Restore a trashed file or directory',
                'responses' => [
                    '200' => [
                        'description' => 'The restored file or directory.',
                        'content' => self::jsonSchema(['oneOf' => [self::ref('#/components/schemas/File'), self::ref('#/components/schemas/Directory')]]),
                    ],
                    '422' => self::ref('#/components/responses/ValidationError'),
                ],
            ],
        ];
    }

    /**
     * @param  list<array{name: string, uri: string, methods: list<string>, abilities: list<string>}>  $routes
     * @return array<string, mixed>
     */
    public static function build(array $routes): array
    {
        $paths = [];

        foreach ($routes as $route) {
            $path = self::pathFor($route['uri']);
            $methods = array_values(array_filter(
                $route['methods'],
                // HEAD is Laravel's own automatic twin of GET on the SAME
                // route, not a second registered operation -- documenting
                // it separately would double every GET for no reason.
                static fn (string $method): bool => $method !== 'HEAD',
            ));

            foreach ($methods as $method) {
                $paths[$path][strtolower($method)] = self::operation($route);
            }
        }

        ksort($paths);

        foreach ($paths as $path => $ops) {
            ksort($ops);
            $paths[$path] = $ops;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'doccum API',
                'version' => '1',
                'description' => 'Content operations under /api/v1 -- spec §11. Generated from the '
                    .'live route table by App\\Console\\Commands\\GenerateOpenApi; never hand-edited.',
            ],
            'servers' => [
                ['url' => '/api/v1'],
            ],
            'security' => [
                ['sanctum' => []],
            ],
            'components' => [
                'securitySchemes' => [
                    'sanctum' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'A Laravel Sanctum personal access token. Every route also '
                            .'requires the token to carry the `ability:` scope(s) named on its '
                            .'operation, spec §11\'s first of three authorisation gates.',
                    ],
                ],
                'responses' => [
                    'Unauthorized' => ['description' => 'Missing or invalid bearer token.', 'content' => self::jsonSchema(self::ref('#/components/schemas/ErrorMessage'))],
                    'Forbidden' => ['description' => "The token lacks the required ability, or the signed-in user's own permissions/directory access refuse the request.", 'content' => self::jsonSchema(self::ref('#/components/schemas/ErrorMessage'))],
                    'NotFound' => ['description' => 'Not found, including a subject entirely outside the caller\'s reach.', 'content' => self::jsonSchema(self::ref('#/components/schemas/ErrorMessage'))],
                    'ValidationError' => ['description' => 'Laravel\'s standard validation error shape.', 'content' => self::jsonSchema(self::ref('#/components/schemas/ValidationErrorBody'))],
                    'TooManyRequests' => ['description' => 'Rate-limited per token (throttle:api).', 'content' => self::jsonSchema(self::ref('#/components/schemas/ErrorMessage'))],
                ],
                'schemas' => self::schemas(),
            ],
            'paths' => $paths,
        ];
    }

    /** @param  array{name: string, uri: string, methods: list<string>, abilities: list<string>}  $route
     * @return array<string, mixed> */
    private static function operation(array $route): array
    {
        $meta = self::operations()[$route['name']] ?? null;

        $parameters = self::pathParameters($route['uri']);

        foreach ($meta['query'] ?? [] as $q) {
            $parameters[] = [
                'name' => $q['name'],
                'in' => 'query',
                'required' => $q['required'],
                'schema' => $q['schema'],
            ];
        }

        $responses = $meta['responses'] ?? [
            // No catalog entry -- a route added to routes/api.php with no
            // matching entry in self::operations() above. Rather than invent a
            // shape or crash, this is the "minimal honest schema" CLAUDE.md
            // asks for: it documents that SOMETHING comes back, and nothing
            // about what.
            '200' => ['description' => 'See the controller; not yet catalogued in OpenApiSpecBuilder::operations().'],
        ];

        $responses['401'] = self::ref('#/components/responses/Unauthorized');
        $responses['403'] = self::ref('#/components/responses/Forbidden');
        $responses['429'] = self::ref('#/components/responses/TooManyRequests');

        if ($parameters !== [] && array_filter($parameters, static fn ($p) => $p['in'] === 'path') !== []) {
            $responses['404'] = self::ref('#/components/responses/NotFound');
        }

        ksort($responses);

        $operation = [
            'operationId' => $route['name'],
            'summary' => $meta['summary'] ?? $route['name'],
            'security' => [['sanctum' => $route['abilities']]],
            'responses' => $responses,
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (isset($meta['requestBody'])) {
            $operation['requestBody'] = $meta['requestBody'];
        }

        return $operation;
    }

    /** @return list<array<string, mixed>> */
    private static function pathParameters(string $uri): array
    {
        $params = [];

        if (preg_match_all('/\{(\w+)}/', $uri, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $type = self::PATH_PARAM_TYPES[$name] ?? ['type' => 'integer'];

                $params[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $type,
                ];
            }
        }

        return $params;
    }

    /** Laravel's registered uri is `api/v1/...`; the document's own server is `/api/v1`. */
    private static function pathFor(string $uri): string
    {
        $relative = preg_replace('#^api/v1/?#', '', $uri) ?? $uri;

        return '/'.$relative;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function body(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /** @return array<string, mixed> */
    private static function ref(string $ref): array
    {
        return ['$ref' => $ref];
    }

    /** @return array<string, mixed> */
    private static function jsonRef(string $schema): array
    {
        return self::jsonSchema(['type' => 'object', 'properties' => ['data' => self::ref("#/components/schemas/{$schema}")], 'required' => ['data']]);
    }

    /** A cursor-paginated collection: {data: [...], links: {...}, meta: {...}}.
     * links/meta are left as open objects -- CursorPaginator's exact key set
     * is Laravel's own to change, and asserting keys this generator cannot
     * run and observe would be the fabrication CLAUDE.md warns against.
     *
     * @return array<string, mixed> */
    private static function cursorPage(string $schema): array
    {
        return self::jsonSchema([
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => self::ref("#/components/schemas/{$schema}")],
                'links' => ['type' => 'object', 'additionalProperties' => true],
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['data'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function jsonArray(string $schema): array
    {
        return self::jsonSchema([
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array', 'items' => self::ref("#/components/schemas/{$schema}")]],
            'required' => ['data'],
        ]);
    }

    /** @param  array<string, mixed>  $schema
     * @return array<string, mixed> */
    private static function jsonSchema(array $schema): array
    {
        return ['application/json' => ['schema' => $schema]];
    }

    /** @return array<string, array<string, mixed>> */
    private static function schemas(): array
    {
        return [
            'ErrorMessage' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => ['message'],
            ],
            'ValidationErrorBody' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'required' => ['message', 'errors'],
            ],
            'Directory' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'parent_id' => ['type' => ['integer', 'null']],
                    'name' => ['type' => 'string'],
                    'depth' => ['type' => 'integer'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    // Present only when the directory is trashed
                    // (DirectoryResource's own $this->when(trashed(), ...)) --
                    // absent otherwise, never null-when-absent, so it is not
                    // listed in `required`.
                    'deleted_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => ['id', 'parent_id', 'name', 'depth', 'created_at', 'updated_at'],
            ],
            'File' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'directory_id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'name' => ['type' => 'string'],
                    'mime' => ['type' => 'string'],
                    'size' => ['type' => 'integer'],
                    'checksum' => ['type' => ['string', 'null']],
                    'legal_hold' => ['type' => 'boolean'],
                    'current_version_id' => ['type' => ['integer', 'null']],
                    'period_year' => ['type' => 'integer'],
                    'period_month' => ['type' => 'integer'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    // Same "present only when trashed" note as Directory above.
                    'deleted_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => [
                    'id', 'directory_id', 'uuid', 'name', 'mime', 'size', 'checksum',
                    'legal_hold', 'current_version_id', 'period_year', 'period_month',
                    'created_at', 'updated_at',
                ],
            ],
            'FileVersion' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'file_id' => ['type' => 'integer'],
                    'version_number' => ['type' => 'integer'],
                    'size' => ['type' => 'integer'],
                    'mime' => ['type' => 'string'],
                    'checksum' => ['type' => 'string'],
                    'uploaded_by' => ['type' => 'integer'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => ['id', 'file_id', 'version_number', 'size', 'mime', 'checksum', 'uploaded_by', 'created_at'],
            ],
            'FileText' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['pending', 'processing', 'done', 'failed', 'unsupported']],
                    'extractor' => ['type' => ['string', 'null']],
                    'text' => ['type' => ['string', 'null']],
                    'chars' => ['type' => 'integer'],
                    'error' => ['type' => ['string', 'null']],
                ],
                'required' => ['status', 'extractor', 'text', 'chars', 'error'],
            ],
            'PropertyDefinition' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'key' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'data_type' => ['type' => 'string', 'enum' => ['string', 'text', 'number', 'date', 'boolean', 'select']],
                    'options' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                    'is_required' => ['type' => 'boolean'],
                    'applies_to' => ['type' => 'string', 'enum' => ['directory', 'file', 'both']],
                    'sort_order' => ['type' => 'integer'],
                ],
                'required' => ['id', 'key', 'label', 'data_type', 'options', 'is_required', 'applies_to', 'sort_order'],
            ],
            'SearchHit' => [
                'type' => 'object',
                'properties' => [
                    'subject_type' => ['type' => 'string'],
                    'subject_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'snippet' => ['type' => 'string'],
                    'score' => ['type' => 'number'],
                    'directory_id' => ['type' => ['integer', 'null']],
                ],
                'required' => ['subject_type', 'subject_id', 'title', 'snippet', 'score', 'directory_id'],
            ],
            'UploadUrl' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'upload_id' => ['type' => 'string'],
                            'upload_url' => ['type' => 'string', 'format' => 'uri'],
                            'upload_headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                        'required' => ['upload_id', 'upload_url', 'upload_headers', 'expires_at'],
                    ],
                ],
                'required' => ['data'],
            ],
            'DownloadUrl' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'expires_in' => ['type' => 'integer'],
                        ],
                        'required' => ['url', 'expires_in'],
                    ],
                ],
                'required' => ['data'],
            ],
            'TrashIndex' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'files' => ['type' => 'array', 'items' => self::ref('#/components/schemas/File')],
                            'directories' => ['type' => 'array', 'items' => self::ref('#/components/schemas/Directory')],
                        ],
                        'required' => ['files', 'directories'],
                    ],
                ],
                'required' => ['data'],
            ],
        ];
    }
}
