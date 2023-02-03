#!/bin/sh

if [ ! -d /var/lib/mysql/jtl_shop ] ; then
   php bin/console system:setup --app-env=dev -n --database-url=$DATABASE_URL
   php bin/console system:install --basic-setup --create-database

   /bin/bash /var/www/html/custom/plugins/Mond1SW6/activate.sh
fi

service apache2 start
tail -f /dev/null

