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
Шаршрутизация работает следующим образом. В файл `routerConfig.php` вызывается функция `Route::add();`, которая принимает три аргумента:
* Метод запроса
* Путь запроса
* Массив:
* * Класс контроллера
* * Метод этого контроллера
Пример:
``` php
use App\Controller\Main;
$router->add('GET', '/', [Main::class, 'index']);
```
**Изменено v0.6.3**
На текущей стадии маршритизатор способен воспринять и передать специальные динамические параметры маршрута, в любом количестве. Таким образом если вам необходимо, создать такой маршрут, который будет обрабатывать запросы вида `/post/12`, для получения например детального просмотра поста из базы данных, то шаблон маршрута должен быть следующим.
``` php
$router->add('GET', '/post/{int:id}', [Post::class, 'show']);
```
Обратите внимание, что параметр всегда заключается в фигурные скобки `{}`, внутри которых указывается тип данных (`int` - для числовых значений, `str` - для символьных), затем через двоеточие имя параметра.
О том как получить значение этих параметров, будет сказано в следующем разделе.
**Добавлено v0.6.4**
Пример группировки маршрутов с префиксом:
``` php
$router->group('/auth', function ($addRoute) {
    $addRoute('GET', '/login', [Auth::class, 'login'], [], "authpage");
    $addRoute('POST', '/login', [Auth::class, 'sigin']);
    $addRoute('POST', '/logout', [Auth::class, 'logout'], [LoginRequared::class], 'logout');
});
```
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
### Шаблонизатор 
Шаблоны - это лицо вашего приложения. Шаблонизатор реализован таким образом, что при загрузке и выполнении логики любого действия контроллера можно вызвать в самом конце функцию представления. 
В обновленной версии фреймворка изменился принцип вывода представления. Во первых есть два способа ответа контроллера на запрос. Это может быть как строка, в том числе сериализованная в формате JSON (за это отвечает втроеный метод `response_json`), во вторых - можно вызвать втроеный метод `render_template`, который теперь принимает только два аргумента вместо трех, как это было в прошлой версии: это путь и имя шаблона без расширения, и данные, которые нужно передать в шаблон.
За отрисовку шаблона теперь отвечает шаблонизатор Smarty. На текущий момент это демократичное решение, которое позволит сгенерировать шаблон любой сложности и парсить его без проблем. 
https://smarty-php.github.io/smarty/stable/