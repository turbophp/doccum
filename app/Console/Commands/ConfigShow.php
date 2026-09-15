<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RuntimeConfig;
use Illuminate\Console\Command;

/**
 * Prints the runtime config with secrets masked, so an operator can inspect
 * what an instance is pointed at without a credential ever reaching the
 * terminal, a support bundle, or whatever else captures command output.
 */
class ConfigShow extends Command
{
    protected $signature = 'doccum:config:show';

    protected $description = 'Show the runtime configuration, masking secrets';

    public function handle(): int
    {
        $config = RuntimeConfig::read();

        if ($config === []) {
            $this->components->info('No runtime configuration is present.');

            return self::SUCCESS;
        }

        foreach ($this->flatten($config) as $key => $value) {
            $this->line(sprintf('%s = %s', $key, $this->displayValue($key, $value)));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function displayValue(string $key, mixed $value): string
    {
        if ($this->isSensitive($key)) {
            return '[hidden]';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * Matches on the dotted key path, not a hardcoded list of full keys, so a
     * new field named e.g. `storage.key` is masked automatically.
     */
    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (['password', 'secret', 'key'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
