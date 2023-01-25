#!/bin/bash
shopt -s expand_aliases

SHOPWARE_CLI_PATH="/var/www/html/bin/console"
alias shpw6="php ${SHOPWARE_CLI_PATH}"

shpw6 cache:clear --no-debug
shpw6 plugin:refresh
shpw6 plugin:install Mond1SW6 --no-debug
shpw6 plugin:activate Mond1SW6 --no-debug
shpw6 cache:clear --no-debug

shpw6 Mond1SW6:Test $BNPL_MERCHANT_API_TOKEN 1 --no-debug

shpw6 Mond1SW6:Config:ApiToken $BNPL_MERCHANT_API_TOKEN 1 --no-debug

shpw6 Mond1SW6:Activate:Payment --no-debug

echo "Plugin successfuly activated"
