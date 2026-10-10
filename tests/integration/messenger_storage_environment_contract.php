<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/core/Environment.php';
require $root.'/modules/messenger/services/MessengerVoiceService.php';
require $root.'/modules/messenger/services/MessengerMediaService.php';
$previous=getenv('PRIVATE_STORAGE_PATH');$previousEnv=$_ENV['PRIVATE_STORAGE_PATH']??null;
try {
    putenv('PRIVATE_STORAGE_PATH');
    $_ENV['PRIVATE_STORAGE_PATH']=sys_get_temp_dir().DIRECTORY_SEPARATOR.'notes-request-local-storage';
    foreach ([App\Services\MessengerVoiceService::class,App\Services\MessengerMediaService::class] as $class) {
        $reflection=new ReflectionClass($class);
        $instance=$reflection->newInstanceWithoutConstructor();
        $method=$reflection->getMethod('privateStorageRoot');$method->setAccessible(true);
        if($method->invoke($instance)!==$_ENV['PRIVATE_STORAGE_PATH'])throw new RuntimeException($class.' lost request-local private storage');
    }
    echo "PASS: voice upload and recorded send preserve request-local storage when process environment disappears\n";
} finally {
    $previous===false?putenv('PRIVATE_STORAGE_PATH'):putenv('PRIVATE_STORAGE_PATH='.$previous);
    if($previousEnv===null)unset($_ENV['PRIVATE_STORAGE_PATH']);else $_ENV['PRIVATE_STORAGE_PATH']=$previousEnv;
}
