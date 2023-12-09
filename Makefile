PORT ?= 8000

lint:
	composer exec --verbose phpcs -- --standard=PSR12 src tests public

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
