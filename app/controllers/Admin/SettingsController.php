<?php

namespace App\Controllers\Admin;

use App\Models\SystemSettingModel;
use App\Models\UserStorageQuotaModel;
use App\Models\UserModel;
use Core\Controller;
use Core\Request;
use Core\DatabaseManager;

/**
 * Контроллер для управления настройками системы в админке
 */
class SettingsController extends Controller {
    
    /**
     * Главная страница настроек
     */
    public function index(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        // Проверка прав администратора
        if (!$user || $user->role < 900) {
            return $this->render_template('core/error', ['message' => 'Доступ запрещен']);
        }
        
        // Получаем все настройки, сгруппированные по категориям
        $settings = SystemSettingModel::select()->orderBy('category', 'ASC')->get();
        
        // Группируем настройки по категориям
        $groupedSettings = [];
        foreach ($settings as $setting) {
            $groupedSettings[$setting->category][] = $setting;
        }
        
        $data = [
            'user' => $user,
            'settings' => $groupedSettings
        ];
        
        return $this->render_template('admin-page/settings', $data);
    }
    
    /**
     * Сохранение настроек
     */
    public function save(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        // Проверка прав администратора
        if (!$user || $user->role < 900) {
            return $this->responseJson(['success' => false, 'message' => 'Доступ запрещен']);
        }
        
        $postData = $request->post();
        $settingsData = $postData['settings'] ?? [];
        
        if (empty($settingsData)) {
            return $this->responseJson(['success' => false, 'message' => 'Нет данных для сохранения']);
        }
        
        $dbManager = DatabaseManager::getInstance();
        $updatedCount = 0;
        
        foreach ($settingsData as $settingId => $value) {
            $setting = SystemSettingModel::select()->where('id', '=', $settingId)->first();
            
            if (!$setting) {
                continue;
            }
            
            // Проверка возможности редактирования
            if (!$setting->is_editable) {
                continue;
            }
            
            // Валидация и преобразование значения в зависимости от типа
            $validatedValue = $this->validateSettingValue($value, $setting->setting_type);
            
            $dbManager->queueUpdate([
                'setting_value' => $validatedValue
            ], 'system_settings', $settingId);
            
            $updatedCount++;
        }
        
        $result = $dbManager->commit();
        
        if ($result !== false) {
            return $this->responseJson([
                'success' => true, 
                'message' => "Обновлено настроек: {$updatedCount}"
            ]);
        }
        
        return $this->responseJson(['success' => false, 'message' => 'Ошибка при сохранении настроек']);
    }
    
    /**
     * Валидация значения настройки в зависимости от типа
     */
    private function validateSettingValue($value, $type) {
        switch ($type) {
            case 'number':
                return (int)$value;
            case 'boolean':
                return ($value == 'on' || $value == '1' || $value === true) ? 1 : 0;
            case 'json':
                // Пытаемся декодировать и закодировать JSON для валидации
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
                return '[]'; // Возвращаем пустой массив по умолчанию
            case 'string':
            default:
                return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Получение информации об использовании хранилища пользователями
     */
    public function storageUsage(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        // Проверка прав администратора
        if (!$user || $user->role < 900) {
            return $this->responseJson(['success' => false, 'message' => 'Доступ запрещен']);
        }
        
        // Получаем максимальный лимит хранилища из настроек
        $maxStorageSetting = SystemSettingModel::select()
            ->where('setting_key', '=', 'file_manager_max_storage_per_user')
            ->first();
        
        $maxStoragePerUser = $maxStorageSetting ? (int)$maxStorageSetting->setting_value : 1073741824; // 1GB по умолчанию
        
        // Получаем всех пользователей с их квотами
        $users = UserModel::select()->get();
        $storageData = [];
        
        foreach ($users as $u) {
            $quota = UserStorageQuotaModel::select()
                ->where('user_id', '=', $u->id)
                ->first();
            
            $usedStorage = $quota ? (int)$quota->used_storage : 0;
            $percentUsed = $maxStoragePerUser > 0 ? round(($usedStorage / $maxStoragePerUser) * 100, 2) : 0;
            
            $storageData[] = [
                'user_id' => $u->id,
                'username' => $u->username,
                'email' => $u->email,
                'firstname' => $u->firstname,
                'lastname' => $u->lastname,
                'used_storage' => $usedStorage,
                'used_storage_formatted' => $this->formatBytes($usedStorage),
                'max_storage' => $maxStoragePerUser,
                'max_storage_formatted' => $this->formatBytes($maxStoragePerUser),
                'percent_used' => $percentUsed
            ];
        }
        
        return $this->responseJson([
            'success' => true,
            'data' => $storageData,
            'default_max_storage' => $maxStoragePerUser,
            'default_max_storage_formatted' => $this->formatBytes($maxStoragePerUser)
        ]);
    }
    
    /**
     * Пересчет использования хранилища для пользователя
     */
    public function recalculateUserStorage(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        // Проверка прав администратора
        if (!$user || $user->role < 900) {
            return $this->responseJson(['success' => false, 'message' => 'Доступ запрещен']);
        }
        
        $targetUserId = (int)($request->post('user_id') ?? 0);
        
        if ($targetUserId <= 0) {
            return $this->responseJson(['success' => false, 'message' => 'Неверный ID пользователя']);
        }
        
        // Получаем все файлы пользователя
        $files = \App\Models\FileModel::select()
            ->where('user_id', '=', $targetUserId)
            ->where('is_deleted', '=', 0)
            ->get();
        
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += (int)$file->size;
        }
        
        // Обновляем или создаем запись о квоте
        $quota = UserStorageQuotaModel::select()
            ->where('user_id', '=', $targetUserId)
            ->first();
        
        $dbManager = DatabaseManager::getInstance();
        
        if ($quota) {
            $dbManager->queueUpdate([
                'used_storage' => $totalSize,
                'last_calculated_at' => date('Y-m-d H:i:s')
            ], 'user_storage_quotas', $quota->id);
        } else {
            $dbManager->queueInsert([
                'user_id' => $targetUserId,
                'used_storage' => $totalSize,
                'last_calculated_at' => date('Y-m-d H:i:s')
            ], 'user_storage_quotas');
        }
        
        $result = $dbManager->commit();
        
        if ($result !== false) {
            return $this->responseJson([
                'success' => true,
                'message' => 'Квота пересчитана',
                'used_storage' => $totalSize,
                'used_storage_formatted' => $this->formatBytes($totalSize)
            ]);
        }
        
        return $this->responseJson(['success' => false, 'message' => 'Ошибка при пересчете']);
    }
    
    /**
     * Форматирование размера в байтах в человекочитаемый формат
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
