# My Local Server System

## Description
PHP суперприложение для работы с: 
* персональными заметками на корпоративном предприятии;
* Хранение и работа с личными файлами пользователя;
* Плеер мультимедия;
* CodeExplorer;
* Мессенджер;
* Ежедневник;
* Задачи;

## Requirement
* **PHP 8.3+** (требуется для строгой типизации, enum, constructor property promotion)
* MySQL / MariaDB или SQLite
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
Так же для работы приложения необходима база данных SQLite, которую необходимо сформировать самостоятельно
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
    $router->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');
    $router->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create');
    $router->add('GET', '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page');
    $router->add('POST', '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note');
    $router->add('GET', '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note');
});

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