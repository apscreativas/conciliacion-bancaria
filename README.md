# Conciliación Bancaria

Aplicación para la conciliación de facturas XML (CFDI) con movimientos bancarios (estados de cuenta), con soporte multiempresa.

> Documentación técnica completa: [docs/INDEX.md](docs/INDEX.md)
> Reglas para agentes IA: [CLAUDE.md](CLAUDE.md) y [AGENTS.md](AGENTS.md)

---

## Requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) corriendo

---

## Configuración inicial

```bash
# 1. Copiar variables de entorno
cp .env.example .env

# 2. Instalar dependencias PHP (usa Docker, no requiere PHP local)
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html composer:latest composer install --ignore-platform-reqs --no-interaction

# 3. Levantar todos los contenedores
./vendor/bin/sail up -d

# 4. Generar clave de aplicación
./vendor/bin/sail artisan key:generate

# 5. Ejecutar migraciones
./vendor/bin/sail artisan migrate

# 6. Compilar assets del frontend
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

La app estará disponible en **http://localhost**

---

## Servicios incluidos

| Servicio        | Descripción                              | Puerto |
| --------------- | ---------------------------------------- | ------ |
| `laravel.test`  | App principal (PHP 8.5)                  | 80     |
| `mysql`         | Base de datos MySQL 8.4                  | 3306   |
| `redis`         | Cache y colas                            | 6379   |
| `mailpit`       | Captura de emails (desarrollo)           | 8025   |
| `queue-imports` | Worker: procesa XMLs y estados de cuenta | —      |
| `queue-exports` | Worker: genera reportes Excel/PDF        | —      |

> Los workers de cola se inician **automáticamente** con `sail up`.

---

## Comandos útiles

```bash
# Detener contenedores
./vendor/bin/sail stop

# Ver logs de los workers
docker logs conciliacion-bancaria-queue-imports-1 -f
docker logs conciliacion-bancaria-queue-exports-1 -f

# Acceder al shell del contenedor
./vendor/bin/sail shell

# Correr tests
./vendor/bin/sail artisan test --compact
```

---

## Despliegue en Producción

### Requisitos del servidor

- PHP 8.5+ con extensiones: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `phpredis`
- MySQL 8.0+
- Redis
- Composer 2+
- Node.js 18+ y npm (solo para build)
- Nginx o Apache
- Supervisor (para queue workers)

### 1. Configuración del entorno

```bash
cp .env.example .env
```

Ajustar valores críticos en `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com

DB_HOST=tu-host-db
DB_DATABASE=nombre_db
DB_USERNAME=usuario
DB_PASSWORD=contraseña_segura

REDIS_HOST=tu-host-redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

LOG_LEVEL=error

MAIL_MAILER=smtp
MAIL_HOST=tu-smtp-host.com
MAIL_PORT=465
MAIL_USERNAME=tu-correo@dominio.com
MAIL_PASSWORD=tu-password-smtp
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=tu-correo@dominio.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 2. Instalar y compilar

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 3. Configurar Nginx

```nginx
server {
    listen 80;
    server_name tu-dominio.com;
    root /var/www/conciliacion/public;

    index index.php;

    charset utf-8;
    client_max_body_size 50M;  # Para carga de XMLs y estados de cuenta

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 4. Configurar Queue Workers (Supervisor)

Crear `/etc/supervisor/conf.d/conciliacion-worker.conf`:

```ini
[program:conciliacion-imports]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/conciliacion/artisan queue:work --queue=imports --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/conciliacion/storage/logs/worker-imports.log

[program:conciliacion-exports]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/conciliacion/artisan queue:work --queue=exports --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/conciliacion/storage/logs/worker-exports.log

[program:conciliacion-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/conciliacion/artisan queue:work --queue=default --sleep=3 --tries=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/conciliacion/storage/logs/worker-default.log
```

Activar:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start conciliacion-imports:*
sudo supervisorctl start conciliacion-exports:*
sudo supervisorctl start conciliacion-default:*
```

### 5. Permisos de archivos

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 6. Despliegues posteriores

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart conciliacion-imports:* conciliacion-exports:* conciliacion-default:*
```

> **Importante:** Los workers `queue-imports` y `queue-exports` deben estar corriendo para que la importación de archivos y la generación de reportes funcionen.

---

## Primera vez en la app

1. Registrarse (se crea un equipo por defecto).
2. Ir a **Formatos Bancarios** y crear el formato de tu banco (ej. BBVA).
3. Ir a **Mesa de Trabajo** → cargar XMLs y estados de cuenta.
4. Usar **Auto Conciliar** o seleccionar manualmente.
