FROM php:8.2-apache

# Enable rewrite module
RUN a2enmod rewrite

# Copy files
COPY . /var/www/html/

# Configure Apache to allow .htaccess
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf \
    && a2enconf override

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
