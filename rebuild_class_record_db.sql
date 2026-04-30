-- EduPulse database rebuild script
-- Compatible with MySQL/MariaDB in XAMPP
-- Mode: safe create only (no DROP statements)

CREATE DATABASE IF NOT EXISTS class_record_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE class_record_db;

-- 1) Users (login/auth table)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Audit logs (login/register/reset activity)
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_email VARCHAR(191) NOT NULL,
  action VARCHAR(255) NOT NULL,
  log_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user_email (user_email),
  KEY idx_audit_log_time (log_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Sections/classes owned by a faculty account
CREATE TABLE IF NOT EXISTS sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_name VARCHAR(191) NOT NULL,
  owner_email VARCHAR(191) NOT NULL,
  notes TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_sections_owner_email (owner_email),
  UNIQUE KEY uq_sections_owner_name (owner_email, section_name),
  CONSTRAINT fk_sections_owner_email
    FOREIGN KEY (owner_email)
    REFERENCES users (email)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Students enrolled in a section
CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  name VARCHAR(191) NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_section_studentid (section_id, student_id),
  KEY idx_students_section (section_id),
  CONSTRAINT fk_students_section
    FOREIGN KEY (section_id)
    REFERENCES sections (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Grade categories/buckets per term (Midterm/Finals)
CREATE TABLE IF NOT EXISTS grading_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id INT UNSIGNED NOT NULL,
  term VARCHAR(20) NOT NULL,
  name VARCHAR(191) NOT NULL,
  weight DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_categories_section (section_id),
  KEY idx_categories_term (term),
  CONSTRAINT fk_categories_section
    FOREIGN KEY (section_id)
    REFERENCES sections (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT chk_categories_term
    CHECK (term IN ('Midterm', 'Finals')),
  CONSTRAINT chk_categories_weight
    CHECK (weight >= 0 AND weight <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Assignments inside a grading category
CREATE TABLE IF NOT EXISTS assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  max_score DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_assignments_category (category_id),
  CONSTRAINT fk_assignments_category
    FOREIGN KEY (category_id)
    REFERENCES grading_categories (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT chk_assignments_max_score
    CHECK (max_score > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Scores per (student internal id, assignment)
-- IMPORTANT: student_id here references students.id (INT), not students.student_id (string)
CREATE TABLE IF NOT EXISTS student_scores (
  student_id INT UNSIGNED NOT NULL,
  assignment_id INT UNSIGNED NOT NULL,
  score DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (student_id, assignment_id),
  KEY idx_scores_assignment (assignment_id),
  CONSTRAINT fk_scores_student
    FOREIGN KEY (student_id)
    REFERENCES students (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_scores_assignment
    FOREIGN KEY (assignment_id)
    REFERENCES assignments (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT chk_scores_nonnegative
    CHECK (score >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role migration for existing databases:
-- 1) Convert legacy adminoffice users into Faculty
-- 2) Convert legacy Teacher users into Faculty
UPDATE users SET role = 'Faculty' WHERE role IN ('adminoffice', 'Teacher');

-- Starter data (safe inserts)
-- Plain-text password is kept to match your current PHP login logic.
INSERT INTO users (email, password, role)
SELECT 'sysadmin@edupulse.local', 'admin123', 'System Admin'
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE email = 'sysadmin@edupulse.local'
);

INSERT INTO users (email, password, role)
SELECT 'faculty@edupulse.local', 'faculty123', 'Faculty'
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE email = 'faculty@edupulse.local'
);

-- Optional sample section for the faculty account
INSERT INTO sections (section_name, owner_email, notes)
SELECT 'BSIT-1A', 'faculty@edupulse.local', 'Sample restored section'
WHERE NOT EXISTS (
  SELECT 1 FROM sections WHERE section_name = 'BSIT-1A' AND owner_email = 'faculty@edupulse.local'
);

-- Sample BSIT-1A grading data for analytics testing
SET @sample_owner := 'faculty@edupulse.local';
SET @sample_section_id := NULL;
SET @midterm_quizzes_cat_id := NULL;
SET @midterm_exams_cat_id := NULL;
SET @finals_projects_cat_id := NULL;
SET @finals_exams_cat_id := NULL;
SET @midterm_quiz_ass_id := NULL;
SET @midterm_exam_ass_id := NULL;
SET @final_project_ass_id := NULL;
SET @final_exam_ass_id := NULL;

SELECT id INTO @sample_section_id
FROM sections
WHERE section_name = 'BSIT-1A' AND owner_email = @sample_owner
LIMIT 1;

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @sample_section_id, 'Midterm', 'Quizzes', 40
WHERE @sample_section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @sample_section_id AND term = 'Midterm' AND name = 'Quizzes'
  );

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @sample_section_id, 'Midterm', 'Exams', 60
WHERE @sample_section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @sample_section_id AND term = 'Midterm' AND name = 'Exams'
  );

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @sample_section_id, 'Finals', 'Projects', 50
WHERE @sample_section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @sample_section_id AND term = 'Finals' AND name = 'Projects'
  );

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @sample_section_id, 'Finals', 'Exams', 50
WHERE @sample_section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @sample_section_id AND term = 'Finals' AND name = 'Exams'
  );

SELECT id INTO @midterm_quizzes_cat_id
FROM grading_categories
WHERE section_id = @sample_section_id AND term = 'Midterm' AND name = 'Quizzes'
LIMIT 1;

SELECT id INTO @midterm_exams_cat_id
FROM grading_categories
WHERE section_id = @sample_section_id AND term = 'Midterm' AND name = 'Exams'
LIMIT 1;

SELECT id INTO @finals_projects_cat_id
FROM grading_categories
WHERE section_id = @sample_section_id AND term = 'Finals' AND name = 'Projects'
LIMIT 1;

SELECT id INTO @finals_exams_cat_id
FROM grading_categories
WHERE section_id = @sample_section_id AND term = 'Finals' AND name = 'Exams'
LIMIT 1;

INSERT INTO assignments (category_id, name, max_score)
SELECT @midterm_quizzes_cat_id, 'Quiz 1', 100
WHERE @midterm_quizzes_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 1'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @midterm_exams_cat_id, 'Midterm Exam', 100
WHERE @midterm_exams_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @midterm_exams_cat_id AND name = 'Midterm Exam'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @finals_projects_cat_id, 'Final Project', 100
WHERE @finals_projects_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @finals_projects_cat_id AND name = 'Final Project'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @finals_exams_cat_id, 'Final Exam', 100
WHERE @finals_exams_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @finals_exams_cat_id AND name = 'Final Exam'
  );

SELECT id INTO @midterm_quiz_ass_id
FROM assignments
WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 1'
LIMIT 1;

SELECT id INTO @midterm_exam_ass_id
FROM assignments
WHERE category_id = @midterm_exams_cat_id AND name = 'Midterm Exam'
LIMIT 1;

SELECT id INTO @final_project_ass_id
FROM assignments
WHERE category_id = @finals_projects_cat_id AND name = 'Final Project'
LIMIT 1;

SELECT id INTO @final_exam_ass_id
FROM assignments
WHERE category_id = @finals_exams_cat_id AND name = 'Final Exam'
LIMIT 1;

INSERT INTO students (student_id, name, section_id)
SELECT v.student_id, v.name, @sample_section_id
FROM (
  SELECT 'BSIT1A-001' AS student_id, 'Alyssa Cruz' AS name
  UNION ALL SELECT 'BSIT1A-002', 'Ben Dela Cruz'
  UNION ALL SELECT 'BSIT1A-003', 'Carla Santos'
  UNION ALL SELECT 'BSIT1A-004', 'Diego Ramos'
  UNION ALL SELECT 'BSIT1A-005', 'Erika Lim'
  UNION ALL SELECT 'BSIT1A-006', 'Felix Garcia'
  UNION ALL SELECT 'BSIT1A-007', 'Grace Molina'
  UNION ALL SELECT 'BSIT1A-008', 'Hannah Reyes'
  UNION ALL SELECT 'BSIT1A-009', 'Ian Torres'
  UNION ALL SELECT 'BSIT1A-010', 'Jamila Flores'
  UNION ALL SELECT 'BSIT1A-011', 'Kevin Navarro'
  UNION ALL SELECT 'BSIT1A-012', 'Lara Ortiz'
  UNION ALL SELECT 'BSIT1A-013', 'Marco Diaz'
  UNION ALL SELECT 'BSIT1A-014', 'Nina Perez'
  UNION ALL SELECT 'BSIT1A-015', 'Oliver Yu'
  UNION ALL SELECT 'BSIT1A-016', 'Paula Tan'
  UNION ALL SELECT 'BSIT1A-017', 'Quincy Ho'
  UNION ALL SELECT 'BSIT1A-018', 'Rhea Bautista'
  UNION ALL SELECT 'BSIT1A-019', 'Sam Lee'
  UNION ALL SELECT 'BSIT1A-020', 'Tina Cruz'
  UNION ALL SELECT 'BSIT1A-021', 'Uriel Gomez'
  UNION ALL SELECT 'BSIT1A-022', 'Vince Dizon'
  UNION ALL SELECT 'BSIT1A-023', 'Wendy Ong'
  UNION ALL SELECT 'BSIT1A-024', 'Xander Cruz'
  UNION ALL SELECT 'BSIT1A-025', 'Yna Manalo'
  UNION ALL SELECT 'BSIT1A-026', 'Zacharias Lim'
  UNION ALL SELECT 'BSIT1A-027', 'Bea Flores'
  UNION ALL SELECT 'BSIT1A-028', 'Clyde Santos'
  UNION ALL SELECT 'BSIT1A-029', 'Dana Villanueva'
  UNION ALL SELECT 'BSIT1A-030', 'Ethan Bautista'
) AS v
WHERE @sample_section_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  section_id = VALUES(section_id);

INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @midterm_quiz_ass_id,
  CASE s.student_id
    WHEN 'BSIT1A-001' THEN 96
    WHEN 'BSIT1A-002' THEN 90
    WHEN 'BSIT1A-003' THEN 85
    WHEN 'BSIT1A-004' THEN 78
    WHEN 'BSIT1A-005' THEN 94
    WHEN 'BSIT1A-006' THEN 88
    WHEN 'BSIT1A-007' THEN 76
    WHEN 'BSIT1A-008' THEN 82
    WHEN 'BSIT1A-009' THEN 70
    WHEN 'BSIT1A-010' THEN 73
    WHEN 'BSIT1A-011' THEN 67
    WHEN 'BSIT1A-012' THEN 88
    WHEN 'BSIT1A-013' THEN 60
    WHEN 'BSIT1A-014' THEN 50
    WHEN 'BSIT1A-015' THEN 40
    WHEN 'BSIT1A-016' THEN 55
    WHEN 'BSIT1A-017' THEN 61
    WHEN 'BSIT1A-018' THEN 59
    WHEN 'BSIT1A-019' THEN 50
    WHEN 'BSIT1A-020' THEN 45
    WHEN 'BSIT1A-021' THEN 65
    WHEN 'BSIT1A-022' THEN 68
    WHEN 'BSIT1A-023' THEN 72
    WHEN 'BSIT1A-024' THEN 62
    WHEN 'BSIT1A-025' THEN 90
    WHEN 'BSIT1A-026' THEN 84
    WHEN 'BSIT1A-027' THEN 95
    WHEN 'BSIT1A-028' THEN 65
    WHEN 'BSIT1A-029' THEN 55
    WHEN 'BSIT1A-030' THEN 100
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @sample_section_id
  AND s.student_id IN (
    'BSIT1A-001','BSIT1A-002','BSIT1A-003','BSIT1A-004','BSIT1A-005','BSIT1A-006','BSIT1A-007','BSIT1A-008',
    'BSIT1A-009','BSIT1A-010','BSIT1A-011','BSIT1A-012','BSIT1A-013','BSIT1A-014','BSIT1A-015','BSIT1A-016',
    'BSIT1A-017','BSIT1A-018','BSIT1A-019','BSIT1A-020','BSIT1A-021','BSIT1A-022','BSIT1A-023','BSIT1A-024',
    'BSIT1A-025','BSIT1A-026','BSIT1A-027','BSIT1A-028','BSIT1A-029','BSIT1A-030'
  )
  AND @midterm_quiz_ass_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @midterm_exam_ass_id,
  CASE s.student_id
    WHEN 'BSIT1A-001' THEN 92
    WHEN 'BSIT1A-002' THEN 88
    WHEN 'BSIT1A-003' THEN 87
    WHEN 'BSIT1A-004' THEN 82
    WHEN 'BSIT1A-005' THEN 90
    WHEN 'BSIT1A-006' THEN 81
    WHEN 'BSIT1A-007' THEN 74
    WHEN 'BSIT1A-008' THEN 85
    WHEN 'BSIT1A-009' THEN 68
    WHEN 'BSIT1A-010' THEN 75
    WHEN 'BSIT1A-011' THEN 72
    WHEN 'BSIT1A-012' THEN 91
    WHEN 'BSIT1A-013' THEN 55
    WHEN 'BSIT1A-014' THEN 60
    WHEN 'BSIT1A-015' THEN 48
    WHEN 'BSIT1A-016' THEN 52
    WHEN 'BSIT1A-017' THEN 58
    WHEN 'BSIT1A-018' THEN 59
    WHEN 'BSIT1A-019' THEN 52
    WHEN 'BSIT1A-020' THEN 47
    WHEN 'BSIT1A-021' THEN 67
    WHEN 'BSIT1A-022' THEN 70
    WHEN 'BSIT1A-023' THEN 74
    WHEN 'BSIT1A-024' THEN 64
    WHEN 'BSIT1A-025' THEN 88
    WHEN 'BSIT1A-026' THEN 86
    WHEN 'BSIT1A-027' THEN 91
    WHEN 'BSIT1A-028' THEN 66
    WHEN 'BSIT1A-029' THEN 57
    WHEN 'BSIT1A-030' THEN 98
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @sample_section_id
  AND s.student_id IN (
    'BSIT1A-001','BSIT1A-002','BSIT1A-003','BSIT1A-004','BSIT1A-005','BSIT1A-006','BSIT1A-007','BSIT1A-008',
    'BSIT1A-009','BSIT1A-010','BSIT1A-011','BSIT1A-012','BSIT1A-013','BSIT1A-014','BSIT1A-015','BSIT1A-016',
    'BSIT1A-017','BSIT1A-018','BSIT1A-019','BSIT1A-020','BSIT1A-021','BSIT1A-022','BSIT1A-023','BSIT1A-024',
    'BSIT1A-025','BSIT1A-026','BSIT1A-027','BSIT1A-028','BSIT1A-029','BSIT1A-030'
  )
  AND @midterm_exam_ass_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @final_project_ass_id,
  CASE s.student_id
    WHEN 'BSIT1A-001' THEN 95
    WHEN 'BSIT1A-002' THEN 86
    WHEN 'BSIT1A-003' THEN 89
    WHEN 'BSIT1A-004' THEN 80
    WHEN 'BSIT1A-005' THEN 91
    WHEN 'BSIT1A-006' THEN 84
    WHEN 'BSIT1A-007' THEN 78
    WHEN 'BSIT1A-008' THEN 88
    WHEN 'BSIT1A-019' THEN 48
    WHEN 'BSIT1A-020' THEN 44
    WHEN 'BSIT1A-021' THEN 40
    WHEN 'BSIT1A-022' THEN 45
    WHEN 'BSIT1A-023' THEN 56
    WHEN 'BSIT1A-024' THEN 54
    WHEN 'BSIT1A-025' THEN 70
    WHEN 'BSIT1A-026' THEN 60
    WHEN 'BSIT1A-027' THEN 89
    WHEN 'BSIT1A-028' THEN 65
    WHEN 'BSIT1A-029' THEN 55
    WHEN 'BSIT1A-030' THEN 96
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @sample_section_id
  AND s.student_id IN (
    'BSIT1A-001','BSIT1A-002','BSIT1A-003','BSIT1A-004','BSIT1A-005','BSIT1A-006','BSIT1A-007','BSIT1A-008',
    'BSIT1A-019','BSIT1A-020','BSIT1A-021','BSIT1A-022','BSIT1A-023','BSIT1A-024','BSIT1A-025','BSIT1A-026',
    'BSIT1A-027','BSIT1A-028','BSIT1A-029','BSIT1A-030'
  )
  AND @final_project_ass_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @final_exam_ass_id,
  CASE s.student_id
    WHEN 'BSIT1A-001' THEN 94
    WHEN 'BSIT1A-002' THEN 90
    WHEN 'BSIT1A-003' THEN 84
    WHEN 'BSIT1A-004' THEN 79
    WHEN 'BSIT1A-005' THEN 93
    WHEN 'BSIT1A-006' THEN 86
    WHEN 'BSIT1A-007' THEN 80
    WHEN 'BSIT1A-008' THEN 84
    WHEN 'BSIT1A-019' THEN 50
    WHEN 'BSIT1A-020' THEN 46
    WHEN 'BSIT1A-021' THEN 45
    WHEN 'BSIT1A-022' THEN 46
    WHEN 'BSIT1A-023' THEN 58
    WHEN 'BSIT1A-024' THEN 52
    WHEN 'BSIT1A-025' THEN 72
    WHEN 'BSIT1A-026' THEN 62
    WHEN 'BSIT1A-027' THEN 87
    WHEN 'BSIT1A-028' THEN 64
    WHEN 'BSIT1A-029' THEN 57
    WHEN 'BSIT1A-030' THEN 97
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @sample_section_id
  AND s.student_id IN (
    'BSIT1A-001','BSIT1A-002','BSIT1A-003','BSIT1A-004','BSIT1A-005','BSIT1A-006','BSIT1A-007','BSIT1A-008',
    'BSIT1A-019','BSIT1A-020','BSIT1A-021','BSIT1A-022','BSIT1A-023','BSIT1A-024','BSIT1A-025','BSIT1A-026',
    'BSIT1A-027','BSIT1A-028','BSIT1A-029','BSIT1A-030'
  )
  AND @final_exam_ass_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);
