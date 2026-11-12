<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Enum\MetricUnit;
use App\Service\Import\RnpSheet\RnpCellParser;
use App\Service\Import\RnpSheet\RnpSheetMapping;
use Codeception\Test\Unit;

final class RnpSheetParsingTest extends Unit
{
    public function testReadsFormattedCellsByUnit(): void
    {
        self::assertSame('3925', (string) RnpCellParser::parse(MetricUnit::Eur, '€3 925'));
        self::assertSame('3925', (string) RnpCellParser::parse(MetricUnit::Eur, "€3\u{00A0}925"));
        self::assertSame('41.5', (string) RnpCellParser::parse(MetricUnit::Eur, '€41,5'));
        self::assertSame('335.67', (string) RnpCellParser::parse(MetricUnit::Eur, '335,6666667'), 'averages are rounded to cents');
        self::assertSame('0.0947', (string) RnpCellParser::parse(MetricUnit::Percent, '9,47%'));
        self::assertSame('0.45', (string) RnpCellParser::parse(MetricUnit::Percent, '45%'));
        self::assertSame('86', (string) RnpCellParser::parse(MetricUnit::Count, '86'));
        self::assertSame('21.7', (string) RnpCellParser::parse(MetricUnit::Minutes, '21,7'));
        self::assertSame('0', (string) RnpCellParser::parse(MetricUnit::Count, '0'), 'zero is a value');
    }

    public function testEmptyAndDashMeanNoValue(): void
    {
        foreach (['', ' ', '-', '-%', '—', 'н/п', 'Н/П'] as $raw) {
            self::assertNull(RnpCellParser::parse(MetricUnit::Eur, $raw), $raw);
        }
    }

    public function testRejectsTextFractionalCountsAndMisplacedPercent(): void
    {
        self::assertSame('0.45', (string) RnpCellParser::parse(MetricUnit::Percent, '0,45'), 'a fraction without the sign');
        foreach ([[MetricUnit::Count, 'тех пробл'], [MetricUnit::Count, '3,4'], [MetricUnit::Eur, '15%'], [MetricUnit::Percent, '🔴 : 48%'], [MetricUnit::Count, 'актуализировать '], [MetricUnit::Percent, '7']] as [$unit, $raw]) {
            try {
                RnpCellParser::parse($unit, $raw);
                self::fail(\sprintf('"%s" must be rejected for %s', $raw, $unit->value));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRowNamesAreComparedNormalized(): void
    {
        self::assertSame('сумма бронирований (общая)', RnpSheetMapping::normalize('Сумма бронирований (общая)  - 5000'));
        self::assertSame('загрузка (%, факт)', RnpSheetMapping::normalize('Загрузка (%, факт) - 46%'));
        self::assertSame('загрузка (%, факт)', RnpSheetMapping::normalize('Загрузка (%, факт) -'), 'November: target erased, dash left');
        // The September sheet really says "Pагрузка" (Latin P for З): it becomes Cyrillic "р", like its alias.
        self::assertSame('рагрузка (%, факт)', RnpSheetMapping::normalize('Pагрузка (%, факт)'), 'Latin P inside a Cyrillic word');
        self::assertSame('средняя сумма брони (check in)', RnpSheetMapping::normalize('Cредняя сумма брони (check in) - 483'));
        self::assertSame('квал лиды', RnpSheetMapping::normalize("Квал лиды\n - 2"));
        self::assertSame('adr апартаменты (check in)', RnpSheetMapping::normalize('ADR апартаменты (check in)'), 'Latin words stay Latin');
        self::assertSame('расход на рекламу (100-110 евро)', RnpSheetMapping::normalize('Расход на рекламу (100-110 евро)'));
    }
}
