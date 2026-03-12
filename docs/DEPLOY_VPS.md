# Deploy seguro em VPS (Ubuntu + Nginx + PHP-FPM + MySQL)

## 1) Preparar servidor
- Instalar pacotes:
  - `sudo apt update`
  - `sudo apt install -y nginx mysql-server php-fpm php-mysql php-curl php-mbstring php-xml php-zip certbot python3-certbot-nginx`

## 2) Banco e usuário dedicado
- Criar banco e usuário com permissão mínima:
  - `sudo mysql`
  - `CREATE DATABASE servicos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
  - `CREATE USER 'flow_app'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';`
  - `GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON servicos_db.* TO 'flow_app'@'localhost';`
  - `FLUSH PRIVILEGES;`

## 3) Publicar aplicação
- Copiar código para `/var/www/flow`.
- Dar permissão de escrita somente em uploads:
  - `sudo chown -R www-data:www-data /var/www/flow/uploads /var/www/flow/storage`
  - `sudo chmod -R 775 /var/www/flow/uploads /var/www/flow/storage`
- Criar `.env` baseado no `.env.example`:
  - `APP_BASE_PATH=` (vazio para domínio raiz)
  - `APP_AUTO_MIGRATE=false`
  - `DB_*` com usuário `flow_app`
  - `MAIL_PROVIDER=resend`, `RESEND_API_KEY`, `MAIL_FROM`

## 4) Rodar migração inicial
- Execute o SQL:
  - `mysql -u flow_app -p servicos_db < /var/www/flow/scripts/migrations/001_init.sql`
  - `mysql -u flow_app -p servicos_db < /var/www/flow/scripts/migrations/002_stripe.sql`

## 5) Nginx (vhost + bloqueios de segredos)
- Exemplo de bloco:
```nginx
server {
    listen 80;
    server_name seu-dominio.com;
    root /var/www/flow;
    index index.php index.html;

    client_max_body_size 12M;

    # Bloqueia segredos e pastas internas
    location ~ /\.(?!well-known) { deny all; }
    location = /.env { deny all; }
    location ^~ /secure/ { deny all; }
    location ^~ /storage/ { deny all; }
    location ^~ /scripts/ { deny all; }
    location ^~ /docs/ { deny all; }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~* ^/uploads/.*\.(php|phtml|php3|php4|php5|php7|php8)$ {
        deny all;
    }
}
```

## 6) HTTPS obrigatório
- Emitir e forçar SSL:
  - `sudo certbot --nginx -d seu-dominio.com`

## 7) PHP produção (erros e limites)
- Em `php.ini`:
  - `display_errors = Off`
  - `log_errors = On`
  - `upload_max_filesize = 6M`
  - `post_max_size = 12M`
- Reiniciar serviços:
  - `sudo systemctl restart php8.2-fpm nginx`

## 8) Backup diário
- Banco:
  - `mysqldump -u flow_app -p'SENHA' servicos_db > /backup/servicos_db_$(date +%F).sql`
- Uploads:
  - `tar -czf /backup/uploads_$(date +%F).tar.gz /var/www/flow/uploads`
- Agendar via `crontab` e testar restore em ambiente separado.

## 9) Rate limit anti abuso
- Nginx (login e feedback):
```nginx
limit_req_zone $binary_remote_addr zone=flow_limit:10m rate=10r/m;

location = /access/login.php { limit_req zone=flow_limit burst=20 nodelay; }
location = /access/submit_feedback.php { limit_req zone=flow_limit burst=20 nodelay; }
location = /access/stripe_webhook.php { limit_req zone=flow_limit burst=60 nodelay; }
```
- Opcional: Fail2ban para tentativas repetidas.

## Stripe (Checklist)
- `.env`: preencher `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_ID`.
- Stripe Dashboard: criar webhook para `/access/stripe_webhook.php` e assinar:
  - `checkout.session.completed`
  - `invoice.payment_succeeded`
  - `invoice.payment_failed`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
