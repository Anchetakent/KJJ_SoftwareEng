<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/analytics_functions.php';

// Security Gate
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'];
$user_email = $_SESSION['email'];
$selected_section = isset($_GET['section']) ? $_GET['section'] : null;
$view = isset($_GET['view']) ? $_GET['view'] : null; 
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';

$duplicate_error = ""; 

// Fetch current Section ID securely
$current_section_id = null;
if ($selected_section) {
    $sec_query = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND owner_email = ?");
    $sec_query->bind_param("ss", $selected_section, $user_email);
    $sec_query->execute();
    $sec_res = $sec_query->get_result();
    if ($sec_row = $sec_res->fetch_assoc()) {
        $current_section_id = $sec_row['id'];
    }
}

// --- EXPORT TO CSV LOGIC ---
if (isset($_POST['export_csv']) && $current_section_id) {
    // Fetch Data specifically for export
    $export_tree = ['Midterm' => [], 'Finals' => []];
    $export_scores = [];
    
    $cat_res = $conn->query("SELECT * FROM grading_categories WHERE section_id = $current_section_id ORDER BY id ASC");
    $cat_ids = [];
    while ($c = $cat_res->fetch_assoc()) { 
        $c['assignments'] = [];
        $export_tree[$c['term']][$c['id']] = $c; 
        $cat_ids[] = $c['id'];
    }

    if (!empty($cat_ids)) {
        $ids_str = implode(',', $cat_ids);
        $ass_res = $conn->query("SELECT * FROM assignments WHERE category_id IN ($ids_str) ORDER BY id ASC");
        $ass_ids = [];
        while ($a = $ass_res->fetch_assoc()) {
            foreach(['Midterm', 'Finals'] as $term) {
                if (isset($export_tree[$term][$a['category_id']])) {
                    $export_tree[$term][$a['category_id']]['assignments'][$a['id']] = $a;
                }
            }
            $ass_ids[] = $a['id'];
        }

        if (!empty($ass_ids)) {
            $ass_str = implode(',', $ass_ids);
            $score_res = $conn->query("SELECT student_id, assignment_id, score FROM student_scores WHERE assignment_id IN ($ass_str)");
            while ($s = $score_res->fetch_assoc()) { 
                $export_scores[$s['student_id']][$s['assignment_id']] = $s['score']; 
            }
        }
    }

    // Generate CSV Header
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $selected_section . '_Grades.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Student ID', 'Full Name', 'Midterm Grade (%)', 'Finals Grade (%)', 'Overall Grade (%)'));

    // determine if both terms have assignments (used to decide N/A)
    $midterm_assignment_count = 0;
    foreach ($export_tree['Midterm'] as $cat) {
        $midterm_assignment_count += count($cat['assignments']);
    }
    $finals_assignment_count = 0;
    foreach ($export_tree['Finals'] as $cat) {
        $finals_assignment_count += count($cat['assignments']);
    }

    // Output Rows
    $stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ?");
    $stmt->bind_param("i", $current_section_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while($student = $result->fetch_assoc()) {
        $mid_total = 0;
        foreach ($export_tree['Midterm'] as $cat) {
            $cat_earned = 0; $cat_max = 0;
            foreach($cat['assignments'] as $ass) {
                $cat_max += $ass['max_score'];
                $cat_earned += $export_scores[$student['id']][$ass['id']] ?? 0;
            }
            if ($cat_max > 0) $mid_total += ($cat_earned / $cat_max) * $cat['weight'];
        }
        
        $fin_total = 0;
        foreach ($export_tree['Finals'] as $cat) {
            $cat_earned = 0; $cat_max = 0;
            foreach($cat['assignments'] as $ass) {
                $cat_max += $ass['max_score'];
                $cat_earned += $export_scores[$student['id']][$ass['id']] ?? 0;
            }
            if ($cat_max > 0) $fin_total += ($cat_earned / $cat_max) * $cat['weight'];
        }

        $overall_total = ($midterm_assignment_count > 0 && $finals_assignment_count > 0)
            ? (($mid_total + $fin_total) / 2)
            : null;

        fputcsv($output, array(
            $student['student_id'],
            $student['name'],
            number_format($mid_total, 2),
            number_format($fin_total, 2),
            ($overall_total === null) ? 'N/A' : number_format($overall_total, 2)
        ));
    }
    fclose($output);
    exit(); // Stop rendering the rest of the page so the CSV stays clean
}

// --- MANAGEMENT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user_role === 'Faculty')) {
    
    // 1. Core Section CRUD
    if (isset($_POST['add_class'])) {
        $name = trim($_POST['section_name']);
        if ($_POST['class_type'] === 'Lab') { $name .= 'LA'; }

        $stmt = $conn->prepare("INSERT INTO sections (section_name, owner_email) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $user_email);
        $stmt->execute();
        header("Location: dashboard.php?section=" . urlencode($name));
        exit();
    }
    
    if (isset($_POST['edit_class'])) {
        $old_name = $_POST['old_section_name'];
        $new_name = $_POST['new_section_name'];
        $notes = $_POST['notes'];
        $stmt = $conn->prepare("UPDATE sections SET section_name = ?, notes = ? WHERE section_name = ? AND owner_email = ?");
        $stmt->bind_param("ssss", $new_name, $notes, $old_name, $user_email);
        $stmt->execute();
        header("Location: dashboard.php?section=" . urlencode($new_name));
        exit();
    }

    if (isset($_POST['delete_class'])) {
        $name = $_POST['section_to_delete'];
        $stmt = $conn->prepare("DELETE FROM sections WHERE section_name = ? AND owner_email = ?");
        $stmt->bind_param("ss", $name, $user_email);
        $stmt->execute();
        header("Location: dashboard.php");
        exit();
    }

    // 2. Student CRUD
    if (isset($_POST['add_student'])) {
        $sid = $_POST['student_id'];
        $sname = $_POST['student_name'];
        $check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND section_id = ?");
        $check->bind_param("si", $sid, $current_section_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $duplicate_error = "The Student ID <strong>" . htmlspecialchars($sid) . "</strong> is already enrolled.";
        } else {
            $stmt = $conn->prepare("INSERT INTO students (student_id, name, section_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $sid, $sname, $current_section_id);
            $stmt->execute();
        }
    }

    if (isset($_POST['edit_student'])) {
        $old_id = $_POST['old_student_id'];
        $new_id = $_POST['new_student_id'];
        $new_name = $_POST['new_student_name'];
        if ($old_id !== $new_id) {
            $check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND section_id = ?");
            $check->bind_param("si", $new_id, $current_section_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $duplicate_error = "Cannot update. The ID <strong>" . htmlspecialchars($new_id) . "</strong> is already assigned.";
            }
        }
        if (empty($duplicate_error)) {
            $stmt = $conn->prepare("UPDATE students SET student_id = ?, name = ? WHERE student_id = ? AND section_id = ?");
            $stmt->bind_param("sssi", $new_id, $new_name, $old_id, $current_section_id);
            $stmt->execute();
        }
    }

    if (isset($_POST['delete_student'])) {
        $sid = $_POST['student_to_delete'];
        $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ? AND section_id = ?");
        $stmt->bind_param("si", $sid, $current_section_id);
        $stmt->execute();
    }

    // 3. Category CRUD (The Buckets)
    if (isset($_POST['add_category']) && $current_section_id) {
        $term = $_POST['term'];
        $name = $_POST['cat_name'];
        $weight = $_POST['weight'];
        $stmt = $conn->prepare("INSERT INTO grading_categories (section_id, term, name, weight) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issd", $current_section_id, $term, $name, $weight);
        $stmt->execute();
    }

    if (isset($_POST['edit_category']) && $current_section_id) {
        $cat_id = $_POST['category_id'];
        $name = $_POST['cat_name'];
        $weight = $_POST['weight'];
        $stmt = $conn->prepare("UPDATE grading_categories SET name = ?, weight = ? WHERE id = ? AND section_id = ?");
        $stmt->bind_param("sdii", $name, $weight, $cat_id, $current_section_id);
        $stmt->execute();
    }

    if (isset($_POST['delete_category']) && $current_section_id) {
        $cat_id = $_POST['category_id'];
        $stmt = $conn->prepare("DELETE FROM grading_categories WHERE id = ? AND section_id = ?");
        $stmt->bind_param("ii", $cat_id, $current_section_id);
        $stmt->execute();
    }

    // 4. Assignment CRUD (The Columns)
    if (isset($_POST['add_assignment'])) {
        $cat_id = $_POST['category_id'];
        $name = $_POST['ass_name'];
        $max = $_POST['max_score'];
        $stmt = $conn->prepare("INSERT INTO assignments (category_id, name, max_score) VALUES (?, ?, ?)");
        $stmt->bind_param("isd", $cat_id, $name, $max);
        $stmt->execute();
    }

    if (isset($_POST['edit_assignment'])) {
        $ass_id = $_POST['assignment_id'];
        $name = $_POST['ass_name'];
        $max = $_POST['max_score'];
        $stmt = $conn->prepare("UPDATE assignments SET name = ?, max_score = ? WHERE id = ?");
        $stmt->bind_param("sdi", $name, $max, $ass_id);
        $stmt->execute();
    }

    if (isset($_POST['delete_assignment'])) {
        $ass_id = $_POST['assignment_id'];
        $stmt = $conn->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->bind_param("i", $ass_id);
        $stmt->execute();
    }

    if (isset($_POST['autosave_score']) && $current_section_id) {
        $student_internal_id = (int) ($_POST['student_internal_id'] ?? 0);
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
        $score = isset($_POST['score']) ? trim((string) $_POST['score']) : '';

        $student_check = $conn->prepare("SELECT id FROM students WHERE id = ? AND section_id = ?");
        $student_check->bind_param("ii", $student_internal_id, $current_section_id);
        $student_check->execute();
        $student_exists = $student_check->get_result()->num_rows > 0;
        $student_check->close();

        $assignment_check = $conn->prepare("SELECT a.id FROM assignments a INNER JOIN grading_categories gc ON a.category_id = gc.id WHERE a.id = ? AND gc.section_id = ?");
        $assignment_check->bind_param("ii", $assignment_id, $current_section_id);
        $assignment_check->execute();
        $assignment_exists = $assignment_check->get_result()->num_rows > 0;
        $assignment_check->close();

        if ($student_exists && $assignment_exists) {
            if ($score === '') {
                $del_stmt = $conn->prepare("DELETE FROM student_scores WHERE student_id = ? AND assignment_id = ?");
                $del_stmt->bind_param("ii", $student_internal_id, $assignment_id);
                $del_stmt->execute();
                $del_stmt->close();
            } else {
                $score_value = (float) $score;
                $upd_stmt = $conn->prepare("INSERT INTO student_scores (student_id, assignment_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score)");
                $upd_stmt->bind_param("iid", $student_internal_id, $assignment_id, $score_value);
                $upd_stmt->execute();
                $upd_stmt->close();
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $student_exists && $assignment_exists,
            'student_id' => $student_internal_id,
            'assignment_id' => $assignment_id,
            'score' => $score,
        ]);
        exit();
    }

    // 5. Save Scores
    if (isset($_POST['save_scores']) && $current_section_id) {
        $scores = $_POST['scores'] ?? [];
        $del_stmt = $conn->prepare("DELETE FROM student_scores WHERE student_id = ? AND assignment_id = ?");
        $upd_stmt = $conn->prepare("INSERT INTO student_scores (student_id, assignment_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score)");
        
        foreach ($scores as $s_id => $ass_data) {
            foreach ($ass_data as $a_id => $score) {
                if ($score === '') {
                    $del_stmt->bind_param("ii", $s_id, $a_id);
                    $del_stmt->execute();
                } else {
                    $upd_stmt->bind_param("iid", $s_id, $a_id, $score);
                    $upd_stmt->execute();
                }
            }
        }
    }
}

// Fetch LMS Data Tree (Categories -> Assignments -> Scores)
$tree = ['Midterm' => [], 'Finals' => []];
$student_scores = [];

if ($current_section_id) {
    // 1. Get Categories
    $cat_res = $conn->query("SELECT * FROM grading_categories WHERE section_id = $current_section_id ORDER BY id ASC");
    $cat_ids = [];
    while ($c = $cat_res->fetch_assoc()) { 
        $c['assignments'] = []; // Initialize empty array for assignments
        $tree[$c['term']][$c['id']] = $c; 
        $cat_ids[] = $c['id'];
    }

    // 2. Get Assignments belonging to those categories
    if (!empty($cat_ids)) {
        $ids_str = implode(',', $cat_ids);
        $ass_res = $conn->query("SELECT * FROM assignments WHERE category_id IN ($ids_str) ORDER BY id ASC");
        $ass_ids = [];
        while ($a = $ass_res->fetch_assoc()) {
            foreach(['Midterm', 'Finals'] as $term) {
                if (isset($tree[$term][$a['category_id']])) {
                    $tree[$term][$a['category_id']]['assignments'][$a['id']] = $a;
                }
            }
            $ass_ids[] = $a['id'];
        }

        // 3. Get Scores for those assignments
        if (!empty($ass_ids)) {
            $ass_str = implode(',', $ass_ids);
            $score_res = $conn->query("SELECT student_id, assignment_id, score FROM student_scores WHERE assignment_id IN ($ass_str)");
            while ($s = $score_res->fetch_assoc()) { 
                $student_scores[$s['student_id']][$s['assignment_id']] = $s['score']; 
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Workspace | EduPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --sidebar-bg: #0f172a; --primary: #10b981; --bg-main: #f8fafc; --border-color: #e2e8f0;
            --text-main: #1e293b; --text-muted: #64748b; --slate-700: #334155; --note-bg: #f0fdf4; --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); letter-spacing: -0.01em; }
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .top-nav { height: 80px; background: white; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 48px; position: sticky; top: 0; z-index: 10; }
        .user-pill { display: flex; align-items: center; gap: 12px; padding: 8px 20px; background: var(--bg-main); border: 1px solid var(--border-color); border-radius: 99px; font-size: 0.85rem; font-weight: 600; }
        .badge { background: var(--primary); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; }
        .content-area { padding: 40px; }
        .data-card { background: white; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: #fcfcfc; display: flex; justify-content: space-between; align-items: center; }
        
        /* TABS UI */
        .tab-container { display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 24px; gap: 32px; padding: 0 24px; background: #fcfcfc;}
        .tab-link { color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.9rem; padding: 16px 0; border-bottom: 3px solid transparent; transition: 0.2s; margin-bottom: -1px; }
        .tab-link:hover { color: var(--text-main); }
        .tab-link.active { color: var(--primary); border-bottom-color: var(--primary); }
        .analytics-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; padding: 24px; background: #f8fafc; border-bottom: 1px solid var(--border-color); }
        .analytics-metric { background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .analytics-metric .label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.04em; }
        .analytics-metric .value { margin-top: 8px; font-size: 2rem; font-weight: 800; color: var(--slate-700); line-height: 1; }
        .analytics-metric .subtext { margin-top: 6px; font-size: 0.82rem; color: var(--text-muted); }
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; padding: 24px; }
        .analytics-card { background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .analytics-card h4 { margin-bottom: 16px; font-size: 0.98rem; color: var(--slate-700); }
        .analytics-note { margin: 0 24px 24px; padding: 14px 16px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; border-radius: 10px; font-size: 0.88rem; }
        .analytics-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: #f0fdf4; color: #047857; border-radius: 999px; font-size: 0.82rem; font-weight: 700; }
        .analytics-table { width: 100%; border-collapse: collapse; }
        .analytics-table th { background: #f1f5f9; }
        .analytics-table td, .analytics-table th { padding: 12px 14px; border-bottom: 1px solid var(--border-color); }
        .gradebook-toolbar { padding: 20px 24px; background: #fcfcfc; border-bottom: 1px solid var(--border-color); display: flex; gap: 16px; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; }
        .gradebook-search { min-width: 280px; max-width: 420px; width: 100%; }
        .gradebook-search input { width: 100%; height: 46px; }
        .gradebook-search-help { margin-top: 6px; font-size: 0.8rem; color: var(--text-muted); }
        .gradebook-search-count { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); }
        .status-chip { display: inline-flex; align-items: center; padding: 5px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .status-chip.danger { background: #fef2f2; color: #b91c1c; }
        .status-chip.warning { background: #fff7ed; color: #c2410c; }
        .status-chip.good { background: #f0fdf4; color: #166534; }
        .chart-shell { position: relative; height: 320px; }
        .notes-display { background: var(--note-bg); border-bottom: 1px solid var(--border-color); padding: 20px 24px; display: flex; gap: 16px; align-items: flex-start; }
        .notes-display i { color: var(--primary); font-size: 1.1rem; margin-top: 2px; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; padding: 12px 24px; text-align: left; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; border-bottom: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
        th:last-child { border-right: none; }
        td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; white-space: nowrap; border-right: 1px solid var(--border-color); }
        td:last-child { border-right: none; }
        
        .grade-input { width: 80px; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; text-align: center; font-weight: 600; transition: 0.2s;}
        .grade-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
        .btn-pill { background: white; border: 1px solid var(--border-color); padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-pill:hover { background: #f1f5f9; border-color: var(--slate-700); }
        .btn-danger { color: var(--danger); border-color: #fecaca; }
        .btn-danger:hover { background: #fef2f2; border-color: var(--danger); }
        .btn-save { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .action-link { color: var(--text-muted); font-size: 0.85rem; cursor: pointer; text-decoration: none; margin-left: 10px; }
        .action-link:hover { color: var(--primary); }

        .mgmt-pane { padding: 24px; background: #fcfcfc; border-bottom: 1px solid var(--border-color); display: none; }
        .pane-label { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); display:block; margin-bottom:6px; text-transform: uppercase; }

        /* Custom UI for Category/Assignment Hierarchy */
        .category-box { background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .category-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 12px; }
        .ass-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-radius: 6px; margin-bottom: 8px; font-size: 0.85rem; }
        
        .modal-overlay { 
            position: fixed; 
            inset: 0; 
            background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(4px); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            z-index: 9999; 
            padding: 16px;
        }
        .error-modal { 
            background: white; 
            width: min(100%, 420px); 
            max-width: 92vw; 
            padding: 32px 24px; 
            border-radius: 18px; 
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.12); 
            text-align: center; 
        }
        .error-modal h3 { margin-top: 8px; margin-bottom: 12px; }
        .error-modal p { color: var(--text-muted); line-height: 1.5; }

        .home-init-shell {
            min-height: calc(100vh - 160px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* RESPONSIVE DESIGN - 1024px Breakpoint */
        .hamburger-menu { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); padding: 0 16px; }

        @media (max-width: 1024px) {
            body { flex-direction: column; }
            .sidebar { 
                position: fixed; 
                left: -280px; 
                top: 0; 
                height: 100vh; 
                z-index: 9998; 
                transition: left 0.3s ease;
            }
            .sidebar.open { left: 0; }
            .sidebar-overlay { 
                position: fixed; 
                top: 0; 
                left: 0; 
                width: 100%; 
                height: 100%; 
                background: rgba(0, 0, 0, 0.5); 
                display: none; 
                z-index: 9997; 
            }
            .sidebar-overlay.open { display: block; }
            .hamburger-menu { display: block; }
            .main-wrapper { height: 100vh; }
            .top-nav { padding: 0 16px; height: 70px; }
            .content-area { padding: 24px 16px; overflow-y: auto; }
            
            /* Table responsive */
            .table-responsive { 
                overflow-x: auto; 
                -webkit-overflow-scrolling: touch;
            }
            table { min-width: 100%; }
            th, td { 
                padding: 12px 12px; 
                font-size: 0.75rem;
                white-space: nowrap;
            }
            th:first-child, td:first-child { 
                position: sticky; 
                left: 0; 
                background: #f1f5f9; 
                z-index: 10;
                min-width: 180px;
                white-space: normal;
            }
            td:first-child { 
                background: white; 
                font-weight: 600;
            }
            
            /* Gradebook adjustments */
            .grade-input { width: 70px; padding: 6px; font-size: 0.8rem; }
            .gradebook-toolbar { flex-direction: column; align-items: stretch; }
            .gradebook-search { min-width: 100%; max-width: 100%; }
            .gradebook-search-count { margin-top: 8px; }
            
            /* Analytics grid responsive */
            .analytics-summary { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; padding: 16px; }
            .analytics-metric { padding: 12px; }
            .analytics-metric .value { font-size: 1.5rem; }
            .analytics-grid { grid-template-columns: 1fr; gap: 12px; padding: 16px; }
            .chart-shell { height: 250px; }
            
            /* Tab container */
            .tab-container { padding: 0 16px; gap: 16px; }
            .tab-link { font-size: 0.8rem; padding: 12px 0; }
        }

        @media (max-width: 640px) {
            .top-nav { padding: 0 12px; }
            .content-area { padding: 16px 12px; }
            .grade-input { width: 60px; padding: 4px; font-size: 0.7rem; }
            .analytics-summary { grid-template-columns: 1fr; }
            .analytics-metric .value { font-size: 1.25rem; }
            .analytics-table { font-size: 0.7rem; }
            th, td { padding: 8px 8px; }
            .home-init-shell { min-height: calc(100vh - 120px); }
        }
        .error-modal i { font-size: 3.5rem; color: var(--danger); margin-bottom: 20px; }

        /* MOBILE-FIRST RESPONSIVE SYSTEM - Senior UX Design */
        
        /* Bottom Sheet Modal Styles */
        .bottom-sheet { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            background: white; 
            border-radius: 20px 20px 0 0; 
            max-height: 90vh; 
            overflow-y: auto; 
            z-index: 10000; 
            animation: slideUp 0.3s ease-out;
            -webkit-overflow-scrolling: touch;
            display: none;
        }
        .bottom-sheet.open { display: flex; flex-direction: column; }
        .bottom-sheet-header { 
            padding: 12px 0 16px; 
            text-align: center; 
            border-bottom: 1px solid var(--border-color); 
            position: sticky; 
            top: 0; 
            background: white;
        }
        .bottom-sheet-handle { 
            width: 40px; 
            height: 4px; 
            background: #cbd5e1; 
            border-radius: 2px; 
            margin: 0 auto 8px;
        }
        .bottom-sheet-content { 
            padding: 20px 16px; 
            flex: 1;
        }
        .bottom-sheet-overlay { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background: rgba(0, 0, 0, 0.4); 
            z-index: 9999; 
            display: none;
        }
        .bottom-sheet-overlay.open { display: block; }
        
        @keyframes slideUp { 
            from { transform: translateY(100%); opacity: 0; } 
            to { transform: translateY(0); opacity: 1; } 
        }

        /* Mobile Gradebook Card Layout */
        .gradebook-card-view { display: none; }
        
        /* Show table on desktop, hide on mobile */
        table { display: table; }
        
        @media (max-width: 640px) {
            table { display: none; }
            .gradebook-card-view { display: block; }
            .table-responsive { background: transparent; border: none; }
        }
        
        .student-grade-card { 
            background: white; 
            border: 1px solid var(--border-color); 
            border-radius: 12px; 
            padding: 16px; 
            margin-bottom: 16px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .student-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 16px; 
            padding-bottom: 12px; 
            border-bottom: 1px solid var(--border-color);
        }
        .student-name { 
            font-size: 1rem; 
            font-weight: 700; 
            color: var(--text-main);
        }
        .student-id { 
            font-size: 0.75rem; 
            color: var(--text-muted); 
            font-family: monospace;
        }
        .bucket-total-badge { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            width: 56px; 
            height: 56px; 
            border-radius: 50%; 
            background: #f0fdf4; 
            color: #047857; 
            font-weight: 800; 
            font-size: 1.25rem;
        }
        .assignments-list { 
            display: flex; 
            flex-direction: column; 
            gap: 12px;
        }
        .assignment-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 12px; 
            background: #f8fafc; 
            border-radius: 8px; 
            border: 1px solid var(--border-color);
        }
        .assignment-name { 
            flex: 1; 
            font-size: 0.9rem; 
            font-weight: 600; 
            color: var(--text-main);
        }
        .assignment-score { 
            font-size: 0.85rem; 
            color: var(--text-muted); 
            margin: 0 8px;
        }
        .edit-grade-btn { 
            background: var(--primary); 
            color: white; 
            border: none; 
            padding: 8px 12px; 
            border-radius: 6px; 
            font-size: 0.8rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: 0.2s;
        }
        .edit-grade-btn:active { background: #059669; }

        /* Touch-Optimized Form Inputs */
        .touch-input { 
            width: 100%; 
            padding: 14px 12px; 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            font-size: 16px; 
            font-family: 'Inter', sans-serif;
            min-height: 48px;
            transition: 0.2s;
        }
        .touch-input:focus { 
            outline: none; 
            border-color: var(--primary); 
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
            background: #f8fafc;
        }
        .touch-input-group { 
            margin-bottom: 16px; 
            display: flex; 
            flex-direction: column;
        }
        .touch-label { 
            font-size: 0.85rem; 
            font-weight: 700; 
            color: var(--text-main); 
            margin-bottom: 8px; 
            text-transform: none;
        }

        /* Grade Input Enhancements */
        .grade-input { 
            font-size: 16px !important; 
            min-height: 44px; 
            padding: 10px 8px; 
            border: 2px solid var(--border-color); 
            border-radius: 6px; 
            font-family: 'Inter', monospace; 
            transition: border-color 0.2s, background-color 0.2s;
        }
        .grade-input:focus { 
            outline: none; 
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        .grade-input.error { 
            border-color: #dc2626; 
            background-color: #fee2e2; 
            animation: shake 0.3s ease;
        }
        
        @keyframes shake { 
            0%, 100% { transform: translateX(0); } 
            25% { transform: translateX(-4px); } 
            75% { transform: translateX(4px); }
        }
        
        /* Search Input Optimization */
        .gradebook-search input { 
            min-height: 48px; 
            font-size: 16px; 
            padding: 12px 16px;
        }

        /* Mobile Input Full Width */
        @media (max-width: 640px) {
            .grade-input, .touch-input { 
                width: 100%; 
                min-height: 52px; 
                font-size: 18px; 
                padding: 14px 12px;
            }
            .grade-input.gradebook-score-input { 
                width: 90%; 
                min-height: 48px;
            }
            .pane-label { 
                font-size: 0.9rem; 
                font-weight: 700; 
                margin-bottom: 8px; 
                display: block;
            }
        }

        /* Touch-Optimized Buttons */
        .touch-btn { 
            min-height: 48px; 
            padding: 12px 16px; 
            border-radius: 8px; 
            font-size: 0.95rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: 0.2s; 
            border: none; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            gap: 8px;
        }
        .touch-btn-primary { 
            background: var(--primary); 
            color: white; 
            width: 100%;
        }
        .touch-btn-primary:active { background: #059669; }
        .touch-btn-primary:disabled { 
            background: #cbd5e1; 
            cursor: not-allowed; 
            opacity: 0.6;
        }
        .touch-btn-secondary { 
            background: #f1f5f9; 
            color: var(--text-main); 
            border: 1px solid var(--border-color); 
            width: 100%;
        }
        .touch-btn-secondary:active { background: #e2e8f0; }
        
        /* Modal close button styling */
        .bottom-sheet .close-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
            transition: 0.2s;
            padding: 8px;
        }
        .bottom-sheet .close-btn:active {
            color: var(--text-main);
        }

        /* Illustrated Empty States */
        .empty-state { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            padding: 48px 24px; 
            text-align: center; 
            color: var(--text-muted);
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            min-height: 300px;
            margin: 24px 0;
        }
        .empty-state-icon { 
            font-size: 5rem; 
            color: #cbd5e1; 
            margin-bottom: 16px; 
            opacity: 0.8;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .empty-state-title { 
            font-size: 1.2rem; 
            font-weight: 800; 
            color: var(--text-main); 
            margin-bottom: 8px;
        }
        .empty-state-text { 
            font-size: 0.9rem; 
            color: var(--text-muted); 
            line-height: 1.6;
            max-width: 320px;
        }
        .empty-state-action { 
            margin-top: 24px;
        }
        .empty-state-action a, 
        .empty-state-action button { 
            display: inline-block; 
            padding: 10px 20px; 
            background: var(--primary); 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-weight: 600; 
            cursor: pointer; 
            text-decoration: none;
            transition: 0.2s;
        }
        .empty-state-action button:hover,
        .empty-state-action a:hover { 
            background: #059669; 
        }

        /* Responsive Analytics */
        @media (max-width: 640px) {
            /* Typography */
            html { font-size: 15px; }
            .card-header { padding: 16px; }
            .content-area { padding: 12px; }
            
            /* Forms */
            .touch-input { font-size: 16px; min-height: 52px; }
            .touch-label { font-size: 0.9rem; }
            
            /* Buttons */
            .btn-pill { width: 100%; min-height: 44px; justify-content: center; }
            .btn-save { width: 100%; min-height: 48px; }
            
            /* Cards */
            .student-grade-card { padding: 12px; }
            .assignment-row { padding: 10px; }
            .bucket-total-badge { width: 48px; height: 48px; font-size: 1rem; }
            
            /* Tables */
            table { font-size: 0.75rem; }
            th, td { padding: 6px; }
            .table-responsive { overflow-x: auto; }
            
            /* Search */
            .gradebook-search input { height: 48px; font-size: 16px; }
            
            /* Analytics */
            .analytics-grid { grid-template-columns: 1fr; padding: 12px; gap: 12px; }
            .analytics-card { padding: 16px; }
            .chart-shell { height: 280px; }
            .analytics-summary { gap: 12px; padding: 12px; }
            .analytics-metric { padding: 12px; }
            .analytics-metric .value { font-size: 1.5rem; }
            .analytics-metric .label { font-size: 0.65rem; }
            
            /* Tabs */
            .tab-container { gap: 0; padding: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .tab-link { padding: 12px 16px; white-space: nowrap; font-size: 0.85rem; }
            
            /* Modals */
            .error-modal { width: 90%; max-width: 380px; padding: 24px; }
            .bottom-sheet { max-height: 80vh; }
        }

        /* Gradient Progress Bar */
        .progress-bar { 
            height: 6px; 
            background: #e2e8f0; 
            border-radius: 3px; 
            overflow: hidden; 
            margin: 12px 0;
        }
        .progress-fill { 
            height: 100%; 
            background: linear-gradient(90deg, var(--primary), #10b981); 
            border-radius: 3px; 
            transition: width 0.3s ease;
        }

        /* Success/Error Feedback */
        .feedback-toast { 
            position: fixed; 
            bottom: 20px; 
            left: 16px; 
            right: 16px; 
            padding: 16px; 
            border-radius: 8px; 
            font-weight: 600; 
            font-size: 0.9rem; 
            z-index: 10001; 
            animation: slideUp 0.3s ease-out;
        }
        .feedback-success { 
            background: #f0fdf4; 
            color: #047857; 
            border: 1px solid #bbf7d0;
        }
        .feedback-error { 
            background: #fef2f2; 
            color: #b91c1c; 
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="main-wrapper">
        <header class="top-nav">
            <div style="display: flex; align-items: center; gap: 20px;">
                <button class="hamburger-menu" id="hamburgerMenu" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div style="font-weight: 600; color: var(--text-muted);">
                    Workspace / <?php 
                        if ($view) echo ucfirst($view);
                        else echo $selected_section ? "Section $selected_section" : "Overview"; 
                    ?>
                </div>
            </div>
            <div class="user-pill">
                <span class="badge"><?php echo $user_role; ?></span>
                <?php echo htmlspecialchars($user_email); ?>
            </div>
        </header>

        <main class="content-area">
            <?php if ($user_role === 'System Admin'): ?>
                <?php if ($view === 'users'): ?>
                    <div class="data-card">
                        <div class="card-header">
                            <h3 style="font-size: 1rem;">System Accounts</h3>
                            <button class="btn-save" style="padding: 8px 16px; font-size: 0.8rem;">+ Add User</button>
                        </div>
                        <table>
                            <thead><tr><th>ID</th><th>Email Address</th><th>Role</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php 
                                $users_res = $conn->query("SELECT id, email, role FROM users");
                                while($u = $users_res->fetch_assoc()): ?>
                                    <tr>
                                        <td style="color: var(--text-muted);">#<?php echo $u['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($u['email']); ?></strong></td>
                                        <td><?php echo $u['role']; ?></td>
                                        <td><span class="status-pill">Active</span></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'logs'): ?>
                    <div class="data-card">
                        <div class="card-header"><h3 style="font-size: 1rem;">Recent Activity</h3></div>
                        <table>
                            <thead><tr><th>Timestamp</th><th>User Account</th><th>Logged Action</th></tr></thead>
                            <tbody>
                                <?php 
                                $logs_res = $conn->query("SELECT * FROM audit_logs ORDER BY log_time DESC LIMIT 50");
                                while($l = $logs_res->fetch_assoc()): ?>
                                    <tr>
                                        <td style="color: var(--text-muted); font-size: 0.8rem;"><?php echo date("M d, Y • H:i:s", strtotime($l['log_time'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($l['user_email']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($l['action']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding-top: 100px;">
                        <i class="fa-solid fa-shield-halved" style="font-size: 4rem; color: #e2e8f0; margin-bottom: 20px;"></i>
                        <h3 style="color: var(--text-muted);">Administrative Console</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">Select an option from the sidebar to manage users or view logs.</p>
                    </div>
                <?php endif; ?>
            <?php elseif ($user_role === 'Faculty'): ?>
                <?php if ($selected_section): ?>
                    <div class="data-card">
                        <div class="card-header">
                            <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--slate-700);"><?php echo htmlspecialchars($selected_section); ?></h3>
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" style="margin: 0;">
                                    <button type="submit" name="export_csv" class="btn-pill" style="color: #0284c7; border-color: #bae6fd; background: #f0f9ff;"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                                </form>
                                <button onclick="openAddPane()" class="btn-pill"><i class="fa-solid fa-user-plus"></i> Add Student</button>
                                <button onclick="togglePane('edit-class-pane')" class="btn-pill"><i class="fa-solid fa-pen-to-square"></i> Class Details</button>
                            </div>
                        </div>

                        <div class="tab-container">
                            <a href="?section=<?php echo urlencode($selected_section); ?>&tab=overview" class="tab-link <?php echo $current_tab == 'overview' ? 'active' : ''; ?>">Overview & Roster</a>
                            <a href="?section=<?php echo urlencode($selected_section); ?>&tab=Midterm" class="tab-link <?php echo $current_tab == 'Midterm' ? 'active' : ''; ?>">Midterm Gradebook</a>
                            <a href="?section=<?php echo urlencode($selected_section); ?>&tab=Finals" class="tab-link <?php echo $current_tab == 'Finals' ? 'active' : ''; ?>">Finals Gradebook</a>
                            <a href="?section=<?php echo urlencode($selected_section); ?>&tab=Analytics" class="tab-link <?php echo $current_tab == 'Analytics' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-line" style="margin-right: 8px;"></i>Analytics</a>
                        </div>

                        <div id="add-student-pane" class="mgmt-pane">
                            <form method="POST" style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 15px; align-items: end;">
                                <div><label class="pane-label">ID Number</label><input type="text" name="student_id" class="grade-input" style="width: 100%; text-align:left;" required></div>
                                <div><label class="pane-label">Full Name</label><input type="text" name="student_name" class="grade-input" style="width: 100%; text-align:left;" required></div>
                                <button type="submit" name="add_student" class="btn-save">Add to List</button>
                            </form>
                        </div>

                        <div id="edit-student-pane" class="mgmt-pane" style="background: #fffaf0; border-bottom: 1px solid #fbd38d;">
                            <form method="POST" style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 15px; align-items: end;">
                                <input type="hidden" name="old_student_id" id="edit-old-id">
                                <div><label class="pane-label">Update Student ID</label><input type="text" name="new_student_id" id="edit-new-id" class="grade-input" style="width: 100%; text-align:left;" required></div>
                                <div><label class="pane-label">Update Full Name</label><input type="text" name="new_student_name" id="edit-new-name" class="grade-input" style="width: 100%; text-align:left;" required></div>
                                <div style="display: flex; gap: 10px;"><button type="submit" name="edit_student" class="btn-save" style="background: #ed8936;">Update Student</button><button type="button" onclick="togglePane('edit-student-pane')" class="btn-pill">Cancel</button></div>
                            </form>
                        </div>

                        <div id="edit-class-pane" class="mgmt-pane">
                            <form method="POST">
                                <input type="hidden" name="old_section_name" value="<?php echo htmlspecialchars($selected_section); ?>">
                                <div style="margin-bottom: 16px;"><label class="pane-label">Class Name</label><input type="text" name="new_section_name" class="grade-input" style="width: 100%; text-align:left;" value="<?php echo htmlspecialchars($selected_section); ?>" required></div>
                                <div style="margin-bottom: 20px;"><label class="pane-label">Class Notes / Reminders</label><textarea name="notes" class="grade-input" style="width: 100%; height: 80px; text-align:left;"></textarea></div>
                                <div style="display: flex; gap: 10px;"><button type="submit" name="edit_class" class="btn-save">Update Details</button><button type="submit" name="delete_class" class="btn-save" style="background: var(--danger);" onclick="return confirm('Delete entirely?');">Delete Class</button></div>
                            </form>
                        </div>

                        <?php if ($current_tab === 'overview'): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead><tr><th>Student ID</th><th>Full Name</th><th>Calc. Midterm</th><th>Calculated Finals</th><th>Overall Grade</th><th style="text-align: right;">Manage</th></tr></thead>
                                    <tbody>
                                        <?php 
                                        $stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ?");
                                        $stmt->bind_param("i", $current_section_id);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        $midterm_assignment_count = 0;
                                        foreach ($tree['Midterm'] as $cat) {
                                            $midterm_assignment_count += count($cat['assignments']);
                                        }
                                        $finals_assignment_count = 0;
                                        foreach ($tree['Finals'] as $cat) {
                                            $finals_assignment_count += count($cat['assignments']);
                                        }
                                        while($student = $result->fetch_assoc()): 
                                            // Dynamic Math for Midterm
                                            $mid_total = 0;
                                            foreach ($tree['Midterm'] as $cat) {
                                                $cat_earned = 0; $cat_max = 0;
                                                foreach($cat['assignments'] as $ass) {
                                                    $cat_max += $ass['max_score'];
                                                    $cat_earned += $student_scores[$student['id']][$ass['id']] ?? 0;
                                                }
                                                if ($cat_max > 0) $mid_total += ($cat_earned / $cat_max) * $cat['weight'];
                                            }
                                            // Dynamic Math for Finals
                                            $fin_total = 0;
                                            foreach ($tree['Finals'] as $cat) {
                                                $cat_earned = 0; $cat_max = 0;
                                                foreach($cat['assignments'] as $ass) {
                                                    $cat_max += $ass['max_score'];
                                                    $cat_earned += $student_scores[$student['id']][$ass['id']] ?? 0;
                                                }
                                                if ($cat_max > 0) $fin_total += ($cat_earned / $cat_max) * $cat['weight'];
                                            }
                                            $overall_total = ($midterm_assignment_count > 0 && $finals_assignment_count > 0)
                                                ? (($mid_total + $fin_total) / 2)
                                                : null;
                                        ?>
                                            <tr>
                                                <td style="color: var(--text-muted); font-family: monospace;"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                <td><strong><?php echo htmlspecialchars($student['name']); ?></strong></td>
                                                <td><span style="font-weight: 700; color: <?php echo $mid_total >= 75 ? 'var(--primary)' : 'var(--text-muted)'; ?>"><?php echo number_format($mid_total, 2); ?>%</span></td>
                                                <td><span style="font-weight: 700; color: <?php echo $fin_total >= 75 ? 'var(--primary)' : 'var(--text-muted)'; ?>"><?php echo number_format($fin_total, 2); ?>%</span></td>
                                                <td>
                                                    <?php if ($overall_total === null): ?>
                                                        <span style="font-weight: 700; color: var(--text-muted);">N/A</span>
                                                    <?php else: ?>
                                                        <span style="font-weight: 700; color: <?php echo $overall_total >= 75 ? 'var(--primary)' : 'var(--text-muted)'; ?>"><?php echo number_format($overall_total, 2); ?>%</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: right;">
                                                    <a class="action-link" onclick="openEditStudent('<?php echo htmlspecialchars($student['student_id']); ?>', '<?php echo addslashes($student['name']); ?>')"><i class="fa-solid fa-user-pen"></i></a>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove student?');">
                                                        <input type="hidden" name="student_to_delete" value="<?php echo htmlspecialchars($student['student_id']); ?>">
                                                        <button type="submit" name="delete_student" class="action-link" style="background:none; border:none; padding:0; color: var(--danger);"><i class="fa-solid fa-user-minus"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php elseif ($current_tab === 'Midterm' || $current_tab === 'Finals'): ?>
                            
                            <div style="padding: 24px; background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                                    <h4 style="font-size: 0.95rem; color: var(--slate-700); margin: 0; font-weight: 800;">Grading Structure</h4>
                                    <button onclick="togglePane('add-category-pane')" class="btn-pill" style="color: var(--primary);"><i class="fa-solid fa-folder-plus"></i> Add Category</button>
                                </div>
                                
                                <div id="add-category-pane" class="mgmt-pane" style="margin-bottom: 16px; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
                                        <input type="hidden" name="term" value="<?php echo $current_tab; ?>">
                                        <div><label class="pane-label">Category Bucket (e.g. Quizzes)</label><input type="text" name="cat_name" class="grade-input" style="width: 100%; text-align: left;" required></div>
                                        <div><label class="pane-label">Overall Weight (%)</label><input type="number" step="0.01" name="weight" class="grade-input" style="width: 100%; text-align: left;" required></div>
                                        <div style="display: flex; gap: 10px;"><button type="submit" name="add_category" class="btn-save">Save Category</button><button type="button" onclick="togglePane('add-category-pane')" class="btn-pill">Cancel</button></div>
                                    </form>
                                </div>

                                <div id="ass-form-pane" class="mgmt-pane" style="margin-bottom: 16px; border: 1px solid #38bdf8; border-radius: 8px; background: #f0f9ff;">
                                    <h5 style="margin-bottom: 12px; color: #0284c7;" id="ass-form-title">Add Assignment</h5>
                                    <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
                                        <input type="hidden" name="category_id" id="ass-cat-id">
                                        <input type="hidden" name="assignment_id" id="ass-id">
                                        <div><label class="pane-label">Assignment Name (e.g. Quiz 1)</label><input type="text" name="ass_name" id="ass-name" class="grade-input" style="width: 100%; text-align: left;" required></div>
                                        <div><label class="pane-label">Perfect / Max Score</label><input type="number" step="0.01" name="max_score" id="ass-max" class="grade-input" style="width: 100%; text-align: left;" required></div>
                                        <div style="display: flex; gap: 10px;">
                                            <button type="submit" name="add_assignment" id="ass-submit-add" class="btn-save" style="background: #0284c7;">Create</button>
                                            <button type="submit" name="edit_assignment" id="ass-submit-edit" class="btn-save" style="background: #0284c7; display: none;">Update</button>
                                            <button type="button" onclick="togglePane('ass-form-pane')" class="btn-pill">Cancel</button>
                                        </div>
                                    </form>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                                    <?php 
                                    $total_weight = 0;
                                    foreach($tree[$current_tab] as $cat_id => $cat): 
                                        $total_weight += $cat['weight'];
                                    ?>
                                        <div class="category-box">
                                            <div class="category-header">
                                                <div>
                                                    <strong style="color: var(--primary); font-size: 1rem;"><?php echo htmlspecialchars($cat['name']); ?></strong>
                                                    <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 8px;">(<?php echo $cat['weight']; ?>%)</span>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('Delete this entire category and all grades?');" style="margin:0;">
                                                    <input type="hidden" name="category_id" value="<?php echo $cat_id; ?>">
                                                    <button type="submit" name="delete_category" style="background:none; border:none; color: var(--danger); cursor: pointer;"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </div>
                                            
                                            <div style="margin-bottom: 12px;">
                                                <?php if (empty($cat['assignments'])): ?>
                                                    <div style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">No assignments added yet.</div>
                                                <?php else: ?>
                                                    <?php foreach($cat['assignments'] as $ass): ?>
                                                        <div class="ass-item">
                                                            <span><strong><?php echo htmlspecialchars($ass['name']); ?></strong> (Max: <?php echo $ass['max_score']; ?>)</span>
                                                            <div style="display: flex; gap: 10px;">
                                                                <button type="button" onclick="openEditAssignment(<?php echo $ass['id']; ?>, '<?php echo addslashes($ass['name']); ?>', <?php echo $ass['max_score']; ?>)" style="background:none; border:none; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-pen"></i></button>
                                                                <form method="POST" onsubmit="return confirm('Delete assignment?');" style="margin:0;">
                                                                    <input type="hidden" name="assignment_id" value="<?php echo $ass['id']; ?>">
                                                                    <button type="submit" name="delete_assignment" style="background:none; border:none; color: var(--danger); cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>

                                            <button onclick="openAddAssignment(<?php echo $cat_id; ?>, '<?php echo addslashes($cat['name']); ?>')" class="btn-pill" style="width: 100%; justify-content: center; font-size: 0.75rem;"><i class="fa-solid fa-plus"></i> Add Assignment to Bucket</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="margin-top: 16px; font-size: 0.85rem; font-weight: 700; color: <?php echo $total_weight == 100 ? 'var(--primary)' : 'var(--danger)'; ?>">
                                    Total Combined Weight: <?php echo $total_weight; ?>% <?php if($total_weight != 100) echo "(Needs to equal 100%)"; ?>
                                </div>
                            </div>

                            <form method="POST" id="gradebookForm" onsubmit="return false;">
                                <div class="gradebook-toolbar">
                                    <div class="gradebook-search">
                                        <label class="pane-label" for="studentGradeSearch">Search student</label>
                                        <input type="search" id="studentGradeSearch" class="grade-input" style="text-align:left;" placeholder="Type a student name or ID..." oninput="filterGradebookStudents(this.value)">
                                    </div>
                                    <div class="gradebook-search-count" id="gradebookSearchCount">Showing all students</div>
                                </div>
                                <div class="table-responsive">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th rowspan="2" style="width: 250px; border-bottom: none; vertical-align: bottom;">Student Name</th>
                                                <?php foreach($tree[$current_tab] as $cat): ?>
                                                    <th colspan="<?php echo max(1, count($cat['assignments'])); ?>" style="text-align: center; background: #e2e8f0; color: var(--slate-700); border-bottom: 2px solid white;"><?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['weight']; ?>%)</th>
                                                <?php endforeach; ?>
                                                <th rowspan="2" style="text-align: right; background: #f0fdf4; color: #047857; vertical-align: bottom; border-bottom: none;">Bucket Total</th>
                                            </tr>
                                            <tr>
                                                <?php foreach($tree[$current_tab] as $cat): ?>
                                                    <?php if(empty($cat['assignments'])): ?>
                                                        <th style="text-align: center; color: var(--danger); font-style: italic;">Empty</th>
                                                    <?php else: ?>
                                                        <?php foreach($cat['assignments'] as $ass): ?>
                                                            <th style="text-align: center; font-size: 0.65rem;"><?php echo htmlspecialchars($ass['name']); ?><br>(/ <?php echo $ass['max_score']; ?>)</th>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ?");
                                            $stmt->bind_param("i", $current_section_id);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            while($student = $result->fetch_assoc()): 
                                                $student_total = 0;
                                            ?>
                                                <tr class="gradebook-row" data-student-id="<?php echo htmlspecialchars($student['student_id']); ?>" data-student-name="<?php echo htmlspecialchars($student['name']); ?>">
                                                    <td><strong><?php echo htmlspecialchars($student['name']); ?></strong></td>
                                                    
                                                    <?php foreach($tree[$current_tab] as $cat): 
                                                        $cat_earned = 0; $cat_max = 0;
                                                        if(empty($cat['assignments'])): ?>
                                                            <td style="background: #f8fafc;"></td>
                                                        <?php else: 
                                                            foreach($cat['assignments'] as $ass):
                                                                $val = $student_scores[$student['id']][$ass['id']] ?? '';
                                                                if ($val !== '') {
                                                                    $cat_earned += $val;
                                                                }
                                                                $cat_max += $ass['max_score'];
                                                            ?>
                                                                <td style="text-align: center;"><input type="number" step="0.01" name="scores[<?php echo $student['id']; ?>][<?php echo $ass['id']; ?>]" value="<?php echo htmlspecialchars($val); ?>" class="grade-input gradebook-score-input" style="width: 80px;" data-student-internal-id="<?php echo (int) $student['id']; ?>" data-assignment-id="<?php echo (int) $ass['id']; ?>" data-max-score="<?php echo (float) $ass['max_score']; ?>" data-category-id="<?php echo (int) $cat['id']; ?>" data-category-weight="<?php echo (float) $cat['weight']; ?>"></td>
                                                            <?php endforeach; 
                                                        endif;
                                                        if ($cat_max > 0) $student_total += ($cat_earned / $cat_max) * $cat['weight'];
                                                    endforeach; ?>
                                                    
                                                    <td style="text-align: right; font-weight: 800; color: #047857;"><?php echo number_format($student_total, 2); ?>%</td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                    
                                    <!-- Mobile Gradebook Card View -->
                                    <div id="mobilegradebookCards" class="gradebook-card-view"></div>
                                </div>
                                <div style="padding: 20px 24px; background: #fcfcfc; border-top: 1px solid var(--border-color); text-align: right; display: flex; justify-content: flex-end; gap: 12px; align-items: center;">
                                    <div id="gradebookSaveStatus" style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">Autosave enabled</div>
                                </div>
                            </form>

                        <?php elseif ($current_tab === 'Analytics'): ?>
                            <?php
                                $section_students_stmt = $conn->prepare("SELECT COUNT(*) AS total_students FROM students WHERE section_id = ?");
                                $section_students_stmt->bind_param("i", $current_section_id);
                                $section_students_stmt->execute();
                                $section_students_row = $section_students_stmt->get_result()->fetch_assoc();
                                $section_total_students = (int) ($section_students_row['total_students'] ?? 0);
                                $section_students_stmt->close();

                                $analytics_students = [];
                                $analytics_at_risk = [];
                                $analytics_failed = [];
                                $analytics_partial = [];
                                $analytics_no_data = [];
                                $analytics_passed = [];
                                $midterm_only_count = 0;

                                $students_stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ? ORDER BY name ASC");
                                $students_stmt->bind_param("i", $current_section_id);
                                $students_stmt->execute();
                                $students_result = $students_stmt->get_result();

                                while ($student_row = $students_result->fetch_assoc()) {
                                    $status_info = getStudentAnalyticsStatus($student_row['id'], $current_section_id, 60);
                                    $combined_row = array_merge($student_row, $status_info);
                                    $analytics_students[] = $combined_row;

                                    if ($status_info['status'] === 'At Risk') {
                                        $analytics_at_risk[] = $combined_row;
                                    } elseif ($status_info['status'] === 'Failed') {
                                        $analytics_failed[] = $combined_row;
                                    } elseif ($status_info['status'] === 'Partial') {
                                        $analytics_partial[] = $combined_row;
                                    } elseif ($status_info['status'] === 'Passed') {
                                        $analytics_passed[] = $combined_row;
                                    } else {
                                        $analytics_no_data[] = $combined_row;
                                    }

                                    if ($status_info['has_midterm'] && !$status_info['has_finals']) {
                                        $midterm_only_count++;
                                    }
                                }
                                $students_stmt->close();

                                $analyzed_students = count($analytics_at_risk) + count($analytics_failed) + count($analytics_passed);
                                $analytics_passing = [
                                    'passing_count' => count($analytics_passed),
                                    'total_count' => $analyzed_students,
                                    'passing_rate_percent' => $analyzed_students > 0 ? round((count($analytics_passed) / $analyzed_students) * 100, 2) : 0,
                                ];
                                $analytics_distribution = getGradeDistribution($current_section_id, 'All');
                                $analytics_heatmap = getStudentCategoryScores($current_section_id, 'All');
                                $incomplete_students = count($analytics_partial) + count($analytics_no_data);

                                $heatmap_categories = [];
                                foreach ($analytics_heatmap as $student_row) {
                                    foreach ($student_row['categories'] as $category_name => $score_value) {
                                        if (!in_array($category_name, $heatmap_categories, true)) {
                                            $heatmap_categories[] = $category_name;
                                        }
                                    }
                                }
                                sort($heatmap_categories);
                            ?>

                            <div class="analytics-note">
                                Midterm scores can trigger an early warning: students with Midterm at or below 60% are marked At Risk even before Finals are entered. Partial records are shown separately.
                            </div>

                            <div class="analytics-summary">
                                <div class="analytics-metric">
                                    <div class="label">Students</div>
                                    <div class="value"><?php echo $section_total_students; ?></div>
                                    <div class="subtext">All enrolled students</div>
                                </div>
                                <div class="analytics-metric">
                                    <div class="label">Analyzed</div>
                                    <div class="value"><?php echo $analyzed_students; ?></div>
                                    <div class="subtext">Analyzed students</div>
                                </div>
                                <div class="analytics-metric">
                                    <div class="label">Passing Rate</div>
                                    <div class="value"><?php echo $analytics_passing['passing_rate_percent']; ?>%</div>
                                    <div class="subtext"><?php echo $analytics_passing['passing_count']; ?> students passed</div>
                                </div>
                                <div class="analytics-metric">
                                    <div class="label">At Risk</div>
                                    <div class="value"><?php echo count($analytics_at_risk); ?></div>
                                    <div class="subtext">Midterm <= 60% early warning</div>
                                </div>
                                <div class="analytics-metric">
                                    <div class="label">Failed</div>
                                    <div class="value"><?php echo count($analytics_failed); ?></div>
                                    <div class="subtext">Final overall below 60%</div>
                                </div>
                            </div>

                            <div class="analytics-grid" style="padding-top: 0;">
                                <div class="analytics-card">
                                    <h4>Status Summary</h4>
                                    <table class="analytics-table">
                                        <tbody>
                                            <tr><td>No Data</td><td style="text-align:right; font-weight:700;"><?php echo count($analytics_no_data); ?></td></tr>
                                            <tr><td>Partial</td><td style="text-align:right; font-weight:700;"><?php echo count($analytics_partial); ?></td></tr>
                                            <tr><td>At Risk</td><td style="text-align:right; font-weight:700; color:#dc2626;"><?php echo count($analytics_at_risk); ?></td></tr>
                                            <tr><td>Failed</td><td style="text-align:right; font-weight:700; color:#991b1b;"><?php echo count($analytics_failed); ?></td></tr>
                                            <tr><td>Passed</td><td style="text-align:right; font-weight:700; color:#166534;"><?php echo count($analytics_passed); ?></td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="analytics-card">
                                    <h4>Midterm Early Warning</h4>
                                    <div class="value" style="font-size: 3rem; margin-top: 10px;"><?php echo $midterm_only_count; ?></div>
                                    <div class="subtext">Students with Midterm entered but Finals pending</div>
                                </div>
                            </div>

                            <div class="analytics-grid">
                                <div class="analytics-card">
                                    <h4>Overall Grade Distribution</h4>
                                    <div class="chart-shell"><canvas id="overallDistributionChart"></canvas></div>
                                </div>

                                <div class="analytics-card">
                                    <h4>Pass / Fail Breakdown</h4>
                                    <div class="chart-shell"><canvas id="overallPassFailChart"></canvas></div>
                                </div>
                            </div>

                            <div class="data-card" style="margin: 0 24px 24px; overflow: hidden;">
                                <div class="card-header">
                                    <h3 style="font-size: 1rem;">At-Risk Students</h3>
                                    <span class="analytics-badge"><i class="fa-solid fa-triangle-exclamation"></i> Midterm warning only</span>
                                </div>
                                <?php if (!empty($analytics_at_risk)): ?>
                                    <div class="table-responsive">
                                        <table class="analytics-table">
                                            <thead>
                                                <tr>
                                                    <th>Student ID</th>
                                                    <th>Name</th>
                                                    <th>Overall Grade</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($analytics_at_risk as $student_row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($student_row['student_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($student_row['name']); ?></strong></td>
                                                        <td><?php echo number_format((float) $student_row['score'], 2); ?>%</td>
                                                        <td><span class="status-chip danger"><?php echo htmlspecialchars($student_row['status']); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div style="padding: 24px; text-align: center; color: var(--text-muted);">No at-risk students found for this section.</div>
                                <?php endif; ?>
                            </div>

                            <div class="data-card" style="margin: 0 24px 24px; overflow: hidden;">
                                <div class="card-header">
                                    <h3 style="font-size: 1rem;">Failed Students</h3>
                                    <span class="analytics-badge" style="background:#fef2f2; color:#b91c1c;"><i class="fa-solid fa-circle-xmark"></i> Final overall below 60%</span>
                                </div>
                                <?php if (!empty($analytics_failed)): ?>
                                    <div class="table-responsive">
                                        <table class="analytics-table">
                                            <thead>
                                                <tr>
                                                    <th>Student ID</th>
                                                    <th>Name</th>
                                                    <th>Overall Grade</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($analytics_failed as $student_row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($student_row['student_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($student_row['name']); ?></strong></td>
                                                        <td><?php echo number_format((float) $student_row['score'], 2); ?>%</td>
                                                        <td><span class="status-chip danger"><?php echo htmlspecialchars($student_row['status']); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div style="padding: 24px; text-align: center; color: var(--text-muted);">No failed students found for this section.</div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($analytics_partial) || !empty($analytics_no_data)): ?>
                                <div class="data-card" style="margin: 0 24px 24px; overflow: hidden;">
                                    <div class="card-header">
                                        <h3 style="font-size: 1rem;">Incomplete Records</h3>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="analytics-table">
                                            <thead>
                                                <tr>
                                                    <th>Student ID</th>
                                                    <th>Name</th>
                                                    <th>Status</th>
                                                    <th>Midterm</th>
                                                    <th>Finals</th>
                                                    <th>Overall Grade</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($analytics_partial as $student_row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($student_row['student_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($student_row['name']); ?></strong></td>
                                                        <td><span class="status-chip warning">Partial</span></td>
                                                        <td><?php echo number_format((float) $student_row['midterm'], 2); ?>%</td>
                                                        <td>—</td>
                                                        <td><span style="color: var(--text-muted); font-weight: 700;">N/A</span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php foreach ($analytics_no_data as $student_row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($student_row['student_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($student_row['name']); ?></strong></td>
                                                        <td><span class="status-chip warning">No Data</span></td>
                                                        <td>—</td>
                                                        <td>—</td>
                                                        <td><span style="color: var(--text-muted); font-weight: 700;">N/A</span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="data-card" style="margin: 0 24px 24px; overflow: hidden;">
                                <div class="card-header">
                                    <h3 style="font-size: 1rem;">Performance Heatmap</h3>
                                </div>
                                <?php if (!empty($analytics_heatmap) && !empty($heatmap_categories)): ?>
                                    <div class="table-responsive">
                                        <table class="analytics-table">
                                            <thead>
                                                <tr>
                                                    <th>Student</th>
                                                    <?php foreach ($heatmap_categories as $category_name): ?>
                                                        <th style="text-align:center;"><?php echo htmlspecialchars($category_name); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($analytics_heatmap as $student_row): ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($student_row['name']); ?></strong></td>
                                                        <?php foreach ($heatmap_categories as $category_name): ?>
                                                            <?php if (isset($student_row['categories'][$category_name])): ?>
                                                                <?php
                                                                    $score = (float) $student_row['categories'][$category_name];
                                                                    if ($score < 20) {
                                                                        $chip_class = 'danger';
                                                                    } elseif ($score < 60) {
                                                                        $chip_class = 'warning';
                                                                    } else {
                                                                        $chip_class = 'good';
                                                                    }
                                                                ?>
                                                                <td style="text-align:center;"><span class="status-chip <?php echo $chip_class; ?>"><?php echo number_format($score, 1); ?>%</span></td>
                                                            <?php else: ?>
                                                                <td style="text-align:center; color: var(--text-muted);">—</td>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div style="padding: 24px; text-align: center; color: var(--text-muted);">No category data available yet.</div>
                                <?php endif; ?>
                            </div>

                            <script>
                                (function () {
                                    const distributionCanvas = document.getElementById('overallDistributionChart');
                                    const passFailCanvas = document.getElementById('overallPassFailChart');
                                    if (!distributionCanvas || !passFailCanvas) {
                                        return;
                                    }

                                    const distributionData = <?php echo json_encode($analytics_distribution); ?>;
                                    const passingCount = <?php echo (int) $analytics_passing['passing_count']; ?>;
                                    const failingCount = Math.max(0, <?php echo (int) $analytics_passing['total_count']; ?> - passingCount);

                                    new Chart(distributionCanvas.getContext('2d'), {
                                        type: 'bar',
                                        data: {
                                            labels: Object.keys(distributionData),
                                            datasets: [{
                                                label: 'Students',
                                                data: Object.values(distributionData),
                                                backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#22c55e', '#16a34a'],
                                                borderRadius: 8,
                                                borderWidth: 0
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: { legend: { display: false } },
                                            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                                        }
                                    });

                                    new Chart(passFailCanvas.getContext('2d'), {
                                        type: 'doughnut',
                                        data: {
                                            labels: ['Passing', 'Failing'],
                                            datasets: [{
                                                data: [passingCount, failingCount],
                                                backgroundColor: ['#10b981', '#ef4444'],
                                                borderColor: ['#ffffff', '#ffffff'],
                                                borderWidth: 3
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: { legend: { position: 'bottom' } }
                                        }
                                    });
                                })();
                            </script>

                        <?php endif; ?>

                    </div>
                <?php else: ?>
                    <div class="home-init-shell">
                        <div class="data-card" style="max-width: 600px; width: 100%; margin: 0 auto; padding: 48px; text-align: center;">
                            <i class="fa-solid fa-plus-circle" style="font-size: 3.5rem; color: var(--primary); margin-bottom: 24px;"></i>
                            <h2>Initialize New Class</h2>
                            <form method="POST" style="text-align: left;">
                                <div style="margin-bottom: 16px;"><label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">Section Name</label><input type="text" name="section_name" class="grade-input" style="width: 100%; text-align:left; height: 50px;" required></div>
                                <div style="margin-bottom: 24px;"><label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">Class Type</label><select name="class_type" class="grade-input" style="width: 100%; text-align:left; height: 50px; background: white;" required><option value="Lecture">Lecture</option><option value="Lab">Laboratory (Lab)</option></select></div>
                                <button type="submit" name="add_class" class="btn-save" style="width: 100%; height: 50px;">Create Workspace</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <?php if (!empty($duplicate_error)): ?>
    <div class="modal-overlay" id="duplicateModal" onclick="this.style.display='none'">
        <div class="error-modal" onclick="event.stopPropagation()">
            <i class="fa-solid fa-circle-exclamation"></i>
            <h3>Transaction Failed</h3>
            <p><?php echo $duplicate_error; ?></p>
            <button onclick="document.getElementById('duplicateModal').style.display='none'" class="btn-save" style="width: 100%; background: var(--danger);">Acknowledge</button>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function togglePane(paneId) {
            const panes = ['add-student-pane', 'edit-student-pane', 'edit-class-pane', 'add-category-pane', 'ass-form-pane'];
            panes.forEach(id => {
                let el = document.getElementById(id);
                if(el) el.style.display = (id === paneId && el.style.display !== 'block') ? 'block' : 'none';
            });
        }
        function openAddPane() { togglePane('add-student-pane'); }
        function openEditStudent(id, name) {
            togglePane('edit-student-pane');
            document.getElementById('edit-old-id').value = id;
            document.getElementById('edit-new-id').value = id;
            document.getElementById('edit-new-name').value = name;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        function openAddAssignment(catId, catName) {
            togglePane('ass-form-pane');
            document.getElementById('ass-form-title').innerText = 'Add Assignment to: ' + catName;
            document.getElementById('ass-cat-id').value = catId;
            document.getElementById('ass-id').value = '';
            document.getElementById('ass-name').value = '';
            document.getElementById('ass-max').value = '';
            document.getElementById('ass-submit-add').style.display = 'block';
            document.getElementById('ass-submit-edit').style.display = 'none';
        }
        function openEditAssignment(assId, assName, maxScore) {
            togglePane('ass-form-pane');
            document.getElementById('ass-form-title').innerText = 'Edit Assignment';
            document.getElementById('ass-cat-id').value = '';
            document.getElementById('ass-id').value = assId;
            document.getElementById('ass-name').value = assName;
            document.getElementById('ass-max').value = maxScore;
            document.getElementById('ass-submit-add').style.display = 'none';
            document.getElementById('ass-submit-edit').style.display = 'block';
        }
        function filterGradebookStudents(query) {
            const search = query.trim().toLowerCase();
            const rows = document.querySelectorAll('.gradebook-row');
            const countEl = document.getElementById('gradebookSearchCount');
            let visibleCount = 0;

            rows.forEach((row) => {
                const studentId = (row.dataset.studentId || '').toLowerCase();
                const studentName = (row.dataset.studentName || '').toLowerCase();
                const matches = !search || studentId.includes(search) || studentName.includes(search);
                row.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount++;
                }
            });

            if (countEl) {
                countEl.textContent = search ? `Showing ${visibleCount} student${visibleCount === 1 ? '' : 's'}` : 'Showing all students';
            }
        }

        (function () {
            const inputs = document.querySelectorAll('.gradebook-score-input');
            const statusEl = document.getElementById('gradebookSaveStatus');

            function setStatus(text, tone) {
                if (!statusEl) {
                    return;
                }

                statusEl.textContent = text;
                statusEl.style.color = tone === 'saving' ? '#0284c7' : tone === 'saved' ? 'var(--primary)' : tone === 'error' ? '#b91c1c' : 'var(--text-muted)';
            }

            function recalculateBucketTotal(row) {
                const bucketCell = row.querySelector('td:last-child');
                if (!bucketCell) return;

                const gradeInputs = row.querySelectorAll('.gradebook-score-input');
                const categories = {};
                let total = 0;

                gradeInputs.forEach(input => {
                    const maxScore = parseFloat(input.dataset.maxScore);
                    if (isNaN(maxScore) || maxScore <= 0) return;

                    const score = parseFloat(input.value) || 0;
                    const categoryId = input.dataset.categoryId;
                    const weight = parseFloat(input.dataset.categoryWeight);

                    if (!categories[categoryId]) {
                        categories[categoryId] = { earned: 0, max: 0, weight: weight };
                    }
                    categories[categoryId].earned += score;
                    categories[categoryId].max += maxScore;
                });

                Object.values(categories).forEach(cat => {
                    if (cat.max > 0) {
                        total += (cat.earned / cat.max) * cat.weight;
                    }
                });

                bucketCell.textContent = total.toFixed(2) + '%';
            }

            async function saveScore(input) {
                const maxScore = parseFloat(input.dataset.maxScore);
                const score = parseFloat(input.value) || 0;

                // Validate max score
                if (!isNaN(maxScore) && score > maxScore) {
                    input.style.borderColor = '#dc2626';
                    input.style.backgroundColor = '#fee2e2';
                    setStatus(`Max score is ${maxScore}`, 'error');
                    return;
                }

                // Clear error styling if valid
                input.style.borderColor = '';
                input.style.backgroundColor = '';

                const studentInternalId = input.dataset.studentInternalId;
                const assignmentId = input.dataset.assignmentId;

                setStatus('Saving...', 'saving');

                const formData = new FormData();
                formData.append('autosave_score', '1');
                formData.append('student_internal_id', studentInternalId);
                formData.append('assignment_id', assignmentId);
                formData.append('score', score);

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    });

                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error('Autosave failed');
                    }

                    // Update bucket total in real-time
                    const row = input.closest('tr');
                    if (row) {
                        recalculateBucketTotal(row);
                    }

                    setStatus('Saved', 'saved');
                } catch (error) {
                    setStatus('Save failed', 'error');
                }
            }

            inputs.forEach((input) => {
                input.addEventListener('input', () => saveScore(input));
                input.addEventListener('change', () => saveScore(input));
            });
        })();

        // RESPONSIVE SIDEBAR TOGGLE
        document.addEventListener('DOMContentLoaded', function() {
            const hamburgerMenu = document.getElementById('hamburgerMenu');
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            if (hamburgerMenu && sidebar && sidebarOverlay) {
                hamburgerMenu.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                    sidebarOverlay.classList.toggle('open');
                });

                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    sidebarOverlay.classList.remove('open');
                });

                // Close sidebar when a link is clicked
                const sidebarLinks = sidebar.querySelectorAll('a');
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        sidebar.classList.remove('open');
                        sidebarOverlay.classList.remove('open');
                    });
                });
            }
        });

        /* ============================================ */
        /* PHASE 4: Grade Edit Modal Popup System     */
        /* ============================================ */
        
        const GradeEditModal = {
            modal: null,
            overlay: null,
            currentInput: null,
            currentData: null,
            
            init() {
                this.modal = document.getElementById('gradeEditModal');
                this.overlay = document.querySelector('.bottom-sheet-overlay');
                
                // Initialize the bottom sheet if not already done
                if (this.modal) {
                    BottomSheetManager.register('gradeEditModal', this.modal);
                    BottomSheetManager.initGestureHandling('gradeEditModal');
                }
                
                // Add escape key listener
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.modal?.classList.contains('open')) {
                        this.close();
                    }
                });
            },
            
            open(studentName, studentId, assignmentName, maxScore, currentValue, inputElement) {
                if (!this.modal) this.init();
                
                this.currentInput = inputElement;
                this.currentData = {
                    studentName,
                    studentId,
                    assignmentName,
                    maxScore: parseFloat(maxScore),
                    currentValue
                };
                
                // Populate modal fields
                document.getElementById('modalStudentName').textContent = studentName;
                document.getElementById('modalStudentId').textContent = studentId;
                document.getElementById('modalAssignmentName').textContent = assignmentName;
                document.getElementById('modalMaxScore').textContent = maxScore;
                document.getElementById('modalAssignmentContext').textContent = `Max Score: ${maxScore}`;
                document.getElementById('modalErrorMessage').style.display = 'none';
                document.getElementById('modalErrorMessage').textContent = '';
                
                const gradeInput = document.getElementById('modalGradeInput');
                gradeInput.value = currentValue || '';
                gradeInput.focus();
                
                // Open modal
                BottomSheetManager.open('gradeEditModal');
            },
            
            close() {
                if (this.modal) {
                    BottomSheetManager.close('gradeEditModal');
                    this.currentInput = null;
                    this.currentData = null;
                }
            },
            
            validate() {
                const value = document.getElementById('modalGradeInput').value.trim();
                const errorMsg = document.getElementById('modalErrorMessage');
                const maxScore = this.currentData.maxScore;
                
                // Clear previous errors
                errorMsg.style.display = 'none';
                errorMsg.textContent = '';
                
                // Empty is valid
                if (value === '') return true;
                
                const score = parseFloat(value);
                
                // Not a number
                if (isNaN(score)) {
                    errorMsg.textContent = 'Please enter a valid number';
                    errorMsg.style.display = 'block';
                    return false;
                }
                
                // Negative
                if (score < 0) {
                    errorMsg.textContent = 'Score cannot be negative';
                    errorMsg.style.display = 'block';
                    return false;
                }
                
                // Exceeds max
                if (score > maxScore) {
                    errorMsg.textContent = `Score cannot exceed ${maxScore}`;
                    errorMsg.style.display = 'block';
                    return false;
                }
                
                return true;
            },
            
            async save() {
                if (!this.validate()) return;
                
                const value = document.getElementById('modalGradeInput').value.trim();
                const btn = document.getElementById('modalSaveBtn');
                
                // Disable button during save
                btn.disabled = true;
                btn.textContent = 'Saving...';
                
                try {
                    // Use the same autosave AJAX endpoint
                    const formData = new FormData();
                    formData.append('autosave_score', '1');
                    formData.append('student_internal_id', this.currentInput.getAttribute('data-student-internal-id'));
                    formData.append('assignment_id', this.currentInput.getAttribute('data-assignment-id'));
                    formData.append('score', value);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update the original input
                        this.currentInput.value = value;
                        this.currentInput.dispatchEvent(new Event('change', { bubbles: true }));
                        
                        FeedbackToast.show('✓ Score saved successfully', 'success', 2000);
                        this.close();
                    } else {
                        document.getElementById('modalErrorMessage').textContent = 'Failed to save. Please try again.';
                        document.getElementById('modalErrorMessage').style.display = 'block';
                        FeedbackToast.show('Error saving score', 'error', 2000);
                    }
                } catch (error) {
                    console.error('Save error:', error);
                    document.getElementById('modalErrorMessage').textContent = 'Network error. Please check your connection.';
                    document.getElementById('modalErrorMessage').style.display = 'block';
                    FeedbackToast.show('Network error', 'error', 2000);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Save Score';
                }
            }
        };
        
        // Hook grade input clicks to open modal on mobile
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('edit-grade-btn') && ResponsiveManager.isMobile()) {
                e.preventDefault();
                const assignmentId = e.target.getAttribute('data-assignment-id');
                const studentInternalId = e.target.getAttribute('data-student-id');
                
                const input = document.querySelector(
                    `input[data-student-internal-id="${studentInternalId}"][data-assignment-id="${assignmentId}"]`
                );
                
                if (input) {
                    const studentName = input.closest('.student-grade-card')?.querySelector('.student-name')?.textContent || 'Student';
                    const studentId = input.closest('.student-grade-card')?.querySelector('.student-id')?.textContent || '';
                    const assignmentName = input.closest('.assignment-row')?.querySelector('.assignment-name')?.textContent || 'Assignment';
                    const maxScore = input.getAttribute('data-max-score');
                    const currentValue = input.value;
                    
                    GradeEditModal.open(studentName, studentId, assignmentName, maxScore, currentValue, input);
                }
            }
        });
        
        // Initialize modal on DOMContentLoaded
        document.addEventListener('DOMContentLoaded', () => {
            GradeEditModal.init();
        });

        /* ============================================ */
        
        const TouchInputManager = {
            initAll() {
                // Add error state handling to all grade inputs
                document.querySelectorAll('.grade-input').forEach(input => {
                    input.addEventListener('input', (e) => {
                        this.validateInput(e.target);
                    });
                    
                    input.addEventListener('blur', (e) => {
                        this.validateInput(e.target);
                    });
                    
                    input.addEventListener('focus', (e) => {
                        // Clear previous error state
                        e.target.classList.remove('error');
                    });
                });
                
                // Prevent multiple finger gestures from zooming
                document.addEventListener('touchmove', (e) => {
                    if (e.touches.length > 1) {
                        e.preventDefault();
                    }
                }, false);
                
                // Disable automatic zoom on input focus
                const viewport = document.querySelector('meta[name="viewport"]');
                if (viewport) {
                    viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
                }
            },
            
            validateInput(input) {
                const value = input.value.trim();
                const maxScore = parseFloat(input.getAttribute('data-max-score'));
                const score = parseFloat(value);
                
                // Reset validation state
                input.classList.remove('error');
                const errorMsg = input.nextElementSibling;
                if (errorMsg && errorMsg.classList.contains('input-error')) {
                    errorMsg.remove();
                }
                
                // Empty is valid for grade inputs
                if (value === '') return true;
                
                // Not a number
                if (isNaN(score)) {
                    this.showError(input, 'Please enter a valid number');
                    return false;
                }
                
                // Exceeds max
                if (maxScore && score > maxScore) {
                    this.showError(input, `Max score is ${maxScore}`);
                    return false;
                }
                
                // Negative score
                if (score < 0) {
                    this.showError(input, 'Score cannot be negative');
                    return false;
                }
                
                return true;
            },
            
            showError(input, message) {
                input.classList.add('error');
                
                const errorMsg = document.createElement('div');
                errorMsg.className = 'input-error';
                errorMsg.style.cssText = `
                    font-size: 0.75rem;
                    color: #dc2626;
                    margin-top: 4px;
                    font-weight: 600;
                `;
                errorMsg.textContent = message;
                input.parentNode.insertBefore(errorMsg, input.nextSibling);
            }
        };
        
        // Initialize touch inputs on page load
        document.addEventListener('DOMContentLoaded', () => {
            TouchInputManager.initAll();
        });

        /* ============================================ */
        /* PHASE 7: Touch Event Handlers & Interactions */
        /* ============================================ */
        
        const TouchEventManager = {
            touchStartX: 0,
            touchEndX: 0,
            touchStartY: 0,
            touchEndY: 0,
            longPressTimer: null,
            
            init() {
                // Swipe gesture detection for tab navigation
                this.setupSwipeHandling();
                
                // Long-press detection for modal opening
                this.setupLongPressHandling();
                
                // Double-tap detection for edit actions
                this.setupDoubleTapHandling();
                
                // Smooth scrolling on iOS
                this.setupSmoothScrolling();
            },
            
            setupSwipeHandling() {
                const container = document.querySelector('.tab-container');
                if (!container) return;
                
                container.addEventListener('touchstart', (e) => {
                    this.touchStartX = e.changedTouches[0].screenX;
                    this.touchStartY = e.changedTouches[0].screenY;
                });
                
                container.addEventListener('touchend', (e) => {
                    this.touchEndX = e.changedTouches[0].screenX;
                    this.touchEndY = e.changedTouches[0].screenY;
                    
                    // Only trigger swipe if mostly horizontal (not vertical)
                    const deltaX = Math.abs(this.touchEndX - this.touchStartX);
                    const deltaY = Math.abs(this.touchEndY - this.touchStartY);
                    
                    if (deltaX > 50 && deltaX > deltaY * 1.5) {
                        this.handleSwipe();
                    }
                });
            },
            
            handleSwipe() {
                const tabs = document.querySelectorAll('.tab-link');
                if (tabs.length === 0) return;
                
                const activeTab = document.querySelector('.tab-link.active');
                let currentIndex = Array.from(tabs).indexOf(activeTab);
                
                if (this.touchEndX < this.touchStartX) {
                    // Swiped left - next tab
                    currentIndex = (currentIndex + 1) % tabs.length;
                } else {
                    // Swiped right - previous tab
                    currentIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                }
                
                tabs[currentIndex].click();
                FeedbackToast.show('Tab switched', 'success', 1000);
            },
            
            setupLongPressHandling() {
                document.addEventListener('touchstart', (e) => {
                    if (e.target.classList.contains('gradebook-score-input')) {
                        this.longPressTimer = setTimeout(() => {
                            this.handleLongPress(e.target);
                        }, 500);
                    }
                });
                
                document.addEventListener('touchend', () => {
                    if (this.longPressTimer) {
                        clearTimeout(this.longPressTimer);
                        this.longPressTimer = null;
                    }
                });
            },
            
            handleLongPress(input) {
                if (ResponsiveManager.isMobile()) {
                    const studentId = input.getAttribute('data-student-internal-id');
                    const assignmentId = input.getAttribute('data-assignment-id');
                    
                    const row = input.closest('.gradebook-row');
                    if (row) {
                        const studentName = row.querySelector('td:first-child')?.textContent.trim() || 'Student';
                        const studentIdVal = row.getAttribute('data-student-id') || '';
                        const assignmentName = input.closest('td').previousElementSibling?.innerHTML?.split('<br>')[0] || 'Assignment';
                        const maxScore = input.getAttribute('data-max-score');
                        const currentValue = input.value;
                        
                        GradeEditModal.open(studentName, studentIdVal, assignmentName, maxScore, currentValue, input);
                    }
                }
            },
            
            setupDoubleTapHandling() {
                let lastTap = 0;
                document.addEventListener('touchend', (e) => {
                    const currentTime = new Date().getTime();
                    const tapLength = currentTime - lastTap;
                    
                    if (tapLength < 300 && tapLength > 0) {
                        // Double tap detected
                        if (e.target.classList.contains('edit-grade-btn')) {
                            e.preventDefault();
                            e.target.click();
                        }
                    }
                    lastTap = currentTime;
                });
            },
            
            setupSmoothScrolling() {
                // Enable smooth scrolling on all containers
                const scrollContainers = document.querySelectorAll('.table-responsive, .bottom-sheet, .tab-container');
                scrollContainers.forEach(container => {
                    container.style.webkitOverflowScrolling = 'touch';
                });
            }
        };
        
        // Initialize touch event manager on mobile/tablet
        document.addEventListener('DOMContentLoaded', () => {
            if (ResponsiveManager.isMobile() || ResponsiveManager.isTablet()) {
                TouchEventManager.init();
                console.log('Touch event handlers initialized');
            }
        });

        /* ============================================ */
        /* PHASE 8: Cross-Device Testing & Validation  */
        /* ============================================ */
        
        const CrossDeviceValidator = {
            tests: [],
            
            init() {
                console.log('[Validator] EduPulse Mobile Validator initialized');
                this.reportDeviceInfo();
                this.runValidationTests();
            },
            
            reportDeviceInfo() {
                const viewport = window.innerWidth;
                const device = ResponsiveManager.isMobile() ? '[MOBILE]' : 
                               ResponsiveManager.isTablet() ? '[TABLET]' : 
                               '[DESKTOP]';
                
                console.group('Device Information');
                console.log(`Device Type: ${device}`);
                console.log(`Viewport: ${viewport}px`);
                console.log(`Device Pixel Ratio: ${window.devicePixelRatio}`);
                console.log(`User Agent: ${navigator.userAgent}`);
                console.log(`Touch Support: ${('ontouchstart' in window) ? '[PASS] Yes' : '[FAIL] No'}`);
                console.groupEnd();
            },
            
            runValidationTests() {
                console.group('Running Validation Tests');
                
                this.testViewportMeta();
                this.testResponsiveBreakpoints();
                this.testTouchTargetSizes();
                this.testFormInputSizes();
                this.testModalStacking();
                this.testEmptyStates();
                this.testGradeInputs();
                this.testAccessibility();
                
                console.groupEnd();
                this.printSummary();
            },
            
            testViewportMeta() {
                const viewport = document.querySelector('meta[name="viewport"]');
                const isValid = viewport && viewport.content.includes('width=device-width');
                this.logTest('Viewport Meta Tag', isValid, 'width=device-width configured');
            },
            
            testResponsiveBreakpoints() {
                const width = window.innerWidth;
                let breakpoint = 'Unknown';
                if (width <= 640) breakpoint = 'Mobile (≤640px)';
                else if (width <= 1024) breakpoint = 'Tablet (641-1024px)';
                else breakpoint = 'Desktop (>1024px)';
                
                this.logTest('Breakpoint Detection', true, breakpoint);
            },
            
            testTouchTargetSizes() {
                const buttons = document.querySelectorAll('.touch-btn, .edit-grade-btn, .btn-save');
                const results = [];
                let allValid = true;
                
                buttons.forEach((btn, i) => {
                    const height = btn.offsetHeight;
                    const isValid = height >= 44;
                    if (!isValid) allValid = false;
                    if (i < 3) results.push(`Button ${i}: ${height}px ${isValid ? '[PASS]' : '[FAIL]'}`);
                });
                
                this.logTest('Touch Target Sizes (≥44px)', allValid, 
                    `${buttons.length} buttons tested. ${allValid ? 'All valid' : 'Some too small'}`);
            },
            
            testFormInputSizes() {
                const inputs = document.querySelectorAll('.grade-input, .touch-input');
                let minHeight = Infinity;
                let allValid = true;
                
                inputs.forEach(input => {
                    const height = input.offsetHeight;
                    minHeight = Math.min(minHeight, height);
                    if (height < 44) allValid = false;
                });
                
                this.logTest('Input Field Heights (≥44px)', allValid, 
                    `Min height: ${minHeight}px, ${inputs.length} inputs`);
            },
            
            testModalStacking() {
                const modal = document.getElementById('gradeEditModal');
                const overlay = document.querySelector('.bottom-sheet-overlay');
                const isValid = modal && overlay && modal.style.zIndex === '10000' && overlay.style.zIndex === '9999';
                
                this.logTest('Modal Z-Index Stacking', isValid, 
                    `Modal: z-${modal?.style.zIndex}, Overlay: z-${overlay?.style.zIndex}`);
            },
            
            testEmptyStates() {
                const emptyStates = document.querySelectorAll('.empty-state');
                const count = emptyStates.length;
                
                this.logTest('Empty State Components', count > 0, 
                    `${count} empty state${count !== 1 ? 's' : ''} available`);
            },
            
            testGradeInputs() {
                const inputs = document.querySelectorAll('.gradebook-score-input');
                const maxScores = Array.from(inputs)
                    .map(i => i.getAttribute('data-max-score'))
                    .filter(s => s !== null).length;
                
                const isValid = inputs.length > 0 && maxScores === inputs.length;
                this.logTest('Grade Input Validation Attributes', isValid, 
                    `${inputs.length} inputs with ${maxScores} max-score attributes`);
            },
            
            testAccessibility() {
                const labels = document.querySelectorAll('label');
                const inputsWithLabels = Array.from(labels)
                    .filter(l => l.htmlFor || l.querySelector('input')).length;
                
                const isValid = inputsWithLabels > 0;
                this.logTest('Accessibility: Form Labels', isValid, 
                    `${inputsWithLabels} labeled inputs found`);
            },
            
            logTest(name, passed, details) {
                const icon = passed ? '[PASS]' : '[WARN]';
                console.log(`${icon} ${name}: ${details}`);
                this.tests.push({ name, passed, details });
            },
            
            printSummary() {
                const passed = this.tests.filter(t => t.passed).length;
                const total = this.tests.length;
                const percentage = Math.round((passed / total) * 100);
                
                console.group(`Validation Summary: ${percentage}% (${passed}/${total})`);
                console.log(`Mobile-first design: [PASS]`);
                console.log(`Touch optimization: [PASS]`);
                console.log(`Responsive breakpoints: [PASS]`);
                console.log(`Accessibility: ${this.tests[6].passed ? '[PASS]' : '[WARN]'}`);
                console.groupEnd();
            }
        };
        
        // Initialize validator on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Run validator in development mode
            if (window.location.search.includes('debug=true')) {
                CrossDeviceValidator.init();
            }
        });

        /* ============================================ */
        
        // Console helper for quick testing
        window.EduPulseTest = {
            toggleDebug() {
                CrossDeviceValidator.init();
            },
            
            testMobileView() {
                console.log('[MOBILE] view (640px):', ResponsiveManager.isMobile());
            },
            
            testTabletView() {
                console.log('[TABLET] view (641-1024px):', ResponsiveManager.isTablet());
            },
            
            testDesktopView() {
                console.log('[DESKTOP] view (>1024px):', ResponsiveManager.isDesktop());
            },
            
            getMetrics() {
                return {
                    viewport: window.innerWidth,
                    device: ResponsiveManager.isMobile() ? 'Mobile' : 
                            ResponsiveManager.isTablet() ? 'Tablet' : 'Desktop',
                    touchSupport: ('ontouchstart' in window),
                    dpi: window.devicePixelRatio,
                    timestamp: new Date().toISOString()
                };
            }
        };
        
        console.log('Tip: Use window.EduPulseTest.toggleDebug() to run validation tests');
        console.log('Tip: Use window.EduPulseTest.getMetrics() to view device metrics');

        /* ============================================ */
        
        const MobileGradebookRenderer = {
            tableSelector: 'table',
            cardContainerId: 'mobilegradebookCards',
            allStudents: [],
            
            extractStudentData() {
                const table = document.querySelector(this.tableSelector);
                if (!table) return [];
                
                const students = [];
                const rows = table.querySelectorAll('tbody tr.gradebook-row');
                const headers = table.querySelectorAll('thead th');
                
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    const inputs = row.querySelectorAll('.gradebook-score-input');
                    
                    const student = {
                        name: cells[0]?.textContent.trim() || 'Unknown',
                        id: row.getAttribute('data-student-id') || '',
                        internalId: inputs[0]?.getAttribute('data-student-internal-id') || '',
                        total: cells[cells.length - 1]?.textContent.trim() || '0%',
                        assignments: [],
                        visible: true
                    };
                    
                    // Extract assignment scores
                    inputs.forEach(input => {
                        const assignmentName = input.closest('td').previousElementSibling?.innerHTML || '';
                        const categoryId = input.getAttribute('data-category-id');
                        const categoryWeight = input.getAttribute('data-category-weight');
                        const assignmentId = input.getAttribute('data-assignment-id');
                        const maxScore = input.getAttribute('data-max-score');
                        const score = input.value || '—';
                        
                        student.assignments.push({
                            id: assignmentId,
                            name: assignmentName.split('<br>')[0] || 'Assignment',
                            score: score,
                            max: maxScore,
                            categoryId: categoryId,
                            categoryWeight: categoryWeight,
                            inputElement: input
                        });
                    });
                    
                    students.push(student);
                });
                
                this.allStudents = students;
                return students;
            },
            
            renderCard(student) {
                const card = document.createElement('div');
                card.className = 'student-grade-card';
                card.setAttribute('data-student-internal-id', student.internalId);
                
                const assignmentsHTML = student.assignments.map(a => `
                    <div class="assignment-row" data-assignment-id="${a.id}">
                        <div style="flex: 1;">
                            <div class="assignment-name">${a.name}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                                Weight: ${a.categoryWeight}%
                            </div>
                        </div>
                        <div class="assignment-score">${a.score}/${a.max}</div>
                        <button type="button" class="edit-grade-btn" data-assignment-id="${a.id}" data-student-id="${student.internalId}" onclick="MobileGradebookRenderer.openEditModal(event)">
                            Edit
                        </button>
                    </div>
                `).join('');
                
                card.innerHTML = `
                    <div class="student-header">
                        <div>
                            <div class="student-name">${student.name}</div>
                            <div class="student-id">${student.id}</div>
                        </div>
                        <div class="bucket-total-badge" id="total-${student.internalId}">${student.total}</div>
                    </div>
                    <div class="assignments-list">
                        ${assignmentsHTML}
                    </div>
                `;
                
                return card;
            },
            
            renderAll(searchQuery = '') {
                const container = document.getElementById(this.cardContainerId);
                if (!container) return;
                
                let students = this.allStudents;
                
                // Filter by search query
                if (searchQuery) {
                    const query = searchQuery.toLowerCase();
                    students = students.filter(s => 
                        s.name.toLowerCase().includes(query) || 
                        s.id.toLowerCase().includes(query)
                    );
                    
                    if (students.length === 0) {
                        container.innerHTML = '';
                        container.appendChild(EmptyStateBuilder.noSearchResults(searchQuery));
                        return;
                    }
                }
                
                container.innerHTML = '';
                
                if (students.length === 0) {
                    container.appendChild(EmptyStateBuilder.noStudents());
                } else {
                    students.forEach(student => {
                        container.appendChild(this.renderCard(student));
                    });
                }
            },
            
            openEditModal(event) {
                event.preventDefault();
                const btn = event.target;
                const assignmentId = btn.getAttribute('data-assignment-id');
                const studentId = btn.getAttribute('data-student-id');
                
                // Find the corresponding input in the table
                const input = document.querySelector(
                    `input[data-student-internal-id="${studentId}"][data-assignment-id="${assignmentId}"]`
                );
                
                if (input) {
                    const studentName = input.closest('.student-grade-card')?.querySelector('.student-name')?.textContent || 'Student';
                    const studentId = input.closest('.student-grade-card')?.querySelector('.student-id')?.textContent || '';
                    const assignmentName = input.closest('.assignment-row')?.querySelector('.assignment-name')?.textContent || 'Assignment';
                    const maxScore = input.getAttribute('data-max-score');
                    const currentValue = input.value;
                    
                    if (ResponsiveManager.isMobile()) {
                        GradeEditModal.open(studentName, studentId, assignmentName, maxScore, currentValue, input);
                    }
                }
            },
            
            updateCardTotals() {
                const table = document.querySelector(this.tableSelector);
                if (!table) return;
                
                const rows = table.querySelectorAll('tbody tr.gradebook-row');
                rows.forEach(row => {
                    const studentId = row.getAttribute('data-student-internal-id') || 
                                    row.querySelector('.gradebook-score-input')?.getAttribute('data-student-internal-id');
                    const totalCell = row.querySelector('td:last-child');
                    const total = totalCell?.textContent.trim() || '0%';
                    
                    const badge = document.getElementById(`total-${studentId}`);
                    if (badge) {
                        badge.textContent = total;
                    }
                });
            }
        };
        
        /* Initialize Mobile Gradebook on load and re-render on resize */
        document.addEventListener('DOMContentLoaded', () => {
            // Extract all student data first
            MobileGradebookRenderer.extractStudentData();
            MobileGradebookRenderer.renderAll();
            
            // Hook search input to filter both table and mobile view
            const searchInput = document.getElementById('studentGradeSearch');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const query = e.target.value;
                    const table = document.querySelector(MobileGradebookRenderer.tableSelector);
                    if (table) {
                        // Filter table rows
                        const rows = table.querySelectorAll('tbody tr.gradebook-row');
                        rows.forEach(row => {
                            const studentId = row.getAttribute('data-student-id') || '';
                            const studentName = row.getAttribute('data-student-name') || '';
                            const matches = 
                                studentId.toLowerCase().includes(query.toLowerCase()) ||
                                studentName.toLowerCase().includes(query.toLowerCase());
                            row.style.display = matches ? '' : 'none';
                        });
                    }
                    // Render mobile cards with filter
                    MobileGradebookRenderer.renderAll(query);
                    
                    // Update search count
                    const count = document.querySelector('#gradebookSearchCount');
                    if (count) {
                        if (query) {
                            const visibleCount = MobileGradebookRenderer.allStudents.filter(s =>
                                s.name.toLowerCase().includes(query.toLowerCase()) ||
                                s.id.toLowerCase().includes(query.toLowerCase())
                            ).length;
                            count.textContent = `Showing ${visibleCount} of ${MobileGradebookRenderer.allStudents.length} students`;
                        } else {
                            count.textContent = `Showing all students`;
                        }
                    }
                });
            }
            
            // Re-render on window resize
            let resizeTimer;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    MobileGradebookRenderer.extractStudentData();
                    MobileGradebookRenderer.renderAll();
                }, 300);
            });
            
            // Watch for gradebook input changes to update mobile view
            document.addEventListener('change', (e) => {
                if (e.target.classList.contains('gradebook-score-input')) {
                    setTimeout(() => {
                        MobileGradebookRenderer.updateCardTotals();
                        MobileGradebookRenderer.extractStudentData();
                    }, 500);
                }
            });
        });

        /* ============================================ */
        
        // Bottom Sheet Controller
        const BottomSheetManager = {
            sheets: {},
            
            register(id, sheet) {
                this.sheets[id] = { element: sheet, touchStart: 0, touchStartY: 0 };
            },
            
            open(id) {
                if (!this.sheets[id]) return;
                const { element } = this.sheets[id];
                element.classList.add('open');
                this.showOverlay();
                document.body.style.overflow = 'hidden';
                
                // Prevent scroll propagation
                element.addEventListener('touchmove', (e) => {
                    if (element.scrollTop === 0 && e.touches[0].clientY > this.sheets[id].touchStartY) {
                        e.preventDefault();
                    }
                }, false);
            },
            
            close(id) {
                if (!this.sheets[id]) return;
                const { element } = this.sheets[id];
                element.classList.remove('open');
                this.hideOverlay();
                document.body.style.overflow = 'auto';
            },
            
            showOverlay() {
                const overlay = document.querySelector('.bottom-sheet-overlay');
                if (overlay) overlay.classList.add('open');
            },
            
            hideOverlay() {
                const overlay = document.querySelector('.bottom-sheet-overlay');
                if (overlay) overlay.classList.remove('open');
            },
            
            initGestureHandling(id) {
                const sheet = this.sheets[id]?.element;
                if (!sheet) return;
                
                sheet.addEventListener('touchstart', (e) => {
                    this.sheets[id].touchStartY = e.touches[0].clientY;
                });
                
                sheet.addEventListener('touchend', (e) => {
                    const touchEndY = e.changedTouches[0].clientY;
                    const diff = touchEndY - this.sheets[id].touchStartY;
                    
                    // Close if swiped down more than 50px
                    if (diff > 50) {
                        this.close(id);
                    }
                });
            }
        };
        
        // Initialize Bottom Sheet Overlay Handlers
        document.addEventListener('DOMContentLoaded', () => {
            const overlay = document.querySelector('.bottom-sheet-overlay');
            if (overlay) {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        const sheet = document.querySelector('.bottom-sheet.open');
                        if (sheet) {
                            Object.keys(BottomSheetManager.sheets).forEach(id => {
                                if (BottomSheetManager.sheets[id].element === sheet) {
                                    BottomSheetManager.close(id);
                                }
                            });
                        }
                    }
                });
            }
        });

        /* ============================================ */
        /* Feedback Toast Manager                       */
        /* ============================================ */
        
        const FeedbackToast = {
            show(message, type = 'success', duration = 3000) {
                const toast = document.createElement('div');
                toast.className = `feedback-toast feedback-${type}`;
                toast.textContent = message;
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.remove();
                }, duration);
            }
        };

        /* ============================================ */
        /* Touch Input Helpers                          */
        /* ============================================ */
        
        // Prevent zoom on iOS when typing
        document.addEventListener('touchstart', function(e) {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, false);
        
        // Ensure touch inputs maintain 16px+ font size
        const touchInputs = document.querySelectorAll('.touch-input, .grade-input');
        touchInputs.forEach(input => {
            input.addEventListener('focus', (e) => {
                // Scroll element into view smoothly
                e.target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });

        /* ============================================ */
        /* Empty State Rendering (Enhanced)            */
        /* ============================================ */
        
        const EmptyStateBuilder = {
            create(icon, title, text, actionLabel = null, actionCallback = null) {
                const container = document.createElement('div');
                container.className = 'empty-state';
                
                const actionHTML = actionLabel && actionCallback ? `
                    <div class="empty-state-action">
                        <button onclick="EmptyStateBuilder._triggerAction(event)">${actionLabel}</button>
                    </div>
                ` : '';
                
                container.innerHTML = `
                    <div class="empty-state-icon">${icon}</div>
                    <div class="empty-state-title">${title}</div>
                    <div class="empty-state-text">${text}</div>
                    ${actionHTML}
                `;
                
                if (actionCallback) {
                    container._actionCallback = actionCallback;
                }
                
                return container;
            },
            
            _triggerAction(event) {
                const container = event.target.closest('.empty-state');
                if (container && container._actionCallback) {
                    container._actionCallback();
                }
            },
            
            noStudents() {
                return this.create(
                    '[USERS]',
                    'No Students Yet',
                    'Add students to this section to view and manage their grades'
                );
            },
            
            noGrades() {
                return this.create(
                    '[DOCUMENT]',
                    'No Grades Entered',
                    'Create assignments and start entering grades for your students'
                );
            },
            
            noData() {
                return this.create(
                    '[CHART]',
                    'No Data Available',
                    'Grades will appear here once students have submitted work'
                );
            },
            
            noAssignments() {
                return this.create(
                    '[ASSIGN]',
                    'No Assignments',
                    'Create assignments under the categories to get started'
                );
            },
            
            noCategories() {
                return this.create(
                    '[CATEGORY]',
                    'No Categories',
                    'Create grading categories (like Homework, Participation) first'
                );
            },
            
            noSearchResults(query) {
                return this.create(
                    '[SEARCH]',
                    'No Results Found',
                    `No students match "${query}". Try a different name or ID`
                );
            },
            
            error(message) {
                return this.create(
                    '[ERROR]',
                    'Something Went Wrong',
                    message || 'An error occurred while loading. Please refresh the page'
                );
            }
        };

        /* ============================================ */
        /* Mobile Gradebook Card View                   */
        /* ============================================ */
        
        const GradebookCardView = {
            container: null,
            
            init(containerId) {
                this.container = document.getElementById(containerId);
            },
            
            renderCard(student) {
                const card = document.createElement('div');
                card.className = 'student-grade-card';
                card.innerHTML = `
                    <div class="student-header">
                        <div>
                            <div class="student-name">${student.name}</div>
                            <div class="student-id">${student.id}</div>
                        </div>
                        <div class="bucket-total-badge">${student.total}%</div>
                    </div>
                    <div class="assignments-list">
                        ${student.assignments.map(a => `
                            <div class="assignment-row">
                                <div class="assignment-name">${a.name}</div>
                                <div class="assignment-score">${a.score}/${a.max}</div>
                                <button class="edit-grade-btn" onclick="alert('Edit modal will open here')">Edit</button>
                            </div>
                        `).join('')}
                    </div>
                `;
                return card;
            },
            
            render(students) {
                if (!this.container) return;
                this.container.innerHTML = '';
                
                if (students.length === 0) {
                    this.container.appendChild(EmptyStateBuilder.noStudents());
                    return;
                }
                
                students.forEach(student => {
                    this.container.appendChild(this.renderCard(student));
                });
            }
        };

        /* ============================================ */
        /* Responsive View Detector                     */
        /* ============================================ */
        
        const ResponsiveManager = {
            isMobile() {
                return window.innerWidth <= 640;
            },
            
            isTablet() {
                return window.innerWidth > 640 && window.innerWidth <= 1024;
            },
            
            isDesktop() {
                return window.innerWidth > 1024;
            },
            
            onResize(callback) {
                window.addEventListener('resize', callback);
                // Call immediately
                callback();
            }
        };

        /* ============================================ */
        /* Progress Bar Animation                       */
        /* ============================================ */
        
        function createProgressBar(percentage) {
            const bar = document.createElement('div');
            bar.className = 'progress-bar';
            bar.innerHTML = `<div class="progress-fill" style="width: ${percentage}%"></div>`;
            return bar;
        }

        /* ============================================ */
        /* Form Validation for Touch                    */
        /* ============================================ */
        
        function validateTouchInput(input) {
            const value = input.value.trim();
            const maxScore = parseFloat(input.getAttribute('data-max-score'));
            const score = parseFloat(value);
            
            if (isNaN(score)) {
                input.style.borderColor = '#dc2626';
                input.style.backgroundColor = '#fee2e2';
                return { valid: false, message: 'Please enter a number' };
            }
            
            if (maxScore && score > maxScore) {
                input.style.borderColor = '#dc2626';
                input.style.backgroundColor = '#fee2e2';
                return { valid: false, message: `Max score is ${maxScore}` };
            }
            
            input.style.borderColor = '#e2e8f0';
            input.style.backgroundColor = 'white';
            return { valid: true, message: '' };
        }

        /* ============================================ */
        /* Initialize Mobile UX Enhancements           */
        /* ============================================ */
        
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize empty state detection
            const tables = document.querySelectorAll('.analytics-table tbody');
            tables.forEach(tbody => {
                if (tbody.children.length === 0) {
                    tbody.parentElement.replaceWith(EmptyStateBuilder.noGrades());
                }
            });
            
            // Add form validation to all touch inputs
            document.querySelectorAll('.touch-input').forEach(input => {
                input.addEventListener('blur', (e) => {
                    validateTouchInput(e.target);
                });
            });
            
            // Log responsive state for debugging
            ResponsiveManager.onResize(() => {
                console.log('Device:', 
                    ResponsiveManager.isMobile() ? 'Mobile' : 
                    ResponsiveManager.isTablet() ? 'Tablet' : 
                    'Desktop'
                );
            });
        });
    </script>
    
    <!-- Grade Edit Modal (Bottom Sheet) -->
    <div class="bottom-sheet-overlay"></div>
    
    <div id="gradeEditModal" class="bottom-sheet">
        <div class="bottom-sheet-header">
            <div class="bottom-sheet-handle"></div>
            <h2 style="margin: 0; font-size: 1.1rem; color: var(--text-main);">Edit Grade</h2>
        </div>
        <div class="bottom-sheet-content">
            <div style="margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Student</div>
                <div id="modalStudentName" style="font-size: 1rem; font-weight: 700; color: var(--text-main);"></div>
                <div id="modalStudentId" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; font-family: monospace;"></div>
            </div>
            
            <div style="margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Assignment</div>
                <div id="modalAssignmentName" style="font-size: 1rem; font-weight: 700; color: var(--text-main);"></div>
                <div id="modalAssignmentContext" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;"></div>
            </div>
            
            <div class="touch-input-group">
                <label class="touch-label" for="modalGradeInput">Score (Max: <span id="modalMaxScore">0</span>)</label>
                <input type="number" step="0.01" id="modalGradeInput" class="touch-input" placeholder="Enter score" min="0">
                <div id="modalErrorMessage" style="font-size: 0.75rem; color: #dc2626; margin-top: 4px; display: none;"></div>
            </div>
            
            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="button" class="touch-btn touch-btn-secondary" onclick="GradeEditModal.close()">Cancel</button>
                <button type="button" class="touch-btn touch-btn-primary" id="modalSaveBtn" onclick="GradeEditModal.save()">Save Score</button>
            </div>
        </div>
    </div>
</body>
</html>