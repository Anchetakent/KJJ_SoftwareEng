    <?php
    session_start();
    require_once 'config/db.php';

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

    function getGradeEquivalent($final_grade) {
        if ($final_grade >= 98) return ['equivalent' => '4.00', 'remark' => 'PASSED'];
        if ($final_grade >= 95) return ['equivalent' => '3.75', 'remark' => 'PASSED'];
        if ($final_grade >= 92) return ['equivalent' => '3.50', 'remark' => 'PASSED'];
        if ($final_grade >= 89) return ['equivalent' => '3.25', 'remark' => 'PASSED'];
        if ($final_grade >= 86) return ['equivalent' => '3.00', 'remark' => 'PASSED'];
        if ($final_grade >= 83) return ['equivalent' => '2.75', 'remark' => 'PASSED'];
        if ($final_grade >= 80) return ['equivalent' => '2.50', 'remark' => 'PASSED'];
        if ($final_grade >= 77) return ['equivalent' => '2.25', 'remark' => 'PASSED'];
        if ($final_grade >= 74) return ['equivalent' => '2.00', 'remark' => 'PASSED'];
        if ($final_grade >= 71) return ['equivalent' => '1.75', 'remark' => 'PASSED'];
        if ($final_grade >= 68) return ['equivalent' => '1.50', 'remark' => 'PASSED'];
        if ($final_grade >= 64) return ['equivalent' => '1.25', 'remark' => 'PASSED'];
        if ($final_grade >= 60) return ['equivalent' => '1.00', 'remark' => 'PASSED'];

        return ['equivalent' => '0.00', 'remark' => 'FAILED'];
    }

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
        fputcsv($output, array('Student ID', 'Full Name', 'Midterm Grade (%)', 'Finals Grade (%)', 'Final Grade (%)', 'Equivalent', 'Remark'));

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

            $final_grade = ($mid_total + $fin_total) / 2;
            $grade_info = getGradeEquivalent($final_grade);

            fputcsv($output, array(
                $student['student_id'],
                $student['name'],
                number_format($mid_total, 2),
                number_format($fin_total, 2),
                number_format($final_grade, 2),
                $grade_info['equivalent'],
                $grade_info['remark']
            ));
        }
        fclose($output);
        exit(); // Stop rendering the rest of the page so the CSV stays clean
    }

    // --- MANAGEMENT LOGIC ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user_role === 'Teacher' || $user_role === 'adminoffice')) {
        
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
        <style>
            :root {
                --sidebar-bg: #0f172a; --primary: #10b981; --bg-main: #f8fafc; --border-color: #e2e8f0;
                --text-main: #1e293b; --text-muted: #64748b; --slate-700: #334155; --note-bg: #f0fdf4; --danger: #ef4444;
            }
            * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
            body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); letter-spacing: -0.01em; }
            .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
            .top-nav { height: 70px; background: white; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 40px; position: sticky; top: 0; z-index: 10; }
            .user-pill { display: flex; align-items: center; gap: 12px; padding: 6px 16px; background: var(--bg-main); border: 1px solid var(--border-color); border-radius: 99px; font-size: 0.85rem; font-weight: 600; }
            .badge { background: var(--primary); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; }
            .content-area { padding: 40px; }
            .data-card { background: white; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 24px; }
            .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: #fcfcfc; display: flex; justify-content: space-between; align-items: center; }
            
            /* TABS UI */
            .tab-container { display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 24px; gap: 32px; padding: 0 24px; background: #fcfcfc;}
            .tab-link { color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.9rem; padding: 16px 0; border-bottom: 3px solid transparent; transition: 0.2s; margin-bottom: -1px; }
            .tab-link:hover { color: var(--text-main); }
            .tab-link.active { color: var(--primary); border-bottom-color: var(--primary); }
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
            .converter-cell { min-width: 230px; white-space: normal; }
            .converter-grade { font-weight: 800; color: var(--slate-700); }
            .status-pill { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
            .pill-passed { background: #ecfdf5; color: #059669; }
            .pill-failed { background: #fef2f2; color: #dc2626; }
            
            .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; }
            .error-modal { background: white; width: 420px; padding: 40px 32px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); text-align: center; }
            .error-modal i { font-size: 3.5rem; color: var(--danger); margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <?php include 'includes/sidebar.php'; ?>
        <div class="main-wrapper">
            <header class="top-nav">
                <div style="font-weight: 600; color: var(--text-muted);">
                    Workspace / <?php 
                        if ($view) echo ucfirst($view);
                        else echo $selected_section ? "Section $selected_section" : "Overview"; 
                    ?>
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
                <?php elseif ($user_role === 'Teacher' || $user_role === 'adminoffice'): ?>
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
                                        <thead><tr><th>Student ID</th><th>Full Name</th><th>Calc. Midterm</th><th>Calculated Finals</th><th>Final Grade Converter</th><th style="text-align: right;">Manage</th></tr></thead>
                                        <tbody>
                                            <?php 
                                            $stmt = $conn->prepare("SELECT id, student_id, name FROM students WHERE section_id = ?");
                                            $stmt->bind_param("i", $current_section_id);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
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

                                                $final_grade = ($mid_total + $fin_total) / 2;
                                                $grade_info = getGradeEquivalent($final_grade);
                                            ?>
                                                <tr>
                                                    <td style="color: var(--text-muted); font-family: monospace;"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($student['name']); ?></strong></td>
                                                    <td><span style="font-weight: 700; color: <?php echo $mid_total >= 75 ? 'var(--primary)' : 'var(--text-muted)'; ?>"><?php echo number_format($mid_total, 2); ?>%</span></td>
                                                    <td><span style="font-weight: 700; color: <?php echo $fin_total >= 75 ? 'var(--primary)' : 'var(--text-muted)'; ?>"><?php echo number_format($fin_total, 2); ?>%</span></td>
                                                    <td class="converter-cell">
                                                        <div class="converter-grade"><?php echo number_format($final_grade, 2); ?>%</div>
                                                        <div style="margin-top: 6px; display: flex; align-items: center; gap: 8px;">
                                                            <span style="font-weight: 700; color: var(--primary);"><?php echo $grade_info['equivalent']; ?></span>
                                                            <span class="status-pill <?php echo ($grade_info['remark'] === 'PASSED') ? 'pill-passed' : 'pill-failed'; ?>"><?php echo $grade_info['remark']; ?></span>
                                                        </div>
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

                                <form method="POST">
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
                                                $stmt = $conn->prepare("SELECT id, name FROM students WHERE section_id = ?");
                                                $stmt->bind_param("i", $current_section_id);
                                                $stmt->execute();
                                                $result = $stmt->get_result();
                                                while($student = $result->fetch_assoc()): 
                                                    $student_total = 0;
                                                ?>
                                                    <tr>
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
                                                                    <td style="text-align: center;"><input type="number" step="0.01" name="scores[<?php echo $student['id']; ?>][<?php echo $ass['id']; ?>]" value="<?php echo htmlspecialchars($val); ?>" class="grade-input" style="width: 60px;"></td>
                                                                <?php endforeach; 
                                                            endif;
                                                            if ($cat_max > 0) $student_total += ($cat_earned / $cat_max) * $cat['weight'];
                                                        endforeach; ?>
                                                        
                                                        <td style="text-align: right; font-weight: 800; color: #047857;"><?php echo number_format($student_total, 2); ?>%</td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div style="padding: 20px 24px; background: #fcfcfc; border-top: 1px solid var(--border-color); text-align: right;">
                                        <button type="submit" name="save_scores" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Save <?php echo $current_tab; ?> Grades</button>
                                    </div>
                                </form>

                            <?php endif; ?>

                        </div>
                    <?php else: ?>
                        <div class="data-card" style="max-width: 600px; margin: 40px auto; padding: 48px; text-align: center;">
                            <i class="fa-solid fa-plus-circle" style="font-size: 3.5rem; color: var(--primary); margin-bottom: 24px;"></i>
                            <h2>Initialize New Class</h2>
                            <form method="POST" style="text-align: left;">
                                <div style="margin-bottom: 16px;"><label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">Section Name</label><input type="text" name="section_name" class="grade-input" style="width: 100%; text-align:left; height: 50px;" required></div>
                                <div style="margin-bottom: 24px;"><label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">Class Type</label><select name="class_type" class="grade-input" style="width: 100%; text-align:left; height: 50px; background: white;" required><option value="Lecture">Lecture</option><option value="Lab">Laboratory (Lab)</option></select></div>
                                <button type="submit" name="add_class" class="btn-save" style="width: 100%; height: 50px;">Create Workspace</button>
                            </form>
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
        </script>
    </body>
    </html>