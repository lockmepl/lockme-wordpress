#!/bin/bash

~/.config/composer/vendor/bin/php-scoper add-prefix --force
composer dump-autoload --working-dir build --classmap-authoritative
