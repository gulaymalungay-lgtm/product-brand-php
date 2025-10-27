FROM php:8.2-apache

# Copy your PHP code into the container
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for web traffic
EXPOSE 80

# Start Apache web server
CMD ["apache2-foreground"]
