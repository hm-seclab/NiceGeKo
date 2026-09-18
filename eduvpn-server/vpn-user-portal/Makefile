HTTP_PORT ?= 8082
SRC_DIRS=src web libexec bin

.PHONY: test cov fmt psalm phpstan sloc dev

test:
	phpunit

cov:
	phpunit --coverage-text

fmt:
	php-cs-fixer fix

psalm:
	psalm

phpstan:
	phpstan

sloc:
	tokei $(SRC_DIRS) || cloc $(SRC_DIRS)

dev:
	@php -S localhost:$(HTTP_PORT) -t web dev/router.php
