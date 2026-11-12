/**
 * The user guide shown on /help. Prose lives here, not in i18n keys: one structure per language.
 * Keep it in sync with the screens (and with the AI assistant guide, backend AssistantGuide::SYSTEM).
 */
export interface HelpItem {
  /** Bold lead-in, e.g. a screen or a colour */
  term?: string
  text: string
}

export interface HelpSection {
  id: string
  title: string
  icon: string
  intro?: string
  items: HelpItem[]
}

const ru: HelpSection[] = [
  {
    id: 'start',
    title: 'Начало работы',
    icon: 'pi pi-sign-in',
    items: [
      { term: 'Вход', text: 'Email и пароль, который выдал администратор. Сразу смените его: аватар справа вверху → «Сменить пароль» (не короче 10 символов). После смены нужно войти заново.' },
      { term: 'Что вы видите', text: 'Руководитель — свой отдел и все отделы под ним. Сотрудник и наблюдатель — только свой отдел; наблюдатель ничего не меняет. Чужих отделов в дашборде нет совсем.' },
      { term: 'Нет доступа к отделу или метрике', text: 'Это решает администратор: он заводит учётки, назначает руководителей и участников отделов.' },
    ],
  },
  {
    id: 'week',
    title: 'Еженедельный ритм',
    icon: 'pi pi-calendar',
    intro: 'РНП — это «рука на пульсе»: раз в неделю каждый руководитель смотрит свои цифры и объясняет отклонения.',
    items: [
      { term: 'Понедельник', text: 'Внесите факты прошлой недели по ручным метрикам (тег «вручную»). Остальные считаются сами из платформы Acme и Битрикс24.' },
      { term: 'Красное и «риск»', text: 'У каждой не выполненной метрики нажмите «Объяснить отклонение»: причина и что делаем. Есть «Черновик от ИИ» — его можно поправить. Действие сразу можно отправить в дорожную карту или в спринт.' },
      { term: 'Действия и спринт', text: 'Отметьте выполненные базовые функции, обновите пункты дорожной карты и задачи спринта. Из этого считается Execution rate.' },
      { term: 'Колокольчик', text: 'Напомнит, если факты не внесены, метрика красная, прогноз месяца ниже плана или действия просрочены. Клик ведёт туда, где это исправить.' },
    ],
  },
  {
    id: 'screens',
    title: 'Что где',
    icon: 'pi pi-compass',
    items: [
      { term: 'Мой кабинет', text: 'Главный экран: 4 главные метрики с трендом к прошлой неделе, ИИ-разбор недели, метрики ветки, run rate, Execution rate и невыполненное. По умолчанию — последняя завершённая неделя.' },
      { term: 'РНП-неделя', text: 'Таблица метрик отдела: план, факт и % за неделю и за месяц, run rate. Отдел и неделя выбираются вверху; фишки «Выполнено / Риск / Не выполнено / Нет плана» фильтруют строки.' },
      { term: 'Страница метрики', text: 'Клик по названию метрики: график динамики (недели или месяцы, «Год назад»), «Логика показателя» — что измеряется, формула, источник — и действия по метрике.' },
      { term: 'Действия', text: 'Базовые функции (регулярные, отмечаются по периодам), дорожная карта с историей и оценкой эффекта, Execution rate, невыполненное за 30 дней.' },
      { term: 'Спринты', text: 'Задачи отдела на неделю (пн–вс): приоритет P1–P5, прогресс 0 / 50 / 100 %, результат, следующий шаг. Незавершённые переносятся в следующую неделю одной кнопкой.' },
      { term: 'Журнал ошибок', text: 'Только значимые ошибки: потеря денег, блокировка работы, недовольный клиент. Что произошло, ущерб, причина, как не допустить, ответственный.' },
      { term: 'Пропущенные звонки', text: 'Для отделов со звонками (сейчас CS). Звонок, пропущенный по объективной причине, сотрудник отправляет на исключение с причиной; руководитель подтверждает или отклоняет. Можно исключать звонки текущего и прошлого месяца.' },
    ],
  },
  {
    id: 'enter',
    title: 'Как вносить цифры',
    icon: 'pi pi-pencil',
    items: [
      { term: 'Ввод', text: 'В «РНП-неделе» нажмите на ячейку с карандашом, введите число, Enter — сохранить, Esc — отменить. Пустое значение удаляет факт.' },
      { term: 'Кто вносит', text: 'Факт — руководитель, сотрудники отдела и назначенный заполняющий, только у ручных метрик. План — руководитель отдела и администратор.' },
      { term: 'Проценты', text: 'Вводятся в процентах: 45 — это 45 %.' },
      { term: 'Ноль и пусто', text: 'Ноль — это результат «ничего не было», его нужно внести. Пусто — «ещё не внесено», колокольчик напомнит.' },
      { term: 'План недели', text: 'Если задан только план месяца, план недели считается от него по дням. План с тегом «план по формуле» считается сам — например, 30 % от входящих броней; заданный вручную план важнее формулы.' },
    ],
  },
  {
    id: 'read',
    title: 'Как читать цифры',
    icon: 'pi pi-chart-bar',
    items: [
      { term: 'Цвета', text: 'Зелёный — план выполнен (от 100 %), жёлтый — риск (90–100 %), красный — ниже 90 %, серый — плана нет. У отдельных метрик пороги свои.' },
      { term: '«Меньше — лучше»', text: 'Для таких метрик (расходы, время ответа, пропущенные звонки) % считается наоборот: план / факт. Потолок — 200 %.' },
      { term: 'Недели', text: 'Неделя — с понедельника по воскресенье, но режется границей месяца: 30.11–06.12 — это две части, 30.11 и 01.12–06.12. Поэтому в начале и в конце месяца бывают короткие недели.' },
      { term: 'Факт месяца', text: 'Сумма недель на дату показанной недели. Отношения — конверсии, CPL, CAC, ADR, загрузка, доли — не усредняются, а пересчитываются из числителя и знаменателя.' },
      { term: 'Run rate', text: 'Прогноз месяца при текущем темпе: факт с начала месяца / прошедшие дни × дней в месяце. Под ним — сколько нужно в день, чтобы выйти на план. Для ручных метрик дни считаются до последней внесённой недели.' },
    ],
  },
  {
    id: 'data',
    title: 'Откуда данные',
    icon: 'pi pi-database',
    items: [
      { term: 'Платформа Acme', text: 'Брони, заезды, ночи, загрузка, уборки. Суммы — по стоимости аренды (с НДС, без уборки, белья и туристического налога), по дате создания брони, если не указано иное в «Логике показателя».' },
      { term: 'Битрикс24', text: 'Сделки собственников (лиды, договоры, новые объекты) и звонки.' },
      { term: 'Обновление', text: 'Каждые 30 минут, ночью — полный проход. Свежесть видна полоской над таблицей РНП: красная — данные устарели, жёлтая — последняя синхронизация не удалась.' },
      { term: 'Вручную', text: 'Всё с тегом «вручную» вносят люди.' },
    ],
  },
  {
    id: 'ai',
    title: 'ИИ-помощник',
    icon: 'pi pi-sparkles',
    items: [
      { term: '«Спросить ИИ»', text: 'Кнопка внизу справа. Отвечает про ваши цифры (видит только то, что видите вы) и про то, как пользоваться дашбордом. Ничего не меняет.' },
      { term: 'Разбор недели', text: 'В кабинете: краткий разбор недели отдела. Сохраняется и виден всем в отделе.' },
      { term: '«Почему»', text: 'У красных метрик в колокольчике — короткое объяснение по данным. ИИ может ошибаться: проверяйте выводы по цифрам.' },
    ],
  },
]

const en: HelpSection[] = [
  {
    id: 'start',
    title: 'Getting started',
    icon: 'pi pi-sign-in',
    items: [
      { term: 'Sign in', text: 'Email and the password from the administrator. Change it right away: avatar at the top right → “Change password” (10+ characters). You sign in again afterwards.' },
      { term: 'What you see', text: 'A head sees their unit and every unit below it. Members and viewers see their unit only; viewers change nothing. Other units do not exist for you.' },
      { term: 'No access to a unit or metric', text: 'Ask the administrator: they create accounts and assign heads and members.' },
    ],
  },
  {
    id: 'week',
    title: 'Weekly routine',
    icon: 'pi pi-calendar',
    intro: 'RNP means “hand on the pulse”: once a week every head reviews their numbers and explains deviations.',
    items: [
      { term: 'Monday', text: 'Enter last week’s facts of manual metrics (tag “manual”). The rest is computed from the Acme platform and Bitrix24.' },
      { term: 'Red and “risk”', text: 'For every missed metric press “Explain deviation”: the reason and what you do about it. “AI draft” helps; the action can go to the roadmap or the sprint.' },
      { term: 'Actions and sprint', text: 'Tick done base functions, update roadmap items and sprint tasks. The execution rate is computed from them.' },
      { term: 'Bell', text: 'Reminds you of missing facts, red metrics, a month forecast below plan and overdue actions. A click leads to where it is fixed.' },
    ],
  },
  {
    id: 'screens',
    title: 'Where things are',
    icon: 'pi pi-compass',
    items: [
      { term: 'My cabinet', text: 'Main screen: 4 key metrics with the trend to last week, AI weekly review, branch metrics, run rate, execution rate and overdue items. Shows the last finished week by default.' },
      { term: 'RNP week', text: 'The unit’s metrics: plan, fact and % for the week and the month, run rate. Unit and week are chosen at the top; the status chips filter rows.' },
      { term: 'Metric page', text: 'Click a metric name: dynamics chart (weeks or months, “Year ago”), “Metric logic” — what is measured, formula, source — and the metric’s actions.' },
      { term: 'Actions', text: 'Base functions (recurring, ticked per period), roadmap with history and effect assessment, execution rate, overdue items for 30 days.' },
      { term: 'Sprints', text: 'Weekly tasks of the unit (Mon–Sun): priority P1–P5, progress 0 / 50 / 100 %, result, next step. Unfinished tasks move to the next week with one button.' },
      { term: 'Error log', text: 'Significant errors only: lost money, blocked work, an unhappy client. What happened, damage, cause, prevention, owner.' },
      { term: 'Missed calls', text: 'For units with calls (CS for now). A call missed for an objective reason is sent for exclusion with a reason; the head approves or rejects. Calls of this and last month only.' },
    ],
  },
  {
    id: 'enter',
    title: 'Entering numbers',
    icon: 'pi pi-pencil',
    items: [
      { term: 'Input', text: 'In “RNP week” click a cell with a pencil, type, Enter saves, Esc cancels. An empty value removes the fact.' },
      { term: 'Who enters', text: 'Facts: the head, unit members and the assigned filler, manual metrics only. Plans: the head and the administrator.' },
      { term: 'Percentages', text: 'Entered in percent: 45 means 45 %.' },
      { term: 'Zero and empty', text: 'Zero is a result (“nothing happened”) and must be entered. Empty means “not entered yet” — the bell will remind you.' },
      { term: 'Week plan', text: 'With only a month plan, the week plan is prorated by days. A “plan by formula” is computed (e.g. 30 % of incoming bookings); a plan set by hand wins over the formula.' },
    ],
  },
  {
    id: 'read',
    title: 'Reading the numbers',
    icon: 'pi pi-chart-bar',
    items: [
      { term: 'Colours', text: 'Green — plan met (from 100 %), yellow — risk (90–100 %), red — below 90 %, grey — no plan. Some metrics have their own thresholds.' },
      { term: '“Lower is better”', text: 'For such metrics (costs, response time, missed calls) the % is plan / fact. Capped at 200 %.' },
      { term: 'Weeks', text: 'Monday to Sunday, cut by the month border: 30.11–06.12 is two parts, 30.11 and 01.12–06.12. Short weeks appear at the start and end of a month.' },
      { term: 'Month fact', text: 'Sum of the weeks up to the shown week. Ratios — conversions, CPL, CAC, ADR, occupancy, shares — are recomputed from numerator and denominator, never averaged.' },
      { term: 'Run rate', text: 'Month forecast at the current pace: fact since the 1st / elapsed days × days in month, with the daily amount needed to reach the plan. Manual metrics count days up to the last entered week.' },
    ],
  },
  {
    id: 'data',
    title: 'Where the data comes from',
    icon: 'pi pi-database',
    items: [
      { term: 'Acme platform', text: 'Bookings, arrivals, nights, occupancy, cleanings. Amounts are the rental price (VAT included, no cleaning, linen or tourist tax), by booking creation date unless “Metric logic” says otherwise.' },
      { term: 'Bitrix24', text: 'Owner deals (leads, contracts, new properties) and calls.' },
      { term: 'Refresh', text: 'Every 30 minutes, a full pass at night. The bar above the RNP table shows freshness: red — outdated, yellow — the last sync failed.' },
      { term: 'Manual', text: 'Everything tagged “manual” is entered by people.' },
    ],
  },
  {
    id: 'ai',
    title: 'AI assistant',
    icon: 'pi pi-sparkles',
    items: [
      { term: '“Ask AI”', text: 'Button at the bottom right. Answers about your numbers (it sees only what you see) and about using the dashboard. Changes nothing.' },
      { term: 'Weekly review', text: 'In the cabinet: a short review of the unit’s week, stored and visible to the unit.' },
      { term: '“Why”', text: 'For red metrics in the bell: a short explanation from the data. AI can be wrong — check its conclusions against the numbers.' },
    ],
  },
]

export function helpSections(locale: string): HelpSection[] {
  return locale.startsWith('ru') ? ru : en
}
