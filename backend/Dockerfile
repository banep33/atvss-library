#############################
# Build stage: Vite assets
#############################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

#############################
# PHP
#############################
FROM serversideup/php:8.4-fpm-nginx-alpine AS base
USER root
RUN install-php-extensions gd
USER www-data

FROM base AS prod
WORKDIR $APP_BASE_DIR
ENV COMPOSER_CACHE_DIR=/dev/null

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction --no-ansi --no-progress
COPY --chown=www-data:www-data . ./
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/database bootstrap/cache

FROM prod AS dev

USER root
RUN apk add --no-cache nano
USER www-data

RUN composer install --no-scripts --optimize-autoloader --no-interaction --no-ansi --no-progress && \
    composer dump-autoload

FROM base AS local
# Switch to root so we can do root things
USER root

# Save the build arguments as a variable
ARG USER_ID
ARG GROUP_ID

# Use the build arguments to change the UID
# and GID of www-data while also changing
# the file permissions for NGINX
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    \
    # Update the file permissions for our NGINX service to match the new UID/GID
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Drop back to our unprivileged user
USER www-data
