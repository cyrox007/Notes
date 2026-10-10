<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/modules/messenger/handlers/MessengerCrypto.php';
$secret=getenv('MSG_SECRET_KEY');$local=$_ENV['MSG_SECRET_KEY']??null;
try {
 putenv('MSG_SECRET_KEY');$_ENV['MSG_SECRET_KEY']=str_repeat('local-regression-key-',3);
 $uid='test-request-local';
 foreach(['','Voice caption'] as $text){$cipher=App\Helpers\MessengerCrypto::encrypt($text,$uid);if(App\Helpers\MessengerCrypto::decrypt($cipher,$uid)!==$text)throw new RuntimeException('Roundtrip failed');}
 echo "PASS: recorded captions decrypt with request-local key when Apache process variable disappears\n";
}finally{$secret===false?putenv('MSG_SECRET_KEY'):putenv('MSG_SECRET_KEY='.$secret);if($local===null)unset($_ENV['MSG_SECRET_KEY']);else $_ENV['MSG_SECRET_KEY']=$local;}
