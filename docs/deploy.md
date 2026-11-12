# Деплой РНП-дашборда

Production — один сервер с Docker Compose (`docker-compose.prod.yml`). Снаружи открыт только фронт (Nuxt);
API, база и планировщик живут во внутренней сети Docker. HTTPS делает обратный прокси перед фронтом:
либо уже стоящий на сервере (как Caddy у платформы), либо встроенный Caddy (`make prod-caddy`).

```
Интернет ──HTTPS──▶ Caddy ──▶ frontend :3000 (Nuxt, прокси /api) ──▶ nginx ──▶ php-fpm (Symfony) ──▶ mariadb
                                                                    scheduler (Ofelia): синки, бэкап
```

Проверено локально 09.11.2026 на копии dev-базы: сборка, миграции, ключи JWT при первом старте, вход,
все страницы, cookie `Secure/HttpOnly/SameSite=Strict`, лимит входа по реальному IP клиента, бэкап
и восстановление из него.

## 1. Что нужно от сервера

| | |
|---|---|
| ОС | Linux x86-64 (Ubuntu 22.04+/Debian 12) |
| Ресурсы | 2 vCPU, 4 ГБ RAM, 30 ГБ диска (база сейчас ~35 МБ, образы ~1,3 ГБ) |
| ПО | Docker 24+ с плагином `docker compose`, `make` (или команды из `Makefile` вручную) |
| Домен | например `rnp.acme.example`, DNS A-запись → IP сервера |
| Сеть | исходящий доступ к API платформы и `acme.bitrix24.example`; входящие 80/443 — только для варианта с встроенным Caddy |

## 2. Первый запуск

```bash
# 1) Код на сервер (git-истории пока нет): архив проекта без node_modules/vendor/var, распаковать, например, в /opt/rnp-dashboard
cd /opt/rnp-dashboard

# 2) Секреты: создаёт .env.prod (права 600) со случайными APP_SECRET, JWT_PASSPHRASE и паролями БД
make prod-env

# 3) Дописать в .env.prod: RNP_DOMAIN, PLATFORM_API_URL/USERNAME/PASSWORD, BITRIX_WEBHOOK_URL
#    (значения — те же, что в backend/.env.local на машине разработки; в чат и git не отправлять)
nano .env.prod

# 4) Сборка и запуск. Контейнер migrate создаёт ключи JWT (только первый раз) и накатывает миграции;
#    php стартует, только если migrate завершился успешно.
make prod-up            # если HTTPS делает прокси, который уже есть на сервере
make prod-caddy         # если прокси нет: встроенный Caddy сам получит сертификат Let's Encrypt
make prod-ps            # все сервисы Up/healthy, migrate — Exited (0)
```

### Чистая облачная VM (проверено 11.11.2026 на Google Cloud, e2-medium, Ubuntu 24.04)

Временный стенд для показа поднимается примерно за час; весь путь:

1. **VM**: 2 vCPU / 4 ГБ, диск **30 ГБ** (10 ГБ по умолчанию мало для сборки образов), x86-64. В Google Cloud при
   создании отметить «Allow HTTP/HTTPS traffic»; в Oracle Cloud — дополнительно открыть 80/443 в Security List и в
   iptables самого образа. SSH-ключ — отдельный, только для этого сервера (`ssh-keygen -t ed25519 -f ~/.ssh/rnp_xxx`);
   в Google Cloud подпись в конце строки ключа — это имя пользователя на машине.
2. **Пакеты и подкачка** (сборке фронта не хватает 4 ГБ без swap):
   ```bash
   sudo apt-get update && sudo apt-get install -y docker.io docker-compose-v2 make
   sudo usermod -aG docker $USER            # перелогиниться
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
   echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
   sudo mkdir -p /opt/rnp-dashboard && sudo chown $USER: /opt/rnp-dashboard
   ```
3. **Код** — архивом с машины разработки, без секретов и тяжёлого (в Git Bash на Windows `tar` нужен
   `--force-local`, иначе «C:» считается адресом удалённого хоста):
   ```bash
   tar --force-local -czf rnp-code.tgz --exclude=./frontend/node_modules --exclude=./e2e/node_modules \
     --exclude=./backend/vendor --exclude=./backend/var --exclude=./backend/.env.local \
     --exclude='./backend/config/jwt/*.pem' --exclude=./backend/config/jwt/test --exclude=./frontend/.output \
     --exclude=./frontend/.nuxt --exclude=./e2e/playwright-report --exclude=./e2e/test-results .
   scp rnp-code.tgz user@IP:/opt/rnp-dashboard/ && ssh user@IP 'cd /opt/rnp-dashboard && tar -xzf rnp-code.tgz && rm rnp-code.tgz'
   ```
   `backend/var` исключать обязательно: там логи, CSV импорта и, возможно, файл начальных паролей.
4. **Домен не обязателен**: `RNP_DOMAIN=rnp-demo.example` (IP через дефисы) — sslip.io отвечает этим IP, и
   встроенный Caddy (`make prod-caddy`) получает на него настоящий сертификат Let's Encrypt.
5. **Секреты интеграций** — не руками, а потоком с машины разработки, чтобы они не попадали на экран и в историю
   команд: `grep -E '^(PLATFORM_API_|BITRIX_WEBHOOK_URL|ANTHROPIC_API_KEY)' backend/.env.local | ssh … 'скрипт,
   подставляющий строки в .env.prod'`.
6. Данные — раздел 3, затем `make prod-caddy`; сборка на 2 vCPU — около 8 минут.
7. Проверка: `curl -I https://<домен>/login` → 200; `docker exec rnp-prod-php php bin/console app:sync:platform`,
   `app:sync:bitrix`, `app:ai:ping`; в `sync_run` — success.

Сервер и машина разработки после переноса живут отдельно: правки на одном не видны на другом.

### HTTPS через прокси, который уже есть на сервере

Фронт слушает `127.0.0.1:${RNP_PORT}` (по умолчанию 3300). Если прокси (Caddy/nginx) стоит на самом
хосте — направить домен туда:

```
rnp.acme.example {
    reverse_proxy 127.0.0.1:3300
}
```

Если Caddy работает в Docker (как у платформы, сеть `acme-staging_default`) — подключить к его сети
контейнер фронта и проксировать по имени:

```bash
docker network connect acme-staging_default rnp-prod-frontend
# в Caddyfile: rnp.acme.example { reverse_proxy rnp-prod-frontend:3000 }
```

`docker network connect` не переживает пересоздание контейнера — после `make prod-up` повторить, либо
добавить сеть в `docker-compose.prod.yml` (решим, когда будет известен сервер).

Прокси обязан подставлять реальный адрес клиента в `X-Forwarded-For` (Caddy делает это сам). Если перед
фронтом прокси нет совсем — поставить `RNP_TRUST_PROXY=false`, иначе клиент сможет подделать свой адрес
и обойти лимит попыток входа.

## 3. Данные

**Рекомендуется перенести dev-базу**: в ней реальная оргструктура и учётки, метрики, планы и история из
«Метрики РНП», снимки платформы и Bitrix. На машине разработки:

```bash
docker compose exec -T mariadb sh -c 'MYSQL_PWD=root mariadb-dump -uroot --single-transaction --quick \
  --default-character-set=utf8mb4 --ignore-table-data=rnp.refresh_tokens rnp' | gzip > rnp-dev.sql.gz
```

На сервере, **до** первого `make prod-up` (или после `make prod-down`, база должна быть пустой):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d mariadb
gunzip -c rnp-dev.sql.gz | docker exec -i rnp-prod-mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot "$MARIADB_DATABASE"'
make prod-up
rm rnp-dev.sql.gz
```

Сессии (refresh-токены) в дамп не попадают — после переноса все входят заново.

Перед открытием доступа проверить:
- в `make prod-console CMD="app:user list"` только реальные люди. Тестовые `*@rnp.test` удалены из dev
  11.11.2026; лишнюю учётку без истории удаляет `app:user delete <email>` (её метрики переходят руководителю
  отдела); учётку с историей (вносила факты, планы, отметки…) команда не удалит — её `app:user deactivate`;
- демо-данные фикстур (действия, спринты, журнал ошибок) удалены из dev 10.11; регулярные действия ФО
  «Приход/Расход» — настоящие, остаются;
- права `ROLE_ADMIN` (сейчас у gestion@ и Регины).

Пустая база тоже работает (миграции создадут схему), но оргструктуру, метрики и учётки тогда заводить
заново.

## 4. Пользователи

```bash
make prod-console CMD="app:user list"
make prod-console CMD="app:user create ivan@acme.example 'Иван'"      # пароль печатается один раз
make prod-console CMD="app:user reset-password ivan@acme.example"    # новый пароль, сессии сбрасываются
make prod-console CMD="app:user deactivate ivan@acme.example"
```

Пароль пользователь потом меняет сам (ключ в шапке). Пароли передавать лично, не в общих чатах.

## 5. Обновление версии

```bash
cd /opt/rnp-dashboard
# заменить код новой версией (.env.prod и backups/ не трогать)
make prod-backup        # на всякий случай
make prod-up            # пересборка образов, миграции, перезапуск (простой — несколько секунд)
make prod-ps
```

Если migrate упал — php не стартует со старой схемой; причина в `docker logs rnp-prod-migrate`.

## 6. Расписание и бэкапы

Планировщик (`docker/prod/ofelia.ini`, время UTC): синк платформы каждые 30 мин и полный в 02:40,
Bitrix — :15/:45 и полный в 03:00, снимок броней дня в 23:55 по Мадриду (задание в 21:55 и 22:55 UTC, лишний
запуск сам пропускается), **бэкап базы в 01:20** → `${RNP_BACKUP_DIR}` (по умолчанию `./backups`),
хранится `RNP_BACKUP_DAYS` дней (14). Файл пишется целиком или не пишется вовсе.

Бэкапы лежат на том же сервере: от потери самого сервера они не спасают. Поэтому у провайдера включить
снимки сервера (например, Hetzner Backups). Вынос копий наружу (rclone/rsync) — позже, когда в дашборд начнут
активно вносить данные руками.

Восстановление:

```bash
make prod-down
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d mariadb
docker exec rnp-prod-mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -e "DROP DATABASE rnp; CREATE DATABASE rnp"'
gunzip -c backups/rnp-2026-11-30_0120.sql.gz | docker exec -i rnp-prod-mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot rnp'
make prod-up
```

## 7. Обслуживание

| Задача | Команда |
|---|---|
| Статус | `make prod-ps` |
| Логи (JSON, ротация 5×10 МБ) | `make prod-logs`, `docker logs rnp-prod-php` |
| Синк вручную | `make prod-console CMD="app:sync:platform"` / `CMD="app:sync:bitrix"` |
| Статус данных | страница «Синхронизация» (админ) |
| Токены Push API | страница «Токены Push API» (админ) |

## 8. Безопасность

- Секреты только в `.env.prod` (права 600, в `.gitignore`); в образах их нет — проверено.
- `.env.prod` не менять задним числом: смена `JWT_PASSPHRASE` ломает ключи (удалить том `rnp-prod-jwt`,
  все разлогинятся), смена паролей БД — доступ к существующей базе.
- Наружу только фронт; Swagger в production не отдаётся; заголовки `nosniff`, `DENY`, `no-referrer`, HSTS у Caddy.
- `APP_DEBUG=0`: тексты ошибок 500 не показываются.
- Временный туннель Cloudflare (`--profile share` локального стека) в production не используется.
