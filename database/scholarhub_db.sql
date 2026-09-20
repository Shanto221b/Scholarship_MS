-- =====================================================================
-- ScholarHub — Scholarship Management System  (MySQL / MariaDB)
--
-- Import this ONE file in phpMyAdmin (Import tab), or just open
-- http://localhost/scholarhub/install.php and click "Install".
--
-- ID format (generated automatically by BEFORE INSERT triggers):
--   USERS USR0001 | DEPARTMENT DEP01 | ADMIN ADM001
--   STUDENT STD0001 | SCHOLARSHIP SCH001 | APPLICATION APP0001
-- Never put the ID column in an INSERT — the trigger fills it in.
--
-- Demo accounts:
--   Admin   : Admin1 / adminpass1   ... Admin5 / adminpass5
--   Student : std1   / stdpass1     ... std5   / stdpass5
-- =====================================================================

CREATE DATABASE IF NOT EXISTS scholarhub_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE scholarhub_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS payment;
DROP TABLE IF EXISTS remember_token;
DROP TABLE IF EXISTS application;
DROP TABLE IF EXISTS scholarship;
DROP TABLE IF EXISTS student;
DROP TABLE IF EXISTS admin;
DROP TABLE IF EXISTS department;
DROP TABLE IF EXISTS users;
DROP FUNCTION IF EXISTS fn_get_cgpa;
DROP FUNCTION IF EXISTS fn_app_count;
DROP FUNCTION IF EXISTS fn_fee_collected;
DROP PROCEDURE IF EXISTS proc_update_status;
DROP PROCEDURE IF EXISTS proc_get_admin_name;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- TABLES
-- =====================================================================

CREATE TABLE users (
    userid       VARCHAR(7)   NOT NULL DEFAULT '',
    username     VARCHAR(50)  NOT NULL,
    passwordhash VARCHAR(255) NOT NULL,
    role         VARCHAR(20)  NOT NULL,
    createdat    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastlogin    DATETIME     NULL,
    CONSTRAINT pk_users PRIMARY KEY (userid),
    CONSTRAINT uq_users_username UNIQUE (username),
    CONSTRAINT chk_users_role CHECK (role IN ('Admin','Student'))
) ENGINE=InnoDB;

CREATE TABLE department (
    deptid      VARCHAR(5)   NOT NULL DEFAULT '',
    deptname    VARCHAR(100) NOT NULL,
    facultyname VARCHAR(100) NOT NULL,
    CONSTRAINT pk_department PRIMARY KEY (deptid),
    CONSTRAINT uq_department_name UNIQUE (deptname)
) ENGINE=InnoDB;

CREATE TABLE admin (
    adminid VARCHAR(6)   NOT NULL DEFAULT '',
    userid  VARCHAR(7)   NOT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NULL,
    phone       VARCHAR(14)  NULL,
    designation VARCHAR(60)  NULL,
    photo       VARCHAR(80)  NULL,
    CONSTRAINT pk_admin PRIMARY KEY (adminid),
    CONSTRAINT uq_admin_user UNIQUE (userid),
    CONSTRAINT uq_admin_email UNIQUE (email),
    CONSTRAINT fk_admin_users FOREIGN KEY (userid) REFERENCES users(userid)
) ENGINE=InnoDB;

CREATE TABLE student (
    studentid VARCHAR(7)    NOT NULL DEFAULT '',
    userid    VARCHAR(7)    NOT NULL,
    deptid    VARCHAR(5)    NOT NULL,
    name      VARCHAR(100)  NOT NULL,
    email     VARCHAR(100)  NOT NULL,
    cgpa      DECIMAL(3,2),
    semester  TINYINT       NOT NULL,
    phone         VARCHAR(14)  NULL,
    dob           DATE         NULL,
    gender        VARCHAR(10)  NULL,
    address       VARCHAR(255) NULL,
    guardianname  VARCHAR(100) NULL,
    guardianphone VARCHAR(14)  NULL,
    photo         VARCHAR(80)  NULL,
    CONSTRAINT pk_student PRIMARY KEY (studentid),
    CONSTRAINT uq_student_user UNIQUE (userid),
    CONSTRAINT uq_student_email UNIQUE (email),
    CONSTRAINT fk_student_users FOREIGN KEY (userid) REFERENCES users(userid),
    CONSTRAINT fk_student_dept  FOREIGN KEY (deptid) REFERENCES department(deptid),
    CONSTRAINT chk_student_cgpa CHECK (cgpa BETWEEN 0 AND 4),
    CONSTRAINT chk_student_semester CHECK (semester BETWEEN 1 AND 12),
    CONSTRAINT chk_student_gender CHECK (gender IS NULL OR gender IN ('Male','Female','Other'))
) ENGINE=InnoDB;

CREATE TABLE scholarship (
    scholarshipid VARCHAR(6)    NOT NULL DEFAULT '',
    createdby     VARCHAR(6)    NOT NULL,
    totalslots    INT           NOT NULL,
    deadline      DATE          NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    type          VARCHAR(50),
    title         VARCHAR(100)  NOT NULL,
    applicationfee DECIMAL(8,2) NOT NULL DEFAULT 0,
    description   VARCHAR(500)  NULL,
    CONSTRAINT pk_scholarship PRIMARY KEY (scholarshipid),
    CONSTRAINT fk_scholarship_admin FOREIGN KEY (createdby) REFERENCES admin(adminid),
    CONSTRAINT chk_scholarship_slots CHECK (totalslots >= 0),
    CONSTRAINT chk_scholarship_amount CHECK (amount >= 0),
    CONSTRAINT chk_scholarship_fee CHECK (applicationfee >= 0)
) ENGINE=InnoDB;

CREATE TABLE application (
    appid         VARCHAR(7)  NOT NULL DEFAULT '',
    studentid     VARCHAR(7)  NOT NULL,
    scholarshipid VARCHAR(6)  NOT NULL,
    approvedby    VARCHAR(6)  NULL,
    applydate     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status        VARCHAR(20) NOT NULL DEFAULT 'Pending',
    CONSTRAINT pk_application PRIMARY KEY (appid),
    CONSTRAINT uq_application_once UNIQUE (studentid, scholarshipid),
    CONSTRAINT fk_app_student     FOREIGN KEY (studentid)     REFERENCES student(studentid),
    CONSTRAINT fk_app_scholarship FOREIGN KEY (scholarshipid) REFERENCES scholarship(scholarshipid),
    CONSTRAINT fk_app_admin       FOREIGN KEY (approvedby)    REFERENCES admin(adminid),
    CONSTRAINT chk_app_status CHECK (status IN ('Pending','Approved','Rejected'))
) ENGINE=InnoDB;

-- "Remember me" login cookies. The cookie holds  selector:validator ;
-- only a SHA-256 hash of the validator is stored, so a leaked table
-- cannot be used to log in.
-- Application-fee payments. A row is created when checkout starts
-- (status Initiated); the application itself is only created after the
-- payment is Completed, and the invoice number is set by a trigger.
CREATE TABLE payment (
    paymentid     VARCHAR(9)    NOT NULL DEFAULT '',
    invoiceno     VARCHAR(16)   NULL,
    studentid     VARCHAR(7)    NOT NULL,
    scholarshipid VARCHAR(6)    NOT NULL,
    appid         VARCHAR(7)    NULL,
    amount        DECIMAL(8,2)  NOT NULL,
    method        VARCHAR(10)   NULL,
    account       VARCHAR(30)   NULL,
    trxid         VARCHAR(20)   NULL,
    status        VARCHAR(12)   NOT NULL DEFAULT 'Initiated',
    failreason    VARCHAR(150)  NULL,
    createdat     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paidat        DATETIME      NULL,
    CONSTRAINT pk_payment PRIMARY KEY (paymentid),
    CONSTRAINT uq_payment_invoice UNIQUE (invoiceno),
    CONSTRAINT uq_payment_trx UNIQUE (trxid),
    CONSTRAINT uq_payment_app UNIQUE (appid),
    CONSTRAINT fk_pay_student     FOREIGN KEY (studentid)     REFERENCES student(studentid),
    CONSTRAINT fk_pay_scholarship FOREIGN KEY (scholarshipid) REFERENCES scholarship(scholarshipid),
    CONSTRAINT fk_pay_app         FOREIGN KEY (appid)         REFERENCES application(appid),
    CONSTRAINT chk_pay_amount CHECK (amount >= 0),
    CONSTRAINT chk_pay_method CHECK (method IS NULL OR method IN ('bKash','Nagad','Rocket','Card')),
    CONSTRAINT chk_pay_status CHECK (status IN ('Initiated','Completed','Failed','Cancelled','Expired'))
) ENGINE=InnoDB;

CREATE TABLE remember_token (
    selector      CHAR(24)    NOT NULL,
    userid        VARCHAR(7)  NOT NULL,
    validatorhash CHAR(64)    NOT NULL,
    expires       DATETIME    NOT NULL,
    CONSTRAINT pk_remember_token PRIMARY KEY (selector),
    CONSTRAINT fk_token_users FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- TRIGGERS  (ID generation + business rules from the Oracle version)
-- =====================================================================

DELIMITER //

CREATE TRIGGER trg_users_id BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.userid IS NULL OR NEW.userid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(userid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM users;
        SET NEW.userid = CONCAT('USR', LPAD(next_num, 4, '0'));
    END IF;
END //

CREATE TRIGGER trg_department_id BEFORE INSERT ON department
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.deptid IS NULL OR NEW.deptid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(deptid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM department;
        SET NEW.deptid = CONCAT('DEP', LPAD(next_num, 2, '0'));
    END IF;
END //

CREATE TRIGGER trg_admin_id BEFORE INSERT ON admin
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.adminid IS NULL OR NEW.adminid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(adminid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM admin;
        SET NEW.adminid = CONCAT('ADM', LPAD(next_num, 3, '0'));
    END IF;
END //

CREATE TRIGGER trg_student_id BEFORE INSERT ON student
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.studentid IS NULL OR NEW.studentid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(studentid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM student;
        SET NEW.studentid = CONCAT('STD', LPAD(next_num, 4, '0'));
    END IF;
END //

-- ID generation + trg_check_slots (TotalSlots cannot be negative)
CREATE TRIGGER trg_scholarship_id BEFORE INSERT ON scholarship
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.totalslots < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TotalSlots cannot be negative';
    END IF;
    IF NEW.scholarshipid IS NULL OR NEW.scholarshipid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(scholarshipid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM scholarship;
        SET NEW.scholarshipid = CONCAT('SCH', LPAD(next_num, 3, '0'));
    END IF;
END //

CREATE TRIGGER trg_check_slots BEFORE UPDATE ON scholarship
FOR EACH ROW
BEGIN
    IF NEW.totalslots < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TotalSlots cannot be negative';
    END IF;
END //

-- ID generation + trg_default_applydate (ApplyDate = now if left NULL)
CREATE TRIGGER trg_application_id BEFORE INSERT ON application
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.applydate IS NULL THEN
        SET NEW.applydate = NOW();
    END IF;
    IF NEW.appid IS NULL OR NEW.appid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(appid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM application;
        SET NEW.appid = CONCAT('APP', LPAD(next_num, 4, '0'));
    END IF;
END //

-- Payment IDs: PAY000001
CREATE TRIGGER trg_payment_id BEFORE INSERT ON payment
FOR EACH ROW
BEGIN
    DECLARE next_num INT;
    IF NEW.paymentid IS NULL OR NEW.paymentid = '' THEN
        SELECT IFNULL(MAX(CAST(SUBSTRING(paymentid, 4) AS UNSIGNED)), 0) + 1 INTO next_num FROM payment;
        SET NEW.paymentid = CONCAT('PAY', LPAD(next_num, 6, '0'));
    END IF;
    IF NEW.status = 'Completed' AND NEW.invoiceno IS NULL THEN
        SET NEW.invoiceno = CONCAT('INV-', YEAR(IFNULL(NEW.paidat, NOW())), '-', SUBSTRING(NEW.paymentid, 5));
    END IF;
END //

-- When a payment becomes Completed: stamp the time and give it an invoice number.
-- A completed payment can never be changed back.
CREATE TRIGGER trg_payment_complete BEFORE UPDATE ON payment
FOR EACH ROW
BEGIN
    IF OLD.status = 'Completed' AND NEW.status <> 'Completed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A completed payment cannot be changed';
    END IF;
    IF NEW.status = 'Completed' AND OLD.status <> 'Completed' THEN
        IF NEW.paidat IS NULL THEN
            SET NEW.paidat = NOW();
        END IF;
        SET NEW.invoiceno = CONCAT('INV-', YEAR(NEW.paidat), '-', SUBSTRING(NEW.paymentid, 5));
    END IF;
END //

-- =====================================================================
-- STORED FUNCTIONS / PROCEDURES  (MySQL versions of the PL/SQL ones)
-- =====================================================================

CREATE FUNCTION fn_get_cgpa(p_studentid VARCHAR(7))
RETURNS DECIMAL(3,2)
READS SQL DATA
BEGIN
    DECLARE v_cgpa DECIMAL(3,2) DEFAULT NULL;
    SELECT cgpa INTO v_cgpa FROM student WHERE studentid = p_studentid;
    RETURN v_cgpa;
END //

CREATE FUNCTION fn_app_count(p_scholarshipid VARCHAR(6))
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;
    SELECT COUNT(*) INTO v_count FROM application WHERE scholarshipid = p_scholarshipid;
    RETURN v_count;
END //

-- Total application fees collected for a scholarship (NULL = all scholarships)
CREATE FUNCTION fn_fee_collected(p_scholarshipid VARCHAR(6))
RETURNS DECIMAL(12,2)
READS SQL DATA
BEGIN
    DECLARE v_total DECIMAL(12,2) DEFAULT 0;
    SELECT IFNULL(SUM(amount), 0) INTO v_total FROM payment
     WHERE status = 'Completed' AND (p_scholarshipid IS NULL OR scholarshipid = p_scholarshipid);
    RETURN v_total;
END //

-- Reviews an application. Refuses to approve once all slots are filled.
CREATE PROCEDURE proc_update_status(
    IN p_appid   VARCHAR(7),
    IN p_status  VARCHAR(20),
    IN p_adminid VARCHAR(6)
)
MODIFIES SQL DATA
BEGIN
    DECLARE v_sch      VARCHAR(6) DEFAULT NULL;
    DECLARE v_current  VARCHAR(20) DEFAULT NULL;
    DECLARE v_slots    INT DEFAULT 0;
    DECLARE v_approved INT DEFAULT 0;

    IF p_status NOT IN ('Approved', 'Rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid status';
    END IF;

    SELECT scholarshipid, status INTO v_sch, v_current FROM application WHERE appid = p_appid;
    IF v_sch IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No application found with this AppID';
    END IF;
    IF v_current <> 'Pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This application has already been reviewed';
    END IF;

    IF p_status = 'Approved' THEN
        SELECT totalslots INTO v_slots FROM scholarship WHERE scholarshipid = v_sch;
        SELECT COUNT(*) INTO v_approved FROM application
            WHERE scholarshipid = v_sch AND status = 'Approved';
        IF v_approved >= v_slots THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'All slots for this scholarship are already filled';
        END IF;
    END IF;

    UPDATE application SET status = p_status, approvedby = p_adminid WHERE appid = p_appid;
END //

CREATE PROCEDURE proc_get_admin_name(IN p_adminid VARCHAR(6), OUT p_name VARCHAR(100))
READS SQL DATA
BEGIN
    SET p_name = NULL;
    SELECT name INTO p_name FROM admin WHERE adminid = p_adminid;
    IF p_name IS NULL THEN
        SET p_name = 'Admin Not Found';
    END IF;
END //

DELIMITER ;

-- =====================================================================
-- SEED DATA  (demo records)
-- =====================================================================

INSERT INTO department (deptname, facultyname) VALUES
    ('CSE', 'Faculty of Science & Technology'),
    ('EEE', 'Faculty of Science & Technology'),
    ('BBA', 'Faculty of Business Administration'),
    ('Civil Engineering', 'Faculty of Engineering'),
    ('Economics', 'Faculty of Arts and Social Science');

-- Passwords are stored as bcrypt hashes (plain text: adminpass1-5, stdpass1-5).
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('Admin1', '$2y$12$xuTfbB7o2HHUdmmUJXPTNOKcGT06I8VpDKpbbNkqJhwPrr0xyBsBK', 'Admin', '2026-06-01 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('Admin2', '$2y$12$4EcD20vND346U7ugQAURA.HysNvQmbuDczsS.vnCfz8CzmS79UkA2', 'Admin', '2026-06-01 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('Admin3', '$2y$12$Kh5EdrW0F3QvuS0fXkSNduhJW4nroFgRTc473PeySUXQ7egiXhIbO', 'Admin', '2026-06-01 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('Admin4', '$2y$12$rnkKbIZ3lmsfarVhWet4IukF.TmEJ64wis5yC4AwS8tv.OqFE3aim', 'Admin', '2026-06-01 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('Admin5', '$2y$12$2rD//l3W02dL9RHEZ29Qz.a9Uf2TsAxnBE4WoSJy24yxjLVf3VjT6', 'Admin', '2026-06-01 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('std1', '$2y$12$SzopomXyORh4QL.v.DranerTFVedloAjTYyMocZvN5zR4DZTrqGNS', 'Student', '2026-06-15 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('std2', '$2y$12$zrL5IV7xq7LODtxn9WtMv.Kw2ktcZZ5zXSdCbwSMTAvGiqhRMO3.2', 'Student', '2026-06-15 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('std3', '$2y$12$EvfDhfoT1SHrv94QBOsxX.hqybLr4b/t3HOTonCEOV8JR8wWxk7Ai', 'Student', '2026-06-15 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('std4', '$2y$12$9vF7lpuAdsjaXk939diyCuZjIBNKDi.BlYqEVfgfVOktIwtDKRK4q', 'Student', '2026-06-15 09:00:00');
INSERT INTO users (username, passwordhash, role, createdat) VALUES ('std5', '$2y$12$WdnLx3LtvpJP5yJ6.OxyrurdxzyT.aYVQ9W4J3DOZQ.KwSbRdCdoO', 'Student', '2026-06-15 09:00:00');

INSERT INTO admin (userid, name, email, phone, designation) VALUES ('USR0001', 'John', 'john@scholarhub.example', '01711000001', 'Scholarship Officer');
INSERT INTO admin (userid, name, email, phone, designation) VALUES ('USR0002', 'Mony', 'mony@scholarhub.example', '01711000002', 'Accounts Officer');
INSERT INTO admin (userid, name, email, phone, designation) VALUES ('USR0003', 'Sony', 'sony@scholarhub.example', NULL, 'Review Committee');
INSERT INTO admin (userid, name, email, phone, designation) VALUES ('USR0004', 'Raju', 'raju@scholarhub.example', NULL, 'Sports Coordinator');
INSERT INTO admin (userid, name, email, phone, designation) VALUES ('USR0005', 'Liza', 'liza@scholarhub.example', NULL, 'Research Coordinator');

INSERT INTO student (userid, deptid, name, email, cgpa, semester, phone, dob, gender, address, guardianname, guardianphone)
    VALUES ('USR0006', 'DEP01', 'Karim', 'karim@example.com', 3.75, 6, '01812345601', '2003-04-12', 'Male', 'House 12, Road 5, Dhanmondi, Dhaka', 'Abdul Karim', '01712345601');
INSERT INTO student (userid, deptid, name, email, cgpa, semester, phone, dob, gender, address, guardianname, guardianphone)
    VALUES ('USR0007', 'DEP02', 'Rita', 'rita@example.com', 3.50, 4, '01912345602', '2004-01-23', 'Female', 'Uttara Sector 7, Dhaka', NULL, NULL);
INSERT INTO student (userid, deptid, name, email, cgpa, semester, phone, gender)
    VALUES ('USR0008', 'DEP03', 'Tuhin', 'tuhin@example.com', 3.20, 8, '01612345603', 'Male');
INSERT INTO student (userid, deptid, name, email, cgpa, semester)
    VALUES ('USR0009', 'DEP04', 'Mitu', 'mitu@example.com', 3.90, 3);
INSERT INTO student (userid, deptid, name, email, cgpa, semester)
    VALUES ('USR0010', 'DEP05', 'Sagor', 'sagor@example.com', 2.95, 5);

INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES ('ADM001', 20, '2026-08-30', 15000.00, 'Merit',      'Vice Chancellor Merit Scholarship', 200.00, 'For students with outstanding academic results in the previous two semesters.');
INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES ('ADM002', 15, '2026-09-15', 10000.00, 'Need-based', 'Financial Assistance Scholarship', 100.00, 'Support for students who need financial help to continue their studies.');
INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES ('ADM003', 10, '2026-10-01', 20000.00, 'Merit',      'Dean''s List Scholarship', 300.00, 'Awarded to students on the Dean''s List with a CGPA of 3.50 or above.');
INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES ('ADM004', 25, '2026-09-20', 12000.00, 'Sports',     'Sports Excellence Scholarship', 150.00, 'For students who represent the institution in national or regional sports.');
INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES ('ADM005',  5, '2026-11-10', 25000.00, 'Research',   'Undergraduate Research Grant', 500.00, 'Funding for undergraduate research projects supervised by a faculty member.');

INSERT INTO application (studentid, scholarshipid, approvedby, applydate, status) VALUES ('STD0001', 'SCH001', 'ADM001', '2026-07-01 10:00:00', 'Approved');
INSERT INTO application (studentid, scholarshipid, approvedby, applydate, status) VALUES ('STD0002', 'SCH002', 'ADM002', '2026-07-03 10:00:00', 'Approved');
INSERT INTO application (studentid, scholarshipid, approvedby, applydate, status) VALUES ('STD0003', 'SCH003', NULL,     '2026-07-05 10:00:00', 'Pending');
INSERT INTO application (studentid, scholarshipid, approvedby, applydate, status) VALUES ('STD0004', 'SCH004', 'ADM004', '2026-07-07 10:00:00', 'Rejected');
INSERT INTO application (studentid, scholarshipid, approvedby, applydate, status) VALUES ('STD0005', 'SCH005', NULL,     '2026-07-10 10:00:00', 'Pending');

-- Application fees paid for the applications above (test-mode transactions)
INSERT INTO payment (studentid, scholarshipid, appid, amount, method, account, trxid, status, createdat, paidat) VALUES ('STD0001', 'SCH001', 'APP0001', 200.00, 'bKash',  '018******01', 'BGT4K7Q2MX', 'Completed', '2026-07-01 09:58:10', '2026-07-01 09:59:42');
INSERT INTO payment (studentid, scholarshipid, appid, amount, method, account, trxid, status, createdat, paidat) VALUES ('STD0002', 'SCH002', 'APP0002', 100.00, 'Nagad',  '019******02', 'NG72HX19QB', 'Completed', '2026-07-03 09:57:03', '2026-07-03 09:58:30');
INSERT INTO payment (studentid, scholarshipid, appid, amount, method, account, trxid, status, createdat, paidat) VALUES ('STD0003', 'SCH003', 'APP0003', 300.00, 'Card',   'VISA **** 4242', 'CRD8Q3ZP5W', 'Completed', '2026-07-05 09:55:47', '2026-07-05 09:57:21');
INSERT INTO payment (studentid, scholarshipid, appid, amount, method, account, trxid, status, createdat, paidat) VALUES ('STD0004', 'SCH004', 'APP0004', 150.00, 'Rocket', '017******04', 'RKT5M8N2VC', 'Completed', '2026-07-07 09:56:12', '2026-07-07 09:57:40');
INSERT INTO payment (studentid, scholarshipid, appid, amount, method, account, trxid, status, createdat, paidat) VALUES ('STD0005', 'SCH005', 'APP0005', 500.00, 'bKash',  '015******05', 'BJX2P6R9TD', 'Completed', '2026-07-10 09:57:55', '2026-07-10 09:59:12');
