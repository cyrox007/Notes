<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptMethods;
use Core\Config;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class UserProvisioningService
{
    private PermissionService $permissions;
    private LicenseSeatPolicy $seatPolicy;

    public function __construct(
        private ?DatabaseManager $db = null,
        ?PermissionService $permissions = null,
        ?LicenseSeatPolicy $seatPolicy = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
        $this->seatPolicy = $seatPolicy ?? new LicenseSeatPolicy($this->db);
    }

    /** @param array<string,mixed> $input */
    public function createByAdmin(int $actorId, array $input): int
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        return $this->createUser($input);
    }

    /** @param array<string,mixed> $input */
    public function createSelfService(array $input): int
    {
        return $this->createUser($input);
    }

    /** @param array<string,mixed> $input */
    private function createUser(array $input): int
    {
        $data = $this->normalizeAndValidate($input);

        return $this->seatPolicy->withAvailableSeat(function () use ($data): int {
            $existing = $this->db->fetchOne(
                'SELECT id,username,email FROM users WHERE username = :username OR email = :email LIMIT 1',
                [':username' => $data['username'], ':email' => $data['email']]
            );
            if ($existing !== null) {
                throw new DomainException('Пользователь с таким логином или email уже существует', 409);
            }

            $now = date('Y-m-d H:i:s');
            try {
                $this->db->execute(
                    'INSERT INTO users '
                    . '(uid,username,email,password_hash,firstname,patronymic,lastname,phone,avatar,property,role,is_active,account_status,created_at,updated_at) '
                    . 'VALUES '
                    . '(:uid,:username,:email,:password_hash,:firstname,:patronymic,:lastname,:phone,NULL,:property,:role,1,\'active\',:created_at,:updated_at)',
                    [
                        ':uid' => \UUID::v4(),
                        ':username' => $data['username'],
                        ':email' => $data['email'],
                        ':password_hash' => CryptMethods::hashPassword($data['password']),
                        ':firstname' => $data['firstname'],
                        ':patronymic' => $data['patronymic'] !== '' ? $data['patronymic'] : null,
                        ':lastname' => $data['lastname'],
                        ':phone' => $data['phone'] !== '' ? $data['phone'] : null,
                        ':property' => json_encode([], JSON_THROW_ON_ERROR),
                        ':role' => Config::USER_ROLE_USER,
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]
                );
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new DomainException('Пользователь с таким логином или email уже существует', 409, $e);
                }
                throw $e;
            }

            return (int) $this->db->getPdo()->lastInsertId();
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{username:string,password:string,firstname:string,patronymic:string,lastname:string,phone:string,email:string}
     */
    private function normalizeAndValidate(array $input): array
    {
        $username = trim((string) ($input['login'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $firstname = trim((string) ($input['first_name'] ?? $input['firstname'] ?? ''));
        $patronymic = trim((string) ($input['patronymic'] ?? ''));
        $lastname = trim((string) ($input['surname'] ?? $input['lastname'] ?? ''));
        $phone = trim((string) ($input['user_phone'] ?? $input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1) {
            throw new InvalidArgumentException('Логин должен содержать 3–50 латинских букв, цифр, точек, дефисов или подчёркиваний', 422);
        }
        if (strlen($password) < 10 || strlen($password) > 200) {
            throw new InvalidArgumentException('Пароль должен содержать от 10 до 200 символов', 422);
        }
        if ($firstname === '' || mb_strlen($firstname) > 80) {
            throw new InvalidArgumentException('Укажите корректное имя длиной до 80 символов', 422);
        }
        if ($lastname === '' || mb_strlen($lastname) > 80) {
            throw new InvalidArgumentException('Укажите корректную фамилию длиной до 80 символов', 422);
        }
        if ($patronymic !== '' && mb_strlen($patronymic) > 80) {
            throw new InvalidArgumentException('Отчество слишком длинное', 422);
        }
        if ($phone !== '' && mb_strlen($phone) > 32) {
            throw new InvalidArgumentException('Телефон слишком длинный', 422);
        }
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Укажите корректный email', 422);
        }

        return [
            'username' => $username,
            'password' => $password,
            'firstname' => $firstname,
            'patronymic' => $patronymic,
            'lastname' => $lastname,
            'phone' => $phone,
            'email' => $email,
        ];
    }
}
