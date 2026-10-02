# This repository holds two Composer packages:
#
#   .          innovin/phpvin           the framework
#   skeleton/  innovin/phpvin-skeleton  an application that consumes it
#
# The skeleton resolves the framework through a path repository, so edits to
# src/ are live in the running app with no reinstall.

.PHONY: help install test lint serve migrate vendor-js package-check clean \
        docker-up docker-down docker-shell

JQUERY_VERSION := 3.7.1
JQUERY_TARGET  := skeleton/public/js/jquery.min.js

help:
	@echo "install       Install both packages' dependencies"
	@echo "test          Run the framework test suite"
	@echo "lint          Syntax-check every PHP file"
	@echo "package-check Verify the framework installs and boots as a real dependency"
	@echo "fuzz          Throw hostile input at every parser"
	@echo "differential  Run generated queries against every driver and compare"
	@echo "memory        Measure what a booted app retains per request"
	@echo "vendor-js     Download jQuery into the skeleton"
	@echo "serve         Run the skeleton at http://localhost:8000"
	@echo "migrate       Apply pending migrations"
	@echo "routes        List every route"
	@echo "clean         Remove installed dependencies and the dev database"
	@echo "docker-up     Start the container stack"

install:
	composer install
	cd skeleton && composer install
	@test -f skeleton/.env || cp skeleton/.env.example skeleton/.env

test:
	./vendor/bin/phpunit --testdox

lint:
	@find src skeleton/app skeleton/routes skeleton/database tests -name '*.php' -print0 \
		| xargs -0 -n1 php -l \
		| grep -v 'No syntax errors' || echo "All files parse."
	@node --check resources/js/phpvin.js && echo "phpvin.js parses."

# Installs the framework the way a stranger would (a copy in vendor/, not a
# symlink to this checkout) and boots an app against it. Catches anything that
# only works because the source happens to sit next door.
package-check:
	@bin/package-check

vendor-js:
	@mkdir -p $(dir $(JQUERY_TARGET))
	curl -fsSL -o $(JQUERY_TARGET) https://code.jquery.com/jquery-$(JQUERY_VERSION).min.js
	@echo "jQuery $(JQUERY_VERSION) -> $(JQUERY_TARGET)"

$(JQUERY_TARGET):
	@$(MAKE) --no-print-directory vendor-js

serve: $(JQUERY_TARGET)
	cd skeleton && ./phpvin serve --port=8000

migrate:
	cd skeleton && ./phpvin migrate

routes:
	cd skeleton && ./phpvin route:list

clean:
	rm -rf vendor skeleton/vendor skeleton/database/*.sqlite skeleton/storage/views/*.php

docker-up:
	docker compose up -d --build

docker-down:
	docker compose down

docker-shell:
	docker compose exec web sh

# --- cross-driver testing ---------------------------------------------------
# The suite is driver-agnostic; these bring up the databases it can run against.
MYSQL_PORT := 33061
PGSQL_PORT := 54321

.PHONY: db-up db-down test-drivers

db-up:
	@docker rm -f phpvin-mysql phpvin-pgsql >/dev/null 2>&1 || true
	docker run -d --name phpvin-mysql -e MYSQL_ROOT_PASSWORD=secret \
		-e MYSQL_DATABASE=phpvin_test -p $(MYSQL_PORT):3306 mysql:8.0 >/dev/null
	docker run -d --name phpvin-pgsql -e POSTGRES_PASSWORD=secret \
		-e POSTGRES_DB=phpvin_test -p $(PGSQL_PORT):5432 postgres:16 >/dev/null
	@printf 'waiting for databases'
	@until docker exec phpvin-mysql mysqladmin ping -psecret >/dev/null 2>&1 \
		&& docker exec phpvin-pgsql pg_isready -U postgres >/dev/null 2>&1; \
		do printf '.'; sleep 1; done; echo ' ready'

db-down:
	@docker rm -f phpvin-mysql phpvin-pgsql >/dev/null 2>&1 || true
	@echo "databases removed"

test-drivers:
	@for d in sqlite mysql pgsql; do \
		printf '\n== %s ==\n' "$$d"; \
		DB_DRIVER=$$d ./vendor/bin/phpunit || exit 1; \
	done

.PHONY: mutate mutate-drivers

# Breaks the source one edit at a time and reruns the suite. A surviving mutant
# is a line that could be wrong with every test still green.
mutate:
	@./bin/mutate

mutate-drivers:
	@./bin/mutate --drivers=sqlite,mysql,pgsql

.PHONY: fuzz

# Hostile input at every parser. A documented exception is a pass; a TypeError
# is a value reaching code that assumed it could not exist. The seed is printed
# so any failure replays exactly.
fuzz:
	@./bin/fuzz --cases=50000

.PHONY: differential

# Asks every driver the same generated question and requires one answer. A
# disagreement is a grammar bug by definition. Needs `make db-up`.
differential:
	@./bin/differential --queries=2000

.PHONY: memory

# What a booted application retains per request. bench answers how fast; this
# answers whether a process that boots once and serves for days is safe.
memory:
	@./bin/memory

.PHONY: bench

# Routing is measured against a realistic table, and the worst case is
# reported next to the best -- an average hides a linear scan.
bench:
	@./bin/bench
