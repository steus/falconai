FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        oniguruma-dev \
        sqlite-dev \
        tzdata \
    && docker-php-ext-install mbstring pdo_sqlite \
    && apk del oniguruma-dev sqlite-dev \
    && apk add --no-cache oniguruma sqlite-libs

RUN apk add --no-cache su-exec

ENV TZ=Europe/Kyiv

WORKDIR /app

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY . /app

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
