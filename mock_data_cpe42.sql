USE class_record_db;

-- Ensure the faculty owner exists for the section foreign key.
INSERT INTO users (email, password, role)
SELECT 'faculty@edupulse.local', 'faculty123', 'Faculty'
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE email = 'faculty@edupulse.local'
);

-- Section: CPE42
INSERT INTO sections (section_name, owner_email, notes)
SELECT 'CPE42', 'faculty@edupulse.local', 'Mock dataset for 32 students'
WHERE NOT EXISTS (
  SELECT 1 FROM sections WHERE section_name = 'CPE42' AND owner_email = 'faculty@edupulse.local'
);

SELECT id INTO @section_id
FROM sections
WHERE section_name = 'CPE42' AND owner_email = 'faculty@edupulse.local'
LIMIT 1;

-- Midterm categories
INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @section_id, 'Midterm', 'Quizzes', 35
WHERE @section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @section_id AND term = 'Midterm' AND name = 'Quizzes'
  );

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @section_id, 'Midterm', 'Practical Exam', 65
WHERE @section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @section_id AND term = 'Midterm' AND name = 'Practical Exam'
  );

-- Finals categories
INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @section_id, 'Finals', 'Project', 40
WHERE @section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @section_id AND term = 'Finals' AND name = 'Project'
  );

INSERT INTO grading_categories (section_id, term, name, weight)
SELECT @section_id, 'Finals', 'Final Exam', 60
WHERE @section_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM grading_categories
    WHERE section_id = @section_id AND term = 'Finals' AND name = 'Final Exam'
  );

SELECT id INTO @midterm_quizzes_cat_id
FROM grading_categories
WHERE section_id = @section_id AND term = 'Midterm' AND name = 'Quizzes'
LIMIT 1;

SELECT id INTO @midterm_exam_cat_id
FROM grading_categories
WHERE section_id = @section_id AND term = 'Midterm' AND name = 'Practical Exam'
LIMIT 1;

SELECT id INTO @final_project_cat_id
FROM grading_categories
WHERE section_id = @section_id AND term = 'Finals' AND name = 'Project'
LIMIT 1;

SELECT id INTO @final_exam_cat_id
FROM grading_categories
WHERE section_id = @section_id AND term = 'Finals' AND name = 'Final Exam'
LIMIT 1;

-- Assignments
INSERT INTO assignments (category_id, name, max_score)
SELECT @midterm_quizzes_cat_id, 'Quiz 1', 100
WHERE @midterm_quizzes_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 1'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @midterm_quizzes_cat_id, 'Quiz 2', 100
WHERE @midterm_quizzes_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 2'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @midterm_exam_cat_id, 'Midterm Exam', 100
WHERE @midterm_exam_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @midterm_exam_cat_id AND name = 'Midterm Exam'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @final_project_cat_id, 'Final Project', 100
WHERE @final_project_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @final_project_cat_id AND name = 'Final Project'
  );

INSERT INTO assignments (category_id, name, max_score)
SELECT @final_exam_cat_id, 'Final Exam', 100
WHERE @final_exam_cat_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM assignments
    WHERE category_id = @final_exam_cat_id AND name = 'Final Exam'
  );

SELECT id INTO @quiz1_id
FROM assignments
WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 1'
LIMIT 1;

SELECT id INTO @quiz2_id
FROM assignments
WHERE category_id = @midterm_quizzes_cat_id AND name = 'Quiz 2'
LIMIT 1;

SELECT id INTO @midterm_exam_id
FROM assignments
WHERE category_id = @midterm_exam_cat_id AND name = 'Midterm Exam'
LIMIT 1;

SELECT id INTO @final_project_id
FROM assignments
WHERE category_id = @final_project_cat_id AND name = 'Final Project'
LIMIT 1;

SELECT id INTO @final_exam_id
FROM assignments
WHERE category_id = @final_exam_cat_id AND name = 'Final Exam'
LIMIT 1;

-- 32 mock students in Surname, First name Middle initial format
INSERT INTO students (student_id, name, section_id)
VALUES
  ('CPE42-001', 'Abad, Alyssa M.', @section_id),
  ('CPE42-002', 'Aguilar, Paolo J.', @section_id),
  ('CPE42-003', 'Bautista, Nicole A.', @section_id),
  ('CPE42-004', 'Cruz, Miguel T.', @section_id),
  ('CPE42-005', 'Dela Cruz, Andrea P.', @section_id),
  ('CPE42-006', 'Dizon, Kevin R.', @section_id),
  ('CPE42-007', 'Flores, Jasmine L.', @section_id),
  ('CPE42-008', 'Garcia, Mark C.', @section_id),
  ('CPE42-009', 'Gomez, Hannah S.', @section_id),
  ('CPE42-010', 'Gutierrez, John Paul D.', @section_id),
  ('CPE42-011', 'Hernandez, Bea M.', @section_id),
  ('CPE42-012', 'Lim, Carl V.', @section_id),
  ('CPE42-013', 'Lopez, Angelica R.', @section_id),
  ('CPE42-014', 'Manalo, Joshua E.', @section_id),
  ('CPE42-015', 'Mendoza, Patricia K.', @section_id),
  ('CPE42-016', 'Navarro, Renzo F.', @section_id),
  ('CPE42-017', 'Ong, Alyssa Q.', @section_id),
  ('CPE42-018', 'Ortiz, Daniel B.', @section_id),
  ('CPE42-019', 'Perez, Camille N.', @section_id),
  ('CPE42-020', 'Ramos, Ethan J.', @section_id),
  ('CPE42-021', 'Reyes, Sofia T.', @section_id),
  ('CPE42-022', 'Santos, Adrian L.', @section_id),
  ('CPE42-023', 'Tan, Bianca M.', @section_id),
  ('CPE42-024', 'Torres, Christian P.', @section_id),
  ('CPE42-025', 'Villanueva, Denise A.', @section_id),
  ('CPE42-026', 'Yu, Francis H.', @section_id),
  ('CPE42-027', 'Zamora, Grace C.', @section_id),
  ('CPE42-028', 'Alcantara, Ivan D.', @section_id),
  ('CPE42-029', 'Balagtas, Janine E.', @section_id),
  ('CPE42-030', 'Castaneda, Lester M.', @section_id),
  ('CPE42-031', 'De Leon, Mikaela P.', @section_id),
  ('CPE42-032', 'Fernandez, Carlo J.', @section_id)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  section_id = VALUES(section_id);

-- Midterm Quiz 1 scores
INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @quiz1_id,
  CASE s.student_id
    WHEN 'CPE42-001' THEN 96
    WHEN 'CPE42-002' THEN 92
    WHEN 'CPE42-003' THEN 94
    WHEN 'CPE42-004' THEN 90
    WHEN 'CPE42-005' THEN 95
    WHEN 'CPE42-006' THEN 91
    WHEN 'CPE42-007' THEN 93
    WHEN 'CPE42-008' THEN 89
    WHEN 'CPE42-009' THEN 84
    WHEN 'CPE42-010' THEN 82
    WHEN 'CPE42-011' THEN 80
    WHEN 'CPE42-012' THEN 78
    WHEN 'CPE42-013' THEN 83
    WHEN 'CPE42-014' THEN 79
    WHEN 'CPE42-015' THEN 81
    WHEN 'CPE42-016' THEN 77
    WHEN 'CPE42-017' THEN 74
    WHEN 'CPE42-018' THEN 71
    WHEN 'CPE42-019' THEN 69
    WHEN 'CPE42-020' THEN 73
    WHEN 'CPE42-021' THEN 67
    WHEN 'CPE42-022' THEN 72
    WHEN 'CPE42-023' THEN 70
    WHEN 'CPE42-024' THEN 68
    WHEN 'CPE42-025' THEN 63
    WHEN 'CPE42-026' THEN 59
    WHEN 'CPE42-027' THEN 57
    WHEN 'CPE42-028' THEN 61
    WHEN 'CPE42-029' THEN 53
    WHEN 'CPE42-030' THEN 49
    WHEN 'CPE42-031' THEN 46
    WHEN 'CPE42-032' THEN 42
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @section_id
  AND s.student_id LIKE 'CPE42-%'
  AND @quiz1_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

-- Midterm Quiz 2 scores
INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @quiz2_id,
  CASE s.student_id
    WHEN 'CPE42-001' THEN 95
    WHEN 'CPE42-002' THEN 93
    WHEN 'CPE42-003' THEN 91
    WHEN 'CPE42-004' THEN 92
    WHEN 'CPE42-005' THEN 94
    WHEN 'CPE42-006' THEN 90
    WHEN 'CPE42-007' THEN 92
    WHEN 'CPE42-008' THEN 88
    WHEN 'CPE42-009' THEN 85
    WHEN 'CPE42-010' THEN 81
    WHEN 'CPE42-011' THEN 79
    WHEN 'CPE42-012' THEN 77
    WHEN 'CPE42-013' THEN 82
    WHEN 'CPE42-014' THEN 80
    WHEN 'CPE42-015' THEN 78
    WHEN 'CPE42-016' THEN 76
    WHEN 'CPE42-017' THEN 73
    WHEN 'CPE42-018' THEN 70
    WHEN 'CPE42-019' THEN 68
    WHEN 'CPE42-020' THEN 72
    WHEN 'CPE42-021' THEN 66
    WHEN 'CPE42-022' THEN 71
    WHEN 'CPE42-023' THEN 69
    WHEN 'CPE42-024' THEN 67
    WHEN 'CPE42-025' THEN 64
    WHEN 'CPE42-026' THEN 58
    WHEN 'CPE42-027' THEN 56
    WHEN 'CPE42-028' THEN 60
    WHEN 'CPE42-029' THEN 52
    WHEN 'CPE42-030' THEN 47
    WHEN 'CPE42-031' THEN 45
    WHEN 'CPE42-032' THEN 40
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @section_id
  AND s.student_id LIKE 'CPE42-%'
  AND @quiz2_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

-- Midterm Exam scores
INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @midterm_exam_id,
  CASE s.student_id
    WHEN 'CPE42-001' THEN 97
    WHEN 'CPE42-002' THEN 95
    WHEN 'CPE42-003' THEN 93
    WHEN 'CPE42-004' THEN 94
    WHEN 'CPE42-005' THEN 96
    WHEN 'CPE42-006' THEN 91
    WHEN 'CPE42-007' THEN 92
    WHEN 'CPE42-008' THEN 89
    WHEN 'CPE42-009' THEN 86
    WHEN 'CPE42-010' THEN 84
    WHEN 'CPE42-011' THEN 82
    WHEN 'CPE42-012' THEN 79
    WHEN 'CPE42-013' THEN 83
    WHEN 'CPE42-014' THEN 81
    WHEN 'CPE42-015' THEN 80
    WHEN 'CPE42-016' THEN 78
    WHEN 'CPE42-017' THEN 75
    WHEN 'CPE42-018' THEN 72
    WHEN 'CPE42-019' THEN 70
    WHEN 'CPE42-020' THEN 74
    WHEN 'CPE42-021' THEN 68
    WHEN 'CPE42-022' THEN 73
    WHEN 'CPE42-023' THEN 71
    WHEN 'CPE42-024' THEN 69
    WHEN 'CPE42-025' THEN 62
    WHEN 'CPE42-026' THEN 57
    WHEN 'CPE42-027' THEN 55
    WHEN 'CPE42-028' THEN 59
    WHEN 'CPE42-029' THEN 50
    WHEN 'CPE42-030' THEN 46
    WHEN 'CPE42-031' THEN 43
    WHEN 'CPE42-032' THEN 38
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @section_id
  AND s.student_id LIKE 'CPE42-%'
  AND @midterm_exam_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

-- Finals Project scores
INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @final_project_id,
  CASE s.student_id
    WHEN 'CPE42-001' THEN 98
    WHEN 'CPE42-002' THEN 96
    WHEN 'CPE42-003' THEN 94
    WHEN 'CPE42-004' THEN 95
    WHEN 'CPE42-005' THEN 97
    WHEN 'CPE42-006' THEN 92
    WHEN 'CPE42-007' THEN 93
    WHEN 'CPE42-008' THEN 90
    WHEN 'CPE42-009' THEN 86
    WHEN 'CPE42-010' THEN 82
    WHEN 'CPE42-011' THEN 80
    WHEN 'CPE42-012' THEN 78
    WHEN 'CPE42-013' THEN 84
    WHEN 'CPE42-014' THEN 81
    WHEN 'CPE42-015' THEN 79
    WHEN 'CPE42-016' THEN 77
    WHEN 'CPE42-017' THEN 74
    WHEN 'CPE42-018' THEN 71
    WHEN 'CPE42-019' THEN 48
    WHEN 'CPE42-020' THEN 44
    WHEN 'CPE42-021' THEN 40
    WHEN 'CPE42-022' THEN 45
    WHEN 'CPE42-023' THEN 56
    WHEN 'CPE42-024' THEN 54
    WHEN 'CPE42-025' THEN 70
    WHEN 'CPE42-026' THEN 60
    WHEN 'CPE42-027' THEN 89
    WHEN 'CPE42-028' THEN 65
    WHEN 'CPE42-029' THEN 55
    WHEN 'CPE42-030' THEN 96
    WHEN 'CPE42-031' THEN 68
    WHEN 'CPE42-032' THEN 42
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @section_id
  AND s.student_id LIKE 'CPE42-%'
  AND @final_project_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);

-- Finals Exam scores
INSERT INTO student_scores (student_id, assignment_id, score)
SELECT s.id, @final_exam_id,
  CASE s.student_id
    WHEN 'CPE42-001' THEN 97
    WHEN 'CPE42-002' THEN 95
    WHEN 'CPE42-003' THEN 93
    WHEN 'CPE42-004' THEN 94
    WHEN 'CPE42-005' THEN 96
    WHEN 'CPE42-006' THEN 91
    WHEN 'CPE42-007' THEN 92
    WHEN 'CPE42-008' THEN 89
    WHEN 'CPE42-009' THEN 83
    WHEN 'CPE42-010' THEN 80
    WHEN 'CPE42-011' THEN 79
    WHEN 'CPE42-012' THEN 76
    WHEN 'CPE42-013' THEN 82
    WHEN 'CPE42-014' THEN 80
    WHEN 'CPE42-015' THEN 78
    WHEN 'CPE42-016' THEN 75
    WHEN 'CPE42-017' THEN 73
    WHEN 'CPE42-018' THEN 70
    WHEN 'CPE42-019' THEN 50
    WHEN 'CPE42-020' THEN 46
    WHEN 'CPE42-021' THEN 45
    WHEN 'CPE42-022' THEN 46
    WHEN 'CPE42-023' THEN 58
    WHEN 'CPE42-024' THEN 52
    WHEN 'CPE42-025' THEN 72
    WHEN 'CPE42-026' THEN 62
    WHEN 'CPE42-027' THEN 87
    WHEN 'CPE42-028' THEN 64
    WHEN 'CPE42-029' THEN 57
    WHEN 'CPE42-030' THEN 97
    WHEN 'CPE42-031' THEN 66
    WHEN 'CPE42-032' THEN 40
    ELSE NULL
  END AS score
FROM students s
WHERE s.section_id = @section_id
  AND s.student_id LIKE 'CPE42-%'
  AND @final_exam_id IS NOT NULL
ON DUPLICATE KEY UPDATE score = VALUES(score);