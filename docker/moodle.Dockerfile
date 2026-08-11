# Moodle 5.1 LTS on PHP 8.3 + Apache
#
# Moodle source is fetched at build time (not vendored) so the image can be
# rebuilt against a newer point release by bumping MOODLE_TARBALL alone.
# Our own plugins are mounted as volumes in docker-compose so editing a PHP
# file does not require a rebuild.
FROM php:8.3-apache

ARG MOODLE_TARBALL=https://packaging.moodle.org/stable501/moodle-latest-501.tgz

# Moodle's required extensions. `exif` and `intl` are optional in the installer
# but the file API and Thai-language sorting misbehave without them.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
        libxml2-dev libicu-dev libcurl4-openssl-dev \
        cron ghostscript unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pgsql pdo_pgsql gd zip intl soap exif opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Moodle 5.0 moved the web root into public/ — everything above it (lib/,
# admin/cli/, vendor code) must stay off the web. Serving /var/www/html
# directly would expose config.php and the CLI scripts.
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf

# Apache ships with Timeout 300, which is below the 330 the plugin waits for
# the AI service. Left alone, Apache cuts the connection first and the learner
# gets a bare 504 instead of the service's own account of what went wrong.
#
# The chain has to widen outwards, or the innermost diagnosis never escapes:
#
#     ai-service 300  <  ai_client 330  <  Apache 420  <  the browser
RUN sed -ri 's/^Timeout [0-9]+/Timeout 420/' /etc/apache2/apache2.conf

# Moodle refuses to install below these values.
RUN { \
        echo 'max_input_vars = 5000'; \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 128M'; \
        echo 'post_max_size = 128M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/moodle.ini

WORKDIR /var/www/html
RUN curl -fsSL "$MOODLE_TARBALL" -o /tmp/moodle.tgz \
    && tar -xzf /tmp/moodle.tgz --strip-components=1 -C /var/www/html \
    && rm /tmp/moodle.tgz \
    && chown -R www-data:www-data /var/www/html

# moodledata lives outside the web root — never serve it.
RUN mkdir -p /var/moodledata && chown -R www-data:www-data /var/moodledata

COPY docker/moodle-entrypoint.sh /usr/local/bin/moodle-entrypoint.sh
RUN chmod +x /usr/local/bin/moodle-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/moodle-entrypoint.sh"]
CMD ["apache2-foreground"]
