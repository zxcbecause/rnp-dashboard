<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Which sheet rows feed which metrics (config/import/rnp_sheet.yaml). Row names drift between months
 * ("Квал-лиды", "Квал лиды - 2"), so each metric lists its aliases; names are compared normalized:
 * case, spacing, Latin look-alike letters inside Cyrillic words ("Cредняя") and a trailing target
 * ("- 5000", "- 46%") are ignored.
 */
final class RnpSheetMapping
{
    private const array LOOKALIKES = ['a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у', 'k' => 'к', 'm' => 'м', 't' => 'т', 'h' => 'н', 'b' => 'в'];

    /** @var list<string>|null */
    private ?array $sections = null;

    /** @var array<string, string>|null "section|normalized name" => metric code */
    private ?array $index = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/import/rnp_sheet.yaml')]
        private readonly string $path,
    ) {
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        $this->load();

        return $this->sections ?? [];
    }

    public function metricFor(string $section, string $rowName): ?string
    {
        $this->load();

        return $this->index[mb_strtolower($section).'|'.self::normalize($rowName)] ?? null;
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        // A trailing target ("- 5000", "- 46%") or a dash left after the target was erased ("Загрузка (%, факт) -").
        $name = (string) preg_replace('/\s+-(\s+[\d\s.,%]*)?$/u', '', $name);

        $words = array_map(
            static fn (string $word): string => 1 === preg_match('/\p{Cyrillic}/u', $word) ? strtr($word, self::LOOKALIKES) : $word,
            explode(' ', $name),
        );

        return implode(' ', $words);
    }

    private function load(): void
    {
        if (null !== $this->index) {
            return;
        }

        $config = Yaml::parseFile($this->path);
        if (!\is_array($config) || !\is_array($config['sections'] ?? null) || !\is_array($config['metrics'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('%s must define "sections" and "metrics".', $this->path));
        }

        $this->sections = array_values(array_map('strval', $config['sections']));
        $this->index = [];
        foreach ($config['metrics'] as $code => $spec) {
            if (!\is_array($spec) || !\is_string($spec['section'] ?? null) || !\is_array($spec['rows'] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('Mapping of "%s" needs "section" and "rows".', $code));
            }
            foreach ($spec['rows'] as $row) {
                $key = mb_strtolower($spec['section']).'|'.self::normalize((string) $row);
                if (isset($this->index[$key]) && $this->index[$key] !== (string) $code) {
                    throw new \InvalidArgumentException(\sprintf('Row "%s" is mapped to both %s and %s.', $row, $this->index[$key], $code));
                }
                $this->index[$key] = (string) $code;
            }
        }
    }
}
