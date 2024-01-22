.SILENT:

phpstan: reset
	./vendor/bin/phpstan analyze -c phpstan.neon ./seowriting

reset:
	reset
