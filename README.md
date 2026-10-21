# EasyDocs

Repositorio de archivos en la nube, clon a Google Drive.

## Stack

- Backend: PHP 8.5 con Laravel (sin ORM, consultas SQL directas)
- Frontend: HTML, CSS y JavaScript
- Base de datos: MySQL

## Infraestructura (AWS)

- EC2 (Ubuntu) con Nginx y PHP-FPM
- RDS MySQL en subred privada, solo accesible desde la EC2
- S3 para guardar los archivos
- IAM Role en la EC2 para acceder al bucket

## Despliegue

En la EC2:

```bash
sudo apt update
sudo apt install -y nginx git unzip mysql-client
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath php8.5-intl
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

Clonar y configurar:

```bash
cd /var/www
git clone <url-del-repo> EasyDocs
cd EasyDocs/backend
composer install --no-dev
cp .env.example .env
php artisan key:generate
```

En el `.env` poner los datos de RDS (`DB_*`) y del bucket (`AWS_BUCKET`, `AWS_DEFAULT_REGION`). Las llaves de AWS se dejan vacías porque se usa el rol.

Crear las tablas:

```bash
mysql -h <endpoint-rds> -u <usuario> -p <base> < database/squema.sql
```

Permisos y caché:

```bash
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
php artisan config:cache
php artisan route:cache
```

Nginx (`/etc/nginx/sites-available/easydocs`):

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /var/www/EasyDocs/frontend;
    index index.html;
    client_max_body_size 110M;

    location / {
        try_files $uri $uri/ =404;
    }

    location ^~ /api/ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/EasyDocs/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/easydocs /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo systemctl reload nginx
```

## Actualizar

```bash
cd /var/www/EasyDocs && git pull
cd backend && php artisan optimize:clear && php artisan config:cache && php artisan route:cache
```
