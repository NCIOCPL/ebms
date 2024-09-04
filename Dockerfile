FROM ubuntu:noble

RUN DEBIAN_FRONTEND=noninteractive apt update

RUN DEBIAN_FRONTEND=noninteractive \
    apt install -yq \
    apache2 \
    build-essential \
    php8.3 \
    libapache2-mod-php8.3 \
    php8.3-bz2 \
    php8.3-cli \
    php8.3-common \
    php8.3-curl \
    php8.3-fpm \
    php8.3-gd \
    php8.3-mbstring \
    php8.3-memcached \
    php8.3-mysql \
    php8.3-oauth \
    php8.3-opcache \
    php8.3-readline \
    php8.3-sqlite3 \
    php8.3-soap \
    php8.3-xml \
    php8.3-zip \
    mariadb-client \
    ssmtp \
    curl \
    git \
    imagemagick \
    vim \
    python3 \
    emacs-nox \
    elpa-php-mode \
    locales \
    elpa-python-environment \
    wget \
    p7zip \
    zip

ADD templates/php.ini /etc/php/8.3/apache2/php.ini
ADD templates/ssmtp.conf /etc/ssmtp/ssmtp.conf
RUN chmod 666 /etc/ssmtp/ssmtp.conf
RUN a2enmod rewrite

RUN locale-gen en_US.UTF-8
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8
ENV TZ America/New_York

RUN curl -sS https://getcomposer.org/installer | \
    php -- --install-dir=/usr/local/bin --filename=composer

CMD ["apachectl", "-D", "FOREGROUND"]

WORKDIR /var/www
