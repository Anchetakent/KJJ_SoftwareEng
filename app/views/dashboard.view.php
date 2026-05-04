<?php
$user_role = $user_role ?? '';
$user_email = $user_email ?? '';
$view = $view ?? null;
$selected_section = $selected_section ?? null;
$current_tab = $current_tab ?? 'overview';
$tree = $tree ?? ['Midterm' => [], 'Finals' => []];
$student_scores = $student_scores ?? [];
$current_section_notes = $current_section_notes ?? '';
$has_section_notes = trim((string) $current_section_notes) !== '';
$duplicate_error = $duplicate_error ?? '';
$analytics_distribution = $analytics_distribution ?? [];
$analytics_passing = $analytics_passing ?? ['passing_count' => 0, 'total_count' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Workspace | EduPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/sidebar.php'; ?>
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
            <?php if (isset($_SESSION['flash_success'])): ?><div class="success-box"><?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div><?php endif; ?>
            <?php if (isset($_SESSION['flash_error'])): ?><div class="error-box"><?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div><?php endif; ?>
            <?php if ($user_role === 'System Admin'): ?>
                <?php if ($view === 'users'): ?>
                    <div class="data-card">
                        <div class="card-header">
                            <h3 style="font-size: 1rem;">System Accounts</h3>
                        </div>
                        <table>
                            <thead><tr><th>ID</th><th>Email Address</th><th>Role</th><th>Status</th><th style="text-align: right;">Manage</th></tr></thead>
                            <tbody>
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td style="color: var(--text-muted);">#<?php echo $u['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($u['email']); ?></strong></td>
                                            <td><?php echo $u['role']; ?></td>
                                            <?php $isSusp = strtolower($u['status']) === 'suspended'; ?>
                                            <td><span class="status-pill<?php echo $isSusp ? ' suspended' : ''; ?>"><?php echo htmlspecialchars($u['status']); ?></span></td>
                                            <td style="text-align: right;">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" name="toggle_status" class="btn-pill" style="padding:6px 10px;"><?php echo $isSusp ? 'Reactivate' : 'Suspend'; ?></button>
                                                </form>
                                                <form method="POST" style="display:inline; margin-left:8px;">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" name="reset_password" class="btn-pill" style="background:#f8fafc; color:var(--slate-700); padding:6px 10px;">Reset Password</button>
                                                </form>
                                                <?php if ($isSusp): ?>
                                                    <button type="button" class="btn-pill" style="background: var(--danger); color: white; margin-left:8px; padding:6px 10px;" onclick="openDeleteModal(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['email'])); ?>')">Delete</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="color: var(--text-muted);">No accounts found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Delete Confirmation Modal -->
                    <div id="deleteModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:2000;">
                        <div style="background: white; padding: 20px; border-radius: 8px; width: 420px; max-width: 90%;">
                            <h3 style="margin-top:0;">Confirm Delete</h3>
                            <p>Are you sure you want to <strong>permanently delete</strong> the user <span id="delUserEmail" style="font-weight:700;"></span>? This action cannot be undone.</p>
                            <form method="POST" id="deleteUserForm" style="display:flex; gap:8px; justify-content:flex-end;">
                                <input type="hidden" name="user_id" id="deleteUserId" value="">
                                <button type="button" onclick="closeDeleteModal()" class="btn-pill" style="background:#f3f4f6; color:var(--slate-700);">Cancel</button>
                                <button type="submit" name="confirm_delete_user" class="btn-pill" style="background: var(--danger); color: #fff;">Delete</button>
                            </form>
                        </div>
                    </div>

                    <script>
                        function openDeleteModal(id, email) {
                            document.getElementById('deleteUserId').value = id;
                            document.getElementById('delUserEmail').textContent = email;
                            document.getElementById('deleteModal').style.display = 'flex';
                        }
                        function closeDeleteModal() {
                            document.getElementById('deleteUserId').value = '';
                            document.getElementById('delUserEmail').textContent = '';
                            document.getElementById('deleteModal').style.display = 'none';
                        }
                    </script>
                <?php elseif ($view === 'logs'): ?>
                    <div class="data-card">
                        <div class="card-header"><h3 style="font-size: 1rem;">Recent Activity</h3></div>
                        <div style="margin-bottom: 10px; padding: 12px 20px 0 20px; display:flex; gap:12px; align-items:center;">
                            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                                <input type="hidden" name="view" value="logs">
                                <input type="text" name="q" placeholder="Search by email or action" value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>" style="padding:8px; border:1px solid var(--border-color); border-radius:6px;">
                                <input type="date" name="date" value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : ''; ?>" style="padding:8px; border:1px solid var(--border-color); border-radius:6px;">
                                <button type="submit" class="btn-pill">Filter</button>
                                <a href="dashboard.php?view=logs" class="btn-pill" style="background:#f8fafc; color:var(--slate-700); text-decoration:none;">Clear</a>
                            </form>
                        </div>
                        <table>
                            <thead><tr><th>Timestamp</th><th>User Account</th><th>Logged Action</th></tr></thead>
                            <tbody>
                                <?php if (!empty($logs)): ?>
                                    <?php foreach ($logs as $l): ?>
                                        <tr>
                                            <td style="color: var(--text-muted); font-size: 0.8rem;"><?php echo date("M d, Y • H:i:s", strtotime($l['log_time'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($l['user_email']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($l['action']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="color: var(--text-muted);">No log entries found.</td></tr>
                                <?php endif; ?>
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
                    <?php if ($has_section_notes): ?>
                        <div class="class-reminder-card">
                            <div class="class-reminder-label"><i class="fa-solid fa-bell"></i> Class Reminder</div>
                            <div class="class-reminder-text"><?php echo nl2br(htmlspecialchars($current_section_notes)); ?></div>
                        </div>
                    <?php endif; ?>
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
                                <div style="margin-bottom: 20px;"><label class="pane-label">Class Notes / Reminders</label><textarea name="notes" class="grade-input" style="width: 100%; height: 80px; text-align:left;"><?php echo htmlspecialchars($current_section_notes); ?></textarea></div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="edit_class" class="btn-save">Update Details</button>
                                    <button type="button" id="openDeleteClassModalBtn" class="btn-save" style="background: var(--danger);">Delete Class</button>
                                </div>
                            </form>
                            <form method="POST" id="deleteClassForm">
                                <input type="hidden" name="section_to_delete" value="<?php echo htmlspecialchars($selected_section); ?>">
                                <input type="hidden" name="delete_class" value="1">
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
                                window.dashboardAnalyticsData = {
                                    distributionData: <?php echo json_encode($analytics_distribution); ?>,
                                    passingCount: <?php echo (int) $analytics_passing['passing_count']; ?>,
                                    totalCount: <?php echo (int) $analytics_passing['total_count']; ?>
                                };
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

    <div class="modal-overlay" id="deleteClassModal" style="display: none;" onclick="if(event.target===this){this.style.display='none';}">
        <div class="error-modal delete-confirm-modal" onclick="event.stopPropagation()">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <h3>Delete Class Permanently?</h3>
            <p>
                This will delete <strong><?php echo htmlspecialchars($selected_section ?? 'this class'); ?></strong> and all related
                students, categories, assignments, and scores. This action cannot be undone.
            </p>
            <div class="delete-confirm-actions">
                <button type="button" class="btn-pill" id="cancelDeleteClassBtn">Cancel</button>
                <button type="button" class="btn-save" style="background: var(--danger);" id="confirmDeleteClassBtn">Yes, Delete Class</button>
            </div>
        </div>
    </div>

    <script src="assets/js/dashboard.js"></script>
    
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
