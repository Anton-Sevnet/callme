# 🚀 CI/CD Deployment Guide

Инструкция по настройке автоматической доставки кода на удаленный сервер.

---

## 📋 Содержание

1. [Подготовка сервера](#1-подготовка-сервера)
2. [Вариант A: GitHub Actions (рекомендуется)](#2-вариант-a-github-actions)
3. [Вариант B: GitHub Webhook](#3-вариант-b-github-webhook)
4. [Вариант C: Ручной деплой](#4-вариант-c-ручной-деплой)
5. [Настройка безопасности](#5-настройка-безопасности)
6. [Откат изменений](#6-откат-изменений)

---

## 1. Подготовка сервера

### 1.1. Установка необходимых компонентов

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка Git
sudo apt install git -y

# Установка PHP и расширений
sudo apt install php php-cli php-mbstring php-curl php-json -y

# Установка Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

### 1.2. Первоначальное клонирование проекта

```bash
# Создайте директорию для проекта
sudo mkdir -p /var/www/callme
cd /var/www/callme

# Клонируйте репозиторий
git clone https://github.com/Anton-Sevnet/callme.git .

# Установите зависимости
composer install --no-dev

# Создайте конфигурационный файл (скопируйте из примера)
cp config.example.php config.php
nano config.php  # Отредактируйте настройки

# Настройте права доступа
sudo chown -R www-data:www-data /var/www/callme
sudo chmod -R 755 /var/www/callme
```

### 1.3. Настройка SSH-ключей (для GitHub Actions)

```bash
# Генерация SSH-ключа для деплоя
ssh-keygen -t ed25519 -C "deploy@your-server" -f ~/.ssh/deploy_key

# Добавьте публичный ключ в authorized_keys
cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys

# Скопируйте приватный ключ (понадобится для GitHub Secrets)
cat ~/.ssh/deploy_key
```

---

## 2. Вариант A: GitHub Actions (рекомендуется)

### 2.1. Настройка GitHub Secrets

Перейдите в настройки репозитория: **Settings → Secrets and variables → Actions → New repository secret**

Добавьте следующие секреты:

| Name | Value | Description |
|------|-------|-------------|
| `SSH_HOST` | `your-server.com` | IP или домен сервера |
| `SSH_USER` | `deploy` или `root` | Пользователь SSH |
| `SSH_PRIVATE_KEY` | `содержимое deploy_key` | Приватный SSH ключ |
| `SSH_PORT` | `22` | Порт SSH (опционально) |
| `PROJECT_PATH` | `/var/www/callme` | Путь к проекту |

### 2.2. Активация workflow

Файл `.github/workflows/deploy.yml` уже создан в репозитории.

**Как это работает:**
- При каждом push в ветку `master` автоматически запускается деплой
- Код проверяется, устанавливаются зависимости
- Изменения загружаются на сервер через SSH

**Ручной запуск:**
1. Перейдите в **Actions** → **Deploy to Server**
2. Нажмите **Run workflow**

---

## 3. Вариант B: GitHub Webhook

### 3.1. Настройка на сервере

```bash
# Создайте директорию для webhook
sudo mkdir -p /var/www/deploy
sudo chown www-data:www-data /var/www/deploy

# Скопируйте webhook.php на сервер
sudo cp deploy/webhook.php /var/www/deploy/

# Отредактируйте конфигурацию
sudo nano /var/www/deploy/webhook.php
```

**Измените в webhook.php:**
```php
define('SECRET_KEY', 'ваш-секретный-ключ-123456');
define('PROJECT_PATH', '/var/www/callme');
define('BRANCH', 'master');
```

### 3.2. Настройка веб-сервера (Nginx)

```nginx
server {
    listen 80;
    server_name deploy.your-server.com;
    
    root /var/www/deploy;
    index webhook.php;
    
    location / {
        try_files $uri $uri/ /webhook.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index webhook.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

```bash
# Перезапустите nginx
sudo nginx -t
sudo systemctl restart nginx
```

### 3.3. Настройка в GitHub

1. Перейдите: **Settings → Webhooks → Add webhook**
2. Заполните:
   - **Payload URL**: `http://deploy.your-server.com/webhook.php`
   - **Content type**: `application/json`
   - **Secret**: тот же ключ, что в `SECRET_KEY`
   - **Events**: выберите `Just the push event`
3. Нажмите **Add webhook**

**Как это работает:**
- GitHub отправляет POST-запрос при каждом push
- Webhook скрипт выполняет `git pull` и обновляет код
- Логи сохраняются в `deploy/deploy.log`

---

## 4. Вариант C: Ручной деплой

### 4.1. Использование скрипта deploy.sh

```bash
# Сделайте скрипт исполняемым
chmod +x /var/www/callme/deploy/deploy.sh

# Запустите деплой
/var/www/callme/deploy/deploy.sh master

# Откат к предыдущей версии
/var/www/callme/deploy/deploy.sh rollback
```

### 4.2. Простой ручной деплой

```bash
cd /var/www/callme
git pull origin master
composer install --no-dev --optimize-autoloader
```

---

## 5. Настройка безопасности

### 5.1. Ограничение прав пользователя

```bash
# Создайте отдельного пользователя для деплоя
sudo adduser deploy
sudo usermod -aG www-data deploy

# Настройте права
sudo chown -R deploy:www-data /var/www/callme
sudo chmod -R 750 /var/www/callme
```

### 5.2. Настройка sudoers (если нужен перезапуск сервисов)

```bash
sudo visudo
```

Добавьте строку:
```
deploy ALL=(ALL) NOPASSWD: /bin/systemctl restart asterisk, /bin/systemctl restart apache2
```

### 5.3. Файрволл

```bash
# Разрешите только необходимые порты
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
```

---

## 6. Откат изменений

### Вариант 1: Через скрипт

```bash
/var/www/callme/deploy/deploy.sh rollback
```

### Вариант 2: Вручную

```bash
cd /var/www/callme

# Посмотрите историю коммитов
git log --oneline -10

# Откатитесь на нужный коммит
git reset --hard <commit-hash>

# Обновите зависимости
composer install --no-dev --optimize-autoloader
```

### Вариант 3: Восстановление из бэкапа

```bash
# Восстановите конфигурацию
cp /var/www/callme/backups/config_YYYYMMDD_HHMMSS.php /var/www/callme/config.php
```

---

## 7. Мониторинг и логи

### Просмотр логов деплоя

```bash
# Webhook логи
tail -f /var/www/deploy/deploy.log

# Скрипт деплоя логи
tail -f /var/www/callme/deploy/deploy.log

# Git операции
cd /var/www/callme && git log --oneline -10
```

### GitHub Actions логи

1. Перейдите в **Actions** в репозитории
2. Выберите нужный workflow run
3. Просмотрите подробные логи каждого шага

---

## 8. Troubleshooting

### Проблема: Permission denied

```bash
# Проверьте владельца файлов
ls -la /var/www/callme

# Исправьте права
sudo chown -R www-data:www-data /var/www/callme
sudo chmod -R 755 /var/www/callme
```

### Проблема: Git pull конфликты

```bash
cd /var/www/callme
git fetch origin
git reset --hard origin/master
```

### Проблема: Composer errors

```bash
# Очистите кэш
composer clear-cache

# Переустановите зависимости
rm -rf vendor/
composer install --no-dev
```

---

## 9. Дополнительные возможности

### Уведомления в Telegram

Добавьте в webhook.php или deploy.sh отправку уведомлений:

```php
// В конце webhook.php
function sendTelegramNotification($message) {
    $botToken = 'YOUR_BOT_TOKEN';
    $chatId = 'YOUR_CHAT_ID';
    
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// После успешного деплоя
sendTelegramNotification("✅ Deployment successful!\nBranch: master\nTime: " . date('Y-m-d H:i:s'));
```

---

## 📞 Поддержка

При возникновении проблем проверьте:
1. Логи деплоя
2. Права доступа к файлам
3. Доступность сервера по SSH
4. Корректность GitHub Secrets
5. Настройки firewall

---

**Создано для проекта CallMe v2 - Asterisk-Bitrix24 Integration**

