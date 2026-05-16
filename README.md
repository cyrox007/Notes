# Workspace Organizer

**Версия:** 0.8.0-alpha  
**Статус:** Alpha Development  
**Дата релиза:** 7 мая 2026

## Описание

PHP суперприложение для работы с:
* персональными заметками на корпоративном предприятии (с двойным шифрованием AES-256);
* хранением и работой с личными файлами пользователя;
* мультимедиа плеером для аудио/видео файлов;
* CodeExplorer для просмотра кода с подсветкой синтаксиса;
* мессенджером с real-time уведомлениями через WebSocket;
* ежедневником и системой управления задачами;
* профилем пользователя с расширенными настройками;
* админ-панелью для управления пользователями;

## Требования

* **PHP 8.3+** (требуется для строгой типизации, enum, constructor property promotion)
* MySQL / MariaDB (рекомендуется версия 8.0+) или SQLite 3.x
* Composer для управления зависимостями PHP
* Расширения PHP: pdo_mysql, json, mbstring, openssl, fileinfo
* Веб-сервер: Apache с mod_rewrite или Nginx
* Node.js (опционально) для некоторых клиентских инструментов

## История версий

### 0.10.0-alpha (16 мая 2026) - File Manager Fixes & Improvements
**Основные изменения:**
- **Файловый менеджер**:
  - Исправлен косяк с определением типа при просмотре файла
  - Корректировка путей при чтении и удалении файла
  - Отображение файлов и пути файлов
  - Игнор папки загрузки
  - Исправлены ошибки загрузки файла
  - Исправлен вызов из-за изменения имен классов в шаблоне
  - Исправлена ошибка роутера с типизацией параметров
  - Исправлено неправильное чтение параметров в шаблоне файлового менеджера
- **Модель файлов**: корректировка модели файлов
- **Логика работы с директориями**:
  - Исправление логики определения корневой папки
  - Исправление базы с корневой директорией
- **База данных**: исправлена обработка оператора IS при проверке на NULL
- **Шаблонизатор (BEM)**:
  - Переписывание файлового менеджера по БЭМ-методологии
  - Добавлены модификаторы для использования в шаблоне
- **Общее**: исправление кое-каких багов

### 0.9.0-alpha (14 мая 2026) - ORM, Notes & Stability Release
**Основные изменения:**
- **Инсталлер и авторизация**: доработан инсталлер, обновлён метод криптографии
- **ORM улучшения**:
  - Исправлен баг с методом `where` (добавлял лишний `AND`)
  - Добавлена поддержка `GROUP BY`
  - Исправлена обработка колонок с `AS`
  - Рефакторинг: замена `user_uid` → `user_id` во всём проекте
  - Исправлен баг с отсутствующей таблицей `fields`
- **Модуль заметок (Notes)**:
  - Обновлены методы криптографии на актуальные
  - Исправлено создание и редактирование заметок
  - Исправлен шаблон: замена `$attachment.isVoice()` на совместимый с Smarty синтаксис
  - Улучшены стили интерфейса редактирования
- **UI/UX**:
  - Множественные исправления стилей сайдбара
  - Исправлены стили профиля
  - Общее форматирование и улучшение визуальных компонентов
- **Роутинг**: исправления роутера и контроллера блокнота
- **Логирование**: исправлены пути до файлов логов
- **Документация**: форматирование кода и документов

### 0.8.0-alpha (7 мая 2026) - Major Update: Task & File Management
**Основные изменения:**
- Добавлен модуль управления задачами с ежедневником
- Добавлен модуль управления личными файлами пользователя
- Мультимедиа плеер для аудио/видео файлов
- CodeExplorer для просмотра кода с подсветкой синтаксиса
- Улучшена система регистрации по инвайт-коду
- Обновлена система авторизации с CSRF защитой
- Расширен профиль пользователя с редактированием
- Админ-панель с управлением пользователями и кастомными полями
- Интеграция WebSocket для мессенджера и уведомлений
- Система заметок с двойным шифрованием (AES-256)
- Полноценная ORM с поддержкой MySQL/MariaDB и SQLite
- Middleware для маршрутизации
- Исправлены проблемы безопасности

### 0.7.0-alpha - Messaging & Notes System
- Добавлена система сообщений (мессенджер) с real-time обновлениями
- Двойное шифрование сообщений и заметок
- WebSocket сервер для мгновенных уведомлений
- Статусы онлайн/офлайн пользователей
- Индикатор набора текста
- Шаринг заметок с разными уровнями доступа
- Загрузка файлов в сообщения
- Голосовые сообщения через MediaRecorder API

### 0.6.0-alpha - Profile & Admin Panel
- Расширенный профиль пользователя с аватаром
- Смена пароля с проверкой сложности
- Удаление аккаунта с подтверждением
- Админ-панель для управления пользователями
- Настройка кастомных полей профиля
- Блокировка/разблокировка пользователей
- Валидация email и телефона

### 0.5.0-alpha - ORM & Database Layer
- Полная переработка ORM системы
- Поддержка SELECT, JOIN, WHERE, GET, FIRST
- Поддержка нескольких СУБД (MySQL, MariaDB, SQLite)
- Логирование SQL запросов
- Connection pooling

### 0.4.0-alpha - Core Architecture
- Модификация session, redirect, getRoute для Smarty
- Route Middleware система
- Улучшенная маршрутизация запросов
- Request data обработка
- Рефакторинг контроллеров
- Оптимизация ядра системы

### 0.3.0-alpha - Templating & Routing
- Интеграция Smarty шаблонизатора
- Система маршрутизации (Router)
- MVC архитектура
- Базовая структура контроллеров и моделей
- Подключение к базе данных

### 0.2.0-alpha - Authentication & Registration
- Система регистрации пользователей
- Авторизация с хешированием паролей
- Управление сессиями
- Профиль пользователя (базовый)
- Система диалогов и сообщений (начальная)

### 0.1.0-alpha - Initial Release
- Первый альфа релиз
- Базовая структура приложения
- Конфигурация окружения
- Начальная настройка проекта

## Технические требования

## Условия для запуска
Для работы системы требуется файл .htaccess со следующим содержимым
``` apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* index.php [L]
```
Так же необходимо установить зависимости для работы сервера WebSocket
``` bash
composer install
```
Запуск сервера WebSocket'a 
``` bash
php ws_server/server.php start
```

## 🗄️ Инициализация базы данных

### Требования к БД

Приложение поддерживает две СУБД:
- **MySQL/MariaDB** (рекомендуется для production)
- **SQLite** (для разработки и тестирования)

### Переменные окружения

Перед запуском приложения необходимо настроить переменные окружения:

1. Скопируйте файл `default.env` в `.env`:
```bash
cp default.env .env
```

2. Отредактируйте `.env` и установите свои значения:
```env
# База данных
DBDRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBUSER=root
DBPASS=your_secure_password_here
DBNAME=messenger_db

# Ключи шифрования (ОБЯЗАТЕЛЬНО!)
MSG_SECRET_KEY=<сгенерируйте через openssl rand -hex 32>
NOTE_SECRET_KEY=<сгенерируйте через openssl rand -hex 32>

# Пути загрузки
UPLOAD_DIR=/var/www/uploads/messenger
NOTES_UPLOAD_DIR=/var/www/uploads/notes
```

3. Сгенерируйте ключи шифрования:
```bash
openssl rand -hex 32
```

### SQL скрипты инициализации

В проекте имеются следующие SQL скрипты:

#### 1. Мессенджер - `database/messenger_schema.sql`

Содержит таблицы для системы обмена сообщениями:
- `users` - пользователи
- `dialogs` - диалоги (личные и групповые)
- `dialog_users` - связи пользователей с диалогами
- `messages` - сообщения
- `message_statuses` - статусы прочтения сообщений

**Инициализация:**
```bash
mysql -u root -p messenger_db < database/messenger_schema.sql
```

#### 2. Заметки (Notes 2.0+) - `database/notes_schema.sql`

Содержит таблицы для системы личных заметок с поддержкой медиа и голосовых сообщений:
- `notes` - личные заметки (текст, шифрование AES-256-GCM/CBC)
- `note_attachments` - медиа-вложения (фото, аудио, видео, файлы, голосовые)
- `shared_notes` - общий доступ к заметкам через токены
- `note_history` - история изменений (автоматически через триггеры)
- `note_tags` - теги для организации заметок
- `note_tag_relations` - связи заметок с тегами

**Инициализация:**
```bash
mysql -u root -p messenger_db < database/notes_schema.sql
```

#### Полная инициализация БД

Для создания всех таблиц выполните:

```bash
# Создайте базу данных
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS messenger_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Импортируйте схемы
mysql -u root -p messenger_db < database/messenger_schema.sql
mysql -u root -p messenger_db < database/notes_schema.sql
```

### Структура таблиц модуля Notes

Модуль заметок версии 2.0 поддерживает:

**Типы контента:**
- `text` - текстовые заметки (шифруются AES-256-CBC)
- `image` - изображения (JPEG, PNG, GIF, WebP)
- `audio` - аудиофайлы (MP3, WAV, OGG)
- `video` - видеофайлы (MP4, WebM, AVI)
- `voice` - голосовые заметки (WebM, MP3)
- `file` - документы и другие файлы

**Функциональность:**
- Шифрование текстового контента
- Загрузка и хранение медиа-вложений
- Голосовые заметки с указанием длительности
- Общий доступ через токены (можно делиться через мессенджер)
- История изменений с возможностью отката
- Система тегов для организации заметок
- Safe-удаление с возможностью восстановления

**Безопасность:**
- Текстовый контент шифруется перед сохранением
- Медиафайлы могут быть зашифрованы опционально
- Файлы хранятся вне корневой директории веб-сервера
- Токены доступа имеют срок действия

## Работа с приложением
### Маршрутизация

**Важно:** Начиная с версии 0.6.4 маршрутизатор полностью переработан для соответствия современным стандартам PHP 8.x+.

#### Базовое использование

Маршрутизация настраивается в файле `core/routerConfig.php`. Для добавления маршрута используется метод `add()`:

```php
use App\Controllers\MainController;
use Core\Router;

$router = Router::getInstance();

// Простой маршрут
$router->add('GET', '/', [MainController::class, 'index'], [], 'main');
```

**Параметры метода `add()`:**
1. `string $method` - HTTP метод запроса ('GET', 'POST', 'PUT', 'DELETE')
2. `string $path` - путь запроса
3. `array $controller` - массив из [класс контроллера, метод контроллера]
4. `array $middlewares` - массив middleware классов (опционально)
5. `string $name` - имя маршрута для использования в redirect и шаблонах (опционально)

#### Динамические параметры

Маршрутизатор поддерживает динамические параметры любого количества. Параметры указываются в фигурных скобках с указанием типа:

```php
// Параметр типа int (только числа)
$router->add('GET', '/post/{int:id}', [PostController::class, 'show'], [], 'post_show');

// Параметр типа str (слова, цифры, дефисы)
$router->add('GET', '/article/{str:slug}', [ArticleController::class, 'view'], [], 'article_view');

// Несколько параметров
$router->add('GET', '/user/{int:userId}/note/{str:noteId}', [NoteController::class, 'view'], [], 'note_view');
```

**Типы параметров:**
- `{int:paramName}` - только числовые значения (`\d+`)
- `{str:paramName}` - символьные значения, включая дефисы (`[\w-]+`)

Получение параметров в контроллере:
```php
namespace App\Controllers;

use Core\Controller;
use Core\Request;

class PostController extends Controller
{
    public function show(Request $request, int $id): void
    {
        // $id содержит значение из маршрута
        echo "Post ID: {$id}";
    }
}
```

#### Группировка маршрутов

Для удобной организации маршрутов с общим префиксом используйте группировку:

```php
use App\Controllers\AuthController;
use App\Middlewares\LoginRequared;
use Core\Router;

$router = Router::getInstance();

$router->group('/auth', function (Router $router) {
    $router->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage');
    $router->add('POST', '/login', [AuthController::class, 'signin']);
    $router->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout');
});
```

Все маршруты внутри группы автоматически получают префикс `/auth`.

#### Middleware

Middleware позволяют выполнять код до обработки запроса контроллером (например, проверка авторизации):

```php
// Добавление middleware к маршруту
$router->add('GET', '/profile', [ProfileController::class, 'index'], [LoginRequared::class], 'profile');

// Несколько middleware
$router->add('GET', '/admin', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel');
```

Middleware класс должен иметь метод `handle(Request $request): bool`, возвращающий `true` для продолжения выполнения или `false` для остановки.

#### Перенаправления

Использование имен маршрутов для редиректов:

```php
use Core\Router;

// Перенаправление по имени маршрута
Router::getInstance()->redirect('main', 'name');

// Перенаправление с параметрами
Router::getInstance()->redirect('edit_page', 'name', ['uid' => $noteUid]);

// Перенаправление по URL
Router::getInstance()->redirect('https://example.com', 'url');
```

#### Получение маршрута в шаблонах

В Smarty шаблонах можно использовать функцию `route_path` для генерации URL:

```smarty
<a href="{route_path name='notes'}">Заметки</a>
<a href="{route_path name='edit_page' uid=$note.uid}">Редактировать</a>
```

#### Пример полной конфигурации

```php
<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\NoteController;
use App\Middlewares\LoginRequared;
use App\Middlewares\IsAdmin;
use Core\Router;

$router = Router::getInstance();

// Публичные маршруты
$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth', function (Router $router) {
    $router->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage');
    $router->add('POST', '/login', [AuthController::class, 'signin']);
    $router->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout');
});

// Защищенные маршруты
$router->group('/notes', function (Router $router) {
    // Список заметок
    $router->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');
    $router->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create');
    
    // Редактирование заметки
    $router->add('GET', '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page');
    $router->add('POST', '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note');
    $router->add('GET', '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note');
    
    // Загрузка вложений (медиа, аудио, голосовые)
    $router->add('POST', '/upload/{str:uid}', [NoteController::class, 'uploadAttachment'], [LoginRequared::class], 'note_upload');
    $router->add('POST', '/attachment/delete/{int:id}', [NoteController::class, 'deleteAttachment'], [LoginRequared::class], 'note_delete_attachment');
    
    // Шаринг заметок
    $router->add('POST', '/share/{str:uid}', [NoteController::class, 'shareNote'], [LoginRequared::class], 'note_share');
    $router->add('POST', '/unshare/{str:uid}', [NoteController::class, 'unshareNote'], [LoginRequared::class], 'note_unshare');
});

// Публичный доступ к заметкам по токену
$router->add('GET', '/notes/shared/{str:token}', [NoteController::class, 'viewShared'], [], 'note_shared_view');

// Админ панель
$router->group('/admin', function (Router $router) {
    $router->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel');
});

$router->dispatch();
```

#### Обработка 404

Если маршрут не найден, автоматически отправляется ответ с кодом 404 и текстом "404 Page Not Found". Вы можете переопределить это поведение в методе `handle404()` класса Router.
### Контроллеры
При запросе к приложению вызывается специальный класс контроллера, являющийся дочерним класса `Controller`.
Контроллер отвечает за логику того или иного аспекта приложения, будь то, главная страница или страница авторизации или регистрации. После выполнения работы контроллера происходит загразка шаблона, с передачей всех необходимых параметров в него.

Располагать файлы контроллера необходимо в папке `app/controllers`.
~~Имя файла контроллера всегда должно начинатся с имени `controller_`, и после нижнего подчеркивания указываем имя контроллера, cуказанием расширения `.php`, например: `controller_admin.php`.~~
**Изменено v0.6.3**
Имя файла контроллера как и имя класса контроллера может быть любым, но лучше придерживаться правила: "чем проще - тем лучше". Например: `Main.php`

Когда вы будет разрабатывать логику своего контроллера, необходимо в первую очередь указать имя класса контроллера, и что это расширение класса контроллера, например: 
``` php
namespace App\Controller;
use Core\Controller;
class Main extends Controller {
    #code...
}
```
~~Вы должны помнить, что любой контроллер должен иметь в себе функции. По умолчанию маршрутизатор будет искать в загружаемом контоллере `action_index`, а это значит вы должны его указать в своем контроллере~~
**Изменено v0.6.3**
Маршрутизатор будет искать в классе котроллера то имя метода, которое вы ему указали, а поэтому:
``` php
/* *code* */
class Main extends Controller {
    function index() {
        #code...
    }
}
```
Если вы в своем маршруте указали какие либо динамические параметры, то их можно принять в качестве входных параметров функции. Если взять пример из предыдущего раздела, о маршрутах, то наш котроллер будет выглядеть следующим образом:
``` php
class Post extends Controller {
    function show($id) {
        #code...
    }
}
```
### Модели
Модели необязательный класс, подключаемый к контроллеру, для работы с ресурсами базы данных. Для того чтобы создать модель для работы с БД, вы должны создать файл в директории `app/models`. Правила именования файла и класса здесь идентичны с правилами контроллера, но имейте ввиду, что имя класса будет использоваться для поиска таблицы в БД. Принцип следующий, имеем класс модели `User`, ему будет соотвествовать имя таблицы `users`. 

Непосредственно в файле модели вы указываете название класса и расширение класса модели: 
``` php
namespace App\Models;
use Core\Model;

class User extends Model {
    #code...
}
```
Таким образом ваша модель будет наследовать стандартные функции для работы с базой данных, и вы сможете настроить эти функции для выполнения своих специализированных задач. 
Однако учтите, это не гарантирует вас от возниктовения ошибок, связанных с именами таблицы. Чтобы избежать подобного вы можете указать в классе модели специальное свойство `$_tablename`.
``` php
namespace App\Models;
use Core\Model;

class User extends Model {
    protected static $_tablename = "users";
}
```

Далее в вашем классе модели вам необходимо указать все необходимые свойства, чьи названия будут соотвествовать именам колонок в вашей таблице.
``` php
namespace App\Models;
use Core\Model;

class User extends Model {
    protected static $_tablename = "users";

    public $id;
    public string $username;
    public string $email;
}
```
#### Работа с данными

**Важно:** Начиная с версии 0.7.0 система работы с БД была полностью переработана для соответствия современным стандартам PHP 8.x+ и улучшения производительности.

##### Основные изменения:

1. **DatabaseManager теперь использует паттерн Singleton** - всегда получайте экземпляр через `getInstance()`
2. **Строгая типизация** - все методы требуют явного указания типов данных
3. **Методы queueInsert/queueUpdate/queueDelete** теперь принимают массив данных, а не объекты
4. **Полное логирование** - все операции БД детально логируются для отладки

##### Получение экземпляра DatabaseManager:

```php
// Неправильно (устарело):
$dbManager = new DatabaseManager();

// Правильно:
$dbManager = DatabaseManager::getInstance();
```

##### Чтение данных:

Для чтения данных используйте наследуемые методы ORM в ваших моделях:

```php
namespace App\Models;
use Core\ORM;

class User extends ORM {
    public function getUsers(): array {
        // Получить все записи как массив объектов
        return $this->select('users')->get(true); 
    }
    
    public function getUserById(int $id): ?object {
        // Получить одну запись как объект
        return $this->select('users')
            ->where('id', '=', $id)
            ->first(true); 
    }
    
    public function getUserPosts(int $userId): array {
        // JOIN с другой таблицей (всегда возвращает массив ассоциативных массивов)
        return $this->select('posts')
            ->innerJoin('users', 'posts.user_id', 'id')
            ->where('users.id', '=', $userId)
            ->get(); 
    }
    
    public function countUsers(): int {
        // Получить количество записей
        return $this->select('users')->count();
    }
}
```

**Доступные методы выборки:**
- `select(string $table)` - начало выборки из таблицы
- `where(string $column, string $operator, mixed $value)` - условие WHERE
- `andWhere()` / `orWhere()` - дополнительные условия
- `innerJoin()` / `leftJoin()` / `rightJoin()` - соединения таблиц
- `orderBy(string $column, string $direction = 'ASC')` - сортировка
- `limit(int $limit, int $offset = 0)` - ограничение количества записей
- `get(bool $asObject = false)` - получить все записи (массив или массив объектов)
- `first(bool $asObject = false)` - получить первую запись (объект или null)
- `count()` - получить количество записей
- `exists()` - проверить существование записей

##### Вставка данных:

```php
use Core\DatabaseManager;

class Post extends ORM {
    public function addPost(string $title, string $content, int $userId): bool {
        $dbManager = DatabaseManager::getInstance();
        
        $data = [
            'title' => $title,
            'content' => $content,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $dbManager->queueInsert($data, 'posts');
    }
}
```

##### Обновление данных:

```php
use Core\DatabaseManager;

class User extends ORM {
    public function updateUserEmail(int $userId, string $newEmail): bool {
        $dbManager = DatabaseManager::getInstance();
        
        $data = [
            'email' => $newEmail,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        return $dbManager->queueUpdate($data, 'users', $userId);
    }
}
```

##### Удаление данных:

```php
use Core\DatabaseManager;

class Post extends ORM {
    public function deletePost(int $postId): bool {
        $dbManager = DatabaseManager::getInstance();
        
        return $dbManager->queueDelete('posts', $postId);
    }
}
```

##### Транзакции:

```php
use Core\DatabaseManager;

$dbManager = DatabaseManager::getInstance();

try {
    $dbManager->beginTransaction();
    
    // Выполнение нескольких операций
    $dbManager->queueInsert(['name' => 'User1'], 'users');
    $dbManager->queueUpdate(['status' => 'active'], 'users', 1);
    
    $dbManager->commit();
} catch (\Exception $e) {
    $dbManager->rollBack();
    // Ошибка будет залогирована автоматически
}
```

##### Сохранение и удаление через модель:

Если ваша модель расширяет ORM, вы можете использовать встроенные методы:

```php
namespace App\Models;
use Core\ORM;

class User extends ORM {
    protected static string $_tablename = 'users';
    
    public int $id;
    public string $username;
    public string $email;
    
    // Сохранение нового или обновление существующего объекта
    public function save(): bool {
        // Автоматически определит: insert для нового, update для существующего
        return parent::save();
    }
    
    // Удаление текущего объекта
    public function remove(): bool {
        return parent::remove();
    }
}

// Использование:
$user = new User();
$user->username = 'john';
$user->email = 'john@example.com';
$user->save(); // Вставка новой записи

$user->email = 'new@example.com';
$user->save(); // Обновление существующей записи

$user->remove(); // Удаление записи
```

##### Логирование и отладка:

Все операции БД детально логируются. Для просмотра статистики и логов:

```php
$dbManager = DatabaseManager::getInstance();

// Получить статистику запросов
$stats = $dbManager->getQueryStats();
echo "Всего запросов: " . $stats['total_queries'];

// Получить полный лог операций
$log = $dbManager->getQueryLog();
foreach ($log as $entry) {
    echo "[{$entry['timestamp']}] {$entry['level']}: {$entry['message']}\n";
}

// Для моделей ORM
$queryCount = User::getQueryStats();
echo "ORM запросов выполнено: {$queryCount}";
```

**Уровни логирования:**
- `DEBUG` - детальная информация о параметрах запросов
- `INFO` - успешное выполнение операций
- `WARNING` - предупреждения (например, незавершенные транзакции)
- `ERROR` - ошибки выполнения запросов

##### Конфигурация подключения:

Настройки БД находятся в `/core/config.php`:

```php
define('DB_DRIVER', 'mysql');
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');
```

**Важно:** Поддерживается только MySQL/MariaDB и SQLite. Для SQLite используйте:
```php
define('DB_DRIVER', 'sqlite');
define('DB_PATH', '/path/to/database.sqlite');
```
### Шаблонизатор (Smarty)

**Важно:** Начиная с версии 0.6.4 шаблонизатор полностью переработан и использует Smarty с кастомными расширениями.

#### Базовое использование

За отрисовку шаблонов отвечает базовый класс `Controller`. В контроллере используйте метод `render_template()`:

```php
namespace App\Controllers;

use Core\Controller;
use Core\Request;

class NoteController extends Controller
{
    public function index(Request $request): void
    {
        $data = [
            'title' => 'Мои заметки',
            'notes' => $this->getNotes(),
            'user' => $request->session('user_uid')
        ];
        
        // Рендерит шаблон app/views/notes_page/index.tpl
        $this->render_template('notes_page/index', $data);
    }
}
```

**Параметры метода `render_template()`:**
1. `string $template` - имя шаблона без расширения `.tpl` (путь относительно `app/views/`)
2. `array|null $data` - ассоциативный массив данных для передачи в шаблон

#### Пользовательские функции Smarty

Контроллер автоматически регистрирует следующие функции для использования в шаблонах:

##### route_path - генерация URL по имени маршрута

```smarty
{* Простая ссылка *}
<a href="{route_path name='notes'}">Заметки</a>

{* Ссылка с параметрами *}
<a href="{route_path name='edit_page' uid=$note.uid}">Редактировать</a>

{* Несколько параметров *}
<a href="{route_path name='user_note' userId=$user.id noteId=$note.id}">
    Заметка пользователя
</a>
```

##### csrf_token - CSRF защита форм

```smarty
<form method="POST" action="{route_path name='note_create'}">
    {csrf_token}
    <input type="text" name="notename" placeholder="Название заметки">
    <button type="submit">Создать</button>
</form>
```

Генерирует: `<input type="hidden" name="_csrf_token" value="...">`

##### session - доступ к данным сессии

```smarty
{* Получить значение из сессии *}
<p>Привет, {session key='username'}!</p>

{* Проверка авторизации *}
{if session key='auth'}
    <a href="{route_path name='logout'}">Выйти</a>
{else}
    <a href="{route_path name='authpage'}">Войти</a>
{/if}
```

##### jsonParse - парсинг JSON в шаблоне

```smarty
{* Распарсить JSON строку и назначить в переменную *}
{jsonParse json=$jsonString assign='parsedData'}

{* Использовать распарсенные данные *}
{foreach from=$parsedData item=item}
    <p>{$item.name}</p>
{/foreach}
```

##### file_get_contents - чтение файлов

```smarty
{* Читать содержимое файла *}
<div class="content">
    {file_get_contents file='app/uploads/content.txt'}
</div>
```

#### Автоматическое экранирование

Smarty настроен на автоматическое экранирование HTML для защиты от XSS-атак:

```smarty
{$userInput} {* Автоматически экранируется *}
{$userInput|noescape} {* Не экранируется, если нужно вывести HTML *}
```

#### Конфигурация Smarty

Пути конфигурируются в контроллере:
- `setTemplateDir` - `SITEPATH . '/app/views'` - директория шаблонов
- `setConfigDir` - `SITEPATH . '/config'` - директория конфигов
- `setCompileDir` - `SITEPATH . '/compile'` - директория компиляции
- `setCacheDir` - `SITEPATH . '/cache'` - директория кэша

#### Пример полного шаблона

```smarty
{extends file="^shared/layout.tpl"}

{block name="title"}{$title}{/block}

{block name="content"}
<div class="notes-container">
    <h1>{$title}</h1>
    
    <form method="POST" action="{route_path name='note_create'}">
        {csrf_token}
        <input type="text" name="notename" placeholder="Название заметки" required>
        <button type="submit">Создать заметку</button>
    </form>
    
    <ul class="notes-list">
        {foreach from=$notes item=note}
        <li class="note-item">
            <h3>{$note.notename}</h3>
            <p>Создано: {$note.created_note}</p>
            <a href="{route_path name='edit_page' uid=$note.uid}">
                Редактировать
            </a>
            <a href="{route_path name='delete_note' uid=$note.uid}" 
               onclick="return confirm('Удалить?')">
                Удалить
            </a>
        </li>
        {foreachelse}
        <li>Заметок пока нет</li>
        {/foreach}
    </ul>
</div>
{/block}
```

#### Альтернативные способы ответа

Кроме рендеринга шаблона, контроллер может вернуть:

**JSON ответ:**
```php
public function apiGetNotes(): void
{
    $this->responseJson([
        'status' => 'success',
        'data' => $this->getNotes()
    ]);
}
```

**Строковый ответ (через echo):**
```php
public function healthCheck(): void
{
    echo "OK";
}
```

**Массив/объект (автоматически конвертируется в JSON в деструкторе):**
```php
public function getData(): array
{
    return ['key' => 'value']; // Автоматически станет JSON
}
```
### Двойное шифрование (Double Cryptography)

**Важно:** Это основная фишка системы Notes, с которой начиналась разработка.

#### Концепция

Перед сохранением данных в базу данных они проходят **два уровня шифрования** разными алгоритмами и ключами:

1. **Первый уровень**: AES-256-GCM с уникальным ключом (`UNIQUE_KEY`)
2. **Второй уровень**: AES-256-CBC с вторичным ключом (`SECONDARY_KEY`)

При чтении расшифровка происходит в обратном порядке.

#### Настройка

В `.env` файле должны быть указаны ключи шифрования:

```env
UNIQUE_KEY=your_32_byte_unique_key_here
SECONDARY_KEY=your_secondary_key_here
```

Если `SECONDARY_KEY` не указан, он автоматически генерируется на основе `UNIQUE_KEY`.

#### Использование в контроллерах

**Шифрование заметки перед сохранением:**

```php
use App\Helpers\CryptMethods;
use Core\DatabaseManager;

class NoteController extends Controller
{
    public function create(Request $request): void
    {
        $content = $request->post('content');
        
        // Двойное шифрование содержимого заметки
        $encryptedContent = CryptMethods::doubleEncrypt(
            $content,
            $noteUid // Контекст для уникального IV
        );
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $noteUid,
            'content' => $encryptedContent, // Сохраняем зашифрованное
            'user_id' => $userId
        ], 'notes');
        $dbManager->commit();
    }
}
```

**Расшифровка при чтении:**

```php
public function view(string $uid): void
{
    $note = NoteModel::select()->where('uid', '=', $uid)->first();
    
    // Двойная расшифровка содержимого
    $decryptedContent = CryptMethods::doubleDecrypt(
        $note->content,
        $uid // Тот же контекст что при шифровании
    );
    
    $this->render_template('notes_page/view', [
        'note' => $note,
        'content' => $decryptedContent
    ]);
}
```

#### Методы класса CryptMethods

##### doubleEncrypt - двойное шифрование

```php
/**
 * @param string $data Данные для шифрования
 * @param string|null $context Контекст для генерации уникального IV (например, UID записи)
 * @return string Зашифрованные данные в base64
 */
$encrypted = CryptMethods::doubleEncrypt($data, $context);
```

##### doubleDecrypt - двойная расшифровка

```php
/**
 * @param string $encryptedData Зашифрованные данные из БД
 * @param string|null $context Контекст, использованный при шифровании
 * @return string Расшифрованные данные
 */
$decrypted = CryptMethods::doubleDecrypt($encryptedData, $context);
```

##### createHashFromPassword / verifyPassword - хеширование паролей

Для паролей используется многоуровневое хеширование:
1. bcrypt
2. SHA256 с UNIQUE_KEY и солью
3. 1000 раундов SHA256

```php
// При регистрации
$hashedPassword = CryptMethods::createHashFromPassword($password);

// При входе
if (CryptMethods::verifyPassword($inputPassword, $storedHash)) {
    // Пароль верный
}
```

##### quickEncrypt / quickDecrypt - быстрое шифрование

Для временных данных или когда не требуется двойное шифрование:

```php
$encrypted = CryptMethods::quickEncrypt($data);
$decrypted = CryptMethods::quickDecrypt($encrypted);
```

#### Применение

**Заметки пользователей:**
- Содержимое заметок шифруется перед сохранением в БД
- Расшифровывается только при просмотре авторизованным пользователем
- Даже при утечке БД данные остаются защищенными

**Сообщения между пользователями:**
- Сообщения в мессенджере шифруются двойным методом
- Каждый диалог может иметь уникальный контекст для IV
- Обеспечивает конфиденциальность переписки

**Личные файлы:**
- Метаданные и содержимое файлов могут быть зашифрованы
- Ключи шифрования привязаны к пользователю

#### Безопасность

**Преимущества двойного шифрования:**
- Даже если один алгоритм будет скомпрометирован, второй уровень защищает данные
- Разные ключи для каждого уровня усложняют атаку
- Уникальный IV для каждой записи предотвращает анализ паттернов

**Важные замечания:**
- Храните ключи шифрования в безопасном месте (.env вне веб-доступа)
- Регулярно делайте бэкапы ключей
- При потере ключей данные невозможно будет восстановить
- Для сквозного шифрования в мессенджере рекомендуется использовать дополнительные методы (например, обмен ключами Diffie-Hellman)

https://smarty-php.github.io/smarty/stable/

---

## 🗄️ База данных

### Установка и миграция

Для развертывания базы данных выполните SQL-скрипт:

```bash
mysql -u root -p messenger_db < database/messenger_schema.sql
```

Или импортируйте файл `database/messenger_schema.sql` через phpMyAdmin или другой GUI-клиент.

### Структура таблиц

Мессенджер использует следующие таблицы:

1. **users** - пользователи системы
2. **dialogs** - диалоги (приватные и групповые)
3. **dialog_users** - связь пользователей с диалогами (участники)
4. **messages** - сообщения с поддержкой шифрования
5. **message_statuses** - статусы прочтения сообщений

Подробная схема описана в файле [`database/messenger_schema.sql`](database/messenger_schema.sql).

### Особенности безопасности БД

- Все текстовые сообщения хранятся в зашифрованном виде (AES-256-CBC)
- При утечке БД злоумышленник не сможет прочитать содержимое сообщений
- Ключ шифрования хранится в переменной окружения `MSG_SECRET_KEY`
- Реализовано safe-удаление: сообщения помечаются как удаленные, но не стираются физически

---

## 🔐 Переменные окружения

### Настройка .env

1. Скопируйте файл `default.env` в `.env`:
   ```bash
   cp default.env .env
   ```

2. Отредактируйте `.env`, указав свои значения:
   ```env
   DBDRIVER=mysql
   DBHOST=localhost
   DBUSER=root
   DBPASS=your_secure_password
   DBNAME=messenger_db
   
   # Обязательно смените ключ!
   MSG_SECRET_KEY=ваш_32_символьный_ключ
   ```

3. Сгенерируйте надежный ключ шифрования:
   ```bash
   openssl rand -hex 32
   ```

### Список всех переменных

| Переменная | Описание | Пример |
|------------|----------|--------|
| `DBDRIVER` | Драйвер БД | `mysql` |
| `DBHOST` | Хост БД | `localhost` |
| `DBPORT` | Порт БД | `3306` |
| `DBUSER` | Пользователь БД | `root` |
| `DBPASS` | Пароль БД | `secret` |
| `DBNAME` | Имя БД | `messenger_db` |
| `MSG_SECRET_KEY` | Ключ шифрования сообщений (32 символа) | `a1b2c3...` |
| `UNIQUE_KEY` | Дополнительный ключ для файлов | `file_key...` |
| `UPLOAD_DIR` | Путь для загрузки файлов | `/var/www/uploads` |
| `SITEURL` | URL сайта | `http://localhost` |
| `WS_HOST` | Хост WebSocket сервера | `0.0.0.0` |
| `WS_PORT` | Порт WebSocket сервера | `8080` |

### ⚠️ Важно!

- Файл `.env` добавлен в `.gitignore` и **не должен** коммититься в репозиторий
- Используйте разные ключи для development/staging/production окружений
- Регулярно меняйте пароли и ключи шифрования

---

## 💬 Мессенджер

### Возможности

✅ **Типы диалогов:**
- Приватные чаты (2 пользователя)
- Групповые чаты (несколько участников)

✅ **Сообщения:**
- Текстовые сообщения с шифрованием
- Медиафайлы (изображения, аудио, видео, документы)
- Голосовые сообщения
- Редактирование своих сообщений
- Safe-удаление (без физического удаления из БД)
- Статусы прочтения

✅ **Безопасность:**
- AES-256-CBC шифрование всех текстовых сообщений
- Защита от утечки БД
- Проверка прав доступа к диалогам

✅ **UX:**
- Индикатор набора текста ("Печатает...")
- Автоматическая прокрутка к новым сообщениям
- Подсчет непрочитанных сообщений
- Поиск пользователей для создания диалога

### Запуск WebSocket сервера

```bash
php ws_server/server.php start
```

Сервер слушает порт `8080` (настраивается в `.env`).

### API WebSocket

#### Создание диалога
```javascript
ws.send(JSON.stringify({
    action: 'create_dialog',
    data: {
        type: 'group', // или 'private'
        users: [1, 2, 3], // ID участников
        name: 'Домовой чат' // опционально, для групп
    }
}));
```

#### Отправка сообщения
```javascript
ws.send(JSON.stringify({
    action: 'message_send',
    data: {
        dialog_id: 1,
        content: 'Привет!',
        type: 'text' // text, image, audio, video, file, voice
    }
}));
```

#### Редактирование сообщения
```javascript
ws.send(JSON.stringify({
    action: 'edit_message',
    data: {
        message_id: 42,
        content: 'Исправленный текст'
    }
}));
```

#### Удаление сообщения
```javascript
ws.send(JSON.stringify({
    action: 'delete_message',
    data: {
        message_id: 42
    }
}));
```

### Архитектура безопасности мессенджера

1. **Шифрование на уровне приложения:**
   - Сообщение шифруется перед сохранением в БД
   - Расшифровка происходит только при чтении авторизованным пользователем
   - Ключ хранится в `MSG_SECRET_KEY`

2. **Защита при утечке БД:**
   - Даже при полном доступе к БД сообщения остаются зашифрованными
   - Поле `content` содержит base64(AES-256-CBC(текст))

3. **Safe-удаление:**
   - Удаленные сообщения помечаются флагом `is_deleted = 1`
   - Контент заменяется на "[Сообщение удалено]"
   - Физическое удаление не производится (для аудита)

4. **Перспектива E2EE:**
   - В будущих версиях планируется полноценное End-to-End шифрование
   - Генерация ключей на стороне клиента
   - Обмен ключами через защищенный канал

---

## 📁 Структура проекта

```
/workspace
├── app/
│   ├── controllers/     # Контроллеры приложения
│   ├── models/          # Модели данных
│   ├── handlers/        # Обработчики (криптография и др.)
│   ├── middlewares/     # Middleware для маршрутов
│   ├── socket/          # WebSocket обработчики
│   └── views/           # Шаблоны Smarty
├── core/                # Ядро фреймворка
├── ws_server/           # WebSocket сервер
│   ├── server.php       # Точка входа WS сервера
│   └── MessagerController.php  # Логика мессенджера
├── database/            # SQL скрипты и миграции
│   ├── messenger_schema.sql    # Схема БД мессенджера
│   └── notes_schema.sql        # Схема БД заметок (Notes 2.0)
├── assets/              # Статические файлы
├── .env                 # Переменные окружения (не коммитить!)
├── default.env          # Шаблон переменных окружения
├── .gitignore           # Игнорируемые файлы Git
└── README.md            # Документация
```

---

## 🔐 Требования к базе данных и конфигурации

### Обязательные таблицы

#### Мессенджер (messenger_schema.sql):
- `users` - пользователи системы
- `dialogs` - диалоги (private/group)
- `dialog_users` - участники диалогов
- `messages` - сообщения с поддержкой типов: text, image, audio, video, file, voice
- `message_statuses` - статусы доставки/прочтения

#### Заметки (notes_schema.sql):
- `notes` - личные заметки с шифрованием контента
- `note_attachments` - медиа-вложения (фото, аудио, видео, голосовые)
- `shared_notes` - общий доступ через токены
- `note_history` - аудит изменений
- `note_tags` - теги пользователей
- `note_tag_relations` - связи заметок с тегами

### Переменные окружения (.env)

**Обязательные:**
- `DBDRIVER` - драйвер БД (mysql/sqlite)
- `DBHOST`, `DBPORT`, `DBUSER`, `DBPASS`, `DBNAME` - подключение к БД
- `MSG_SECRET_KEY` - ключ шифрования сообщений (32 символа)
- `NOTE_SECRET_KEY` - ключ шифрования заметок (32 символа)

**Рекомендуемые:**
- `UPLOAD_DIR` - путь загрузки файлов мессенджера
- `NOTES_UPLOAD_DIR` - путь загрузки файлов заметок
- `MAX_UPLOAD_SIZE` - максимальный размер файла (байты)
- `MAX_NOTE_ATTACHMENTS` - макс. количество вложений на заметку
- `WS_HOST`, `WS_PORT` - настройки WebSocket сервера
- `LOG_LEVEL`, `LOG_FILE` - настройки логирования

### Генерация ключей шифрования

```bash
# Для сообщений мессенджера
openssl rand -hex 32

# Для заметок
openssl rand -hex 32
```

---

## 🛡️ Рекомендации по безопасности

1. **На production:**
   - Используйте HTTPS для веб-интерфейса
   - Настройте WSS (WebSocket Secure) для мессенджера
   - Регулярно обновляйте зависимости
   - Мониторьте логи на предмет подозрительной активности

2. **Резервное копирование:**
   - Делайте бэкапы БД ежедневно
   - Храните бэкапы в зашифрованном виде
   - Сохраняйте ключи шифрования отдельно от данных

3. **Ключи шифрования:**
   - Никогда не храните ключи в коде
   - Используйте менеджеры секретов (HashiCorp Vault, AWS Secrets Manager)
   - Меняйте ключи при компрометации

4. **Аудит:**
   - Включите логирование всех операций с сообщениями
   - История изменений заметок ведется автоматически через триггеры БД

---

## 📋 Новые функции Notes 2.0+

### Поддерживаемые типы контента

1. **Текстовые заметки** - шифруются двойным шифрованием (AES-256-GCM + AES-256-CBC)
2. **Изображения** - JPEG, PNG, GIF, WebP с предпросмотром
3. **Аудиофайлы** - MP3, WAV, OGG с плеером
4. **Видеофайлы** - MP4, WebM, AVI с плеером
5. **Голосовые заметки** - запись прямо из браузера через Web Audio API
6. **Документы** - PDF, DOC, DOCX, TXT и другие файлы

### Функциональность

- ✅ **Загрузка файлов** - drag & drop или выбор через диалог
- ✅ **Голосовая запись** - встроенный рекордер с предпросмотром
- ✅ **Просмотр медиа** - встроенные плееры для аудио/видео
- ✅ **Шаринг заметок** - создание ссылок с настройками доступа
- ✅ **История изменений** - автоматическое сохранение через триггеры
- ✅ **Теги** - организация заметок по категориям
- ✅ **Safe-удаление** - восстановление удаленных заметок

### API контроллера

| Метод | URL | Описание |
|-------|-----|----------|
| GET | `/notes/` | Список заметок пользователя |
| POST | `/notes/` | Создание новой заметки |
| GET | `/notes/{uid}/edit` | Страница редактирования |
| POST | `/notes/{uid}/edit` | Обновление текста заметки |
| GET | `/notes/{uid}/delete` | Удаление заметки (safe) |
| POST | `/notes/upload/{uid}` | Загрузка вложения (AJAX) |
| POST | `/notes/attachment/delete/{id}` | Удаление вложения |
| POST | `/notes/share/{uid}` | Создать ссылку для шаринга |
| POST | `/notes/unshare/{uid}` | Деактивировать ссылку |
| GET | `/notes/shared/{token}` | Публичный просмотр по токену |

### Примеры использования

#### Создание заметки с вложением

```javascript
// Загрузка изображения
const formData = new FormData();
formData.append('attachment', fileInput.files[0]);

fetch('/notes/upload/' + noteUid, {
    method: 'POST',
    body: formData
})
.then(res => res.json())
.then(data => {
    if (data.success) {
        console.log('Файл загружен:', data.attachment.file_name);
    }
});
```

#### Запись голосового сообщения

```javascript
// Использование MediaRecorder API
const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
const recorder = new MediaRecorder(stream);

recorder.ondataavailable = (e) => chunks.push(e.data);
recorder.onstop = () => {
    const blob = new Blob(chunks, { type: 'audio/webm' });
    // Отправка blob на сервер
};
recorder.start();
```

#### Шаринг заметки

```javascript
// Создание ссылки с доступом на редактирование
fetch('/notes/share/' + noteUid, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'access_type=edit&expires_in=24' // 24 часа
})
.then(res => res.json())
.then(data => {
    console.log('Ссылка:', data.share_url);
});
```

---

## 📚 Дополнительные ресурсы

- [Документация по шифрованию](docs/encryption.md)
- [Руководство по WebSocket](docs/websocket.md)
- [API мессенджера](docs/messenger-api.md)
   - Регулярно проверяйте доступы к БД
   - Настройте алерты на подозрительную активность

5. **Файлы заметок:**
   - Храните загруженные файлы вне корневой директории веб-сервера
   - Используйте защищенные директивы .htaccess для директорий загрузок
   - Ограничьте MIME-типы разрешенных файлов
   - Сканируйте загруженные файлы на наличие вредоносного кода
