FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Copy app files
COPY ./public/ /var/www/html/

# Apache expects to run on port 10000 on Render
RUN sed -i 's/80/10000/' /etc/apache2/ports.conf && \
    sed -i 's/80/10000/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

# Enable mod_rewrite if needed
RUN a2enmod rewrite