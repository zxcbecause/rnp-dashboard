# RNP Dashboard Acme — local development shortcuts.
# Everything runs inside docker compose; nothing is required on the host except Docker.
# Without make (Windows), copy the command after the target name into the terminal.

DC      = docker compose
PHP     = $(DC) exec php
CONSOLE = $(PHP) php bin/console
FE      = $(DC) exec frontend

.DEFAULT_GOAL := help

# ─── Lifecycle ─────────────────────────────────────────

init:         ## First run: build, .env.local + JWT keys, DB, migrations, fixtures
	$(DC) up -d --build
	$(DC) run --rm php bash bin/init-dev.sh
	$(CONSOLE) doctrine:migrations:migrate -n
	$(CONSOLE) doctrine:fixtures:load -n

up:           ## Start all containers
	$(DC) up -d

down:         ## Stop all containers
	$(DC) down

build:        ## Rebuild images
	$(DC) build

logs:         ## Tail logs of all containers
	$(DC) logs -f --tail=100

ps:           ## Container status
	$(DC) ps

health:       ## Backend healthcheck
	curl -s http://localhost:8081/api/health

# ─── Backend ───────────────────────────────────────────

sh:           ## Shell in the php container
	$(PHP) bash

composer-install: ## composer install
	$(PHP) composer install

migrate:      ## Run migrations
	$(CONSOLE) doctrine:migrations:migrate -n

migration:    ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff -n

fixtures:     ## Reload fixtures (wipes the dev DB data)
	$(CONSOLE) doctrine:fixtures:load -n

sync:         ## Pull platform API data and recalculate metrics (needs PLATFORM_API_* in .env.local)
	$(CONSOLE) app:sync:platform

sync-full:    ## Full platform re-sync
	$(CONSOLE) app:sync:platform --full

recalc:       ## Recalculate platform metrics from raw snapshots
	$(CONSOLE) app:metrics:recalculate

schema-validate: ## Doctrine mapping + DB sync check
	$(CONSOLE) doctrine:schema:validate

test:         ## Codeception (all suites, test env)
	$(PHP) vendor/bin/codecept run

stan:         ## PHPStan level 8
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs:           ## PHP-CS-Fixer dry run
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:       ## PHP-CS-Fixer apply
	$(PHP) vendor/bin/php-cs-fixer fix

qa: schema-validate stan cs test ## All backend checks

# ─── Frontend ──────────────────────────────────────────

fe-sh:        ## Shell in the frontend container
	$(FE) sh

fe-lint:      ## ESLint + vue-tsc
	$(FE) npm run lint
	$(FE) npm run typecheck

fe-test:      ## Vitest unit tests
	$(FE) npm run test

fe-build:     ## Production build check (nuxt build)
	$(FE) npm run build

e2e:          ## Browser e2e tests (Playwright) on a throwaway stand; filter: make e2e ARGS='-g "вход"'
	sh e2e/run.sh $(ARGS)

# ─── Production (on the server, docs/deploy.md) ────────

PROD = docker compose -f docker-compose.prod.yml --env-file .env.prod

prod-env:     ## Create .env.prod with random secrets (once; never overwrites)
	sh docker/prod/init-env.sh

prod-up:      ## Build and (re)start the production stack; migrations run before php starts
	$(PROD) up -d --build --remove-orphans

prod-caddy:   ## Same, plus Caddy with Let's Encrypt for RNP_DOMAIN (server without its own HTTPS proxy)
	$(PROD) --profile caddy up -d --build --remove-orphans

prod-down:    ## Stop the production stack (data volumes stay)
	$(PROD) down

prod-ps:      ## Production container status
	$(PROD) ps -a

prod-logs:    ## Tail production logs
	$(PROD) logs -f --tail=100

prod-console: ## Symfony console in production, e.g. make prod-console CMD="app:user list"
	$(PROD) exec php php bin/console $(CMD)

prod-backup:  ## Database dump right now (same as the nightly job)
	$(PROD) exec mariadb sh /usr/local/bin/rnp-backup.sh

.PHONY: init up down build logs ps health sh composer-install migrate migration fixtures sync sync-full recalc \
	schema-validate test stan cs cs-fix qa fe-sh fe-lint fe-test fe-build e2e help \
	prod-env prod-up prod-caddy prod-down prod-ps prod-logs prod-console prod-backup

help:         ## Show this help
	@grep -E '^[-a-zA-Z0-9_]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-18s\033[0m %s\n", $$1, $$2}'
