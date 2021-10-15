phpcs:
	vendor/bin/php-cs-fixer fix --dry-run --verbose --config .php_cs.php

phpcs-fix:
	vendor/bin/php-cs-fixer fix --verbose --config .php_cs.php

phpstan:
	vendor/bin/phpstan analyse --level max src

phpunit:
	vendor/bin/phpunit

test: phpstan phpunit
