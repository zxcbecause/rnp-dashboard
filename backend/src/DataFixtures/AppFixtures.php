<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Action\BaseFunction;
use App\Entity\Action\BaseFunctionCheck;
use App\Entity\Action\RecurringTask;
use App\Entity\Action\RoadmapEvent;
use App\Entity\Action\RoadmapItem;
use App\Entity\Action\SprintTask;
use App\Entity\Auth\User;
use App\Entity\Journal\ErrorEntry;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricLink;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricThreshold;
use App\Entity\Metric\MetricValue;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\ErrorImpactType;
use App\Enum\ErrorStatus;
use App\Enum\MetricDirection;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Enum\Periodicity;
use App\Enum\PeriodType;
use App\Enum\RoadmapEventType;
use App\Enum\RoadmapStatus;
use App\Enum\SprintPriority;
use App\Enum\TimeAggregation;
use App\Enum\TreeAggregation;
use App\Enum\ValueOrigin;
use App\Service\Org\OrgTreeManager;
use App\Service\Period\PeriodCalendar;
use BcMath\Number;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Local development data: org tree Company → Revenue (Marta), Marketing (Oleg), CS (Gleb),
 * metrics from the real "Ноябрь" sheet, plans and facts for September–November 2026.
 *
 * All accounts use the local-only password below; it is not a secret and exists only in dev DBs.
 */
final class AppFixtures extends Fixture
{
    public const string PASSWORD = 'rnp-local-123';

    private const int YEAR = 2026;
    private const array MONTHS = [9, 10, 11];

    /**
     * Base (stored) metrics: code => [monthly plan, monthly fact as share of plan range].
     * Weekly facts are spread over week segments proportionally to their days, with noise.
     * Ratio metrics are never stored: they are recomputed from these by formula.
     *
     * @var array<string, array{plan: string, min: float, max: float}>
     */
    private const array STORED = [
        'rev_bookings_sum' => ['plan' => '180000', 'min' => 0.82, 'max' => 1.12],
        'rev_arrivals_count' => ['plan' => '210', 'min' => 0.85, 'max' => 1.10],
        'rev_nights_sold' => ['plan' => '1500', 'min' => 0.85, 'max' => 1.08],
        'rev_nights_available' => ['plan' => '2100', 'min' => 1.0, 'max' => 1.0],
        'rev_cancelled_count' => ['plan' => '12', 'min' => 0.6, 'max' => 1.5],
        'mkt_ad_spend' => ['plan' => '6000', 'min' => 0.85, 'max' => 1.10],
        'mkt_leads' => ['plan' => '240', 'min' => 0.75, 'max' => 1.15],
        'mkt_qualified_leads' => ['plan' => '60', 'min' => 0.70, 'max' => 1.20],
        'sales_contracts_count' => ['plan' => '8', 'min' => 0.5, 'max' => 1.4],
        'mkt_direct_site_sum' => ['plan' => '15000', 'min' => 0.70, 'max' => 1.25],
        'cs_incoming_sum' => ['plan' => '45000', 'min' => 0.80, 'max' => 1.15],
        'cs_direct_base_sum' => ['plan' => '9000', 'min' => 0.70, 'max' => 1.20],
        'cs_calls_total' => ['plan' => '600', 'min' => 0.9, 'max' => 1.1],
        'cs_calls_missed' => ['plan' => '30', 'min' => 0.6, 'max' => 1.6],
    ];

    public function __construct(
        private readonly OrgTreeManager $tree,
        private readonly PeriodCalendar $calendar,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Deterministic "random" facts, so every reload produces the same dataset.
        mt_srand(20261104);

        $users = $this->loadUsers($manager);

        $company = $this->tree->createUnit('Acme', null, 0);
        $revenue = $this->tree->createUnit('Revenue', $company, 10);
        $marketing = $this->tree->createUnit('Marketing', $company, 20);
        $cs = $this->tree->createUnit('Customer Service', $company, 30);
        // Signed contracts belong to Sales (Alina, 10.11.2026); its manual metrics come from the YAML catalog.
        $sales = $this->tree->createUnit('Sales', $company, 35);

        $company->setHeadUser($users['ceo']);
        $revenue->setHeadUser($users['marta']);
        $marketing->setHeadUser($users['oleg']);
        $cs->setHeadUser($users['gleb']);

        foreach ([
            [$users['admin'], $company, OrgRole::Admin],
            [$users['ceo'], $company, OrgRole::Head],
            [$users['marta'], $revenue, OrgRole::Head],
            [$users['oleg'], $marketing, OrgRole::Head],
            [$users['gleb'], $cs, OrgRole::Head],
            [$users['revenue_member'], $revenue, OrgRole::Member],
            [$users['marketing_viewer'], $marketing, OrgRole::Viewer],
        ] as [$user, $unit, $role]) {
            $manager->persist(new OrgMembership($user, $unit, $role));
        }

        $metrics = $this->loadMetrics($manager, $company, $revenue, $marketing, $cs, $sales, $users);

        $manager->persist(new MetricThreshold(null, new Number('100'), new Number('90')));
        $manager->persist(new MetricThreshold($metrics['rev_cancelled_count'], new Number('100'), new Number('80')));

        $manager->flush();

        $this->loadPlansAndFacts($manager, $metrics, $users);
        $this->loadActions($manager, $metrics, $users);

        $manager->flush();

        $this->loadManualStageOneMetrics($manager, $company, $revenue, $users);
        $manager->flush();
    }

    /**
     * Metrics received on 04.11.2026 (docs/metrics-catalog.md), entered manually in Stage 1:
     * OTA unit under Revenue, extra Revenue metrics, Finance with "Приход" / "Расход".
     * Ratios are formulas over manual numerators/denominators. Facts for September–October only,
     * November is left for manual entry.
     *
     * @param array<string, User> $users
     */
    private function loadManualStageOneMetrics(ObjectManager $manager, OrgUnit $company, OrgUnit $revenue, array $users): void
    {
        $ota = $this->tree->createUnit('OTA', $revenue, 10);
        $finance = $this->tree->createUnit('Финансовый отдел', $company, 40);
        $finance->setHeadUser($users['finance']);
        $income = $this->tree->createUnit('Приход', $finance, 10);
        $expense = $this->tree->createUnit('Расход', $finance, 20);
        $manager->persist(new OrgMembership($users['ota'], $ota, OrgRole::Member));
        $manager->persist(new OrgMembership($users['finance'], $finance, OrgRole::Head));

        $sort = 100;
        $metric = function (OrgUnit $unit, string $code, string $name, MetricUnit $type, array $o = []) use ($manager, &$sort): Metric {
            $m = new Metric($unit, $code, $name, $type);
            $m->setSourceType(MetricSourceType::Manual)
                ->setGranularity($o['granularity'] ?? PeriodType::Month)
                ->setTimeAgg(isset($o['formula']) ? TimeAggregation::Formula : ($o['agg'] ?? TimeAggregation::Sum))
                ->setTreeAgg(isset($o['formula']) ? TreeAggregation::Formula : TreeAggregation::Sum)
                ->setDirection($o['direction'] ?? MetricDirection::HigherBetter)
                ->setFormula($o['formula'] ?? null)
                ->setOwnerUser($o['owner'] ?? null)
                ->setFillerUser(isset($o['formula']) ? null : ($o['owner'] ?? null))
                ->setSort($sort += 10)
                ->setWhatMeasured($o['what'] ?? null)
                ->setFormulaText($o['formulaText'] ?? null)
                ->setSourceText($o['source'] ?? 'Вручную')
                ->setPeriodText(PeriodType::Week === ($o['granularity'] ?? PeriodType::Month) ? 'Неделя, месяц' : 'Месяц');
            $manager->persist($m);

            return $m;
        };
        $lower = MetricDirection::LowerBetter;
        $week = PeriodType::Week;
        $marta = $users['marta'];
        $fin = $users['finance'];
        $otaUser = $users['ota'];

        $data = [];
        // OTA.
        $data[] = [$metric($ota, 'ota_tasks_total', 'OTA-задачи — всего', MetricUnit::Count, ['granularity' => $week, 'owner' => $otaUser]), null, ['40', '38']];
        $data[] = [$metric($ota, 'ota_tasks_on_time', 'OTA-задачи — закрыты в срок', MetricUnit::Count, ['granularity' => $week, 'owner' => $otaUser]), null, ['36', '33']];
        $data[] = [$metric($ota, 'ota_tasks_on_time_share', 'OTA-задачи, выполненные в срок', MetricUnit::Percent, ['granularity' => $week, 'formula' => 'ota_tasks_on_time / ota_tasks_total', 'owner' => $otaUser,
            'what' => 'Доля OTA-задач, закрытых в SLA 2 рабочих дня (с выполненным чек-листом; «Ожидание» с блокирующей причиной — не просрочка)',
            'formulaText' => 'Закрытые в срок / все задачи × 100', 'source' => 'Вручную; позже Битрикс24 (воронка OTA-задач)']), '0.95', null];
        $data[] = [$metric($ota, 'ota_launch_days_sum', 'Вывод объектов — сумма дней', MetricUnit::Days, ['owner' => $otaUser]), null, ['42', '30']];
        $data[] = [$metric($ota, 'ota_launch_objects', 'Вывод объектов — количество', MetricUnit::Count, ['owner' => $otaUser]), null, ['6', '5']];
        $data[] = [$metric($ota, 'ota_launch_days', 'Срок вывода нового объекта на продажу', MetricUnit::Days, ['formula' => 'ota_launch_days_sum / ota_launch_objects', 'direction' => $lower, 'owner' => $otaUser,
            'what' => 'Среднее число дней от получения PLANTILLA до активности на всех каналах (без ожидания фото, документов, ответа OTA)',
            'formulaText' => 'Σ дней по объектам / количество объектов', 'source' => 'Вручную; позже Битрикс24 + дата активации']), '5', null];
        $data[] = [$metric($ota, 'ota_content_done', 'Контент-кампания — сделано объектов', MetricUnit::Count, ['owner' => $otaUser]), null, ['18', '27']];
        $data[] = [$metric($ota, 'ota_content_total', 'Контент-кампания — объектов в кампании', MetricUnit::Count, ['owner' => $otaUser]), null, ['30', '30']];
        $data[] = [$metric($ota, 'ota_content_share', 'Выполнение контент-кампании', MetricUnit::Percent, ['formula' => 'ota_content_done / ota_content_total', 'owner' => $otaUser,
            'formulaText' => 'Выполненные объекты / все объекты кампании × 100', 'source' => 'Вручную (Google-таблица кампании)']), '1', null];

        // Revenue additions.
        $data[] = [$metric($revenue, 'rev_rental_revenue', 'Rental revenue (план по доходу)', MetricUnit::Eur, ['owner' => $marta,
            'what' => 'Фактический rental revenue за месяц; % выполнения = выполнение плана по доходу', 'source' => 'Вручную (таблица Марты)']), '150000', ['141200', '156300']];
        $data[] = [$metric($revenue, 'rev_bookings_checked', 'Брони, сверенные с ценовым минимумом', MetricUnit::Count, ['owner' => $marta]), null, ['180', '200']];
        $data[] = [$metric($revenue, 'rev_below_floor_count', 'Брони ниже ценового минимума — количество', MetricUnit::Count, ['owner' => $marta, 'direction' => $lower]), null, ['4', '1']];
        $data[] = [$metric($revenue, 'rev_below_floor_share', 'Бронирования ниже ценового минимума', MetricUnit::Percent, ['formula' => 'rev_below_floor_count / rev_bookings_checked', 'direction' => $lower, 'owner' => $marta,
            'what' => 'Доля броней с ADR ниже планового ADR на эти даты. Цель 0 %', 'formulaText' => 'Брони ниже минимума / все брони × 100', 'source' => 'Вручную (таблица Марты)']), '0', null];

        // Finance.
        $data[] = [$metric($finance, 'fin_close_days', 'Скорость закрытия отчётного периода', MetricUnit::Days, ['agg' => TimeAggregation::Last, 'direction' => $lower, 'owner' => $fin,
            'what' => 'Рабочих дней на закрытие месяца/квартала']), '5', ['7', '6']];
        $data[] = [$metric($finance, 'fin_reports_total', 'Управленческие отчёты — всего', MetricUnit::Count, ['owner' => $fin]), null, ['8', '8']];
        $data[] = [$metric($finance, 'fin_reports_on_time', 'Управленческие отчёты — в срок', MetricUnit::Count, ['owner' => $fin]), null, ['6', '8']];
        $data[] = [$metric($finance, 'fin_reports_on_time_share', 'Своевременность управленческой отчётности', MetricUnit::Percent, ['formula' => 'fin_reports_on_time / fin_reports_total', 'owner' => $fin,
            'formulaText' => 'Отчёты, сданные в срок / все отчёты × 100']), '1', null];
        $data[] = [$metric($finance, 'fin_savings', 'Экономия / оптимизация затрат', MetricUnit::Eur, ['owner' => $fin, 'what' => 'Эффект от инициатив отдела по снижению издержек']), '2000', ['1500', '2400']];
        $data[] = [$metric($finance, 'fin_report_corrections', 'Ошибки в отчётности', MetricUnit::Count, ['direction' => $lower, 'owner' => $fin, 'what' => 'Корректировок после сдачи отчётности']), '0', ['2', '0']];
        $data[] = [$metric($finance, 'fin_tax_deadlines_met', 'Соблюдение сроков налоговой/бухгалтерской отчётности', MetricUnit::Boolean, ['agg' => TimeAggregation::Last, 'owner' => $fin,
            'what' => 'Данные для аутсорса собраны корректно и в срок (1 — да, 0 — нет)']), '1', ['1', '1']];
        $data[] = [$metric($finance, 'fin_payments_total', 'Платежи по календарю — всего', MetricUnit::Count, ['owner' => $fin]), null, ['120', '134']];
        $data[] = [$metric($finance, 'fin_payments_on_time', 'Платежи по календарю — в срок', MetricUnit::Count, ['owner' => $fin]), null, ['112', '131']];
        $data[] = [$metric($finance, 'fin_payments_on_time_share', 'Соблюдение платёжного календаря', MetricUnit::Percent, ['formula' => 'fin_payments_on_time / fin_payments_total', 'owner' => $fin,
            'formulaText' => 'Платежи по графику / все платежи × 100']), '0.98', null];
        $data[] = [$metric($finance, 'fin_bus_factor', 'Bus factor', MetricUnit::Count, ['agg' => TimeAggregation::Last, 'direction' => $lower, 'owner' => $fin,
            'what' => 'Критичные процессы, которые по матрице компетенций умеет только один сотрудник']), '2', ['5', '4']];
        $data[] = [$metric($finance, 'fin_budget_regulation', 'Регламент бюджетирования утверждён', MetricUnit::Boolean, ['agg' => TimeAggregation::Last, 'owner' => $fin,
            'what' => 'Бюджетирование, этап 0 (подготовка)']), '1', ['0', '0']];
        $data[] = [$metric($finance, 'fin_budget_forms_ready', 'Готовность бюджетных форм', MetricUnit::Percent, ['agg' => TimeAggregation::Last, 'owner' => $fin,
            'what' => 'Бюджетирование, этап 0: % готовых форм и шаблонов от плана']), '1', ['0.3', '0.55']];

        $manager->flush();

        $this->loadFinanceRoutines($manager, $income, $expense, $users['finance']);

        foreach ($data as [$m, $monthlyPlan, $facts]) {
            foreach ([9, 10, 11] as $i => $month) {
                $start = $this->calendar->monthStart(self::YEAR, $month);
                if (null !== $monthlyPlan) {
                    $manager->persist(new MetricPlan($m, PeriodType::Month, $start, new Number($monthlyPlan), $m->getOwnerUser()));
                }
                if (null === $facts || !isset($facts[$i])) {
                    continue;
                }
                if (PeriodType::Month === $m->getGranularity()) {
                    $manager->persist(new MetricValue($m, PeriodType::Month, $start, new Number($facts[$i]), ValueOrigin::Import));
                    continue;
                }
                // Weekly metrics: spread the monthly total over the week segments.
                $weeks = $this->calendar->monthWeeks(self::YEAR, $month);
                $left = (int) $facts[$i];
                foreach ($weeks as $n => $segment) {
                    $share = $n === \count($weeks) - 1 ? $left : intdiv((int) $facts[$i] * $segment->days(), $this->calendar->daysInMonth(self::YEAR, $month));
                    $left -= $share;
                    $manager->persist(new MetricValue($m, PeriodType::Week, $segment->start, new Number((string) $share), ValueOrigin::Import));
                }
            }
        }
    }

    /**
     * @return array<string, User>
     */
    private function loadUsers(ObjectManager $manager): array
    {
        $definitions = [
            'admin' => ['admin@rnp.test', 'Администратор', ['ROLE_ADMIN']],
            'ceo' => ['ceo@rnp.test', 'Топ-менеджмент', []],
            'marta' => ['marta@rnp.test', 'Марта (Revenue)', []],
            'oleg' => ['oleg@rnp.test', 'Олег (Marketing)', []],
            'gleb' => ['gleb@rnp.test', 'Глеб (CS)', []],
            'revenue_member' => ['revenue.member@rnp.test', 'Сотрудник Revenue', []],
            'marketing_viewer' => ['marketing.viewer@rnp.test', 'Наблюдатель Marketing', []],
            'inactive' => ['inactive@rnp.test', 'Отключённый пользователь', []],
            'ota' => ['ota@rnp.test', 'OTA-менеджер', []],
            'finance' => ['finance@rnp.test', 'Руководитель ФО', []],
        ];

        $users = [];
        foreach ($definitions as $key => [$email, $name, $roles]) {
            $user = new User($email, $name);
            $user->setRoles($roles)->setIsActive('inactive' !== $key);
            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
            $manager->persist($user);
            $users[$key] = $user;
        }

        $manager->flush();

        return $users;
    }

    /**
     * @param array<string, User> $users
     *
     * @return array<string, Metric>
     */
    private function loadMetrics(
        ObjectManager $manager,
        OrgUnit $company,
        OrgUnit $revenue,
        OrgUnit $marketing,
        OrgUnit $cs,
        OrgUnit $sales,
        array $users,
    ): array {
        $m = [];
        $sort = 0;

        $define = function (
            OrgUnit $unit,
            string $code,
            string $name,
            MetricUnit $unitType,
            TimeAggregation $timeAgg,
            MetricSourceType $source,
            ?User $owner,
            array $card,
            MetricDirection $direction = MetricDirection::HigherBetter,
            ?string $formula = null,
        ) use (&$m, &$sort, $manager): Metric {
            $metric = new Metric($unit, $code, $name, $unitType);
            $metric->setTimeAgg($timeAgg)
                ->setTreeAgg(TimeAggregation::Formula === $timeAgg ? TreeAggregation::Formula : TreeAggregation::Sum)
                ->setDirection($direction)
                ->setFormula($formula)
                ->setSourceType($source)
                ->setGranularity(PeriodType::Week)
                ->setOwnerUser($owner)
                ->setFillerUser(MetricSourceType::Manual === $source ? $owner : null)
                ->setSort($sort += 10)
                ->setWhatMeasured($card[0])
                ->setFormulaText($card[1])
                ->setSourceText($card[2])
                ->setPeriodText($card[3])
                ->setUsedIn($card[4] ?? null);
            $manager->persist($metric);

            return $m[$code] = $metric;
        };

        $api = MetricSourceType::PlatformApi;
        $bitrix = MetricSourceType::Bitrix;
        $manual = MetricSourceType::Manual;
        $sum = TimeAggregation::Sum;
        $formula = TimeAggregation::Formula;
        $lower = MetricDirection::LowerBetter;
        $higher = MetricDirection::HigherBetter;
        $marta = $users['marta'];
        $oleg = $users['oleg'];
        $gleb = $users['gleb'];

        // Revenue / Marta.
        $define($revenue, 'rev_bookings_sum', 'Сумма бронирований', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды (rentalPrice) всех подтверждённых бронирований, созданных за период', 'SUM(booking.rentalPrice)', 'API платформы: GET /api/bookings', 'Неделя (пн–вс), месяц = сумма недель', 'РНП Revenue, РНП компании']);
        $define($revenue, 'rev_stayed_sum', 'Сумма бронирований (прожили)', MetricUnit::Eur, $sum, $api, $marta,
            ['Выручка за ночи проживания в периоде (как «прожили» в PriceLabs)', 'Σ rentalPrice брони × ночей брони в периоде / всех ночей брони; ночь относится к дате, с которой начинается', 'API платформы: GET /api/bookings (+ карточка брони для rentalPrice)', 'Неделя, месяц = сумма недель; текущая неделя — по сегодняшнюю ночь', 'РНП Revenue']);
        $define($revenue, 'rev_arrivals_count', 'Количество заездов', MetricUnit::Count, $sum, $api, $marta,
            ['Число бронирований с датой заезда в периоде', 'COUNT(booking) по arrivalDate', 'API платформы: GET /api/bookings?arrivalFrom&arrivalTo', 'Неделя, месяц']);
        // Check-in based ratios (decision 10.11.2026, as Marta's «(check in)» rows): numerator and denominator of the
        // same bookings — those arriving in the period.
        $define($revenue, 'rev_checkin_sum', 'Сумма броней с заездом в периоде', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды броней с заездом в периоде', 'SUM(booking.rentalPrice) по arrivalDate', 'API платформы: GET /api/bookings', 'Неделя, месяц', 'Числитель средней суммы брони']);
        $define($revenue, 'rev_avg_booking', 'Средняя сумма брони', MetricUnit::Eur, $formula, $api, $marta,
            ['Средняя стоимость аренды одной брони с заездом в периоде (check in)', 'Сумма броней с заездом / Количество заездов', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне, не усредняется'],
            $higher, 'rev_checkin_sum / rev_arrivals_count');
        $define($revenue, 'rev_nights_sold', 'Проданные ночи', MetricUnit::Count, $sum, $api, $marta,
            ['Ночи проживания по бронированиям периода', 'SUM(departure − arrival)', 'API платформы: GET /api/bookings', 'Неделя, месяц', 'Знаменатель ADR, числитель загрузки']);
        $define($revenue, 'rev_nights_available', 'Доступные ночи', MetricUnit::Count, $sum, $api, $marta,
            ['Ночи, доступные к продаже по активным объектам', 'Σ объектов × дней в периоде', 'API платформы: GET /api/accommodations', 'Неделя, месяц', 'Знаменатель загрузки']);
        $define($revenue, 'rev_apt_checkin_sum', 'Апартаменты: сумма броней с заездом', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды броней апартаментов и студий с заездом в периоде', 'SUM(booking.rentalPrice) по arrivalDate, тип объекта APARTMENT / STUDIO', 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Числитель ADR апартаментов']);
        $define($revenue, 'rev_apt_checkin_nights', 'Апартаменты: ночи броней с заездом', MetricUnit::Count, $sum, $api, $marta,
            ['Все ночи броней апартаментов и студий с заездом в периоде', 'SUM(departure − arrival) по arrivalDate, тип объекта APARTMENT / STUDIO', 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Знаменатель ADR апартаментов']);
        $define($revenue, 'rev_adr', 'ADR апартаменты', MetricUnit::Eur, $formula, $api, $marta,
            ['Средняя цена ночи в апартаментах и студиях по броням с заездом в периоде (check in)', 'Сумма броней апартаментов с заездом / их ночи', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'rev_apt_checkin_sum / rev_apt_checkin_nights');
        $villas = 'VILLA / HOUSE / TOWNHOUSE / COTTAGE';
        $define($revenue, 'rev_villa_checkin_sum', 'Виллы и дома: сумма броней с заездом', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды броней вилл и домов с заездом в периоде', "SUM(booking.rentalPrice) по arrivalDate, тип объекта {$villas}", 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Числитель ADR вилл и домов']);
        $define($revenue, 'rev_villa_checkin_nights', 'Виллы и дома: ночи броней с заездом', MetricUnit::Count, $sum, $api, $marta,
            ['Все ночи броней вилл и домов с заездом в периоде', "SUM(departure − arrival) по arrivalDate, тип объекта {$villas}", 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Знаменатель ADR вилл и домов']);
        $define($revenue, 'rev_adr_villa', 'ADR виллы и дома', MetricUnit::Eur, $formula, $api, $marta,
            ['Средняя цена ночи в виллах и домах по броням с заездом в периоде (check in)', 'Сумма броней вилл и домов с заездом / их ночи', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'rev_villa_checkin_sum / rev_villa_checkin_nights');
        // Rest of Marta's rows (10.11.2026): bookings created in the period split by arrival month, 14+ night stays.
        $define($revenue, 'rev_bookings_count', 'Количество бронирований (созданных)', MetricUnit::Count, $sum, $api, $marta,
            ['Число бронирований, созданных в периоде (в листе — «бронирования на будущие даты»)', 'COUNT(booking) по creationDate', 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        foreach (['this' => ['в этом месяце', 'месяц периода'], 'next' => ['в следующем месяце', 'месяц периода + 1']] as $key => [$label, $rule]) {
            $define($revenue, "rev_created_{$key}_month_count", "Созданные брони: заезд {$label}", MetricUnit::Count, $sum, $api, $marta,
                ["Брони, созданные в периоде, с заездом {$label}", "COUNT(booking) по creationDate, месяц arrivalDate = {$rule}", 'API платформы: GET /api/bookings', 'Неделя, месяц']);
            $define($revenue, "rev_created_{$key}_month_sum", "Созданные брони: сумма с заездом {$label}", MetricUnit::Eur, $sum, $api, $marta,
                ["Стоимость аренды броней, созданных в периоде, с заездом {$label}", "SUM(booking.rentalPrice) по creationDate, месяц arrivalDate = {$rule}", 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        }
        $define($revenue, 'rev_long_count', 'Долгосрочные брони', MetricUnit::Count, $sum, $api, $marta,
            ['Брони от 14 ночей, созданные в периоде', 'COUNT(booking) по creationDate, departure − arrival ≥ 14', 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        $define($revenue, 'rev_long_sum', 'Сумма долгосрочных бронирований', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды броней от 14 ночей, созданных в периоде', 'SUM(booking.rentalPrice) по creationDate, departure − arrival ≥ 14', 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        $define($revenue, 'rev_cancelled_count', 'Отменённые бронирования', MetricUnit::Count, $sum, $api, $marta,
            ['Бронирования, отменённые в периоде', 'COUNT(booking) со status = CANCELLED по дате отмены', 'API платформы: GET /api/bookings (дату отмены фиксирует синк дашборда)', 'Неделя, месяц'],
            $lower);
        $define($revenue, 'rev_cancelled_sum', 'Сумма отменённых бронирований', MetricUnit::Eur, $sum, $api, $marta,
            ['Стоимость аренды броней, отменённых в периоде', 'SUM(booking.rentalPrice) по дате отмены', 'API платформы: GET /api/bookings (дату отмены фиксирует синк дашборда)', 'Неделя, месяц'],
            $lower);
        $define($revenue, 'rev_occupancy', 'Загрузка по гостевым бронированиям', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля ночей гостевых броней (без собственников и блокировок) от доступных к продаже', 'Проданные ночи / Доступные ночи', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'rev_nights_sold / rev_nights_available');
        // Marta (11.11.2026): «Загрузка общая» adds owner stays; free nights = capacity − occupied − blocked.
        $define($revenue, 'rev_owner_nights', 'Ночи собственников', MetricUnit::Count, $sum, $api, $marta,
            ['Ночи проживания собственников в объектах, которые находятся в продаже', 'Ночи броней со status = OWNER внутри периода продаж объекта', 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Числитель общей загрузки']);
        $define($revenue, 'rev_blocked_nights', 'Заблокированные ночи', MetricUnit::Count, $sum, $api, $marta,
            ['Ночи, закрытые блокировками календаря в объектах, которые находятся в продаже', 'Ночи броней со status = UNAVAILABLE внутри периода продаж объекта', 'API платформы: GET /api/bookings, /api/accommodations', 'Неделя, месяц', 'Вычитается из свободных ночей'],
            $lower);
        $define($revenue, 'rev_occupancy_total', 'Загрузка общая (с собственниками)', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля ночей, занятых гостями и собственниками, от ёмкости портфеля в продаже («Загрузка общая» в листе)', '(Проданные ночи + Ночи собственников) / Доступные ночи', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, '(rev_nights_sold + rev_owner_nights) / rev_nights_available');
        $define($revenue, 'rev_free_nights_share', 'Свободные ночи — %', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля ночей, не занятых гостями и собственниками и не закрытых блокировками', '(Доступные − Проданные − Ночи собственников − Заблокированные) / Доступные ночи', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $lower, '(rev_nights_available - rev_nights_sold - rev_owner_nights - rev_blocked_nights) / rev_nights_available');
        // Marta's second batch (11.11.2026): a forecast for the next five days and the traffic light of listings.
        $define($revenue, 'rev_next5_taken_nights', 'Следующие 5 дней: занятые ночи', MetricUnit::Count, TimeAggregation::Last, $api, $marta,
            ['Ночи гостей и собственников в следующие 5 дней после последнего дня периода (на идущей неделе — после сегодняшнего)', 'Ночи броней гостей и собственников в окне 5 дней, только объекты в продаже', 'API платформы: GET /api/bookings, /api/accommodations', 'Значение на дату; прошедшие недели хранят последний прогноз', 'Числитель загрузки на следующие 5 дней']);
        $define($revenue, 'rev_next5_capacity_nights', 'Следующие 5 дней: ёмкость', MetricUnit::Count, TimeAggregation::Last, $api, $marta,
            ['Ночи объектов в продаже в следующие 5 дней после последнего дня периода', 'Σ объектов в продаже × 5 дней', 'API платформы: GET /api/accommodations', 'Значение на дату; прошедшие недели хранят последний прогноз', 'Знаменатель загрузки на следующие 5 дней']);
        $define($revenue, 'rev_occupancy_next5', 'Загрузка на следующие 5 дней', MetricUnit::Percent, $formula, $api, $marta,
            ['Прогноз: доля ночей, уже занятых гостями и собственниками, в следующие 5 дней', 'Занятые ночи следующих 5 дней / Ёмкость следующих 5 дней', 'Расчёт из базовых метрик', 'Значение на дату: неделя и месяц показывают последний прогноз'],
            $higher, 'rev_next5_taken_nights / rev_next5_capacity_nights');
        $define($revenue, 'rev_objects_total', 'Светофор: объекты в продаже', MetricUnit::Count, TimeAggregation::Avg, $api, $marta,
            ['Объекты, которые были в продаже в периоде', 'COUNT(объектов с ночами в продаже в периоде)', 'API платформы: GET /api/accommodations, /api/bookings', 'Неделя; месяц = среднее недель', 'Знаменатель светофора']);
        $define($revenue, 'rev_objects_green', 'Светофор: объекты с загрузкой от 60 %', MetricUnit::Count, TimeAggregation::Avg, $api, $marta,
            ['Объекты, у которых гостями и собственниками занято 60 % ночей периода или больше', 'COUNT(объектов: занятые ночи / ночи в продаже ≥ 60 %)', 'API платформы: GET /api/accommodations, /api/bookings', 'Неделя; месяц = среднее недель']);
        $define($revenue, 'rev_objects_yellow', 'Светофор: объекты с загрузкой до 60 %', MetricUnit::Count, TimeAggregation::Avg, $api, $marta,
            ['Объекты, у которых в периоде занята хотя бы одна ночь, но меньше 60 %', 'COUNT(объектов: 0 < занятые ночи / ночи в продаже < 60 %)', 'API платформы: GET /api/accommodations, /api/bookings', 'Неделя; месяц = среднее недель']);
        $define($revenue, 'rev_objects_red', 'Светофор: объекты без броней', MetricUnit::Count, TimeAggregation::Avg, $api, $marta,
            ['Объекты в продаже, у которых в периоде не занята ни одна ночь', 'COUNT(объектов без ночей гостей и собственников)', 'API платформы: GET /api/accommodations, /api/bookings', 'Неделя; месяц = среднее недель'],
            $lower);
        $define($revenue, 'rev_objects_green_share', 'Светофор 🟢: доля объектов с загрузкой от 60 %', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля объектов в продаже с загрузкой 60 % и выше за период', 'Объекты с загрузкой от 60 % / Объекты в продаже', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'rev_objects_green / rev_objects_total');
        $define($revenue, 'rev_objects_yellow_share', 'Светофор 🟡: доля объектов с загрузкой до 60 %', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля объектов в продаже, занятых в периоде меньше чем на 60 %', 'Объекты с загрузкой до 60 % / Объекты в продаже', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'rev_objects_yellow / rev_objects_total');
        $define($revenue, 'rev_objects_red_share', 'Светофор 🔴: доля объектов без броней', MetricUnit::Percent, $formula, $api, $marta,
            ['Доля объектов в продаже без единой занятой ночи за период', 'Объекты без броней / Объекты в продаже', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $lower, 'rev_objects_red / rev_objects_total');

        // Marketing / Oleg.
        $define($marketing, 'mkt_ad_spend', 'Расход на рекламу', MetricUnit::Eur, $sum, $manual, $oleg,
            ['Расход на рекламные кабинеты (Meta Ads и др.)', 'Σ расходов за период', 'Вручную (Meta Ads — не в Этапе 1)', 'Неделя, месяц'],
            $lower);
        $define($marketing, 'mkt_leads', 'Лиды', MetricUnit::Count, $sum, $bitrix, $oleg,
            ['Новые собственники: сделки воронок «Propietario» и «Online Managmant», кроме холодных звонков', 'COUNT(сделок) по дате создания, источник ≠ Cold call', 'Bitrix24: crm.deal.list (воронки 4, 20)', 'Неделя, месяц', 'Знаменатель CPL']);
        $define($marketing, 'mkt_qualified_leads', 'Квал-лиды', MetricUnit::Count, $sum, $bitrix, $oleg,
            ['Собственники, дошедшие до инспекции объекта', 'COUNT(сделок) по дате первого перехода на «Inspección» или дальше (без отказов и «No localizable»), источник ≠ Cold call', 'Bitrix24: crm.stagehistory.list', 'Неделя, месяц']);
        $define($sales, 'sales_contracts_count', 'Подписанные контракты', MetricUnit::Count, $sum, $bitrix, null,
            ['Собственники, подписавшие договор', 'COUNT(сделок) по дате первого перехода на «Contrato firmado» или дальше (воронки «Propietario» и «Online Managmant»)', 'Bitrix24: crm.stagehistory.list', 'Неделя, месяц = сумма недель']);
        // Cohorts of the month's leads: values per month, growing while the deals progress.
        $define($marketing, 'mkt_new_objects', 'Новые объекты', MetricUnit::Count, TimeAggregation::MonthlyCohort, $bitrix, $oleg,
            ['Собственники из лидов месяца, подписавшие договор (когорта по дате создания сделки)', 'COUNT(сделок месяца, дошедших до «Contrato firmado» или дальше), источник ≠ Cold call', 'Bitrix24: crm.deal.list + crm.stagehistory.list', 'Месяц, когортно: значение растёт, пока сделки месяца двигаются', 'Числитель CV и знаменатель CAC'])
            ->setGranularity(PeriodType::Month);
        $define($marketing, 'mkt_cv_lead_contract', 'CV: лид → договор', MetricUnit::Percent, $formula, $bitrix, $oleg,
            ['Доля лидов месяца, дошедших до договора', 'Новые объекты / Лиды (той же когорты)', 'Расчёт из базовых метрик', 'Месяц, когортно'],
            $higher, 'mkt_new_objects / mkt_leads')
            ->setGranularity(PeriodType::Month);
        $define($marketing, 'mkt_cac', 'CAC', MetricUnit::Eur, $formula, $manual, $oleg,
            ['Стоимость привлечения одного объекта', 'Расход на рекламу / Новые объекты', 'Расчёт из базовых метрик', 'Месяц, когортно'],
            $lower, 'mkt_ad_spend / mkt_new_objects')
            ->setGranularity(PeriodType::Month);
        $define($marketing, 'mkt_cpl', 'CPL', MetricUnit::Eur, $formula, $manual, $oleg,
            ['Стоимость одного лида', 'Расход на рекламу / Лиды', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $lower, 'mkt_ad_spend / mkt_leads');
        $define($marketing, 'mkt_direct_site_sum', 'Прямые бронирования с сайта — сумма', MetricUnit::Eur, $sum, $api, $oleg,
            ['Сумма бронирований, пришедших с сайта Acme', 'SUM(booking.rentalPrice) по дате создания, portal = Acme.app', 'API платформы: GET /api/bookings', 'Неделя, месяц']);

        // CS / Gleb.
        $define($cs, 'cs_incoming_sum', 'Входящие бронирования — сумма', MetricUnit::Eur, $sum, $api, $gleb,
            ['Сумма всех бронирований, созданных за период (все порталы, кроме переселений из-за инцидента)', 'SUM(booking.rentalPrice) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц', 'Знаменатель доли прямых по базе']);
        $define($cs, 'cs_incoming_count', 'Входящие бронирования — количество', MetricUnit::Count, $sum, $api, $gleb,
            ['Число всех бронирований, созданных за период (все порталы, кроме переселений из-за инцидента)', 'COUNT(booking) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        $define($cs, 'cs_direct_base_sum', 'Прямые бронирования по базе — сумма', MetricUnit::Eur, $sum, $api, $gleb,
            ['Сумма прямых бронирований, оформленных CS: порталы Manual, Manual robo, Manual prolongación, Manual reubicación', 'SUM(booking.rentalPrice) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц', 'Числитель доли прямых по базе']);
        // Direct bookings split by the platform portal CS enters them with.
        foreach ([
            'manual' => ['Manual', 'manual'],
            'manual_robo' => ['Manual robo', 'manual robo'],
            'manual_prolongation' => ['Manual prolongación', 'manual prolongación'],
        ] as $key => [$portal, $label]) {
            // Gleb, 11.11.2026: a guest relocated because the owner closed the dates is an ordinary manual booking.
            $with = 'manual' === $key ? ' или «Manual reubicación» (переселение: собственник закрыл даты)' : '';
            $define($cs, "cs_{$key}_sum", "Сумма {$label}", MetricUnit::Eur, $sum, $api, $gleb,
                ["Сумма бронирований с порталом «{$portal}»{$with}", 'SUM(booking.rentalPrice) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц', 'Часть прямых по базе']);
            $define($cs, "cs_{$key}_count", "Количество {$label}", MetricUnit::Count, $sum, $api, $gleb,
                ["Число бронирований с порталом «{$portal}»{$with}", 'COUNT(booking) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц']);
        }
        $define($cs, 'cs_direct_base_share', 'Прямые бронирования по базе — %', MetricUnit::Percent, $formula, $api, $gleb,
            ['Доля прямых бронирований по базе во входящих', 'Прямые по базе / Входящие (суммы)', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $higher, 'cs_direct_base_sum / cs_incoming_sum');
        // Gleb (09.11.2026): the plan of direct bookings is a share (30 %) of the incoming bookings of the same period.
        $m['cs_direct_base_sum']->setPlanFormula('cs_direct_base_share.plan * cs_incoming_sum');
        $define($cs, 'cs_response_minutes', 'Среднее время ответа', MetricUnit::Minutes, TimeAggregation::Avg, $manual, $gleb,
            ['Среднее время первого ответа гостю в чате. Учитываются только чаты, созданные с 09:00 до 24:00; чаты, переданные другому отделу, и выбросы, исключённые руководителем, не учитываются', 'AVG(время первого ответа) по чатам, созданным 09:00–24:00, без переданных', 'Вручную (дашборд SimpleSales)', 'Неделя; месяц = среднее недель'],
            $lower);
        $define($cs, 'cs_calls_total', 'Входящие звонки', MetricUnit::Count, $sum, $bitrix, $gleb,
            ['Все входящие звонки, кроме исключённых руководителем (технический сбой, спам, тестовые, свои)', 'COUNT(звонков DIRECTION = входящий) по времени начала, без подтверждённых исключений', 'Bitrix24: crm.activity.list (TYPE_ID = 2)', 'Неделя, месяц', 'Знаменатель % пропущенных']);
        $define($cs, 'cs_calls_missed', 'Пропущенные звонки', MetricUnit::Count, $sum, $bitrix, $gleb,
            ['Входящие звонки без ответа, кроме исключённых руководителем (технический сбой, спам, тестовые, свои)', 'COUNT(входящих с флагом «пропущенный»), без подтверждённых исключений', 'Bitrix24: crm.activity.list (SETTINGS.MISSED_CALL)', 'Неделя, месяц'],
            $lower);
        $define($cs, 'cs_missed_calls_share', 'Пропущенные звонки — %', MetricUnit::Percent, $formula, $api, $gleb,
            ['Доля пропущенных звонков', 'Пропущенные / Входящие звонки', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $lower, 'cs_calls_missed / cs_calls_total');
        $define($cs, 'cs_calls_lost', 'Потерянные звонки', MetricUnit::Count, $sum, $bitrix, $gleb,
            ['Пропущенные входящие, по которым за 24 часа не было ни исходящего, ни принятого звонка по той же CRM-карточке (лид, сделка, контакт, компания)', 'COUNT(пропущенных входящих без follow-up за 24 ч), без одобренных исключений; звонок попадает в счёт, когда его 24 часа прошли', 'Bitrix24: crm.activity.list (TYPE_ID = 2)', 'Неделя, месяц'],
            $lower);
        $define($cs, 'cs_lost_calls_share', 'Потерянные звонки — %', MetricUnit::Percent, $formula, $bitrix, $gleb,
            ['Доля потерянных звонков (без follow-up за 24 ч) от всех входящих', 'Потерянные / Входящие звонки', 'Расчёт из базовых метрик', 'Пересчитывается на каждом уровне'],
            $lower, 'cs_calls_lost / cs_calls_total');
        $define($cs, 'cs_direct_commission', 'Комиссия с перехваченных бронирований', MetricUnit::Eur, $formula, $api, $gleb,
            ['Комиссия 10 % со всех перехваченных бронирований (manual, manual robo, manual prolongación)', '10 % × сумма перехваченных бронирований (rentalPrice, по дате создания)', 'Расчёт из базовых метрик', 'Неделя, месяц'],
            $higher, '0.1 * cs_direct_base_sum');
        // Gleb, 11.11.2026: a relocation caused by an incident counts nowhere else and is shown as a problem.
        $define($cs, 'cs_relocation_incident_count', 'Переселения из-за инцидента — количество', MetricUnit::Count, $sum, $api, $gleb,
            ['Бронирования с порталом «Manual reubicación por incidencia»: гостя пришлось переселить из-за инцидента. Не входят ни во входящие, ни в manual', 'COUNT(booking) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц'],
            $lower);
        $define($cs, 'cs_relocation_incident_sum', 'Переселения из-за инцидента — сумма', MetricUnit::Eur, $sum, $api, $gleb,
            ['Сумма бронирований с порталом «Manual reubicación por incidencia» (переселение из-за инцидента). Не входит ни во входящие, ни в manual', 'SUM(booking.rentalPrice) по дате создания', 'API платформы: GET /api/bookings', 'Неделя, месяц'],
            $lower);
        // María's outbound sales to the accumulated client base (KPI from Gleb's voice message 04.11.2026):
        // kept in Gleb's Google Sheet, entered by hand until the calls can be pulled from a source.
        $define($cs, 'cs_base_outbound_calls', 'Исходящие звонки по базе (Мария)', MetricUnit::Count, $sum, $manual, $gleb,
            ['Исходящие звонки Марии клиентам из накопленной базы (персональные продажи)', 'Σ звонков за период', 'Вручную (Google-таблица Глеба)', 'Неделя, месяц = сумма недель', 'KPI Марии']);
        $define($cs, 'cs_base_interested_clients', 'Заинтересованные клиенты по базе (Мария)', MetricUnit::Count, $sum, $manual, $gleb,
            ['Клиенты из базы, проявившие интерес по итогам общения с Марией', 'Σ клиентов за период', 'Вручную (Google-таблица Глеба)', 'Неделя, месяц = сумма недель', 'KPI Марии']);

        // Company level: roll-ups of the department metrics (metric_link).
        $companyBookings = $define($company, 'company_bookings_sum', 'Сумма бронирований — компания', MetricUnit::Eur, $sum, $api, $users['ceo'],
            ['Все бронирования компании', 'Σ дочерних метрик', 'Свёртка по дереву', 'Неделя, месяц']);
        $companyAds = $define($company, 'company_ad_spend', 'Расход на рекламу — компания', MetricUnit::Eur, $sum, $manual, $users['ceo'],
            ['Весь рекламный расход', 'Σ дочерних метрик', 'Свёртка по дереву', 'Неделя, месяц'],
            $lower);
        $companyBookings->setTreeAgg(TreeAggregation::Sum);
        $companyAds->setTreeAgg(TreeAggregation::Sum);

        $manager->persist(new MetricLink($companyBookings, $m['rev_bookings_sum']));
        $manager->persist(new MetricLink($companyAds, $m['mkt_ad_spend']));

        // CEO view: key department results in one place. Sums roll up through metric_link,
        // ratios are formulas straight over the department metrics (never averaged).
        $companyLeads = $define($company, 'company_leads', 'Лиды — компания', MetricUnit::Count, $sum, $bitrix, $users['ceo'],
            ['Новые собственники всех отделов', 'Σ дочерних метрик', 'Свёртка по дереву', 'Неделя, месяц']);
        $companyContracts = $define($company, 'company_contracts', 'Подписанные договоры — компания', MetricUnit::Count, $sum, $bitrix, $users['ceo'],
            ['Новые договоры с собственниками', 'Σ дочерних метрик', 'Свёртка по дереву', 'Неделя, месяц']);
        $define($company, 'company_occupancy', 'Загрузка — компания', MetricUnit::Percent, $formula, $api, $users['ceo'],
            ['Доля проданных ночей от доступных', 'Проданные ночи / Доступные ночи (Revenue)', 'Расчёт из метрик Revenue', 'Неделя, месяц'],
            $higher, 'rev_nights_sold / rev_nights_available');
        $define($company, 'company_cpl', 'CPL — компания', MetricUnit::Eur, $formula, $manual, $users['ceo'],
            ['Стоимость лида', 'Расход на рекламу / Лиды (Marketing)', 'Расчёт из метрик Marketing', 'Неделя, месяц'],
            $lower, 'mkt_ad_spend / mkt_leads');
        $define($company, 'company_missed_calls_share', 'Пропущенные звонки — компания', MetricUnit::Percent, $formula, $bitrix, $users['ceo'],
            ['Доля пропущенных входящих звонков', 'Пропущенные / Входящие (CS)', 'Расчёт из метрик CS', 'Неделя, месяц'],
            $lower, 'cs_calls_missed / cs_calls_total');
        $companyLeads->setTreeAgg(TreeAggregation::Sum);
        $companyContracts->setTreeAgg(TreeAggregation::Sum);
        $manager->persist(new MetricLink($companyLeads, $m['mkt_leads']));
        $manager->persist(new MetricLink($companyContracts, $m['sales_contracts_count']));

        // How app:sync:platform computes the Revenue metrics from raw_platform_* (see PlatformMetricConfig).
        // Money = rentalPrice (decision 08.11.2026): rent only, as in «Метрики РНП», not the full booking total.
        $rent = ['amount_field' => 'rental_price'];
        $m['rev_bookings_sum']->setSourceConfig(['calculator' => 'bookings_sum', 'date_field' => 'creation_date', ...$rent]);
        $m['rev_stayed_sum']->setSourceConfig(['calculator' => 'stayed_revenue', ...$rent]);
        $byArrival = ['date_field' => 'arrival_date'];
        $apartments = ['accommodation_types' => ['APARTMENT', 'STUDIO']];
        $m['rev_checkin_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byArrival, ...$rent]);
        $m['rev_apt_checkin_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byArrival, ...$rent, ...$apartments]);
        $m['rev_apt_checkin_nights']->setSourceConfig(['calculator' => 'booking_nights', ...$byArrival, ...$apartments]);
        $m['rev_arrivals_count']->setSourceConfig(['calculator' => 'bookings_count', 'date_field' => 'arrival_date']);
        $m['rev_nights_sold']->setSourceConfig(['calculator' => 'booked_nights']);
        $m['rev_nights_available']->setSourceConfig(['calculator' => 'available_nights']);
        // Owner stays and calendar blocks count only on listings that are on sale (the capacity above).
        $m['rev_owner_nights']->setSourceConfig(['calculator' => 'booked_nights', 'statuses' => ['OWNER'], 'sellable_only' => true]);
        $m['rev_blocked_nights']->setSourceConfig(['calculator' => 'booked_nights', 'statuses' => ['UNAVAILABLE'], 'sellable_only' => true]);
        // Guests and owners take a night; the forecast looks at the five days after the period.
        $taken = ['statuses' => ['CONFIRMED', 'UNPAID', 'INVOICED', 'PAID', 'IN_PROGRESS', 'OWNER']];
        $m['rev_next5_taken_nights']->setSourceConfig(['calculator' => 'booked_nights', ...$taken, 'sellable_only' => true, 'forward_days' => 5]);
        $m['rev_next5_capacity_nights']->setSourceConfig(['calculator' => 'available_nights', 'forward_days' => 5]);
        $m['rev_objects_total']->setSourceConfig(['calculator' => 'objects_by_occupancy', 'band' => 'all']);
        $m['rev_objects_green']->setSourceConfig(['calculator' => 'objects_by_occupancy', 'band' => 'green', 'threshold_pct' => 60, ...$taken]);
        $m['rev_objects_yellow']->setSourceConfig(['calculator' => 'objects_by_occupancy', 'band' => 'yellow', 'threshold_pct' => 60, ...$taken]);
        $m['rev_objects_red']->setSourceConfig(['calculator' => 'objects_by_occupancy', 'band' => 'red', ...$taken]);
        $cancelled = ['date_field' => 'cancelled_at', 'statuses' => ['CANCELLED']];
        $m['rev_cancelled_count']->setSourceConfig(['calculator' => 'bookings_count', ...$cancelled]);
        $m['rev_cancelled_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$cancelled, ...$rent]);
        $villaTypes = ['accommodation_types' => ['VILLA', 'HOUSE', 'TOWNHOUSE', 'COTTAGE']];
        $m['rev_villa_checkin_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byArrival, ...$rent, ...$villaTypes]);
        $m['rev_villa_checkin_nights']->setSourceConfig(['calculator' => 'booking_nights', ...$byArrival, ...$villaTypes]);
        $created = ['date_field' => 'creation_date'];
        $m['rev_bookings_count']->setSourceConfig(['calculator' => 'bookings_count', ...$created]);
        foreach (['this' => 0, 'next' => 1] as $key => $offset) {
            $m["rev_created_{$key}_month_count"]->setSourceConfig(['calculator' => 'bookings_count', ...$created, 'arrival_month_offset' => $offset]);
            $m["rev_created_{$key}_month_sum"]->setSourceConfig(['calculator' => 'bookings_sum', ...$created, ...$rent, 'arrival_month_offset' => $offset]);
        }
        $m['rev_long_count']->setSourceConfig(['calculator' => 'bookings_count', ...$created, 'min_nights' => 14]);
        $m['rev_long_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$created, ...$rent, 'min_nights' => 14]);

        // Portal mapping (decision 06.11.2026): the site is Marketing's, the manual portals are CS's direct bookings.
        $byCreation = ['date_field' => 'creation_date'];
        // Relocations (Gleb, 11.11.2026): «Manual reubicación» is a manual booking, «… por incidencia» counts nowhere.
        $incident = ['Manual reubicación por incidencia'];
        $directPortals = ['Manual', 'Manual robo', 'Manual prolongación', 'Manual reubicación'];
        $m['mkt_direct_site_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byCreation, ...$rent, 'portals' => ['Acme.app']]);
        $m['cs_incoming_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byCreation, ...$rent, 'exclude_portals' => $incident]);
        $m['cs_incoming_count']->setSourceConfig(['calculator' => 'bookings_count', ...$byCreation, 'exclude_portals' => $incident]);
        $m['cs_relocation_incident_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byCreation, ...$rent, 'portals' => $incident]);
        $m['cs_relocation_incident_count']->setSourceConfig(['calculator' => 'bookings_count', ...$byCreation, 'portals' => $incident]);
        $m['cs_direct_base_sum']->setSourceConfig(['calculator' => 'bookings_sum', ...$byCreation, ...$rent, 'portals' => $directPortals]);
        foreach (['manual' => ['Manual', 'Manual reubicación'], 'manual_robo' => ['Manual robo'], 'manual_prolongation' => ['Manual prolongación']] as $key => $portals) {
            $m["cs_{$key}_sum"]->setSourceConfig(['calculator' => 'bookings_sum', ...$byCreation, ...$rent, 'portals' => $portals]);
            $m["cs_{$key}_count"]->setSourceConfig(['calculator' => 'bookings_count', ...$byCreation, 'portals' => $portals]);
        }

        // How app:sync:bitrix computes Marketing / CS metrics from raw_bitrix_* (see BitrixMetricConfig).
        // Owners come as deals of pipelines 4 and 20 (Bitrix leads are no longer used); cold calls are sales work.
        $notColdCalls = ['exclude_owner_sources' => ['Cold call']];
        $m['mkt_leads']->setSourceConfig(['calculator' => 'deals_created', 'categories' => [4, 20], ...$notColdCalls]);
        $m['mkt_qualified_leads']->setSourceConfig(['calculator' => 'deals_reached_stage', 'stages' => [
            'C4:PREPAYMENT_INVOICE', 'C4:EXECUTING', 'C4:FINAL_INVOICE', 'C4:UC_F73YYT', 'C4:UC_G88IW0', 'C4:UC_0IVJRK', 'C4:UC_QFV9OT', 'C4:UC_FO7C6K', 'C4:WON',
            'C20:3', 'C20:UC_W541T6', 'C20:4', 'C20:7', 'C20:8', 'C20:9', 'C20:10', 'C20:UC_0ZXGUT', 'C20:WON',
        ], ...$notColdCalls]);
        // Sales counts every signed owner, cold calls included.
        $m['sales_contracts_count']->setSourceConfig(['calculator' => 'deals_reached_stage', 'stages' => [
            'C4:UC_G88IW0', 'C4:UC_0IVJRK', 'C4:UC_QFV9OT', 'C4:UC_FO7C6K', 'C4:WON',
            'C20:8', 'C20:9', 'C20:10', 'C20:UC_0ZXGUT', 'C20:WON',
        ]]);
        $m['mkt_new_objects']->setSourceConfig(['calculator' => 'deals_cohort_reached_stage', 'categories' => [4, 20], 'stages' => [
            'C4:UC_G88IW0', 'C4:UC_0IVJRK', 'C4:UC_QFV9OT', 'C4:UC_FO7C6K', 'C4:WON',
            'C20:8', 'C20:9', 'C20:10', 'C20:UC_0ZXGUT', 'C20:WON',
        ], ...$notColdCalls]);
        $m['cs_calls_total']->setSourceConfig(['calculator' => 'calls', 'direction' => 'incoming']);
        $m['cs_calls_missed']->setSourceConfig(['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true]);
        // Gleb (11.11.2026): at most 5 % of the incoming calls may be missed; the plan in calls follows the plan of
        // the share and the incoming calls of the same period. A plan set for a period wins over the formula.
        $m['cs_calls_missed']->setPlanFormula('cs_missed_calls_share.plan * cs_calls_total');
        // Gleb (11.11.2026): calls as totals, with the average per day next to them.
        $m['cs_calls_total']->setShowDailyAvg(true);
        $m['cs_calls_missed']->setShowDailyAvg(true);
        $m['cs_calls_lost']->setSourceConfig(['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true, 'no_follow_up_hours' => 24]);

        return $m;
    }

    /**
     * @param array<string, Metric> $metrics
     * @param array<string, User>   $users
     */
    private function loadPlansAndFacts(ObjectManager $manager, array $metrics, array $users): void
    {
        // Facts only up to "today" of the dataset, so November has a current week and run rate.
        $factsUntil = new \DateTimeImmutable('2026-11-04');

        foreach (self::MONTHS as $month) {
            $monthStart = $this->calendar->monthStart(self::YEAR, $month);
            $daysInMonth = $this->calendar->daysInMonth(self::YEAR, $month);

            foreach (self::STORED as $code => $spec) {
                $metric = $metrics[$code];
                $monthlyPlan = new Number($spec['plan']);
                // November of cs_direct_base_sum follows its plan formula (30 % of incoming), as in production;
                // earlier months keep a set plan, which wins over the formula.
                $setPlan = !('cs_direct_base_sum' === $code && 11 === $month);
                if ($setPlan) {
                    $manager->persist(new MetricPlan($metric, PeriodType::Month, $monthStart, $monthlyPlan, $metric->getOwnerUser()));
                }

                foreach ($this->calendar->monthWeeks(self::YEAR, $month) as $week) {
                    $weekShare = new Number((string) $week->days())->div($daysInMonth, 6);
                    $weekPlan = ($monthlyPlan * $weekShare)->round(0);
                    if ($setPlan) {
                        $manager->persist(new MetricPlan($metric, PeriodType::Week, $week->start, $weekPlan, $metric->getOwnerUser()));
                    }

                    if ($week->start > $factsUntil) {
                        continue;
                    }

                    $factor = $spec['min'] + (mt_rand() / mt_getrandmax()) * ($spec['max'] - $spec['min']);
                    $fact = ($weekPlan * new Number(number_format($factor, 4, '.', '')))->round(0);
                    $origin = $metric->isManual() ? ValueOrigin::Manual : ValueOrigin::Import;
                    $manager->persist(new MetricValue($metric, PeriodType::Week, $week->start, $fact, $origin, $metric->isManual() ? $metric->getOwnerUser() : null));
                }
            }

            // Ratio target that drives the plan formula of cs_direct_base_sum.
            $manager->persist(new MetricPlan($metrics['cs_direct_base_share'], PeriodType::Month, $monthStart, new Number('0.3'), $users['gleb']));

            // Average metric: plan and facts are per-week averages, month plan is the same target.
            $response = $metrics['cs_response_minutes'];
            $manager->persist(new MetricPlan($response, PeriodType::Month, $monthStart, new Number('15'), $users['gleb']));
            foreach ($this->calendar->monthWeeks(self::YEAR, $month) as $week) {
                $manager->persist(new MetricPlan($response, PeriodType::Week, $week->start, new Number('15'), $users['gleb']));
                if ($week->start <= $factsUntil) {
                    $minutes = number_format(10 + (mt_rand() / mt_getrandmax()) * 12, 1, '.', '');
                    $manager->persist(new MetricValue($response, PeriodType::Week, $week->start, new Number($minutes), ValueOrigin::Manual, $users['gleb']));
                }
            }
        }
    }

    /**
     * Regular actions of the finance team («метрики отдела .pages», lists «Приход» / «Расход»)
     * with October checks: mostly done, a few gaps so "Невыполненное" and Execution rate have data.
     */
    private function loadFinanceRoutines(ObjectManager $manager, OrgUnit $income, OrgUnit $expense, User $owner): void
    {
        $daily = Periodicity::Daily;
        $weekly = Periodicity::Weekly;
        $monthly = Periodicity::Monthly;

        $routines = [
            [$income, 'Ежедневная работа с приходом', $daily, null],
            [$income, 'Возврат депозитов', $daily, 'на вторые сутки после выезда'],
            [$income, 'Обработка запросов в Битрикс', $daily, 'в течение суток'],
            [$income, 'Сверка авансовых платежей', $weekly, null],
            [$income, 'Сверка мануальных бронирований на корректность комиссии', $monthly, null],
            [$income, 'Ликвидации проведены и выплачены в срок', $monthly, null],
            [$income, 'Фактуры сформированы', $monthly, 'до 20 числа следующего месяца'],
            [$income, 'Перерасчёты с La Vida', $monthly, null],
            [$income, 'Сверка остатков наличных в обоих офисах', $weekly, null],
            [$income, 'Контроль диспутов и споров', $weekly, null],
            [$income, 'Подготовка данных для юристов и налоговой', $monthly, 'своевременно'],
            [$expense, 'Контроль расходов и обработка фактур', $daily, null],
            [$expense, 'Учёт подрядчиков — работа с операционным отделом', $weekly, null],
            [$expense, 'У всех расходных операций есть статья и ответственный', $weekly, null],
            [$expense, 'Ведение Acme Invest', $weekly, null],
            [$expense, 'Работа со сметами', $daily, 'ответ на запрос в течение суток'],
            [$expense, 'Обработка запросов в Битрикс', $daily, 'в течение суток'],
            [$expense, 'Ликвидации проведены и выплачены в срок', $monthly, null],
            [$expense, 'Фактуры сформированы', $monthly, 'до 20 числа следующего месяца'],
        ];

        $sort = 0;
        $n = 0;
        foreach ($routines as [$unit, $title, $periodicity, $due]) {
            $function = new BaseFunction($unit, $title, $periodicity);
            $function->setDueText($due)->setOwnerUser($owner)->setSort($sort += 10);
            $manager->persist($function);

            foreach ($this->periodsOf($periodicity, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')) as $start) {
                // Deterministic gaps: roughly every seventh slot is missed or marked as not done.
                ++$n;
                if (0 === $n % 7) {
                    continue;
                }
                $manager->persist(new BaseFunctionCheck($function, $start, 0 !== $n % 11, $owner));
            }
        }
    }

    /**
     * @return list<\DateTimeImmutable> check period starts of October for fixtures
     */
    private function periodsOf(Periodicity $periodicity, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $starts = [];
        if (Periodicity::Monthly === $periodicity) {
            return [$from];
        }
        if (Periodicity::Weekly === $periodicity) {
            foreach ($this->calendar->monthWeeks((int) $from->format('Y'), (int) $from->format('n')) as $week) {
                $starts[] = $week->start;
            }

            return $starts;
        }
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $starts[] = $day;
            }
        }

        return $starts;
    }

    /**
     * @param array<string, Metric> $metrics
     * @param array<string, User>   $users
     */
    private function loadActions(ObjectManager $manager, array $metrics, array $users): void
    {
        foreach ([
            ['rev_bookings_sum', 'Ежедневно проверять цены конкурентов и корректировать тарифы', Periodicity::Daily, 'marta'],
            ['rev_cancelled_count', 'Связываться с гостями за 7 дней до заезда для подтверждения', Periodicity::Weekly, 'marta'],
            ['mkt_leads', 'Запускать 2 новых креатива в неделю', Periodicity::Weekly, 'oleg'],
            ['cs_response_minutes', 'Разбирать очередь непрочитанных сообщений каждые 2 часа', Periodicity::Daily, 'gleb'],
        ] as [$code, $title, $periodicity, $owner]) {
            $function = new BaseFunction($metrics[$code]->getOrgUnit(), $title, $periodicity, $metrics[$code]);
            $function->setOwnerUser($users[$owner]);
            $manager->persist($function);
        }

        $item = new RoadmapItem($metrics['mkt_cpl'], 'Перенастроить аудитории Lookalike по собственникам');
        $item->setOwner($users['oleg'])
            ->setDueDate(new \DateTimeImmutable('2026-11-20'))
            ->setExpectedEffect('CPL −15% к концу месяца')
            ->setStatus(RoadmapStatus::InProgress);
        $manager->persist($item);
        $manager->persist(new RoadmapEvent($item, RoadmapEventType::Created, $users['oleg']));
        $manager->persist(new RoadmapEvent($item, RoadmapEventType::StatusChanged, $users['oleg'], 'status', 'planned', 'in_progress'));

        $revenue = $metrics['rev_bookings_sum']->getOrgUnit();
        $marketing = $metrics['mkt_leads']->getOrgUnit();

        // Sprints: two past October weeks and the current week of November 2026.
        foreach ([
            [$revenue, '2026-10-19', 'P1', 'Пересчитать тарифы на ноябрьские праздники', 100, 'rev_bookings_sum', 'Тарифы обновлены в 42 объектах'],
            [$revenue, '2026-10-19', 'P2', 'Сверить минимальные цены с таблицей ADR', 60, 'rev_below_floor_share', null],
            [$revenue, '2026-10-26', 'P1', 'Запустить промо «Длинные выходные»', 100, 'rev_bookings_sum', null],
            [$revenue, '2026-11-02', 'P1', 'Разобрать отмены октября по причинам', 30, 'rev_cancelled_count', null],
            [$revenue, '2026-11-02', 'P3', 'Обновить плановый ADR на декабрь', 0, null, null],
            [$marketing, '2026-10-26', 'P1', 'Перезапустить кампанию по собственникам', 100, 'mkt_leads', 'CPL снизился до 22 €'],
            [$marketing, '2026-11-02', 'P2', 'Новый лендинг для собственников', 50, 'mkt_qualified_leads', null],
        ] as [$unit, $week, $priority, $title, $progress, $code, $result]) {
            $task = new SprintTask($unit, new \DateTimeImmutable($week), $title);
            $task->setPriority(SprintPriority::from($priority))->setProgressPct($progress)->setResultNote($result)
                ->setMetric(null === $code ? null : ($metrics[$code] ?? null));
            $manager->persist($task);
        }

        foreach ([[$revenue, 'Планёрка по загрузке на 4 недели вперёд', Periodicity::Weekly], [$revenue, 'Отчёт по выручке за месяц', Periodicity::Monthly], [$marketing, 'Отчёт по рекламным кабинетам', Periodicity::Weekly]] as [$unit, $title, $periodicity]) {
            $manager->persist(new RecurringTask($unit, $title, $periodicity));
        }

        // Error log: significant mistakes only.
        foreach ([
            [$revenue, '2026-10-08', 'Объект открыт к продаже с ценой 35 € вместо 135 € на 3 ночи', ErrorImpactType::Money, '300', 'Опечатка при ручном вводе тарифа', 'Проверка тарифов вторым сотрудником', ErrorStatus::Resolved],
            [$revenue, '2026-10-22', 'Овербукинг: две брони на один апартамент на 24–26.10', ErrorImpactType::Client, null, 'Ручная бронь не внесена в Avantio', null, ErrorStatus::InProgress],
            [$marketing, '2026-10-15', 'Рекламная кампания остановлена на 3 дня из-за неоплаченного счёта', ErrorImpactType::Blocked, null, null, null, ErrorStatus::Open],
        ] as [$unit, $date, $description, $impact, $amount, $cause, $prevention, $status]) {
            $entry = new ErrorEntry($unit, new \DateTimeImmutable($date), $description, $impact, $unit === $revenue ? $users['marta'] : $users['oleg']);
            $entry->setImpactAmount(null === $amount ? null : new Number($amount))->setCause($cause)->setPrevention($prevention)->setStatus($status);
            $manager->persist($entry);
        }
    }
}
