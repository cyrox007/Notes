--
-- Файл сгенерирован с помощью SQLiteStudio v3.1.0 в Сб авг 20 14:53:41 2022
--
-- Использованная кодировка текста: UTF-8
--
PRAGMA foreign_keys = off;
BEGIN TRANSACTION;

-- Таблица: profile
CREATE TABLE profile (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name             TEXT    NOT NULL,
    patronymic             TEXT    NOT NULL,
    surname                TEXT    NOT NULL,
    user_phone             TEXT    NOT NULL,
    user_photo             TEXT    NOT NULL,
    user_position          TEXT    NOT NULL,
    department             TEXT    NOT NULL,
    office_phone           TEXT    NOT NULL,
    additional_information TEXT    NOT NULL,
    user_id                INTEGER REFERENCES users (id) 
                                   NOT NULL,
    FOREIGN KEY (
        user_id
    )
    REFERENCES users (id) 
);

INSERT INTO profile (
                        id,
                        first_name,
                        patronymic,
                        surname,
                        user_phone,
                        user_photo,
                        user_position,
                        department,
                        office_phone,
                        additional_information,
                        user_id
                    )
                    VALUES (
                        1,
                        'Константин',
                        'Петрович',
                        'Филипов',
                        '+79876543210',
                        'app/uploads/us_avatars/user_default.png',
                        'Самый главный по тарелочкам',
                        'Диванный спецназ',
                        '+79999999999',
                        'доп. информация для админа',
                        3
                    );

INSERT INTO profile (
                        id,
                        first_name,
                        patronymic,
                        surname,
                        user_phone,
                        user_photo,
                        user_position,
                        department,
                        office_phone,
                        additional_information,
                        user_id
                    )
                    VALUES (
                        2,
                        'Александр',
                        'Александрович',
                        'Александров',
                        '89997509826',
                        'app/uploads/us_avatars/user_default.png',
                        'Секретная',
                        'ФСБ',
                        '89997509826',
                        'fff',
                        4
                    );


-- Таблица: users
CREATE TABLE users (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT    NOT NULL,
    password TEXT    NOT NULL,
    role     INTEGER NOT NULL
);

INSERT INTO users (
                      id,
                      username,
                      password,
                      role
                  )
                  VALUES (
                      3,
                      'admin',
                      'Yw1Yn3G0Q1WFLOjayj/PiQ==',
                      111
                  );

INSERT INTO users (
                      id,
                      username,
                      password,
                      role
                  )
                  VALUES (
                      4,
                      'alex',
                      'Yw1Yn3G0Q1WFLOjayj/PiQ==',
                      888
                  );


-- Таблица: notes
CREATE TABLE notes (
    id            INTEGER  PRIMARY KEY AUTOINCREMENT,
    name_note     TEXT     NOT NULL,
    date_create   DATETIME NOT NULL,
    date_edit     DATETIME NOT NULL,
    notefile_link TEXT     NOT NULL,
    author        TEXT     NOT NULL,
    user_id       INTEGER  REFERENCES users (id) 
                           NOT NULL,
    FOREIGN KEY (
        user_id
    )
    REFERENCES users (id) 
);

INSERT INTO notes (
                      id,
                      name_note,
                      date_create,
                      date_edit,
                      notefile_link,
                      author,
                      user_id
                  )
                  VALUES (
                      15,
                      'Первая заметка',
                      '2022-07-07 15:34:30',
                      '2022-07-07 16:13:32',
                      'c855721/20220707_153429_Первая заметка.txt',
                      'admin',
                      3
                  );


-- Таблица: invations
CREATE TABLE invations (
    id            INTEGER  PRIMARY KEY AUTOINCREMENT,
    date_create   DATETIME NOT NULL,
    invation_code TEXT     NOT NULL,
    user_id       INTEGER  NOT NULL
                           REFERENCES users (id),
    FOREIGN KEY (
        user_id
    )
    REFERENCES users (id) 
);

INSERT INTO invations (
                          id,
                          date_create,
                          invation_code,
                          user_id
                      )
                      VALUES (
                          1,
                          'TstyUGeNmE',
                          'TstyUGeNmE',
                          3
                      );


-- Таблица: conversation
CREATE TABLE conversation (
    id              INTEGER     PRIMARY KEY AUTOINCREMENT
                                NOT NULL,
    user_id         INTEGER     NOT NULL
                                REFERENCES users (id),
    interlocutor_id INTEGER     REFERENCES users (id) 
                                NOT NULL,
    last_message_id INTEGER     REFERENCES messages (id) 
                                NOT NULL,
    sender_id       INTEGER     REFERENCES users (id) 
                                NOT NULL,
    first_delete    INTEGER (1) NOT NULL
                                DEFAULT (0),
    second_delete   INTEGER (1) NOT NULL
                                DEFAULT (0),
    unread          INTEGER     NOT NULL
);


-- Таблица: messages
CREATE TABLE messages (
    id               INTEGER     PRIMARY KEY AUTOINCREMENT
                                 NOT NULL,
    conv_id          INTEGER     REFERENCES conversation (id),
    sender_id        INTEGER     REFERENCES users (id),
    addressee        INTEGER     REFERENCES users (id),
    readed           INTEGER (1),
    sender_delete    INTEGER (1) DEFAULT (0),
    addressee_delete INTEGER (1) DEFAULT (0),
    message          TEXT,
    date             DATETIME
);

INSERT INTO messages (
                         id,
                         conv_id,
                         sender_id,
                         addressee,
                         readed,
                         sender_delete,
                         addressee_delete,
                         message,
                         date
                     )
                     VALUES (
                         1,
                         NULL,
                         NULL,
                         NULL,
                         NULL,
                         0,
                         0,
                         NULL,
                         NULL
                     );


COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
