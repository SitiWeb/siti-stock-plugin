FROM wordpress:6.9-php8.2

# Install handy tooling (git, mariadb client, wp-cli) for local development.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git less vim unzip mariadb-client \
	&& rm -rf /var/lib/apt/lists/* \
	&& curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp

WORKDIR /var/www/html

# Match the default Linux UID/GID to avoid permission headaches with bind mounts.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
