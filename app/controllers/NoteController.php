<?php
namespace App\Controller;

use App\Models\NoteModel;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Route;

    class NoteController extends Controller {
        public function index(Request $request) {
            $userModel = new UserModel();
            $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();

            $noteModel = new NoteModel();
            $userNotes = $noteModel->select('notes')->where('user_id', '=', $user['id'])->get();
            $allNotes = $noteModel->select('notes')->get();
            $data = [
                'personalNotes' => $userNotes,
                'allNotes' => $allNotes,
                'user' => $user
            ]; 

            $this->render_template('notes_page/index', $data);
        }

        public function edit(Request $request, $uid) {
            $userModel = new UserModel();
            $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();

            $noteModel = new NoteModel();
            $note = $noteModel->select('notes')->innerJoin('users', 'notes.user_id', 'id', ['username'])->where('notes.uid', '=', $uid)->first();
            
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
            $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();

            $noteModel = new NoteModel();
            $note = $noteModel->select('notes')->where('uid', '=', $uid)->first(true);
            
            if ($note['user_id'] != $user['id'] || $user['role'] < 900) {
                return Route::getInstance()->redirect('notes', 'name');
            }

            $note->content = $request->post('content');

            $dbManager = new DatabaseManager();
            $dbManager->queueUpdate($note);

            $dbManager->commit();
            return Route::getInstance()->redirect('notes', 'name');
        }

        function delete(Request $request, $uid) {  
            $userModel = new UserModel();
            $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();

            $noteModel = new NoteModel();
            $note = $noteModel->select('notes')->where('uid', '=', $uid)->first(true);

            if ($note['user_id'] != $user['id'] || $user['role'] < 900) {
                return Route::getInstance()->redirect('notes', 'name');
            }

            $dbManager = new DatabaseManager();
            $dbManager->queueDelete($note);

            $dbManager->commit();
            return Route::getInstance()->redirect('notes', 'name');
        }
    }