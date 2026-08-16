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
	@echo "vendor-js     Download jQuery into the skeleton"
	@echo "serve         Run the skeleton at http://localhost:8000"
	@echo "migrate       Apply pending migrations"
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
	cd skeleton && php -S localhost:8000 -t public

migrate:
	cd skeleton && php database/migrate.php

clean:
	rm -rf vendor skeleton/vendor skeleton/database/*.sqlite skeleton/storage/views/*.php

docker-up:
	docker compose up -d --build

docker-down:
	docker compose down

docker-shell:
	docker compose exec web sh
