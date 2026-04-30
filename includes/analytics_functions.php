<?php
/**
 * Analytics Helper Functions
 * Database queries and calculations for student performance analytics
 */

/**
 * Get a student's overall grade for a given section and term
 * Formula: (Midterm average + Finals average) / 2
 * 
 * @param int $studentId - Student internal ID (from students table)
 * @param int $sectionId - Section ID
 * @param string $term - Term filter: 'Midterm', 'Finals', or 'All' for both
 * @return float|string - Overall grade percentage, or 'N/A' if data incomplete
 */
function getStudentOverallGrade($studentId, $sectionId, $term = 'All') {
    global $conn;
    
    $grades = [];
    
    $terms = ($term === 'All') ? ['Midterm', 'Finals'] : [$term];
    
    foreach ($terms as $t) {
        // Query: average score for this student in this term
        $query = "
            SELECT AVG(ss.score / a.max_score * 100) as term_average
            FROM student_scores ss
            INNER JOIN assignments a ON ss.assignment_id = a.id
            INNER JOIN grading_categories gc ON a.category_id = gc.id
            WHERE ss.student_id = ? 
              AND gc.section_id = ? 
              AND gc.term = ?
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('iis', $studentId, $sectionId, $t);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['term_average'] !== null) {
            $grades[$t] = $row['term_average'];
        }
    }
    
    // Calculate overall only when both terms are present.
    // This keeps analytics focused on complete Midterm + Finals data.
    if (count($grades) === 2) {
        return ($grades['Midterm'] + $grades['Finals']) / 2;
    }

    return 'N/A';
}

/**
 * Get a student's analytics status for a section.
 *
 * Rules:
 * - No Data: no Midterm grades yet
 * - Partial: Midterm exists, Finals missing, Midterm > 60
 * - At Risk: Midterm <= 60 before Finals are complete, or Midterm stays weak even if Finals are present
 * - Failed: full overall < threshold after both terms are present
 * - Passed: full overall >= threshold after both terms are present
 *
 * @param int $studentId
 * @param int $sectionId
 * @param int $threshold
 * @return array{status:string, score:float|null, midterm:float|null, finals:float|null, has_midterm:bool, has_finals:bool}
 */
function getStudentAnalyticsStatus($studentId, $sectionId, $threshold = 60) {
    global $conn;

    $termScores = [
        'Midterm' => null,
        'Finals' => null,
    ];

    foreach ($termScores as $termName => $unused) {
        $query = "
            SELECT AVG(ss.score / a.max_score * 100) as term_average
            FROM student_scores ss
            INNER JOIN assignments a ON ss.assignment_id = a.id
            INNER JOIN grading_categories gc ON a.category_id = gc.id
            WHERE ss.student_id = ?
              AND gc.section_id = ?
              AND gc.term = ?
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('iis', $studentId, $sectionId, $termName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row && $row['term_average'] !== null) {
            $termScores[$termName] = (float) $row['term_average'];
        }
    }

    $hasMidterm = $termScores['Midterm'] !== null;
    $hasFinals = $termScores['Finals'] !== null;
    $overall = null;

    if ($hasMidterm && $hasFinals) {
        $overall = ($termScores['Midterm'] + $termScores['Finals']) / 2;
        if ($overall < $threshold) {
            return [
                'status' => 'Failed',
                'score' => $overall,
                'midterm' => $termScores['Midterm'],
                'finals' => $termScores['Finals'],
                'has_midterm' => true,
                'has_finals' => true,
            ];
        }

        return [
            'status' => 'Passed',
            'score' => $overall,
            'midterm' => $termScores['Midterm'],
            'finals' => $termScores['Finals'],
            'has_midterm' => true,
            'has_finals' => true,
        ];
    }

    if ($hasMidterm) {
        if ($termScores['Midterm'] <= $threshold) {
            return [
                'status' => 'At Risk',
                'score' => $termScores['Midterm'],
                'midterm' => $termScores['Midterm'],
                'finals' => null,
                'has_midterm' => true,
                'has_finals' => false,
            ];
        }

        return [
            'status' => 'Partial',
            'score' => $termScores['Midterm'],
            'midterm' => $termScores['Midterm'],
            'finals' => null,
            'has_midterm' => true,
            'has_finals' => false,
        ];
    }

    return [
        'status' => 'No Data',
        'score' => null,
        'midterm' => null,
        'finals' => null,
        'has_midterm' => false,
        'has_finals' => false,
    ];
}

/**
 * Get list of at-risk students in a section for a given term
 * 
 * @param int $sectionId - Section ID
 * @param string $term - Term: 'Midterm', 'Finals', or 'All'
 * @param int $threshold - Grade threshold (default 60%)
 * @return array - List of students with ID, name, overall grade
 */
function getAtRiskStudents($sectionId, $term = 'All', $threshold = 60) {
    global $conn;
    
    $students = [];
    
    // Get all students in section
    $query = "SELECT id, student_id, name FROM students WHERE section_id = ? ORDER BY name ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $grade = getStudentOverallGrade($row['id'], $sectionId, $term);
        
        // If grade is N/A or a valid number below threshold, include
        if ($grade !== 'N/A' && $grade < $threshold) {
            $students[] = [
                'student_id' => $row['student_id'],
                'name' => $row['name'],
                'grade' => round($grade, 2)
            ];
        }
    }
    $stmt->close();
    
    return $students;
}

/**
 * Get class-wide passing rate
 * 
 * @param int $sectionId - Section ID
 * @param string $term - Term: 'Midterm', 'Finals', or 'All'
 * @param int $passingThreshold - Minimum grade to pass (default 60%)
 * @return array - ['passing_count', 'total_count', 'passing_rate_percent']
 */
function getClassPassingRate($sectionId, $term = 'All', $passingThreshold = 60) {
    global $conn;
    
    $passingCount = 0;
    $totalCount = 0;
    
    // Get all students in section
    $query = "SELECT id FROM students WHERE section_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $grade = getStudentOverallGrade($row['id'], $sectionId, $term);
        if ($grade !== 'N/A') {
            $totalCount++;
            if ($grade >= $passingThreshold) {
                $passingCount++;
            }
        }
    }
    $stmt->close();
    
    $passingRate = ($totalCount > 0) ? round(($passingCount / $totalCount) * 100, 2) : 0;
    
    return [
        'passing_count' => $passingCount,
        'total_count' => $totalCount,
        'passing_rate_percent' => $passingRate
    ];
}

/**
 * Get grade distribution for all students in a section
 * Returns count of students in grade buckets
 * 
 * @param int $sectionId - Section ID
 * @param string $term - Term: 'Midterm', 'Finals', or 'All'
 * @return array - ['0-20' => 2, '20-40' => 5, '40-60' => 8, '60-80' => 12, '80-100' => 3]
 */
function getGradeDistribution($sectionId, $term = 'All') {
    global $conn;
    
    $distribution = [
        '0-20' => 0,
        '20-40' => 0,
        '40-60' => 0,
        '60-80' => 0,
        '80-100' => 0
    ];
    
    $query = "SELECT id FROM students WHERE section_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $grade = getStudentOverallGrade($row['id'], $sectionId, $term);
        if ($grade !== 'N/A') {
            if ($grade < 20) {
                $distribution['0-20']++;
            } elseif ($grade < 40) {
                $distribution['20-40']++;
            } elseif ($grade < 60) {
                $distribution['40-60']++;
            } elseif ($grade < 80) {
                $distribution['60-80']++;
            } else {
                $distribution['80-100']++;
            }
        }
    }
    $stmt->close();
    
    return $distribution;
}

/**
 * Get student performance matrix per grading category
 * Used for heatmap visualization
 * 
 * @param int $sectionId - Section ID
 * @param string $term - Term: 'Midterm', 'Finals', or 'All'
 * @return array - [['student_id' => 'STU001', 'name' => 'John Doe', 'categories' => ['Attendance' => 85, 'Quizzes' => 92, ...]], ...]
 */
function getStudentCategoryScores($sectionId, $term = 'All') {
    global $conn;
    
    $studentData = [];
    
    // Get all students in section
    $query = "SELECT id, student_id, name FROM students WHERE section_id = ? ORDER BY name ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $studentId = $row['id'];
        $categories = [];
        
        // Get all categories for this term
        $termCondition = ($term === 'All') ? "" : "AND gc.term = '$term'";
        $categoryQuery = "
            SELECT DISTINCT gc.id, gc.name
            FROM grading_categories gc
            WHERE gc.section_id = ? $termCondition
            ORDER BY gc.name ASC
        ";
        
        // Manual query construction for dynamic term filter
        if ($term === 'All') {
            $categoryQuery = "
                SELECT DISTINCT gc.id, gc.name
                FROM grading_categories gc
                WHERE gc.section_id = ?
                ORDER BY gc.name ASC
            ";
            $catStmt = $conn->prepare($categoryQuery);
            $catStmt->bind_param('i', $sectionId);
        } else {
            $categoryQuery = "
                SELECT DISTINCT gc.id, gc.name
                FROM grading_categories gc
                WHERE gc.section_id = ? AND gc.term = ?
                ORDER BY gc.name ASC
            ";
            $catStmt = $conn->prepare($categoryQuery);
            $catStmt->bind_param('is', $sectionId, $term);
        }
        
        $catStmt->execute();
        $categoryResult = $catStmt->get_result();
        
        while ($catRow = $categoryResult->fetch_assoc()) {
            $categoryId = $catRow['id'];
            $categoryName = $catRow['name'];
            
            // Get average score for this student in this category
            $scoreQuery = "
                SELECT AVG(ss.score / a.max_score * 100) as category_average
                FROM student_scores ss
                INNER JOIN assignments a ON ss.assignment_id = a.id
                WHERE ss.student_id = ? AND a.category_id = ?
            ";
            
            $scoreStmt = $conn->prepare($scoreQuery);
            $scoreStmt->bind_param('ii', $studentId, $categoryId);
            $scoreStmt->execute();
            $scoreResult = $scoreStmt->get_result();
            $scoreRow = $scoreResult->fetch_assoc();
            $scoreStmt->close();
            
            if ($scoreRow['category_average'] !== null) {
                $categories[$categoryName] = round($scoreRow['category_average'], 1);
            }
        }
        $catStmt->close();
        
        if (!empty($categories)) {
            $studentData[] = [
                'student_id' => $row['student_id'],
                'name' => $row['name'],
                'categories' => $categories
            ];
        }
    }
    $stmt->close();
    
    return $studentData;
}

/**
 * Get all sections owned by a faculty member
 * 
 * @param string $facultyEmail - Faculty email from session
 * @return array - List of sections with ID and name
 */
function getFacultySections($facultyEmail) {
    global $conn;
    
    $sections = [];
    
    $query = "SELECT id, section_name FROM sections WHERE owner_email = ? ORDER BY section_name ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $facultyEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
    $stmt->close();
    
    return $sections;
}

/**
 * Get all terms available for a given section
 * 
 * @param int $sectionId - Section ID
 * @return array - List of unique terms ['Midterm', 'Finals', ...]
 */
function getSectionTerms($sectionId) {
    global $conn;
    
    $terms = [];
    
    $query = "SELECT DISTINCT term FROM grading_categories WHERE section_id = ? ORDER BY term ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $terms[] = $row['term'];
    }
    $stmt->close();
    
    return $terms;
}
?>
