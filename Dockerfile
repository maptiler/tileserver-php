FROM debian:jessie

RUN apt-get update -y && apt-get install -qq -y \
  apache2 \
  php5 \
  php5-sqlite \
  php5-gd \
  unzip

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
 a2enconf servername && a2enmod rewrite

ADD start.sh \
  https://github.com/klokantech/tileserver-php/archive/master.zip /

RUN chmod +x /start.sh && \
  unzip master.zip && \
  rm -Rf /var/www && \
  cp -rf /tileserver-php-master /var/www

VOLUME /var/www
EXPOSE 80

ENTRYPOINT ["/start.sh"]
