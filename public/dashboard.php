<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/includes/analytics_functions.php';

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

$current_section_id = null;
$current_section_notes = '';
if ($selected_section) {
    $sec_query = $conn->prepare("SELECT id, notes FROM sections WHERE section_name = ? AND owner_email = ?");
    $sec_query->bind_param("ss", $selected_section, $user_email);
    $sec_query->execute();
    $sec_res = $sec_query->get_result();
    if ($sec_row = $sec_res->fetch_assoc()) {
        $current_section_id = $sec_row['id'];
        $current_section_notes = $sec_row['notes'] ?? '';
    }
}

// System Admin actions: suspend/unsuspend users, reset passwords, and prepare users/logs for view
$users = null;
$logs = null;
if ($user_role === 'System Admin') {
    // Handle suspension toggle
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status']) && isset($pdo)) {
        $uid = (int)$_POST['user_id'];
        try {
            $stmt = $pdo->prepare("SELECT email, status FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            if ($u) {
                $newStatus = (strtolower($u['status'] ?? 'active') === 'suspended') ? 'Active' : 'Suspended';
                $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, $uid]);
                $action = ($newStatus === 'Suspended') ? "Account suspended by {$user_email}" : "Account reactivated by {$user_email}";
                $log = $pdo->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                $log->execute([$u['email'], $action]);
                $_SESSION['flash_success'] = "User status updated.";
            }
        } catch (Exception $e) {
            error_log('Suspend toggle failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = "Failed to update status.";
        }
        header('Location: dashboard.php?view=users');
        exit();
    }

    // Handle manual password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && isset($pdo)) {
        $uid = (int)$_POST['user_id'];
        try {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            if ($u) {
                // Generate an 8-character temporary password
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $temp = '';
                $max = strlen($chars) - 1;
                for ($i = 0; $i < 8; $i++) { $temp .= $chars[random_int(0, $max)]; }

                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->execute([$temp, $uid]);

                $log = $pdo->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                $log->execute([$u['email'], "Password reset by {$user_email}"]);

                $_SESSION['flash_success'] = "Temporary password for {$u['email']}: <strong>" . htmlspecialchars($temp) . "</strong>";
            }
        } catch (Exception $e) {
            error_log('Reset password failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = "Failed to reset password.";
        }
        header('Location: dashboard.php?view=users');
        exit();
    }

    // Handle permanent user deletion (confirmation required)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_user']) && isset($pdo)) {
        $uid = (int)$_POST['user_id'];
        try {
            $stmt = $pdo->prepare("SELECT email, role FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            if ($u) {
                // Safety: do not allow deleting System Admin role
                if (strtolower($u['role']) === 'system admin') {
                    $_SESSION['flash_error'] = "Cannot delete a System Admin account.";
                } else {
                    $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$uid]);

                    $log = $pdo->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                    $log->execute([$u['email'], "Account permanently deleted by {$user_email}"]);

                    $_SESSION['flash_success'] = "User " . htmlspecialchars($u['email']) . " deleted.";
                }
            }
        } catch (Exception $e) {
            error_log('Delete user failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = "Failed to delete user.";
        }
        header('Location: dashboard.php?view=users');
        exit();
    }

    // Prepare users list (PDO)
    try {
        if (isset($pdo)) {
            // Exclude the System Admin role from the list
            $u_stmt = $pdo->query("SELECT id, email, role, COALESCE(status, 'Active') AS status FROM users WHERE role <> 'System Admin' ORDER BY id ASC");
            $users = $u_stmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log('Failed to fetch users: ' . $e->getMessage());
    }

    // Prepare audit logs with optional filters
    try {
        $where = [];
        $params = [];
        if (!empty($_GET['q'])) {
            $where[] = "(user_email LIKE ? OR action LIKE ?)";
            $q = '%' . $_GET['q'] . '%';
            $params[] = $q; $params[] = $q;
        }
        if (!empty($_GET['date'])) {
            // Expect YYYY-MM-DD
            $where[] = "DATE(log_time) = ?";
            $params[] = $_GET['date'];
        }
        $sql = "SELECT * FROM audit_logs";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY log_time DESC LIMIT 200";
        if (isset($pdo)) {
            $lstmt = $pdo->prepare($sql);
            $lstmt->execute($params);
            $logs = $lstmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log('Failed to fetch logs: ' . $e->getMessage());
    }
}

if (isset($_POST['export_csv']) && $current_section_id) {
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
            foreach (['Midterm', 'Finals'] as $term) {
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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $selected_section . '_Grades.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Student ID', 'Full Name', 'Midterm Grade (%)', 'Finals Grade (%)', 'Overall Grade (%)'));

    $midterm_assignment_count = 0;
    foreach ($export_tree['Midterm'] as $cat) {
        $midterm_assignment_count += count($cat['assignments']);
    }
    $finals_assignment_count = 0;
    foreach ($export_tree['Finals'] as $cat) {
        $finals_assignment_count += count($cat['assignments']);
    }

    $stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ?");
    $stmt->bind_param("i", $current_section_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($student = $result->fetch_assoc()) {
        $mid_total = 0;
        foreach ($export_tree['Midterm'] as $cat) {
            $cat_earned = 0;
            $cat_max = 0;
            foreach ($cat['assignments'] as $ass) {
                $cat_max += $ass['max_score'];
                $cat_earned += $export_scores[$student['id']][$ass['id']] ?? 0;
            }
            if ($cat_max > 0) {
                $mid_total += ($cat_earned / $cat_max) * $cat['weight'];
            }
        }

        $fin_total = 0;
        foreach ($export_tree['Finals'] as $cat) {
            $cat_earned = 0;
            $cat_max = 0;
            foreach ($cat['assignments'] as $ass) {
                $cat_max += $ass['max_score'];
                $cat_earned += $export_scores[$student['id']][$ass['id']] ?? 0;
            }
            if ($cat_max > 0) {
                $fin_total += ($cat_earned / $cat_max) * $cat['weight'];
            }
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
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user_role === 'Faculty')) {
    if (isset($_POST['add_class'])) {
        $name = trim($_POST['section_name']);
        if ($_POST['class_type'] === 'Lab') {
            $name .= 'LA';
        }

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
        $name = $_POST['section_to_delete'] ?? ($_POST['old_section_name'] ?? '');
        $stmt = $conn->prepare("DELETE FROM sections WHERE section_name = ? AND owner_email = ?");
        $stmt->bind_param("ss", $name, $user_email);
        $stmt->execute();
        header("Location: dashboard.php");
        exit();
    }

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
        $student_internal_id = (int)($_POST['student_internal_id'] ?? 0);
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $score = isset($_POST['score']) ? trim((string)$_POST['score']) : '';

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
                $score_value = (float)$score;
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

$tree = ['Midterm' => [], 'Finals' => []];
$student_scores = [];

if ($current_section_id) {
    $cat_res = $conn->query("SELECT * FROM grading_categories WHERE section_id = $current_section_id ORDER BY id ASC");
    $cat_ids = [];
    while ($c = $cat_res->fetch_assoc()) {
        $c['assignments'] = [];
        $tree[$c['term']][$c['id']] = $c;
        $cat_ids[] = $c['id'];
    }

    if (!empty($cat_ids)) {
        $ids_str = implode(',', $cat_ids);
        $ass_res = $conn->query("SELECT * FROM assignments WHERE category_id IN ($ids_str) ORDER BY id ASC");
        $ass_ids = [];
        while ($a = $ass_res->fetch_assoc()) {
            foreach (['Midterm', 'Finals'] as $term) {
                if (isset($tree[$term][$a['category_id']])) {
                    $tree[$term][$a['category_id']]['assignments'][$a['id']] = $a;
                }
            }
            $ass_ids[] = $a['id'];
        }

        if (!empty($ass_ids)) {
            $ass_str = implode(',', $ass_ids);
            $score_res = $conn->query("SELECT student_id, assignment_id, score FROM student_scores WHERE assignment_id IN ($ass_str)");
            while ($s = $score_res->fetch_assoc()) {
                $student_scores[$s['student_id']][$s['assignment_id']] = $s['score'];
            }
        }
    }
}

require __DIR__ . '/../app/views/dashboard.view.php';
