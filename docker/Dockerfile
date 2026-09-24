FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx

COPY . /app
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-bbs.ini /usr/local/etc/php/conf.d/zz-bbs.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /app/app/data /app/app/upload /app/app/avatars /run/nginx \
    && chown -R www-data:www-data /app/app

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
