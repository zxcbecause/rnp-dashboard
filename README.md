# РНП-дашборд Acme


## Продукт
Acme управляет арендой апартаментов в Испании. Метрики ведутся по методике «Рука на пульсе» (РНП) в Google-таблице
«Метрики РНП». Этот сервис полностью её заменяет.

- **Дерево метрик по оргструктуре.** Кабинет руководителя = его метрики + свёрнутые метрики его ветки. Топ видит корень.
  Чужие ветки не видны (404).
- **Недельный РНП-экран** на узел: план / факт / % выполнения, цвет (зелёный — выполнен, красный — нет), пороги, run rate.
- **Карточка «Логика показателя»**: что измеряется, формула, источник, период, где используется.
- **График динамики** за произвольный период.
- **Минимум ручного ввода**: данные из API платформы Acme и Bitrix24; ручной ввод помечается «вручную».
- **Действия вокруг метрики**: базовые функции (регулярные, с отметкой), дорожная карта (с историей), Execution rate.
- **Спринты** (недельные задачи отдела) и **журнал ошибок** (только значимые: деньги, блокировки, клиент).

**Дедлайн всего проекта (не только Этапа 1) — 30.11.2026** (уточнено 06.11). **Этап 1:** 2 уровня (Компания → Revenue/Марта, Marketing/Олег, CS/Глеб), 5–10 метрик с
план/факт/цветом, карточки, графики ≥3 периода назад (импорт истории), скрытие/порядок метрик, run rate, базовые функции +
дорожная карта с историей.

## Архитектурные решения (приняты, не пересматривать)
- Отдельный автономный сервис со своей БД. Никаких зависимостей от `../backend-api`, `../platform-frontend`, `../infra` —
  они **только для чтения** как образец конвенций (их `AGENTS.md`). Ничего там не запускать и не менять.
- Локальная разработка; production-стек подготовлен 09.11 по просьбе (см. «Деплой»), сервера ещё нет. CI — не делать,
  пока не скажут.
- **Git: историю не строим** (никаких add/commit/branch/push). Read-only git-команды можно.
- Неочевидные решения (структура, библиотека, отступление от конвенций платформы) — спрашивать пользователя.
- Секретов нет в коде, фикстурах, этом файле и логах. Секреты только в `backend/.env.local` (gitignored).
- Вход — свои учётки (email + пароль, без 2FA). Аутентификация изолирована так, чтобы позже переключиться на вход через
  платформу (отдельный user provider / authenticator).

## Стек
- **backend/**: Symfony 8.1, PHP 8.4, Doctrine ORM 3.7, MariaDB, LexikJWT + Gesdinet refresh, NelmioApiDoc, Codeception,
  PHPStan level 8, PHP-CS-Fixer `@Symfony`.
- **frontend/**: Nuxt 4 SPA (`ssr: false`), TypeScript, Pinia 3, PrimeVue 4, Tailwind 4 CSS-first, i18n (ru — основной, en).
- **Всё только через docker compose** (на хосте нет PHP/Composer/Node). `make` на хосте (Windows) может отсутствовать —
  тогда команды из `Makefile` выполнять напрямую.

## Структура репозитория
```
docker-compose.yml   общий compose, сеть rnp-net
Makefile             шорткаты команд
docker/              php/Dockerfile, php/conf.d, nginx/default.conf
backend/             Symfony
frontend/            Nuxt
```
Контейнеры: `rnp-php` (fpm), `rnp-nginx` → http://localhost:8081, `rnp-mariadb` → localhost:3308 (БД `rnp`, тестовая
`rnp_test`), `rnp-frontend` → http://localhost:3001. Порты 80, 8080, 3307, 8025 заняты платформой — не использовать.

## Конвенции (из AGENTS.md платформы)
Backend:
- Контроллер тонкий → бизнес-логика в `src/Service/**/*Handler.php`.
- Request DTO в `src/DTO/Request/`, response DTO в `src/DTO/` с `static fromEntity()`.
- Деньги и точные значения — `BcMath\Number` / DECIMAL, **не float**.
- **Новые методы — в конец класса.**
- Doctrine 3.7: `\SortDirection` в `orderBy`, `OffsetPaginator` вместо `Paginator`.
- Область видимости — единственный источник правды `AccessScopeResolver` (fail-closed). Чужое → 404, а не 403.
- Тесты — Codeception (`--env=test`). `doctrine:schema:validate` всегда зелёный. «Должно работать» не принимается.

Frontend:
- Feature-based: `features/<name>/{api,components,composables,stores,types}`.
- Мутации — через `useAsyncAction` (single-flight), `pending` → `:loading` и `:disabled`.
- Перекрывающиеся загрузки — `createLatestRequestScope()`.
- Прокси `/api/*` через Nitro (`server/api/[...].ts`). JWT в памяти (Pinia), refreshToken в httpOnly cookie.
- Каждый новый top-level маршрут регистрируется в конфиге доступа маршрутов.

## Модель данных
| Таблица | Ключевые поля |
|---|---|
| `user` | email, name, roles, is_active |
| `org_unit` | parent_id, name, head_user_id, sort |
| `org_unit_path` | ancestor_id, descendant_id, depth (closure table, включая self с depth 0) |
| `org_membership` | user_id, org_unit_id, role (head / member / viewer / admin) |
| `metric` | org_unit_id, code (unique), name, unit (eur/count/percent/minutes/ratio), direction (higher_better/lower_better), time_agg (sum/avg/last/formula/monthly_cohort), tree_agg (sum/weighted_avg/formula/none), formula, source_type (manual/platform_api/bitrix/push_api), source_config (json), granularity (day/week/month), filler_user_id, owner_user_id, is_active, sort; карточка: what_measured, formula_text, source_text, period_text, used_in |
| `metric_link` | parent_metric_id, child_metric_id (метрика отдела = свёртка дочерних) |
| `metric_value` | metric_id, period_type, period_start, value DECIMAL(18,4), origin (manual/auto/import), entered_by, entered_at; UNIQUE(metric_id, period_type, period_start) |
| `metric_plan` | metric_id, period_type, period_start, value, set_by |
| `metric_threshold` | metric_id NULL = по умолчанию, green_from_pct, red_below_pct |
| `user_metric_pref` | user_id, metric_id, visible, sort |
| `base_function` / `base_function_check` | metric_id, title, periodicity, owner_user_id / period_start, done, note |
| `roadmap_item` / `roadmap_event` | metric_id, title, owner, due_date, status, expected_effect / каждая смена статуса/поля + вывод «повлияло / нет» |
| `recurring_task` | org_unit_id, title, periodicity |
| `sprint_task` | org_unit_id, week_start, tag, priority (P1–P5), title, metric_id NULL, progress_pct, result_note, link, next_step, carried_from_id |
| `error_entry` | org_unit_id, occurred_on, reporter_id, description, impact_type (money/blocked/client/other), impact_amount, cause, prevention, owner_id, status |
| `sync_run` | source, started_at, finished_at, status, rows, error |
| `raw_*` | снимки из API платформы и Bitrix — делаются вместе с интеграциями |

## Доступ
- `head` — свой узел + все потомки (через closure table).
- `member`, `viewer` — только свой узел.
- `admin` — всё.
- Запрос чужого узла/метрики → **404**.

## Правила расчёта (не нарушать)
- Две свёртки: по времени (день → неделя → месяц) и по дереву (сотрудник → отдел → компания).
- **Отношения (CV, CPL, CAC, ADR, % жалоб, загрузка) не хранятся и не усредняются** — всегда пересчитываются из числителя
  и знаменателя на каждом уровне (`time_agg = formula`).
- `last` — состояние на дату: последнее значение периода; по дереву — сумма.
- `monthly_cohort` — только помесячно по когорте лидов (новые объекты, CV лид → договор, CAC).
- **% выполнения** = факт / план × 100; для `lower_better` инвертируется. Зелёный ≥ green_from_pct, красный < red_below_pct.
- **Run rate** = факт с начала месяца / прошедшие дни × дней в месяце (для sum-метрик).
- **Execution rate** = выполнено (базовые функции + дорожная карта + спринт) / запланировано за период.
- Ноль — это 0, а не пусто. Факт месяца = сумма фактов недель.
- Недели пн–вс; неделя относится к месяцу по своим дням — крайние недели режутся границей месяца
  («Неделя 1: 01.11», «Неделя 6: 30.11»).
- Жёсткая типизация ввода по unit. Проценты храним долей (0.45). Деньги — DECIMAL, валюта EUR.

## Интеграции
**API платформы Acme** (Swagger `/api/doc.json`): `POST /api/login` → JWT (15 мин) + refreshToken (скользящие 30 мин),
`POST /api/token/refresh`. Учётка `dashboard_client` — `ROLE_MACHINE` + `ROLE_REVENUE_MANAGER`, только чтение, все данные.
Эндпоинты (пагинация `page,size` → `items, page, pagesTotal, itemsTotal`):
- `GET /api/bookings` — `arrivalFrom/To, departureFrom/To, creationDateFrom/To, status, portal, accommodationId`;
  поля `total, rentalPrice, portalCommission, arrivalDate, departureDate, creationDate, status, portal, currency`
  (схему сверять с `../backend-api/src/Entity/Business/Booking.php`);
- `GET /api/housekeeping-tasks` — `status, type, dueDateFrom/To`;
- `GET /api/tickets` — `type (service|improvement|incident), status, priority, dateFrom/To, price`;
- `GET /api/accommodations` — `name, status, type` (даты продаж — только в `GET /api/accommodations/{id}`);
- позже: `/api/payments`, `/api/expenses`, `/api/invoices`.
Конфиг: `PLATFORM_API_URL`, `PLATFORM_API_USERNAME`, `PLATFORM_API_PASSWORD`.

**Bitrix24** (входящий вебхук, scope `crm`, `BITRIX_WEBHOOK_URL`): воронки сделок 4 «Propietario», 20 «Online Managmant»
(«Nuevo propietario» → «Contrato firmado» / «Cerrado Ganado»), 0 «Booking». Лиды: Unassigned → In Progress → Processed →
Good lead (= квал-лид) → Junk Lead; источники Facebook, Lead-form, MT-landing-propietarios. Звонки: `crm.activity.list`
`TYPE_ID = 2`. Лимит ~2 req/s — `batch` и пагинация `start`.

**Push API**: `POST /api/ingest/metric-values`, авторизация по токену источника.

**Meta Ads, Huntflow** — не в Этапе 1, ручной ввод.

## Реализация (решения каркаса)
- **Сущности** сгруппированы: `Entity/Auth` (User, RefreshToken), `Entity/Org`, `Entity/Metric`, `Entity/Action`,
  `Entity/Journal`, `Entity/Sync`; enum-ы в `src/Enum`. Таблица пользователей — `` `user` `` (в кавычках).
- **Closure table** пишет только `Service/Org/OrgTreeManager::createUnit()` (self-строка depth 0 + пути предков).
  Перенос — `OrgTreeManager::moveUnit()` (11.11): пути старых предков к ветке удаляются, от новых — вставляются
  (DBAL, сущность путей read-only); в свою ветку и корень — отказ. Консоль `app:org move-unit <unit-id> <parent-id>`.
- **Недели**: `Service/Period/PeriodCalendar` — сегменты пн–вс, обрезанные границей месяца. Недельный период
  (`period_type = week`) идентифицируется **датой начала сегмента**, а не понедельником (01.11.2026 — воскресенье).
- **Хранимые vs формульные метрики**: значения пишутся только для base-метрик; формульные (`time_agg = formula`)
  ссылаются на коды числителя/знаменателя. Для этого в фикстурах есть вспомогательные метрики (проданные/доступные ночи,
  звонки всего/пропущенные, прямые по базе — сумма).
- **Пороги** `metric_threshold` хранятся в процентных пунктах выполнения (100.00 = план), `metric_id NULL` — дефолт.
- **Доступ**: `Security/Access/AccessScopeResolver` (fail-closed, кеш на запрос) → `AccessScope`; `OrgUnitVoter`,
  `MetricVoter`; handler-ы бросают одинаковый 404 для чужого и несуществующего. Глобальный `ROLE_ADMIN` или членство
  `admin` = всё дерево; `member` = `viewer`.
- **Ошибки API** — JSON `{"error": "...", "violations"?: {...}}` (`EventSubscriber/ApiExceptionSubscriber`);
  ошибки JWT-бандла — `{"code", "message"}`.
- **Auth**: `/api/login` (json_login, throttling 5/15 мин, кроме test), `/api/token/refresh` (single use, 30 мин
  скользящее окно), `/api/logout` (`{"refreshToken"}` → удаление), JWT 15 мин. Неактивные — `ActiveUserChecker`.
- **Фронт**: Nitro-хэндлеры `server/api/{login,logout,token/refresh}` держат refresh-токен в httpOnly cookie
  `rnp_refresh` + подсказку `rnp_session`; остальное — `server/api/[...].ts`. `runtimeConfig.apiBase` — только серверный.
  Между вкладками (11.11, `features/auth/utils/crossTab.ts`): refresh под Web Lock `rnp-auth-refresh` (браузер шлёт
  cookie в момент запроса — ждавшая вкладка идёт с уже ротированным токеном; без этого проигравший получал 401, Nitro
  стирал cookie и выбивало все вкладки), выход рассылается BroadcastChannel `rnp-auth` (`plugins/auth-tabs.client.ts`).
  Web Locks есть только в защищённом контексте (HTTPS/localhost) — поэтому в e2e Playwright сидит в сети фронта
  (`network_mode: service:frontend`, `BASE_URL=http://localhost:3000`).
- **Тесты**: Codeception `Functional` (ядро Symfony + БД `rnp_test`, пересоздаётся из миграций расширением
  `TestDatabaseExtension`, каждый тест — в откатываемой транзакции) и `Unit`. Фронт — Vitest (happy-dom).
  В `codeception.yml` **нельзя** возвращать `params: [.env, .env.test]`: тогда процесс получает `APP_ENV=dev`,
  грузится `.env.local` и тесты молча работают на локальных секретах.
- **E2E (с 09.11)**: Playwright 1.49.1 в Docker, `sh e2e/run.sh [-g "фильтр"]` / `make e2e`. Одноразовый стенд
  `docker-compose.e2e.yml` (проект `rnp-e2e`): MariaDB в tmpfs, бэкенд — dev-образ php + том `rnp-backend-vendor` в
  `APP_ENV=test` (не грузит `.env.local`, интеграции — моки, без лимита входа; БД `rnp_e2e_test`), миграции + фикстуры,
  фронт — production-сборка текущего кода (`NUXT_COOKIE_SECURE=false`, http внутри сети); после прогона `down -v`.
  Тесты в `e2e/tests` (вне `frontend/`, чтобы не попадать в его lint/typecheck): вход/сессия/выход, РНП (план+факт →
  200 %, неверное значение, страница метрики, наблюдатель read-only и 404 чужого отдела), отметка функции, журнал
  ошибок, спринт; с 11.11 — исключение пропущенного звонка (заявка → колокольчик → подтверждение → API РНП:
  пропущенных 1 из 2) и алерт «не внесены факты» (ссылка → ввод → алерт исчез), `e2e/seed.sql` (2 пропущенных
  звонка «позавчера», грузится после фикстур). С 11.11 ещё: 4 вкладки одновременно восстанавливают сессию (без
  блокировки падает), выход в одной вкладке выкидывает остальные; админка; телефон; план по формуле. 21 тест ~3 мин. Проверены мутацией (потолок % 200→150; один факт из двух — тест алерта падает). Отчёт —
  `e2e/playwright-report` (gitignored). Нужен один раз поднятый dev-стек (образ php, vendor, `config/jwt/test`).
- **Фикстуры** перезагружаются повторно благодаря `DataFixtures/Purger/ForeignKeySafePurgerFactory`
  (FK-проверки выключены только на время purge; self-FK `org_unit.parent_id` остаётся RESTRICT).

## Локальные учётки (фикстуры, пароль `rnp-local-123`; **из dev-базы удалены 11.11** перед переносом на прод —
метрики переданы руководителям, бэкап `backend/var/before-test-users-delete-2026-11-11.sql`; есть только в
тестах/e2e. Для скриншотов в dev — временно `app:user create`, потом `delete`)
`admin@rnp.test` (ROLE_ADMIN), `ceo@rnp.test` (head компании), `marta@rnp.test` (head Revenue), `oleg@rnp.test`
(head Marketing), `gleb@rnp.test` (head CS), `revenue.member@rnp.test` (member), `marketing.viewer@rnp.test` (viewer),
`inactive@rnp.test` (отключён).

## Dev-фронт в Docker на Windows (важно)
- События файловой системы из Windows в контейнер не доходят: в `nuxt.config.ts` при `WATCH_POLLING=true` включён polling
  и у Vite (`vite.server.watch`), и у Nuxt (`watchers.chokidar`). Без второго новые/удалённые страницы не попадают в
  карту маршрутов → **белый экран** (так было 04.11: `pages/metrics.vue` перенесён, а маршруты ссылались на старый файл).
- Если всё же белый экран: `docker compose logs frontend` → затем
  `docker compose exec frontend sh -c 'rm -rf node_modules/.cache/vite .nuxt' && docker compose restart frontend`.
- `nuxt build` такие ошибки не ловит (собирает маршруты заново) — после изменений страниц проверять сам dev-сервер.

## Команды
`make <target>` (список — `make help`); на Windows без make — команда после имени цели в `Makefile`.
```bash
# первый запуск: сборка, .env.local со случайными секретами, JWT-ключи, миграции, фикстуры
docker compose up -d --build
docker compose run --rm php bash bin/init-dev.sh
docker compose exec php php bin/console doctrine:migrations:migrate -n
docker compose exec php php bin/console doctrine:fixtures:load -n

# backend QA
docker compose exec php php bin/console doctrine:schema:validate
docker compose exec php vendor/bin/codecept run
docker compose exec php vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

# интеграция с платформой (нужны PLATFORM_API_* в backend/.env.local)
docker compose exec php php bin/console app:sync:platform [--resource=bookings] [--full] [--recalc-from=2026-09-01]
docker compose exec php php bin/console app:metrics:recalculate [--from=2026-09-01 | --months=12]
docker compose exec php php bin/console app:sync:bitrix [--resource=calls] [--full] [--recalc-from=2026-09-01]

# frontend QA (контейнер frontend запущен)
docker compose exec frontend npm run lint
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run test
```
URL: фронт http://localhost:3001, API http://localhost:8081/api, Swagger http://localhost:8081/api/doc.

## Push API (бэклог п.7 — сделано 05.11.2026)
- `POST /api/ingest/metric-values` `{"values": [{"metric": code, "date": "Y-m-d", "value": "8.5"|42|null}]}`, до 500
  элементов, **всё или ничего** (422 с `violations` по путям `values[i].metric|date|value`); ответ
  `{written, unchanged, removed}`. `date` — любой день периода → сегмент недели / месяц по granularity метрики; будущий
  период — отказ; `value` — строка-десятичное (числа принимаются, float → кратчайшая запись), типизация
  `MetricValueNormalizer`, `null` удаляет факт. `origin = auto`; каждый запрос → `sync_run` (push_api, scope = имя токена).
- Решение 05.11: пишет **только в метрики `source_type = push_api`** (активные, не формулы) ветки отдела токена;
  ручная/чужая/несуществующая/формульная — одинаково «Unknown metric». Чтобы подключить скрипт к ручной метрике —
  переключить ей источник на push_api.
- Токен (`Entity/Sync/IngestToken`): привязан к отделу (+ потомки), хранится только SHA-256 и первые 12 символов,
  формат `rnp_` + 48 hex. Выдаёт админ: `app:ingest-token create <name> <org-unit-id>` (токен печатается один раз),
  `app:ingest-token list`, `app:ingest-token revoke <name>`. Экрана управления токенами нет.
- Безопасность: отдельный файрвол `api_ingest` (`^/api/ingest/`, `Security/Ingest/IngestTokenAuthenticator`,
  пользователь `IngestClient` с `ROLE_INGEST`); JWT там не принимается, а токен источника не открывает остальной `/api`.
  Ошибка — 401 `{"error": "Invalid or revoked ingest token."}`.
- В фикстурах push_api-метрик нет.

## Статус данных и когорты (05.11.2026)
- `GET /api/data-status` (`Service/Sync/DataStatusHandler`): по источникам platform_api и bitrix — `configured`,
  `dataAsOf` (самый старый «последний успех» среди ресурсов), `stale` (> 2 ч без успеха или ресурс ни разу не
  синкался), `failing` (последний запуск ресурса упал), ресурсы; текст ошибки — только `ROLE_ADMIN`. Push/импорт не
  показываются. Фронт: `features/sync/components/DataStatusBar.vue` на `/rnp-week` (время — Мадрид, обновление раз в
  5 мин; цвета: красный — устарело, жёлтый — синк упал, но данные свежие).
- Когорты (`time_agg = monthly_cohort`, granularity month): `AutoMetricRecalculator` пишет по одному значению за месяц
  (текущий — по сегодня), пересчитывает все месяцы окна (когорта растёт). Bitrix-калькулятор
  `deals_cohort_reached_stage` — сделки, созданные в месяце, которые когда-либо дошли до стадий.
  Метрики Marketing: `mkt_new_objects` (когорта, договор), `mkt_cv_lead_contract` = new_objects / leads,
  `mkt_cac` = ad_spend / new_objects (обе формулы помесячно). Планы — из «Метрики РНП».

## Редизайн фронта (05.11.2026)
- Сделано: токены палитры (`assets/css/main.css`: `--rnp-*` + Tailwind `@theme` `rnp-*`, анимации `page-*`,
  `.rnp-rise` с `--i`, `.rnp-fade`, `.rnp-skeleton`, `.rnp-card-hover`, reduced-motion), пресет PrimeVue (тёплые
  surface, пилюли), логотип `public/logo(-light).svg` (копия из платформы), layout (тёмный сайдбар со
  сворачиванием/`localStorage`/<1024 px, скользящая пилюля; шапка: заголовок раздела, поиск метрик, колокольчик,
  пилюля пользователя с меню), `features/ui/*` (UiCard, UiPageHeader, UiPill, UiPillSelect, UiStatusBadge,
  UiProgressRing, UiGauge, UiKpiCard, UiSkeleton, `useCountUp`), «Мой кабинет» (KPI = первые 4 метрики личного
  порядка, тренд к прошлой неделе — для сумм в день; «Метрики ветки», gauge, Run rate, Execution rate, невыполненное),
  страница `/admin/sync`. Переход страниц выключен у `/login` (смена layout).
- 09.11 — РНП-неделя: `UiPageHeader` с пилюлями (отдел, неделя, «Настроить вид»), `DataStatusBar` пилюлей, таблица в
  `UiCard` (заголовок — неделя и месяц, фишки-фильтры по статусу с количеством), колонки недели/месяца — тонированные
  блоки, полоска статуса слева у метрики, `CompletionBadge` — пастельная пилюля (neutral = жёлтый «риск»), скелетон,
  каскад строк один раз, дальше кроссфейд. Проверено скриншотом (headless Chrome + puppeteer-core в scratchpad) на 1280
  и 1440 без горизонтального скролла страницы.
- 09.11 — Метрика (`metrics/[id].vue`): шапка-карточка (название, код, пилюли-теги; справа последний **завершённый**
  период с фактом: факт / план, тренд к предыдущему — для sum в день, бейдж, кольцо), `MetricChart` в `UiCard`
  (сегментные пилюли периода и диапазона, «Год назад» — пилюля; цвета палитры, план — тёмный пунктир; эмитит `loaded`
  с точками для шапки), `MetricCard` строками с разделителями. Тексты карточек денежных метрик исправлены на
  `SUM(booking.rentalPrice)` (фикстуры + dev). Базовые функции и дорожная карта на странице метрики не выводятся.
- 09.11 — Действия: шапка как у РНП-недели (`WeekNavigator` — `weekIndex` необязателен, «текущая» неделя считается
  по сегодняшней дате), Execution rate — карточки с кольцом, базовые функции / дорожная карта / невыполненное в `UiCard`,
  отметки `CheckSlot` — пилюли палитры, статусы карты — `UiStatusBadge`, фильтр «В работе / Все» — сегментная пилюля.
- 09.11 — Спринты: шапка с `WeekNavigator` (неделя пн–вс, без номера), задачи в `UiCard` «Задачи недели …» с
  действиями-пилюлями (регулярные, подтянуть, перенести, + задача), кольцо «выполнено N из M», приоритет — пилюля
  (P1 красный, P2 жёлтый, прочие серые), прогресс — своя полоска (100 % зелёная) и пилюли 0/50/100, выполненные
  зачёркнуты. `UiPill` получил стиль `disabled`.
- 09.11 — Журнал ошибок: в шапке период — одна пилюля с двумя нативными `input[type=date]` (класс `.rnp-date-input`
  в main.css), «+ Записать ошибку»; 3 `UiKpiCard` (всего, не закрыто, ущерб €) + карточка «По последствиям»; записи в
  `UiCard`: полоска и пилюля цвета последствий (деньги красный, блокировка оранжевый, клиент жёлтый, другое серый),
  статус — `UiStatusBadge` (открыта красный, в работе жёлтый, закрыта зелёный). С 09.11 журнал узла включает записи
  всех **видимых** пользователю отделов ветки (head — вся ветка, member — свой узел; `ErrorEntryDto.orgUnitId/Name`,
  на фронте у чужих записей пилюля отдела), тест `errorLogOfAUnitIncludesTheVisibleBranch`.
- 09.11 — Логин: две половины (lg+): слева тёмная панель-«монитор» (сетка, жёлтое свечение, логотип, «Рука на
  пульсе», три пилюли-фичи), анимация `features/auth/components/PulseLine.vue` — ЭКГ-ритм, по которому бежит яркий
  сегмент с точкой (SMIL: `stroke-dashoffset` + `animateMotion` на одном таймлайне, `pathLength=1`; при reduced motion —
  статичная линия; `glow` выключается на светлом фоне); справа карточка формы. На мобильном — логотип, линия без
  свечения и форма. Layout `auth` теперь только фон. Точка в бейдже «бьётся» двойным ударом (CSS).
  Правая половина (переделана 09.11 по просьбе — была «слишком простой»): тоже тёмная (`#1a1917`) с продолжением
  сетки и жёлтым свечением, стеклянная карточка (полупрозрачный фон, тонкая рамка, жёлтый блик сверху), иконка-пульс
  в жёлтом квадрате, тёмные поля PrimeVue через `:deep` в `.login-form` (фокус — жёлтое кольцо, автозаполнение
  Chrome перекрашено), под формой «данные обновляются каждые 30 минут» с зелёной точкой; между половинами
  вертикальная линия с бегущим импульсом. Мобильный — весь экран тёмный, линия пульса со свечением.
- 09.11 — Кабинет без `?week` показывает **последнюю завершённую неделю** (`lastFinishedWeekDate` в
  `features/cabinet/utils/overview.ts`: сегмент до max(понедельник, 1-е число); тесты в `overview.spec.ts`), в
  подзаголовке «последняя завершённая неделя»; «Текущая неделя» в навигаторе открывает идущую неделю (`?week=` сегодня).
  Execution rate и невыполненное — за ту же неделю.
- 09.11 — общая проверка (puppeteer-core + Chrome хоста, скрипты в scratchpad, вход под временно включённым
  `admin@rnp.test`): 9 страниц × 1000/1280/1366/1440/1920 — горизонтального скролла нет, ошибок консоли нет; < 1024 px
  сайдбар 72 px; при `prefers-reduced-motion` `.rnp-rise`, скелетоны, hover, переходы страниц и кроссфейд — 1 мс,
  на логине SMIL не рендерится, JS-анимации (`useMotion`) проверяют media query. По итогам: `/metrics` без
  `PagePlaceholder` (удалён), таблица спринтов `min-w-[760px]`.
- 09.11 — Токены в новом стиле (`UiPageHeader`, таблица в `UiCard`, статус — `UiStatusBadge`, новый токен —
  жёлтая карточка с копированием). Редизайн завершён (отчёт решено не делать).
- 09.11 — Страница метрики: карточка «Действия по метрике» (`features/metrics/components/MetricActions.vue`) —
  базовые функции с отметками недели и пункты дорожной карты этой метрики из `GET /org-units/{id}/actions` её отдела
  (фильтр по `metric.id`), «+ пункт» (диалог с одной этой метрикой), ссылка на все действия отдела; при 404 скрыта.
- Регина посмотрела кабинет через туннель 05.11 (туннель остановлен; у неё пока ROLE_ADMIN, preview на 3002).

## Телефон (11.11.2026, по просьбе пользователя — раньше мобильную версию откладывали)
- < 768 px (`useSidebar().mobile`, читается синхронно — без мигания рейки): бокового меню нет, в шапке ☰ открывает
  шторку (280 px, затемнение, закрывается при переходе / тапе мимо / Esc); шапка 64 px, отступы 16 px. 768–1023 px —
  как раньше (рейка 72 px).
- РНП: на телефоне вместо таблицы `features/rnp/components/RnpCards.vue` — карточка метрики с блоками «Неделя» и
  «Месяц» (те же `EditableValue`), run rate и темп; рендерится **одно из двух** (не скрытие CSS — иначе дубли в DOM).
  Теги — общая `features/rnp/utils/tags.ts`.
- Спринты — таблица перестраивается в блоки через `max-md:` (без дублирования разметки); кабинет «Метрики ветки» и
  «Пользователи» — лишние колонки скрыты на телефоне; навигатор недель и KPI-карточки переносятся.
- e2e `mobile.spec.ts` (390×844): шторка, ввод плана и факта в карточке → 200 %, нет горизонтальной прокрутки.

## РНП-экран (бэклог п.4 — сделано: неделя, ввод, карточка, график)
- `GET /api/org-units/{id}/rnp?week=Y-m-d` (`Service/Rnp/RnpWeekHandler`): все активные метрики узла; неделя
  (план/факт/%/цвет; `null` у помесячных), месяц (план, факт на дату = сегменты до показанной недели), run rate.
- Значения: `Service/Metric/Values/MetricValueResolver` (+ `MetricDataLoader` грузит метрики, компоненты формул
  и детей `metric_link` транзитивно, значения, планы, пороги). Формулы — `Formula/FormulaEvaluator`
  (безопасный парсер: коды, числа, `+-*/()`; нет операнда или деление на 0 → нет значения).
  Месяц из недель по `time_agg`: sum / avg / last; отношения пересчитываются, не усредняются. Недельный план без
  явного: месячный × дни сегмента / дни месяца (sum) или тот же (avg/last). Свёртка: сумма детей.
- % выполнения (`Service/Metric/Completion`): higher — факт/план; lower — план/факт, **потолок 200 %**;
  **план 0**: higher → 100; lower → 100 при факте 0, иначе 0. Цвет: ≥ green_from — green, < red_below — red, иначе neutral.
- Run rate — только недельные sum-метрики без формулы: факт месяца на дату / прошедшие дни × дней в месяце
  (прошедшие дни — до конца показанной недели или до сегодня). % run rate — от плана месяца.
- Ввод: `PUT /api/metrics/{id}/facts|plans` `{periodType, periodStart, value|null}` (`MetricValueWriteHandler`).
  Факт — только manual и не формула, период = granularity метрики, начало сегмента/месяца; план — любой метрики.
  Типизация — `MetricValueNormalizer` (eur 2 знака; count целое ≥0; percent доля 0..10; days/minutes ≥0; boolean 0/1).
  Права (`MetricEditPolicy` + `AccessScope::canEnterFacts/canSetPlans`): факт — head ветки, member своего узла,
  filler метрики, admin; план — head ветки и admin; viewer — только чтение. Чужое — 404.
- `GET /api/metrics/{id}/series?period=week|month&from&to` — точки графика (≤ 24 мес.).
- Фронт: `pages/rnp-week.vue` (выбор отдела + неделя в URL `?unit=&week=`), `features/rnp/*` (таблица, ввод по
  клику в ячейке, Enter/Esc), `pages/metrics/[id].vue` (график PrimeVue Chart/chart.js + «Логика показателя»).
  Проценты вводятся в п.п. («45»), в API уходят долей — `utils/metricFormat.ts` (строковая арифметика, без float).
- Скрытие/порядок метрик — **личный вид пользователя** (`user_metric_pref`, `Service/Metric/MetricViewPrefs`):
  `PUT /api/org-units/{id}/metric-view` `{items: [{metricId, visible}]}` — ровно все активные метрики узла в нужном
  порядке, хотя бы одна видимая; `DELETE` — сброс. Настраивать может любой, кто видит узел (viewer тоже) — данные и
  права не меняются. RNP отдаёт видимые в личном порядке + `hiddenCount`; `?showHidden=true` — все с флагом `hidden`.
  Метрики без настройки (например, новые) идут после упорядоченных в порядке `metric.sort`.
  Фронт: кнопка «Настроить вид» → `features/rnp/components/MetricViewDialog.vue` (галочки + стрелки).
- Не сделано из п.4: сравнение год к году (сделано 05.11). E2E — сделаны 09.11.

## Действия: базовые функции, дорожная карта, Execution rate (бэклог п.5 — сделано)
- `base_function` принадлежит **отделу** (`org_unit_id`), метрика необязательна (той же единицы); `due_text` — срок
  внутри периода текстом («до 20 числа»). Периоды отметок (`Service/Action/ActionPeriods`): daily — рабочие дни
  пн–пт; weekly — сегменты недели как в РНП; monthly; quarterly. Отметка = `base_function_check.period_start` = начало
  периода; срок (due) — последний день периода. Будущий период отметить нельзя.
- `GET /api/org-units/{id}/actions?week=` (`ActionsWeekHandler`): функции с отметками недели (state: done / not_done /
  missed / pending / future), дорожная карта с историей (открытые сначала), Execution rate недели и месяца,
  «Невыполненное» за 30 дней, метрики и люди отдела (исполнители: участники ветки и предков).
- **Execution rate** = выполнено / запланировано. Запланировано: отметки и пункты карты со сроком в периоде, срок
  которых уже наступил или которые уже выполнены; отменённые пункты не считаются. Месячная функция попадает в
  неделю, где кончается месяц. Спринты в формулу добавятся с п.6.
- Запись (`ActionWriteHandler`): `POST /api/org-units/{id}/base-functions`, `PUT /api/base-functions/{id}` (полная
  замена, `isActive=false` — скрыть), `PUT /api/base-functions/{id}/checks` `{periodStart, done|null, note}`,
  `POST /api/metrics/{id}/roadmap`, `PUT /api/roadmap-items/{id}` (полная замена), `POST /api/roadmap-items/{id}/effect`
  (только после `done`). Каждое изменение пункта карты → `roadmap_event` (created / status_changed / field_changed с
  old→new / effect_assessed).
- Права (`ActionPolicy`): управлять — head ветки и admin; отмечать — head, member отдела, исполнитель функции;
  менять статус пункта — управляющие и исполнитель пункта (остальные поля — 403). Чужое — 404.
- Фронт: `pages/actions.vue` + `features/actions/*` (карточки Execution rate, функции с отметками по клику:
  выполнено → не выполнено → снять; дорожная карта с фильтром, статусом, историей, оценкой эффекта; «Невыполненное»).
- Фикстуры: регулярные действия ФО «Приход» (11) / «Расход» (9) с отметками за октябрь (детерминированные пропуски).

## Спринты, регулярные задачи, журнал ошибок (бэклог п.6 — сделано)
- **Спринт** (`Service/Sprint/SprintHandler`, `pages/sprints.vue`): неделя **пн–вс** (не сегмент РНП), `sprint_task.week_start` —
  понедельник. Выполнено = прогресс 100 %. `GET /api/org-units/{id}/sprint?week=` (любой день недели),
  `POST /api/org-units/{id}/sprint-tasks` (с `weekStart`), `PUT|DELETE /api/sprint-tasks/{id}`.
  Перенос: `POST …/sprint/carry-over?week=` — копии незавершённых в следующую неделю с `carried_from` (повторно не копирует).
- **Регулярные задачи** (`recurring_task`): `POST /api/org-units/{id}/recurring-tasks`, `PUT /api/recurring-tasks/{id}`;
  `POST …/sprint/pull-recurring?week=` добавляет активные в неделю: weekly/daily — всегда, monthly — неделя первого
  понедельника месяца, quarterly — квартала; задача с тем же названием в неделе не дублируется (тег «регулярная»).
- Права спринта: вести — head + member отдела (`canEnterFacts`); регулярные задачи — head (`canSetPlans`).
- **Execution rate** теперь включает спринт: задача в периоде, где её воскресенье; планируется, если неделя
  закончилась или задача уже на 100 % (`ExecutionRateDto.sprintPlanned/sprintDone`).
- **Журнал ошибок** (`Service/ErrorLog/ErrorLogHandler`, `pages/errors.vue`): `GET /api/org-units/{id}/errors?from&to`
  (по умолчанию с 1-го числа позапрошлого месяца по сегодня) — записи (новые сверху), итоги: всего, не закрыто,
  по типам последствий, ущерб в €. `POST …/errors`, `PUT /api/errors/{id}`. Только значимые: описание ≥ 10 символов,
  тип последствий обязателен, для `money` — сумма > 0 (типизация как у eur), дата не в будущем, ответственный — из ветки.
  Записать: head/member; изменить: head/admin, автор, ответственный; viewer — чтение.

## Деплой (подготовлено 09.11.2026, сервера ещё нет)
- `docker-compose.prod.yml` (проект `rnp-prod`, контейнеры `rnp-prod-*`, сеть `rnp-prod-net`), секреты — `.env.prod`
  (шаблон `.env.prod.example`, генератор `docker/prod/init-env.sh`, gitignored), инструкция — `docs/deploy.md`,
  `make prod-*`. Образец — прод платформы (`../backend-api/docker-compose.prod.yaml`, Caddy перед фронтом), без
  blue/green.
- Образы: `docker/prod/backend.Dockerfile` (контекст — корень, свой `.dockerignore` рядом): target `app` — php-fpm с
  кодом, `composer --no-dev`, `cache:warmup` на сборке, opcache без проверки файлов; target `nginx` — `public/` той же
  сборки. Фронт — существующий `frontend/Dockerfile`. В образах нет `.env.local`, ключей, `var/`, тестов (проверено).
- Порядок: `migrate` (одноразовый: `lexik:jwt:generate-keypair --skip-if-exists` в том `rnp-prod-jwt`, миграции,
  `up-to-date --fail-on-unregistered`) → `php` (`service_completed_successfully`). Наружу только фронт на
  `${RNP_BIND:-127.0.0.1}:${RNP_PORT:-3300}`; HTTPS — прокси сервера или `--profile caddy` (`docker/prod/Caddyfile`).
- **Адрес клиента для лимита входа**: Nitro берёт `X-Forwarded-For` только при `NUXT_TRUST_PROXY=true`
  (`runtimeConfig.trustProxy`, `server/utils/session.ts`); бэкенд в prod доверяет ему через
  `SYMFONY_TRUSTED_PROXIES=private_ranges`, `SYMFONY_TRUSTED_HEADERS=x-forwarded-for`. В dev этого нет — там Symfony
  видит адрес контейнера фронта (лимит общий; для локальной разработки не важно). Cookie в prod — `Secure`
  (`NUXT_COOKIE_SECURE=true`).
- Планировщик `docker/prod/ofelia.ini` = локальный + бэкап 01:20 UTC (`docker/prod/backup.sh` в контейнере mariadb:
  дамп в файл → gzip → переименование, хранение `RNP_BACKUP_DAYS`).
- Проверено локально (стек на 3310 с копией dev-базы, потом удалён `down -v`): миграции, ключи, вход, 9 страниц,
  cookie, доверенные прокси (приватный адрес → XFF принят, публичный → нет), бэкап и восстановление (брони,
  66 метрик сошлись). Git Bash: путь внутри контейнера в `docker exec` — с `MSYS_NO_PATHCONV=1`.
- Открыто: сервер и домен; подключение к Caddy платформы (сеть в compose или `docker network connect`); снимки сервера у провайдера (решение 11.11 — вместо выноса бэкапов); демо-данные и тестовые учётки из dev убраны 10–11.11.

## ИИ (09.11.2026, решение пользователя: A–E; уведомления пока только в колокольчике, вход через платформу — позже)
- **Claude через официальный PHP SDK** `anthropic-ai/sdk` (^1.6, HTTP — через уже установленный Symfony HttpClient),
  модель `claude-opus-5-5`, beta `messages->create` с `fallbacks: 'default'` (`server-side-fallback-2026-07-01`),
  effort per request, системный промпт кешируется (`cacheControl`). Ключ `ANTHROPIC_API_KEY` — только `.env.local` /
  `.env.prod` (в dev записан 09.11 из файла пользователя в «Загрузках», `sk-ant-usr…`); пусто = ИИ выключен, UI пишет
  «ИИ не настроен». Проверка ключа: `php bin/console app:ai:ping` (один короткий запрос).
- **Статус 09.11: ключ принят, но API отвечает 403 `verification_required`** — администратор организации Anthropic должен
  пройти верификацию (Anthropic Verification Portal, ссылка в ошибке/логе). До этого ИИ-кнопки показывают «ИИ сейчас
  недоступен». Реальный ответ модели ещё ни разу не проверен — после верификации прогнать `app:ai:ping` и все функции.
- Слои: `Service/Ai/Llm/{LlmClient, ClaudeLlmClient, LlmReply, LlmToolCall, AiUnavailableException}` (503 с текстом для
  пользователя; Nitro `maskServerError` пропускает 503), `AiGateway` (проверка ключа, лимит `ai_requests` 40/час на
  пользователя, учёт токенов в `ai_usage`), `AiContextBuilder` (РНП-неделя + прошлая неделя + действия через обычные
  handler-ы с правами пользователя; `VALUE_NOTES` объясняет модели единицы). В `APP_ENV=test` (и на e2e-стенде) вместо
  Claude — `tests/Support/Ai/FakeLlmClient` (очередь ответов; в Cest: `grabService` + `persistService`).
- **A. Разбор недели**: `GET|POST /api/org-units/{id}/ai/weekly-review?week=` → `ai_weekly_review` (уникально отдел+
  неделя, «Обновить» перезаписывает); карточка `features/ai/components/AiWeeklyReviewCard.vue` в кабинете.
- **Разбор отклонения (п.2) + B + п.3**: `metric_deviation_note` (метрика, период week/month, reason, action, автор),
  `PUT /api/metrics/{id}/deviation-note` (пустая причина — удалить), права `MetricEditPolicy::canExplainDeviation`
  (head/member/filler, и для авто-метрик); в РНП-строке `note` + `canExplain`. Черновик ИИ: `POST
  /api/metrics/{id}/ai/deviation-draft` — structured output (JSON-схема reason/action), контекст: карточка, ряд 12
  недель/месяцев с годом назад, неделя отдела, прошлые разборы; не сохраняется. Фронт: `DeviationNoteDialog.vue`
  (из РНП: пометка под метрикой или «Объяснить отклонение» у red/risk), кнопки «В дорожную карту» / «В спринт».
  Разборы попадают в контекст ИИ-разбора недели.
- **C + E. ИИ-помощник**: `POST /api/ai/assistant {messages, page}` — без состояния на сервере; цикл до 8 вызовов с
  инструментами только для чтения `Service/Ai/Assistant/AssistantTools` (list_units, find_metrics, get_rnp_week,
  get_metric_series, get_metric_card, get_error_log, get_data_status; аргументы валидируются, чужое → «нет доступа»);
  руководство по дашборду — `AssistantGuide::SYSTEM` (держать в актуальном виде при изменении экранов!). Фронт:
  плавающая кнопка «Спросить ИИ» + панель `features/ai/components/AiAssistant.vue` (layout default), переписка в Pinia
  (`assistantStore`), чистится при смене пользователя. `GET /api/ai/status`.
- **D (объяснение аномалий) — сделано 10.11**: `POST /api/metrics/{id}/ai/anomaly {kind: week|forecast, date}`
  (`Service/Ai/AnomalyExplainHandler`): «почему метрика красная на неделе / прогноз месяца ниже плана» — 3–5 пунктов,
  первый — цифры, затем причины из данных (история с годом назад, метрики отдела, действия, прошлые объяснения), последний
  «Проверить: …»; плана действий не составляет. Спросить может любой, кто видит метрику (404 иначе). Хранится в
  `ai_anomaly_explanation` (метрика + kind + начало недели/месяца, `input_hash` = SHA-1 значений метрики в РНП):
  повторно отдаётся без запроса к модели (`cached: true`), при смене цифр генерируется заново. Фронт: в колокольчике у
  `metric_red` / `run_rate_behind` кнопки «Почему: <метрика>» (первые 3, `NotificationDto.metricIds`) →
  `features/ai/components/AiAnomalyDialog.vue`; стили Markdown ИИ (`.ai-markdown`) вынесены в `assets/css/main.css`.
  Проверено на живой модели (загрузка Revenue, звонки Sales: 16 с, повтор 0,24 с) и скриншотом. Тест в `AiCest`.
- **Статус 10.11: ИИ работает** — верификация пройдена, `app:ai:ping` отвечает, Регина 10.11 сгенерировала разбор
  недели (в `ai_usage` 6 запросов).
- **Проверка на живой модели (10.11, от gestion@, неделя 02.11):** разбор недели Revenue/CS/Marketing (20–32 с),
  черновики отклонений (11–20 с), помощник — 5 вопросов (6–21 с, инструменты вызываются, на вопрос о 2023 г. честно
  «данных нет»). Цифры сверены с базой — верны. Исправлено: модель советовала «задать план» будущим неделям метрики с
  планом по формуле → в `VALUE_NOTES` объяснено, что такой план появляется с фактом, и что `factPrevYear` недели бывает
  коротким сегментом; в черновик передаётся `planFormula`. Остальные огрехи — от данных: устаревший импорт ручных
  фактов ноября, демо-действия фикстур («test»), планы
  загрузки сен/окт 45 % от «общей». Сохранились разборы недели 02.11 Revenue, CS, Marketing (автор — gestion@).
  Расход проверки: ~21 запрос, ~95 тыс. входных (большая часть из кеша) и ~16 тыс. выходных токенов.
- Markdown ответов ИИ — `features/ai/utils/markdown.ts` (сначала экранирование, потом свои теги; тесты).
- Тесты: `tests/Functional/Api/AiCest.php` (7), e2e `e2e/tests/ai.spec.ts` (3, на заглушке).
- Попутно 09.11: `composer update "symfony/*"` 8.1.10 → 8.1.11 (18 advisories, в т.ч. `private_ranges` trusted proxies),
  `composer audit` чистый. PHPStan при `APP_DEBUG=0` требует debug-контейнер:
  `docker compose exec -e APP_DEBUG=1 php php bin/console cache:warmup` перед `phpstan analyse`.

## Улучшения удобства (09.11.2026, список пользователя: всё кроме мобильной версии; Telegram-бот отложен — только колокольчик)
- **п.1 «Сколько нужно в день до плана»** — `RnpRowDto.pace` (`PaceDto`: currentPerDay = факт месяца / прошедшие дни,
  requiredPerDay = (план − факт) / оставшиеся дни, не меньше 0; planReached), те же прошедшие дни и условия, что у run
  rate (суммируемые недельные без формулы, есть план месяца, месяц не кончился). Для `lower_better` — лимит в день.
  Фронт: `features/rnp/utils/pace.ts` (`paceView`, тесты) + `PaceHint.vue` в колонке Run rate РНП и в карточке Run rate
  кабинета. В контексте ИИ — поле `pace`.

- **п.16 Алерты в колокольчике (10.11)** — `Service/Notification/AlertCollector` (вызывается из `NotificationHandler`,
  ничего не хранит; считается через обычные handler-ы с правами и личным видом пользователя). По отделам, где
  пользователь **head или member напрямую** (руководитель компании получает метрики узла компании, не всех отделов):
  head — `metric_red` (красные метрики последней завершённой недели), `run_rate_behind` (run rate красный, с 7-го
  числа), `actions_missed` (невыполненное из «Действий», 30 дней), `errors_new` (записи журнала ветки за 7 дней не от
  себя); head и member — `facts_missing` (ручные недельные не-формульные метрики без факта прошлой недели, где можно
  вносить); member — только свои просроченные действия; `ROLE_ADMIN` — `data_stale` (источник устарел/падает).
  `NotificationDto`: `orgUnitId` nullable, `metrics` (первые 3 названия), `week`, `source`. Фронт: тексты, иконки и
  ссылки (`notificationLink` в `features/notifications/utils/unread.ts`), бейдж: заявки и невнесённые факты — всегда,
  остальное — до открытия колокольчика; при навигации перезапрос не чаще раза в 30 с (ответ 0,35–0,66 с на реальных
  данных). Тесты `AlertsCest` (3), vitest. Проверено скриншотом (puppeteer-core в scratchpad, временно включённый
  admin@rnp.test + head CS, после — откат и удаление его refresh-токенов).

## Экран администрирования (11.11.2026)
- Только `ROLE_ADMIN` (иначе 403, пункты меню «Пользователи» / «Оргструктура» — `adminOnly`). Та же логика, что в
  консоли: `Service/Auth/UserAccountManager` (учётки) и `Service/Org/OrgStructureManager` (отделы, руководитель,
  участники; `app:org` переписан на него); handler-ы `Service/Admin/Admin{User,Org}Handler`, сообщения 422 — по-русски.
- API: `GET|POST /api/admin/users` (пароль в ответе создания — один раз), `PUT /api/admin/users/{id}` `{name, isAdmin,
  isActive}`, `POST …/{id}/reset-password` → `{password}` (сессии завершаются), `DELETE …/{id}` (только без истории);
  `GET|POST /api/admin/org-units` (плоский список с `members`, `headUserId`, `activeMetrics`), `PUT …/{id}` `{name,
  parentId}` (переименовать + перенести с веткой), `PUT …/{id}/head` `{userId|null}`, `PUT …/{id}/members` `{userId,
  role: member|viewer}`, `DELETE …/{id}/members/{userId}`.
- Защита: нельзя снять админа/отключить/удалить **себя**; последний активный администратор не снимается и не
  отключается (и из консоли); отключение удаляет refresh-токены; руководителем/участником — только активные учётки;
  роль `head` — только через «Руководитель» (head_user_id + членство head всегда вместе).
- Изменения учётки — в DBAL-транзакции (ORM `wrapInTransaction` закрывает EntityManager при исключении).
- Фронт: `pages/admin/{users,org}.vue`, `features/admin/{api,types,utils/orgTree}`; после изменений оргструктуры
  перезагружаются `orgStore` и `/api/me`. Тесты: `AdminCest` (4), vitest `orgTree.spec`, e2e `admin.spec.ts`
  (учётка → руководитель нового отдела → вход с выданным паролем). ИИ-помощник знает об экранах (`AssistantGuide`).

## Ревью 11.11.2026 (всё ниже исправлено и покрыто тестами)
- **Run rate / темп ручных метрик**: прошедшие дни — до конца последней недели **с внесённым фактом** (а не до
  сегодня), иначе ложные `run_rate_behind` каждую неделю (`RnpWeekHandler::elapsedUntil`).
- **План по формуле**: сумма недельных планов заменяет формулу месяца, только если план задан у **всех** недель.
- **Исключения звонков**: решение и отзыв — под `SELECT … FOR UPDATE` (второе решение → 422); двойная заявка → 422;
  если пересчёт занят синком, а месяц звонка старше окна плановых пересчётов (`first day of -2 months`) — 409,
  решение не сохраняется.
- **Когорты** (`monthly_cohort`) всегда пересчитываются за 12 месяцев, какое бы окно ни просили.
- **Синк броней**: карточка брони с любой ошибкой (не только 404) пропускается и повторяется позже; 10 подряд — ресурс
  падает (лежит платформа).
- **Доступные ночи**: не-ACTIVE объект с датой активации, но без гостевых броней — 0 ночей.
- **Каталог**: метрика становится вычисляемой → её `manual`-факты переводятся в `import` (пересчёт их заменит).
- **Прод PHP — `Europe/Madrid`** (был UTC; raw-данные и «сегодня» — мадридское время, как в dev).
- **ИИ**: контекст разбора недели и объяснений — все метрики отдела, без личного вида (результат общий); пустой или
  обрезанный ответ (`max_tokens`) не сохраняется (503); объяснение аномалии генерируется под lock (второй запрос
  ждёт и берёт готовое); помощник: длинные прошлые ответы обрезаются до 6000 символов, история — последние 20,
  ≤ 6 инструментов за шаг и ≤ 150 000 символов результатов на вопрос.
- **Админка/сессия**: выход — под той же Web Lock, что refresh; refresh с тайм-аутом (15 с браузер, 10 с Nitro);
  удаление последнего администратора запрещено и из консоли; переименование + перенос проверяют имя в новом
  родителе (`OrgStructureManager::update`); списки руководителя/роли сбрасываются после отказа; отключённый
  руководитель виден в списке.
- Не исправлено (низкий риск): два админа одновременно снимают права друг с друга → без админов (лечится консолью).
- **Прежний руководитель (11.11)** при замене/снятии остаётся в отделе **наблюдателем** (минимальные права; админ
  меняет роль или убирает) — раньше терял отдел совсем.
- **e2e плана по формуле (11.11)**: в фикстурах у `cs_direct_base_sum` в ноябре нет заданного плана (работает формула,
  как на проде); тест `plan-formula.spec.ts`: план = 30 % входящих, ручной план перекрывает, очистка возвращает.

