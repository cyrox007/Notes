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
        $user = UserModel::select()
        ->where('uid', '=', $request->session('user_uid'))
        ->first();

        $sort = $request->get('sort') ?: 'created_note';
        $direction = $request->get('direction')?: 'desc';

        $userNotes = NoteModel::select('uid', 'notename', 'created_note', 'updated_note')
        ->where('user_id', '=', $user->id)
        ->orderBy($sort, $direction)
        ->get();
        
        $allNotes = NoteModel::select(
            'notes.uid', 'notes.notename', 'notes.created_note', 'notes.updated_note',
            'author.username', 'author.uid'
        )
        ->innerJoin([UserModel::class, 'author'], 'notes.user_id', '=', 'author.id')
        ->orderBy($sort, $direction)
        ->get();
        
        $data = [
            'personalNotes' => $userNotes,
            'allNotes' => $allNotes,
            'user' => $user
        ]; 
        
        $this->render_template('notes_page/index', $data);
    }

    public function create(Request $request) {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $uidNote = UUID::guidv4();
        $created_at = date("Y-m-d H:i:s");
        
        $newNote = new NoteModel();
        $newNote->uid = $uidNote;
        $newNote->notename = $request->post('notename');
        $newNote->content = '';
        $newNote->created_note = $created_at;
        $newNote->updated_note = $created_at;
        $newNote->user_id = $user->id;

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $newNote->uid,
            'notename' => $newNote->notename,
            'content' => $newNote->content,
            'created_note' => $newNote->created_note,
            'updated_note' => $newNote->updated_note,
            'user_id' => $newNote->user_id
        ], 'notes');
        $dbManager->commit();

        return Route::getInstance()->redirect('edit_page', 'name', ['uid' => $uidNote]);
    }

    public function edit(Request $request, string $uid) {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $note = NoteModel::select(
            'notes.uid', 'notes.notename', 'notes.created_note', 
            'notes.updated_note', 'notes.user_id', 'notes.content',
            'author.username', 'author.uid'
        )
            ->innerJoin([UserModel::class, 'author'], 'notes.user_id', '=', 'author.id')
            ->where('notes.uid', '=', $uid)
            ->first();
        
        if ($note->user_id != $user->id || $user->role < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }
        
        $data = [
            'user' => $user,
            'note' => $note
        ];
        return $this->render_template('notes_page/edit_view', $data);
    }

    public function update(Request $request, $uid) {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $note = NoteModel::select()->where('uid', '=', $uid)->first(true);
        
        if ($note->user_id != $user->id || $user->role < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }

        $note->content = $request->post('content');
        $note->updated_note = date("Y-m-d H:i:s");

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'content' => $note->content,
            'updated_note' => $note->updated_note
        ], 'notes', $note->id);

        $dbManager->commit();
        return Route::getInstance()->redirect('notes', 'name');
    }

    function delete(Request $request, $uid) {  
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $note = NoteModel::select()->where('uid', '=', $uid)->first();

        if ($note->user_id != $user->id || $user->role < 900) {
            return Route::getInstance()->redirect('notes', 'name');
        }

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueDelete('notes', $note->id);

        $dbManager->commit();
        return Route::getInstance()->redirect('notes', 'name');
    }
}