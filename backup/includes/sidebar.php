<style>
    .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; height: 100vh; }
    .sidebar-header { padding: 32px 24px; display: flex; align-items: center; gap: 12px; }
    .sidebar-header i { font-size: 1.5rem; color: #10b981; }
    .sidebar-header h2 { font-size: 1.25rem; font-weight: 800; letter-spacing: 0.5px; }

    .sidebar-menu { flex: 1; padding: 0 16px; }
    .menu-label { padding: 24px 12px 8px; font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
    .sidebar-menu a { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        padding: 12px; 
        color: #94a3b8; 
        text-decoration: none; 
        font-size: 0.9rem; 
        font-weight: 500; 
        border-radius: 8px; 
        transition: 0.2s;
    }
    .sidebar-menu a:hover, .sidebar-menu a.active { background: rgba(255,255,255,0.05); color: white; }
    .sidebar-menu a.active { color: #10b981; }

    .sidebar-footer { padding: 24px; border-top: 1px solid rgba(255,255,255,0.05); }
    .sidebar-footer a { color: #f87171; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; }
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <i class="fa-solid fa-graduation-cap"></i>
        <h2>EDUPULSE</h2>
    </div>
    
    <div class="sidebar-menu">
        <a href="dashboard.php" class="<?php echo (!isset($_GET['section']) && !isset($_GET['view'])) ? 'active' : ''; ?>">
            <i class="fa-solid fa-house"></i> Home
        </a>
        
        <?php if ($_SESSION['role'] === 'System Admin'): ?>
            <div class="menu-label">Admin</div>
            <a href="dashboard.php?view=users" class="<?php echo (isset($_GET['view']) && $_GET['view'] === 'users') ? 'active' : ''; ?>">
                <i class="fa-solid fa-users"></i> Users
            </a>
            <a href="dashboard.php?view=logs" class="<?php echo (isset($_GET['view']) && $_GET['view'] === 'logs') ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-check"></i> Audit Logs
            </a>
        <?php else: ?>
            
            <div class="menu-label" style="display: flex; justify-content: space-between; align-items: center;">
                Assigned Sections
                <a href="dashboard.php" style="padding: 0; color: #10b981; margin: 0; display: inline-flex;" title="Add New Class">
                    <i class="fa-solid fa-circle-plus"></i>
                </a>
            </div>

            <?php 
            // ISOLATION FIX: Only load sections owned by the logged-in email
            $stmt_side = $conn->prepare("SELECT section_name FROM sections WHERE owner_email = ?");
            $stmt_side->bind_param("s", $_SESSION['email']);
            $stmt_side->execute();
            $res_side = $stmt_side->get_result();
            while($sec = $res_side->fetch_assoc()): 
                $active = (isset($_GET['section']) && $_GET['section'] == $sec['section_name']) ? 'active' : '';
            ?>
                <a href="dashboard.php?section=<?php echo urlencode($sec['section_name']); ?>" class="<?php echo $active; ?>">
                    <i class="fa-solid fa-users-rectangle"></i> <?php echo htmlspecialchars($sec['section_name']); ?>
                </a>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Secure Logout</a>
    </div>
</div>