# Инструкция по развертыванию скрипта отчета по МАХ (stat_max.php)

## 1. Назначение
Скрипт stat_max.php формирует отчёт по активности пользователей в системе МАХ за выбранный период:

общее количество чатов МАХ;
созданные чаты за период;
статусы рассмотрения дел;
уведомления о готовности результатов;
подтверждённые предварительные записи.

## 2. Требования к серверу

Веб-сервер: Apache / Nginx / любой, поддерживающий PHP.

PHP: версия 7.4
Расширение PHP для PostgreSQL: pgsql или pdo_pgsql.
Доступ к PostgreSQL: сервер должен быть доступен, иметь базу данных и учётные данные.
Структура каталогов: скрипт использует файлы config/db.php и server.ini, которые должны находиться в корневой директории веб-сервера (DocumentRoot). Сам скрипт размещается в подпапке maxcount внутри DocumentRoot.

## 3. Определение DocumentRoot
Перед установкой определите, где находится корневая директория вашего веб-сервера (DocumentRoot). Это можно сделать следующими способами:

Для Apache:

```bash
sudo grep -i DocumentRoot /etc/apache2/sites-enabled/*
```
Обычно это /var/www/html или /var/www.

Для Nginx:

```bash
sudo grep -i root /etc/nginx/sites-enabled/*
```
Через существующие файлы: если у вас уже есть файлы server.ini и config/db.php, они, скорее всего, находятся в DocumentRoot. Проверьте их расположение:

```bash
find / -name "server.ini" 2>/dev/null
find / -name "db.php" 2>/dev/null
```
В примерах ниже DocumentRoot будет обозначаться как [DOCUMENT_ROOT]. Замените его на реальный путь, полученный выше (например, /var/www/www_https).

## 4. Подготовка файлов конфигурации
### 4.1. Файл [DOCUMENT_ROOT]/config/db.php
Если файл отсутствует, создайте его со следующим содержимым:

```php
<?php
return [
    'pass' => 'пароль_пользователя_postgres'
];
```

Скрипт использует только ключ 'pass'. Если файл уже существует, убедитесь, что он возвращает массив с этим ключом. Пример корректного содержимого:

```php
<?php return ['pass' => 'mypassword'];
```
### 4.2. Файл [DOCUMENT_ROOT]/server.ini
Файл должен содержать секцию [SERVERINF] с параметрами подключения к БД. Пример:

```ini
[SERVERINF]
SERVER = "127.0.0.1"          ; или IP-адрес сервера БД
DATABASE = "mfc"              ; имя базы данных
PORT = 5432                   ; порт PostgreSQL (по умолчанию 5432)
USER = "postgres"             ; пользователь БД
PASSWORD = "пароль"           ; пароль (необязателен, т.к. берётся из db.php)
```
Скрипт читает из этой секции параметры SERVER, DATABASE, PORT, USER. Пароль он берёт из db.php. Убедитесь, что эти параметры заполнены корректно.

## 5. Размещение скрипта
Внутри DocumentRoot создайте папку maxcount:

``bash
sudo mkdir [DOCUMENT_ROOT]/maxcount
```
Скопируйте файл stat_max.php в эту папку:

```bash
sudo cp stat_max.php [DOCUMENT_ROOT]/maxcount/
```
Установите права на чтение:

```bash
sudo chmod 644 [DOCUMENT_ROOT]/maxcount/stat_max.php
```
## 6. Настройка прав доступа
Убедитесь, что веб-сервер имеет права на чтение всех необходимых файлов. Владельцем обычно является пользователь, от которого работает веб-сервер (например, www-data для Apache, nginx для Nginx). Выполните команды, заменив [USER] на нужного пользователя:

```bash
sudo chown -R [USER]:[USER] [DOCUMENT_ROOT]/maxcount
sudo chown [USER]:[USER] [DOCUMENT_ROOT]/config/db.php
sudo chown [USER]:[USER] [DOCUMENT_ROOT]/server.ini
sudo chmod 644 [DOCUMENT_ROOT]/maxcount/stat_max.php
sudo chmod 644 [DOCUMENT_ROOT]/config/db.php
sudo chmod 644 [DOCUMENT_ROOT]/server.ini
```
Если вы не знаете, от какого пользователя работает веб-сервер, посмотрите в конфигурации или выполните:

```bash
ps aux | grep -E 'apache|nginx'
```

## 7. Проверка работоспособности
### 7.1. Проверка расширения PostgreSQL
Создайте временный файл info.php в папке maxcount:

```bash
echo "<?php phpinfo(); ?>" | sudo tee [DOCUMENT_ROOT]/maxcount/info.php
```

Откройте в браузере http://ваш_сервер/maxcount/info.php. Найдите раздел pgsql. Если его нет, установите расширение:

```bash
sudo apt update
sudo apt install php-pgsql
sudo systemctl restart apache2   # или nginx
```
После проверки удалите info.php.

### 7.2. Проверка подключения к БД
Выполните команду, используя данные из server.ini, чтобы убедиться, что БД доступна:

```bash
psql -h 127.0.0.1 -U postgres -d mfc -c "SELECT 1"
```
Если подключение успешно, вы увидите результат.

### 7.3. Запуск скрипта
Откройте в браузере: http://ваш_сервер/maxcount/stat_max.php. Выберите период и нажмите «Сформировать отчет». Если данные отображаются – скрипт работает корректно.

## 8. Возможные проблемы и их решение
### 8.1. Ошибка «DB connect error»
Проверьте параметры в server.ini и db.php.

Убедитесь, что PostgreSQL запущен: sudo systemctl status postgresql.

Проверьте файл pg_hba.conf – разрешены ли подключения с веб-сервера.

### 8.2. Пустой отчет
Убедитесь, что выбранный период содержит данные.

Проверьте, что в таблицах есть записи с service_kind = 5 (МАХ).

Проверьте названия шаблонов уведомлений в SQL-запросах скрипта (список template_name).

### 8.3. Ошибка «Некорректная дата»
Браузер должен передавать дату в формате ГГГГ-ММ-ДД. Используйте стандартный календарь поля type="date".

### 8.4. Не работает копирование числа
Функция копирования использует document.execCommand('copy'). Если не работает, обновите браузер или используйте ручное выделение и Ctrl+C.

### 8.5. Ошибка «Call to undefined function pg_connect()»
Расширение PostgreSQL не установлено. Установите php-pgsql и перезапустите веб-сервер.

## 9. Заключение
Скрипт готов к использованию. Для доступа к отчёту используйте URL вида http://ваш_сервер/maxcount/stat_max.php. При необходимости адаптируйте SQL-запросы под свою структуру БД. Успешной работы!
