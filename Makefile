PORT ?= 8000

install:
	composer install

validate:
	composer validate

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --ignore='/public/styles/*, /public/scripts/*' public 

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

build_docker_image:
	docker build --tag 'page_analizer' .

start_in_docker:
	docker run -it -p 127.0.0.1:8000:8000 'page_analizer'
