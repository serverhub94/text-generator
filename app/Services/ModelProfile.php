<?php
namespace App\Services;

use InvalidArgumentException;

class ModelProfile
{
    private string $key;
    private array $cfg;

    private function __construct(string $key, array $cfg)
    {
        $this->key = $key;
        $this->cfg = $cfg;
    }

    public static function fromConfig(string $key, array $config): self
    {
        if (!isset($config['models'][$key])) {
            throw new InvalidArgumentException("Unknown model: {$key}");
        }
        return new self($key, $config['models'][$key]);
    }

    public function id(): string
    {
        return $this->cfg['id'] ?? $this->key;
    }

    public function label(): string
    {
        return $this->cfg['label'] ?? $this->key;
    }

    public function enabled(): bool
    {
        return (bool) ($this->cfg['enabled'] ?? false);
    }

    public function supportsEffort(): bool
    {
        return !empty($this->cfg['effort']);
    }

    /**
     * Возвращает ближайший поддерживаемый уровень effort или null.
     */
    public function effortFor(string $requested): ?string
    {
        $allowed = $this->cfg['effort'] ?? [];
        if (in_array($requested, $allowed, true)) {
            return $requested;
        }
        // Понижение: порядок предпочтения low < medium < high
        $order = ['low', 'medium', 'high'];
        $reqIndex = array_search($requested, $order, true);
        if ($reqIndex === false) {
            // если неизвестный — вернуть первый доступный
            return $allowed[0] ?? null;
        }
        for ($i = $reqIndex - 1; $i >= 0; $i--) {
            if (in_array($order[$i], $allowed, true)) {
                return $order[$i];
            }
        }
        return null;
    }

    public function maxTokensFor(int $requested): int
    {
        $max = (int) ($this->cfg['max_output_tokens'] ?? 0);
        return min($requested, $max);
    }

    public function thinking(): string
    {
        return $this->cfg['thinking'] ?? 'none';
    }





    public function webTools(): string
    {
        return $this->cfg['web_tools'] ?? 'none';
    }

    public function contextTokens(): int
    {
        return (int) ($this->cfg['context_tokens'] ?? 0);
    }

    public function pricing(): array
    {
        return $this->cfg['pricing'] ?? [];
    }

    public function cost(int $in, int $out, int $cacheRead, int $cacheWrite): float
    {
        $p = $this->pricing();
        $inRate = $p['input'] ?? 0.0;
        $outRate = $p['output'] ?? 0.0;
        $cr = $p['cache_read'] ?? 0.0;
        $cw = $p['cache_write'] ?? 0.0;

        return round($in * $inRate + $out * $outRate + $cacheRead * $cr + $cacheWrite * $cw, 6);
    }
}
