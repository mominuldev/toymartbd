# One-command local environment. From a clean clone:  make setup
# PHP and Node run on the host; override the binaries if yours are elsewhere,
# e.g.  make setup PHP=/usr/local/opt/php/bin/php
PHP      ?= php
COMPOSER ?= composer
NPM      ?= npm

.PHONY: setup up down services-wait install env migrate fresh test test-mysql lint stan arch check tokens

setup: up install env services-wait migrate ## First run: services, dependencies, .env, database with seed data
	@echo "Ready: $(PHP) artisan serve  →  http://localhost:8000/admin   (and: $(NPM) run dev)"
	@echo "Local sign-in: owner@toymart.test / password  (seeded in local and testing only)"

up: ## Start MySQL, Redis, Meilisearch, Mailpit
	docker compose up -d

down:
	docker compose down

services-wait:
	@until docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -psecret --silent >/dev/null 2>&1; do sleep 1; done

install:
	$(PHP) $$(command -v $(COMPOSER)) install --no-interaction
	$(NPM) install

env:
	@test -f .env || (cp .env.example .env && $(PHP) artisan key:generate)

migrate:
	$(PHP) artisan migrate --seed --force

fresh: ## Drop everything and reseed
	$(PHP) artisan migrate:fresh --seed

test: ## Pest on in-memory SQLite (fast, default)
	$(PHP) artisan test

test-mysql: ## The MySQL-only group: collation, locking, FOR UPDATE ordering
	DB_CONNECTION=mysql DB_DATABASE=toymartbd_testing $(PHP) vendor/bin/pest --group=mysql

lint:
	$(PHP) vendor/bin/pint --test

stan:
	$(PHP) -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

arch:
	$(PHP) vendor/bin/deptrac analyse --no-progress

check: lint stan arch test ## Everything CI runs on the PHP side

tokens:
	$(NPM) run tokens:build
