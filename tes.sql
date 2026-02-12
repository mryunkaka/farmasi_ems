ALTER TABLE user_rh
ADD COLUMN citizen_id VARCHAR(30) NULL AFTER full_name,
ADD COLUMN jenis_kelamin ENUM('Laki-laki','Perempuan') NULL AFTER citizen_id;

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_event VARCHAR(150) NOT NULL,
    tanggal_event DATE NOT NULL,
    lokasi VARCHAR(150),
    keterangan TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_user (event_id, user_id)
);

ALTER TABLE user_rh
ADD COLUMN no_hp_ic VARCHAR(20) NULL
AFTER citizen_id;

CREATE TABLE event_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    group_name VARCHAR(50) NOT NULL,
    locked TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX (event_id)
);

CREATE TABLE event_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_group_id INT NOT NULL,
    user_id INT NOT NULL,

    UNIQUE KEY uniq_group_user (event_group_id, user_id),
    INDEX (event_group_id),
    INDEX (user_id)
);

