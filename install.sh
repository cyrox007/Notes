#!/bin/bash

# ============================================
# Workspace Organizer - Installer Script
# Версия: 1.0
# ============================================

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Пути
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
DEFAULT_ENV_FILE="${SCRIPT_DIR}/default.env"
DATABASE_DIR="${SCRIPT_DIR}/database"

# Логотип
print_logo() {
    echo -e "${CYAN}"
    cat << "EOF"
 _      __                     ____                __              
| | /| / /__  ____ _____ ___   / __ \___  _________/ /_  ___  _____
| |/ |/ / _ \/ __ `/ __ `__ \ / /_/ / _ \/ ___/ __  / / / / / / _ \
|  /|  /  __/ /_/ / / / / / // _, _/  __/ /  / /_/ / / /_/ / /  __/
|__/|__/\___/\__,_/_/ /_/ /_//_/ |_|\___/_/   \__,_/_/\__,_/ \___/  
EOF
    echo -e "${NC}"
    echo -e "${BLUE}Workspace Organizer - Installer v1.0${NC}"
    echo ""
}

# Вывод сообщения об успехе
success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# Вывод сообщения об ошибке
error() {
    echo -e "${RED}✗ $1${NC}"
}

# Вывод информационного сообщения
info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# Вывод предупреждения
warn() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Проверка наличия PHP
check_php() {
    info "Проверка PHP..."
    if ! command -v php &> /dev/null; then
        error "PHP не найден! Требуется PHP 8.3+"
        exit 1
    fi
    
    PHP_VERSION=$(php -r "echo PHP_VERSION;" 2>/dev/null)
    PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;" 2>/dev/null)
    PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;" 2>/dev/null)
    
    if [[ "$PHP_MAJOR" -lt 8 ]] || [[ "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 3 ]]; then
        error "Требуется PHP 8.3+, у вас версия $PHP_VERSION"
        exit 1
    fi
    
    success "PHP $PHP_VERSION найден"
}

# Проверка расширений PHP
check_php_extensions() {
    info "Проверка расширений PHP..."
    
    local required_extensions=("pdo_mysql" "json" "mbstring" "openssl" "fileinfo" "pdo_sqlite")
    local missing=()
    
    for ext in "${required_extensions[@]}"; do
        if ! php -m | grep -qi "$ext"; then
            missing+=("$ext")
        fi
    done
    
    if [ ${#missing[@]} -gt 0 ]; then
        warn "Отсутствуют расширения: ${missing[*]}"
        info "Установите их командой:"
        if command -v apt-get &> /dev/null; then
            echo "   sudo apt-get install php${PHP_MAJOR}.${PHP_MINOR}-${missing[*]}"
        elif command -v yum &> /dev/null; then
            echo "   sudo yum install php-${missing[*]}"
        fi
        echo ""
        read -p "Продолжить без проверки расширений? (y/n): " continue_check
        if [[ ! "$continue_check" =~ ^[Yy]$ ]]; then
            exit 1
        fi
    else
        success "Все необходимые расширения найдены"
    fi
}

# Проверка Composer
check_composer() {
    info "Проверка Composer..."
    if ! command -v composer &> /dev/null; then
        warn "Composer не найден"
        read -p "Установить зависимости без Composer? (y/n): " skip_composer
        if [[ ! "$skip_composer" =~ ^[Yy]$ ]]; then
            echo "Установите Composer: https://getcomposer.org/download/"
            exit 1
        fi
    else
        success "Composer найден"
    fi
}

# Проверка MySQL клиента
check_mysql_client() {
    info "Проверка MySQL клиента..."
    if command -v mysql &> /dev/null; then
        success "MySQL клиент найден"
        return 0
    elif command -v mariadb &> /dev/null; then
        success "MariaDB клиент найден"
        return 0
    else
        warn "MySQL/MariaDB клиент не найден"
        info "Для работы с MySQL потребуется клиент"
        return 1
    fi
}

# Проверка существования .env файла
check_env_file() {
    info "Проверка конфигурационного файла..."
    
    if [ -f "$ENV_FILE" ]; then
        success "Файл .env найден"
        return 0
    else
        warn "Файл .env не найден"
        return 1
    fi
}

# Создание .env файла из default.env
create_env_file() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Настройка базы данных${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    # Копируем default.env если он существует
    if [ -f "$DEFAULT_ENV_FILE" ]; then
        cp "$DEFAULT_ENV_FILE" "$ENV_FILE"
        success "Создан файл .env из default.env"
    else
        # Создаем новый файл с дефолтными значениями
        cat > "$ENV_FILE" << 'EOF'
# База данных
DBDRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBUSER=root
DBPASS=
DBNAME=workspace_db

# Ключи шифрования
MSG_SECRET_KEY=
NOTE_SECRET_KEY=
UNIQUE_KEY=unique_encryption_key_for_files
SECONDARY_KEY=secondary_key_for_backup_encryption

# Пути загрузки
UPLOAD_DIR=/var/www/uploads/messenger
NOTES_UPLOAD_DIR=/var/www/uploads/notes

# Ограничения
MAX_UPLOAD_SIZE=10485760
MAX_NOTE_ATTACHMENTS=10

# Настройки приложения
SITEURL=http://localhost
BASE_PATH=/

# WebSocket
WS_HOST=0.0.0.0
WS_PORT=8080

# Логирование
LOG_LEVEL=DEBUG
LOG_FILE=/var/log/messenger/app.log

# Безопасность
SESSION_LIFETIME=3600
MAX_LOGIN_ATTEMPTS=5
CSRF_ENABLED=true
EOF
        success "Создан новый файл .env"
    fi
    
    echo ""
    info "Заполните параметры подключения к базе данных:"
    echo ""
    
    # Чтение текущих значений
    source "$ENV_FILE" 2>/dev/null || true
    
    # Выбор драйвера БД
    echo -n "Тип БД (mysql/sqlite) [${DBDRIVER:-mysql}]: "
    read db_driver_input
    DB_DRIVER=${db_driver_input:-${DBDRIVER:-mysql}}
    
    if [ "$DB_DRIVER" = "sqlite" ]; then
        echo -n "Путь к SQLite файлу [./database/workspace.db]: "
        read sqlite_path
        DB_NAME=${sqlite_path:-./database/workspace.db}
        DB_HOST=""
        DB_PORT=""
        DB_USER=""
        DB_PASS=""
    else
        # Хост БД
        echo -n "Хост БД [${DBHOST:-localhost}]: "
        read db_host_input
        DB_HOST=${db_host_input:-${DBHOST:-localhost}}
        
        # Порт БД
        echo -n "Порт БД [${DBPORT:-3306}]: "
        read db_port_input
        DB_PORT=${db_port_input:-${DBPORT:-3306}}
        
        # Пользователь БД
        echo -n "Пользователь БД [${DBUSER:-root}]: "
        read db_user_input
        DB_USER=${db_user_input:-${DBUSER:-root}}
        
        # Пароль БД
        echo -n "Пароль БД: "
        read -s DB_PASS_INPUT
        echo ""
        DB_PASS=${DB_PASS_INPUT:-${DBPASS}}
        
        # Имя БД
        echo -n "Имя БД [${DBNAME:-workspace_db}]: "
        read db_name_input
        DB_NAME=${db_name_input:-${DBNAME:-workspace_db}}
    fi
    
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Генерация ключей шифрования${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    # Генерация ключей шифрования
    if command -v openssl &> /dev/null; then
        info "Генерация ключей шифрования..."
        MSG_SECRET_KEY=$(openssl rand -hex 32)
        NOTE_SECRET_KEY=$(openssl rand -hex 32)
        UNIQUE_KEY=$(openssl rand -hex 32)
        SECONDARY_KEY=$(openssl rand -hex 32)
        success "Ключи сгенерированы"
    else
        warn "OpenSSL не найден, используем дефолтные ключи (НЕБЕЗОПАСНО!)"
        MSG_SECRET_KEY="change_this_to_your_32_char_secret_key_now!"
        NOTE_SECRET_KEY="change_this_to_your_32_char_note_secret_key!"
        UNIQUE_KEY="unique_encryption_key_for_files"
        SECONDARY_KEY="secondary_key_for_backup_encryption"
    fi
    
    # Обновление файла .env
    cat > "$ENV_FILE" << EOF
# ============================================
# Workspace Organizer - Configuration
# Сгенерировано установщиком: $(date)
# ============================================

# --------------------------------------------
# База данных
# --------------------------------------------
DBDRIVER=${DB_DRIVER}
DBHOST=${DB_HOST}
DBPORT=${DB_PORT}
DBUSER=${DB_USER}
DBPASS=${DB_PASS}
DBNAME=${DB_NAME}

# --------------------------------------------
# Шифрование (AES-256)
# --------------------------------------------
MSG_SECRET_KEY=${MSG_SECRET_KEY}
NOTE_SECRET_KEY=${NOTE_SECRET_KEY}
UNIQUE_KEY=${UNIQUE_KEY}
SECONDARY_KEY=${SECONDARY_KEY}

# --------------------------------------------
# Пути загрузки
# --------------------------------------------
UPLOAD_DIR=${SCRIPT_DIR}/uploads/messenger
NOTES_UPLOAD_DIR=${SCRIPT_DIR}/uploads/notes

# --------------------------------------------
# Ограничения
# --------------------------------------------
MAX_UPLOAD_SIZE=10485760
MAX_NOTE_ATTACHMENTS=10

# --------------------------------------------
# Настройки приложения
# --------------------------------------------
SITEURL=http://localhost
BASE_PATH=/

# --------------------------------------------
# WebSocket
# --------------------------------------------
WS_HOST=0.0.0.0
WS_PORT=8080

# --------------------------------------------
# Логирование
# --------------------------------------------
LOG_LEVEL=DEBUG
LOG_FILE=${SCRIPT_DIR}/logs/app.log

# --------------------------------------------
# Безопасность
# --------------------------------------------
SESSION_LIFETIME=3600
MAX_LOGIN_ATTEMPTS=5
CSRF_ENABLED=true
EOF

    success "Файл .env обновлен"
    
    # Создание директорий
    mkdir -p "${SCRIPT_DIR}/uploads/messenger"
    mkdir -p "${SCRIPT_DIR}/uploads/notes"
    mkdir -p "${SCRIPT_DIR}/logs"
    success "Директории для загрузок созданы"
}

# Проверка соединения с БД
test_db_connection() {
    local driver="$1"
    local host="$2"
    local port="$3"
    local user="$4"
    local pass="$5"
    local dbname="$6"
    
    info "Проверка соединения с БД..."
    
    if [ "$driver" = "sqlite" ]; then
        # Для SQLite просто проверяем возможность создания файла
        local db_dir=$(dirname "$dbname")
        mkdir -p "$db_dir"
        if touch "$dbname" 2>/dev/null; then
            success "SQLite база данных доступна"
            return 0
        else
            error "Не удалось создать SQLite базу данных"
            return 1
        fi
    else
        # Проверка MySQL/MariaDB соединения
        if command -v mysql &> /dev/null; then
            if mysql -h "$host" -P "$port" -u "$user" -p"$pass" -e "SELECT 1;" &>/dev/null; then
                success "Соединение с MySQL успешно установлено"
                
                # Проверка существования базы данных
                if mysql -h "$host" -P "$port" -u "$user" -p"$pass" -e "USE $dbname;" &>/dev/null; then
                    success "База данных '$dbname' существует"
                else
                    warn "База данных '$dbname' не существует"
                    read -p "Создать базу данных '$dbname'? (y/n): " create_db
                    if [[ "$create_db" =~ ^[Yy]$ ]]; then
                        if mysql -h "$host" -P "$port" -u "$user" -p"$pass" -e "CREATE DATABASE \`$dbname\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" &>/dev/null; then
                            success "База данных '$dbname' создана"
                        else
                            error "Не удалось создать базу данных"
                            return 1
                        fi
                    else
                        error "База данных не создана, продолжение невозможно"
                        return 1
                    fi
                fi
                return 0
            else
                error "Не удалось подключиться к MySQL"
                return 1
            fi
        else
            # Если нет mysql клиента, пробуем через PHP
            info "Проверка соединения через PHP..."
            php -r "
try {
    \$pdo = new PDO(
        'mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4',
        '$user',
        '$pass',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo \"SUCCESS\n\";
} catch (PDOException \$e) {
    echo \"ERROR: \" . \$e->getMessage() . \"\n\";
    exit(1);
}
" 2>&1
            if [ $? -eq 0 ]; then
                success "Соединение с БД подтверждено через PHP"
                return 0
            else
                error "Ошибка подключения через PHP"
                return 1
            fi
        fi
    fi
}

# Проверка наличия таблиц в БД
check_database_tables() {
    local driver="$1"
    local host="$2"
    local port="$3"
    local user="$4"
    local pass="$5"
    local dbname="$6"
    
    info "Проверка таблиц базы данных..."
    
    # Список ожидаемых таблиц
    local expected_tables=(
        "users"
        "dialogs"
        "dialog_users"
        "messages"
        "message_statuses"
        "notes"
        "note_attachments"
        "shared_notes"
        "note_history"
        "note_tags"
        "note_tag_relations"
        "user_files"
    )
    
    if [ "$driver" = "sqlite" ]; then
        # Для SQLite
        local existing_tables=$(sqlite3 "$dbname" ".tables" 2>/dev/null | tr ' ' '\n' | sort -u)
    else
        # Для MySQL
        if command -v mysql &> /dev/null; then
            local existing_tables=$(mysql -h "$host" -P "$port" -u "$user" -p"$pass" -D "$dbname" -e "SHOW TABLES;" 2>/dev/null | tail -n +2 | sort -u)
        else
            local existing_tables=$(php -r "
try {
    \$pdo = new PDO(
        'mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4',
        '$user',
        '$pass',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    \$stmt = \$pdo->query('SHOW TABLES');
    foreach(\$stmt->fetchAll(PDO::FETCH_COLUMN) as \$table) {
        echo \$table . \"\n\";
    }
} catch (PDOException \$e) {
    exit(1);
}
" 2>/dev/null | sort -u)
        fi
    fi
    
    local missing_tables=()
    for table in "${expected_tables[@]}"; do
        if ! echo "$existing_tables" | grep -q "^${table}$"; then
            missing_tables+=("$table")
        fi
    done
    
    if [ ${#missing_tables[@]} -gt 0 ]; then
        warn "Отсутствуют таблицы: ${missing_tables[*]}"
        return 1
    else
        success "Все необходимые таблицы присутствуют"
        return 0
    fi
}

# Импорт схемы БД
import_database_schema() {
    local driver="$1"
    local host="$2"
    local port="$3"
    local user="$4"
    local pass="$5"
    local dbname="$6"
    
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Импорт схемы базы данных${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    info "Поиск SQL файлов схемы..."
    
    local schema_files=()
    if [ -d "$DATABASE_DIR" ]; then
        for file in "$DATABASE_DIR"/*_schema.sql; do
            if [ -f "$file" ]; then
                schema_files+=("$file")
            fi
        done
    fi
    
    if [ ${#schema_files[@]} -eq 0 ]; then
        error "SQL файлы схемы не найдены в $DATABASE_DIR"
        return 1
    fi
    
    success "Найдено схем: ${#schema_files[@]}"
    
    for schema_file in "${schema_files[@]}"; do
        info "Импорт: $(basename "$schema_file")"
        
        if [ "$driver" = "sqlite" ]; then
            # Для SQLite нужна адаптация (упрощенная)
            warn "SQLite требует ручной конвертации схемы MySQL"
            echo "   Файл: $schema_file"
        else
            if command -v mysql &> /dev/null; then
                if mysql -h "$host" -P "$port" -u "$user" -p"$pass" "$dbname" < "$schema_file" 2>/dev/null; then
                    success "Схема импортирована: $(basename "$schema_file")"
                else
                    error "Ошибка импорта: $(basename "$schema_file")"
                    return 1
                fi
            else
                # Через PHP
                php -r "
try {
    \$pdo = new PDO(
        'mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4',
        '$user',
        '$pass',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    \$sql = file_get_contents('$schema_file');
    \$statements = array_filter(array_map('trim', explode(';', \$sql)));
    foreach(\$statements as \$statement) {
        if(!empty(\$statement)) {
            \$pdo->exec(\$statement);
        }
    }
    echo \"SUCCESS\n\";
} catch (PDOException \$e) {
    echo \"ERROR: \" . \$e->getMessage() . \"\n\";
    exit(1);
}
" 2>&1
                if [ $? -eq 0 ]; then
                    success "Схема импортирована: $(basename "$schema_file")"
                else
                    error "Ошибка импорта: $(basename "$schema_file")"
                    return 1
                fi
            fi
        fi
    done
    
    return 0
}

# Создание первичного пользователя
create_admin_user() {
    local driver="$1"
    local host="$2"
    local port="$3"
    local user="$4"
    local pass="$5"
    local dbname="$6"
    
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Создание администратора системы${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    info "Введите данные первичного пользователя (администратора):"
    echo ""
    
    # Логин
    echo -n "Логин (username): "
    read admin_username
    if [ -z "$admin_username" ]; then
        admin_username="admin"
        warn "Использовано имя по умолчанию: admin"
    fi
    
    # Email
    echo -n "Email: "
    read admin_email
    if [ -z "$admin_email" ]; then
        error "Email обязателен"
        return 1
    fi
    
    # Имя
    echo -n "Имя: "
    read admin_firstname
    if [ -z "$admin_firstname" ]; then
        admin_firstname="Admin"
    fi
    
    # Фамилия
    echo -n "Фамилия: "
    read admin_lastname
    if [ -z "$admin_lastname" ]; then
        admin_lastname="User"
    fi
    
    # Пароль
    echo -n "Пароль: "
    read -s admin_password
    echo ""
    if [ -z "$admin_password" ]; then
        error "Пароль обязателен"
        return 1
    fi
    
    # Подтверждение пароля
    echo -n "Подтвердите пароль: "
    read -s admin_password_confirm
    echo ""
    if [ "$admin_password" != "$admin_password_confirm" ]; then
        error "Пароли не совпадают"
        return 1
    fi
    
    # Хеширование пароля через PHP с использованием CryptMethods (если доступен)
    local password_hash=$(php -r "
require_once __DIR__ . '/app/handlers/CryptMethods.php';
try {
    // Создаем временные ключи если их нет
    if (!getenv('UNIQUE_KEY')) {
        putenv('UNIQUE_KEY=' . bin2hex(random_bytes(32)));
    }
    if (!getenv('SECONDARY_KEY')) {
        putenv('SECONDARY_KEY=' . hash('sha256', getenv('UNIQUE_KEY') . '_secondary_salt', true));
    }
    echo \\App\\Helpers\\CryptMethods::createHashFromPassword('$admin_password');
} catch (\\Exception \$e) {
    // Fallback на стандартный bcrypt
    echo password_hash('$admin_password', PASSWORD_BCRYPT);
}
" 2>/dev/null)

    if [ -z "$password_hash" ]; then
        # Если CryptMethods не сработал, используем fallback
        password_hash=$(php -r "echo password_hash('$admin_password', PASSWORD_BCRYPT);")
    fi
    
    info "Создание пользователя в базе данных..."
    
    if [ "$driver" = "sqlite" ]; then
        sqlite3 "$dbname" "INSERT INTO users (username, email, password_hash, firstname, lastname, role, is_active) VALUES ('$admin_username', '$admin_email', '$password_hash', '$admin_firstname', '$admin_lastname', 1, 1);" 2>/dev/null
    else
        if command -v mysql &> /dev/null; then
            mysql -h "$host" -P "$port" -u "$user" -p"$pass" "$dbname" -e "INSERT INTO users (username, email, password_hash, firstname, lastname, role, is_active) VALUES ('$admin_username', '$admin_email', '$password_hash', '$admin_firstname', '$admin_lastname', 1, 1);" 2>/dev/null
        else
            php -r "
try {
    \$pdo = new PDO(
        'mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4',
        '$user',
        '$pass',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    \$stmt = \$pdo->prepare('INSERT INTO users (username, email, password_hash, firstname, lastname, role, is_active) VALUES (:username, :email, :password_hash, :firstname, :lastname, :role, :is_active)');
    \$stmt->execute([
        ':username' => '$admin_username',
        ':email' => '$admin_email',
        ':password_hash' => '$password_hash',
        ':firstname' => '$admin_firstname',
        ':lastname' => '$admin_lastname',
        ':role' => 1,
        ':is_active' => 1
    ]);
    echo \"SUCCESS\n\";
} catch (PDOException \$e) {
    echo \"ERROR: \" . \$e->getMessage() . \"\n\";
    exit(1);
}
" 2>&1
        fi
    fi
    
    if [ $? -eq 0 ]; then
        success "Администратор '$admin_username' создан"
    else
        error "Не удалось создать администратора"
        warn "Вы сможете создать пользователя через веб-интерфейс"
    fi
}

# Установка зависимостей Composer
install_composer_dependencies() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Установка зависимостей Composer${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    if command -v composer &> /dev/null; then
        info "Установка PHP зависимостей..."
        cd "$SCRIPT_DIR"
        if composer install --no-interaction --prefer-dist; then
            success "Зависимости установлены"
        else
            warn "Ошибка установки зависимостей Composer"
        fi
    else
        info "Composer не найден, пропускаем установку зависимостей"
    fi
}

# Настройка прав доступа
setup_permissions() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Настройка прав доступа${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    info "Настройка прав на директории..."
    
    local dirs=(
        "uploads"
        "uploads/messenger"
        "uploads/notes"
        "logs"
    )
    
    for dir in "${dirs[@]}"; do
        local full_path="${SCRIPT_DIR}/${dir}"
        if [ -d "$full_path" ]; then
            chmod -R 755 "$full_path" 2>/dev/null || true
            chown -R www-data:www-data "$full_path" 2>/dev/null || true
            success "Права настроены: $dir"
        fi
    done
}

# Создание .htaccess
create_htaccess() {
    local htaccess_file="${SCRIPT_DIR}/.htaccess"
    
    if [ ! -f "$htaccess_file" ]; then
        info "Создание .htaccess..."
        cat > "$htaccess_file" << 'EOF'
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* index.php [L]

# Защита файлов
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# Защита .env
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

# Защита SQL файлов
<Files "*.sql">
    Order allow,deny
    Deny from all
</Files>

# Безопасность заголовков
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

# PHP настройки
<IfModule mod_php.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value max_execution_time 300
    php_value max_input_time 300
</IfModule>
EOF
        success ".htaccess создан"
    fi
}

# Пинг системы самой себя (проверка конфигурации)
self_test() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Самотестирование системы${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    info "Проверка конфигурации..."
    
    # Загрузка .env
    if [ -f "$ENV_FILE" ]; then
        source "$ENV_FILE"
        success "Конфигурация загружена"
    else
        error "Конфигурация не найдена"
        return 1
    fi
    
    # Проверка переменных окружения
    info "Экспорт переменных окружения..."
    export DBDRIVER DBHOST DBPORT DBUSER DBPASS DBNAME
    export MSG_SECRET_KEY NOTE_SECRET_KEY
    export UPLOAD_DIR NOTES_UPLOAD_DIR
    
    success "Переменные окружения установлены"
    
    # Тест через PHP
    info "PHP тест подключения..."
    php -r "
require_once '${SCRIPT_DIR}/core/config.php';

\$config = new \Core\Config();
\$dbConfig = \Core\Config::\$db_connection;

echo 'Driver: ' . \$dbConfig['driver'] . \"\n\";
echo 'Host: ' . \$dbConfig['hostname'] . \"\n\";
echo 'Database: ' . \$dbConfig['database'] . \"\n\";

try {
    \$pdo = new PDO(
        \$dbConfig['driver'] . ':host=' . \$dbConfig['hostname'] . ';port=' . \$dbConfig['port'] . ';dbname=' . \$dbConfig['database'] . ';charset=utf8mb4',
        \$dbConfig['username'],
        \$dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo \"Database connection: SUCCESS\n\";
} catch (PDOException \$e) {
    echo \"Database connection: ERROR - \" . \$e->getMessage() . \"\n\";
    exit(1);
}
" 2>&1
    
    if [ $? -eq 0 ]; then
        success "Самотестирование пройдено"
    else
        warn "Самотестирование выявило проблемы"
    fi
}

# Главная функция установки
main_installation() {
    print_logo
    
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}Мастер установки Workspace Organizer${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
    
    # Шаг 1: Проверка системных требований
    echo -e "${YELLOW}[Шаг 1/8]${NC} Проверка системных требований"
    echo "-------------------------------------------"
    check_php
    check_php_extensions
    check_composer
    echo ""
    
    # Шаг 2: Проверка/создание конфигурации
    echo -e "${YELLOW}[Шаг 2/8]${NC} Конфигурация"
    echo "-------------------------------------------"
    if ! check_env_file; then
        create_env_file
    else
        info "Файл .env уже существует"
        read -p "Пересоздать конфигурацию? (y/n): " recreate
        if [[ "$recreate" =~ ^[Yy]$ ]]; then
            create_env_file
        fi
    fi
    echo ""
    
    # Загрузка конфигурации
    source "$ENV_FILE"
    
    # Шаг 3: Проверка соединения с БД
    echo -e "${YELLOW}[Шаг 3/8]${NC} Проверка соединения с БД"
    echo "-------------------------------------------"
    if ! test_db_connection "$DBDRIVER" "$DBHOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME"; then
        error "Не удалось подключиться к базе данных"
        read -p "Повторить настройку БД? (y/n): " retry_db
        if [[ "$retry_db" =~ ^[Yy]$ ]]; then
            create_env_file
            source "$ENV_FILE"
            if ! test_db_connection "$DBDRIVER" "$DBHOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME"; then
                exit 1
            fi
        else
            exit 1
        fi
    fi
    echo ""
    
    # Шаг 4: Проверка таблиц
    echo -e "${YELLOW}[Шаг 4/8]${NC} Проверка структуры БД"
    echo "-------------------------------------------"
    if ! check_database_tables "$DBDRIVER" "$DBHOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME"; then
        info "Требуется импорт схемы базы данных"
    else
        info "База данных уже содержит все таблицы"
        read -p "Переимпортировать схему? (y/n): " reimport
        if [[ ! "$reimport" =~ ^[Yy]$ ]]; then
            skip_import=true
        fi
    fi
    echo ""
    
    # Шаг 5: Импорт схемы (если нужно)
    echo -e "${YELLOW}[Шаг 5/8]${NC} Импорт схемы БД"
    echo "-------------------------------------------"
    if [ "$skip_import" != "true" ]; then
        if ! import_database_schema "$DBDRIVER" "$DBHOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME"; then
            warn "Импорт схемы не выполнен"
        fi
    else
        info "Импорт схемы пропущен"
    fi
    echo ""
    
    # Шаг 6: Создание администратора
    echo -e "${YELLOW}[Шаг 6/8]${NC} Создание администратора"
    echo "-------------------------------------------"
    create_admin_user "$DBDRIVER" "$DBHOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME"
    echo ""
    
    # Шаг 7: Установка зависимостей
    echo -e "${YELLOW}[Шаг 7/8]${NC} Установка зависимостей"
    echo "-------------------------------------------"
    install_composer_dependencies
    echo ""
    
    # Шаг 8: Настройка прав и создание файлов
    echo -e "${YELLOW}[Шаг 8/8]${NC} Финальная настройка"
    echo "-------------------------------------------"
    setup_permissions
    create_htaccess
    echo ""
    
    # Самотестирование
    self_test
    
    # Завершение
    echo ""
    echo -e "${GREEN}============================================${NC}"
    echo -e "${GREEN}Установка завершена успешно!${NC}"
    echo -e "${GREEN}============================================${NC}"
    echo ""
    echo -e "${BLUE}Данные для входа:${NC}"
    echo "  Логин: $admin_username"
    echo "  URL: ${SITEURL:-http://localhost}/"
    echo ""
    echo -e "${YELLOW}Следующие шаги:${NC}"
    echo "  1. Настройте веб-сервер (Apache/Nginx) на директорию $SCRIPT_DIR"
    echo "  2. Запустите WebSocket сервер: php ws_server/server.php start"
    echo "  3. Откройте сайт в браузере"
    echo ""
    echo -e "${CYAN}Документация: README.md${NC}"
    echo ""
}

# Запуск установки
main_installation
