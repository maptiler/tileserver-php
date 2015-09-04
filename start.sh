#!/bin/bash
echo "Starting tileserver-php container"
echo "---------------------------------"
file="/var/www/tileserver.php"
if [ -f "$file" ]
then
  echo "$file found in the volume."
else
  echo "Copying tileserver.php into volume..."
  cp /tileserver-php-master/* /tileserver-php-master/.* /var/www/
fi
echo
echo "Apache logs:"
echo
/usr/sbin/apache2ctl -e info -D FOREGROUND
