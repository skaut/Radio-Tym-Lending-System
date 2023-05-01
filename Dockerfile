FROM php:7.4-apache

# Preparation for apt
RUN set -ex
RUN apt-get update \
    && apt-get install -y \
	sqlite3 \
	curl \
	nano \
	git \
	zip \
    libpq-dev \
    zlib1g-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev

RUN docker-php-ext-install \
  gd \
  pcntl \
  pdo \
  pdo_pgsql \
  pgsql

# Refresh apache2
RUN a2enmod rewrite

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
