FROM php:8.4-apache

# Preparation for apt
RUN set -ex
RUN apt-get update \
    && apt-get install -y \
	sqlite3 \
	curl \
	nano \
	git \
	unzip

RUN docker-php-ext-install \
  pdo \
  pdo_sqlite

# Refresh apache2
RUN a2enmod rewrite

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
