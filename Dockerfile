FROM php:8.2-apache
LABEL authors="innovin"
EXPOSE 80
WORKDIR /var/www/html
COPY . .
COPY src/.htaccess .

