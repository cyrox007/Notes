<?php
namespace App\Controllers;

use App\Models\NoteModel;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Route;
use UUID;

class NoteController extends Controller {
    public function index(Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $noteModel = new NoteModel();
        $userNotes = $noteModel->select()->where('user_id', '=', $user['id'])->get();
        
        $allNotes = $noteModel->select()->innerJoin('users', 'user_id', 'id', ['username', 'uid'])->get();
        
        $data = [
            'personalNotes' => $userNotes,
            'allNotes' => $allNotes,
            'user' => $user
        ]; 

        $this->render_template('notes_page/index', $data);
    }

    public function create(Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $uidNote = UUID::guidv4();
        $created_at = date("Y-m-d H:i:s");
        
        $newNote = new NoteModel();
        $newNote->uid = $uidNote;
        $newNote->notename = $request->post('notename');
        $newNote->content = '';
        $newNote->created_note = $created_at;
        $newNote->updated_note = $created_at;
        $newNote->user_id = $user['id'];

        $dbManager = new DatabaseManager();
        $dbManager->queueInsert($newNote);
        $dbManager->commit();

        return Route::getInstance()->redirect('edit_page', 'name', ['uid' => $uidNote]);
    }

    public function edit(Request $request, $uid) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $noteModel = new NoteModel();
        $note = $noteModel->select()->innerJoin('users', 'user_id', 'id', ['username'])->where('uid', '=', $uid)->first();
        
        if ($note['user_id'] != $user['id'] || $user['role'] < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }
        
        $data = [
            'user' => $user,
            'note' => $note
        ];
        return $this->render_template('notes_page/edit_view', $data);
    }

    public function update(Request $request, $uid) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $noteModel = new NoteModel();
        $note = $noteModel->select()->where('uid', '=', $uid)->first(true);
        
        if ($note->user_id != $user['id'] || $user['role'] < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }

        $note->content = $request->post('content');
        $note->updated_note = date("Y-m-d H:i:s");

        $dbManager = new DatabaseManager();
        $dbManager->queueUpdate($note);

        $dbManager->commit();
        return Route::getInstance()->redirect('notes', 'name');
    }

    function delete(Request $request, $uid) {  
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $noteModel = new NoteModel();
        $note = $noteModel->select()->where('uid', '=', $uid)->first(true);

        if ($note->user_id != $user['id'] || $user['role'] < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }

        $dbManager = new DatabaseManager();
        $dbManager->queueDelete($note);

        $dbManager->commit();
        return Route::getInstance()->redirect('notes', 'name');
    }
}