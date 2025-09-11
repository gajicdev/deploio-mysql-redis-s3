FROM php:8.2-apache

# Install necessary PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy the project code into the container
COPY . /var/www/html