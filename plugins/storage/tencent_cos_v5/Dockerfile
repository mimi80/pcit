FROM khs1994/php:7.2.10-fpm-alpine

COPY composer.json /srv/pcit/plugin/cosv5/

RUN composer --working-dir=/srv/pcit/plugin/cosv5 install \
    && rm -rf /tmp/cache

COPY index.php .env.example /srv/pcit/plugin/cosv5/

ENTRYPOINT ["php"]

CMD ["/srv/pcit/plugin/cosv5/index.php"]
