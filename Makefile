.PHONY: up down serve test

up:
	docker compose up

down:
	docker compose down

serve:
	php -S localhost:8080 -t public

test:
	php tests/run.php
