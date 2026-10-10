<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\MessengerCallService;
use Core\Controller;
use Core\Request;

final class MessengerCallController extends Controller
{
    public function poll(Request $request):void { $this->respond($request,false); }
    public function signal(Request $request):void { $this->respond($request,true); }
    private function respond(Request $request,bool $write):void
    {
        header('Cache-Control: no-store');
        try {
            $userId=(int)$request->session('user_id',0);
            if($userId<=0) throw new \DomainException('Требуется авторизация');
            if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
            $service=new MessengerCallService();
            $data=$write?$service->signal($userId,(string)$request->rawPost('dialog_uid',''),(string)$request->rawPost('id',''),(string)$request->rawPost('action',''),(string)$request->rawPost('mode','audio'),(string)$request->rawPost('sdp','')):$service->poll($userId);
            $this->responseJson(['success'=>true,$write?'call':'calls'=>$data]);
        } catch(\DomainException $e) {http_response_code(403);$this->responseJson(['success'=>false,'message'=>$e->getMessage()]);}
        catch(\InvalidArgumentException $e) {http_response_code(422);$this->responseJson(['success'=>false,'message'=>$e->getMessage()]);}
        catch(\Throwable $e) {error_log('Native call signaling: '.$e->getMessage());http_response_code(503);$this->responseJson(['success'=>false,'message'=>'Сигнализация звонка временно недоступна']);}
    }
}
