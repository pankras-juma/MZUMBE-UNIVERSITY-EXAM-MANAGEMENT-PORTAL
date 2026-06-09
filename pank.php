<?php
// 
// 1. DATABASE AUTO-SETUP ENGINE & SESSION MANAGEMENT
$host = "localhost";
$user = "root";
$pass = "";

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("Database Server Connection Failed: " . $conn->connect_error);
}

// Automatically create database if it doesn't exist
$conn->query("CREATE DATABASE IF NOT EXISTS mzumbe_sys");
$conn->select_db("mzumbe_sys");

// Core Table Structures
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'lecturer') NOT NULL,
    full_name VARCHAR(100) NOT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS classrooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(50) NOT NULL UNIQUE
)");

$conn->query("CREATE TABLE IF NOT EXISTS duties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT,
    course_name VARCHAR(100),
    exam_date DATE,
    exam_time TIME,
    classroom_id INT,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT,
    receiver_id INT,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT,
    file_name VARCHAR(255),
    file_path VARCHAR(255),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Insert default Admin if missing (Username: pank@gmail.com | Password: pankras1)
$checkAdmin = $conn->query("SELECT id FROM users WHERE username='pankras'");
if ($checkAdmin->num_rows == 0) {
    $adminPassword = password_hash('pankras1', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO users (username, password, role, full_name) VALUES ('admin', '$adminPassword', 'admin', 'Mzumbe Admin Panel')");
}

session_start();
$current_page = $_SERVER['PHP_SELF']; // Dynamic routing tracker

// 
// 2. BACKEND CONTROLLER ACTIONS
// 

// --- LOGOUT ACTION ---
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: " . $current_page);
    exit();
}

// --- AUTHENTICATION: LOGIN ---
if (isset($_POST['login'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = trim($_POST['password']);
    
    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['full_name'];
            header("Location: " . $current_page);
            exit();
        } else { echo "<script>alert('Error: Incorrect Password!');</script>"; }
    } else { echo "<script>alert('Error: Username not found!');</script>"; }
}

// --- AUTHENTICATION: REGISTRATION ---
if (isset($_POST['register'])) {
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = password_hash(trim($_POST['password']), PASSWORD_BCRYPT);
    
    $check = $conn->query("SELECT id FROM users WHERE username='$username'");
    if ($check->num_rows > 0) {
        echo "<script>alert('Error: Username already taken!');</script>";
    } else {
        $sql = "INSERT INTO users (username, password, role, full_name) VALUES ('$username', '$password', 'lecturer', '$fullname')";
        if ($conn->query($sql)) {
            echo "<script>alert('Success: Lecturer account created successfully!');</script>";
        }
    }
}

// --- ADMIN: MANAGE CLASSROOMS ---
if (isset($_POST['add_room']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $room = $conn->real_escape_string(trim($_POST['room_name']));
    if (!empty($room)) {
        $conn->query("INSERT INTO classrooms (room_name) VALUES ('$room')");
    }
    header("Location: " . $current_page . "?panel=classrooms"); exit();
}

// --- ADMIN: ASSIGN DUTY ---
if (isset($_POST['assign_duty']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $lecturer_id = $_POST['lecturer_id'];
    $course = $conn->real_escape_string($_POST['course']);
    $date = $_POST['date'];
    $time = $_POST['time'];
    $classroom_id = $_POST['classroom_id'];
    
    $conn->query("INSERT INTO duties (lecturer_id, course_name, exam_date, exam_time, classroom_id) VALUES ('$lecturer_id', '$course', '$date', '$time', '$classroom_id')");
    header("Location: " . $current_page . "?panel=duties"); exit();
}

// --- ADMIN: UPDATE EXSTING DUTY ---
if (isset($_POST['update_duty']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $duty_id = $_POST['duty_id'];
    $course = $conn->real_escape_string($_POST['course']);
    $date = $_POST['date'];
    $time = $_POST['time'];
    $classroom_id = $_POST['classroom_id'];
    
    $conn->query("UPDATE duties SET course_name='$course', exam_date='$date', exam_time='$time', classroom_id='$classroom_id' WHERE id=$duty_id");
    header("Location: " . $current_page . "?panel=duties"); exit();
}

// --- ADMIN: SEND MESSAGE ---
if (isset($_POST['send_msg_admin']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $to = $_POST['lecturer_id'];
    $msg = $conn->real_escape_string($_POST['message']);
    $sender = $_SESSION['user_id'];
    $conn->query("INSERT INTO messages (sender_id, receiver_id, message) VALUES ('$sender', '$to', '$msg')");
    header("Location: " . $current_page . "?panel=messages"); exit();
}

// --- LECTURER: SEND MESSAGE ---
if (isset($_POST['send_msg_lec']) && isset($_SESSION['role']) && $_SESSION['role'] == 'lecturer') {
    $msg = $conn->real_escape_string($_POST['message']);
    $my_id = $_SESSION['user_id'];
    $conn->query("INSERT INTO messages (sender_id, receiver_id, message) VALUES ('$my_id', '1', '$msg')");
    header("Location: " . $current_page); exit();
}

// --- LECTURER: UPLOAD DOCUMENT ---
if (isset($_POST['upload_doc']) && isset($_SESSION['role']) && $_SESSION['role'] == 'lecturer') {
    $my_id = $_SESSION['user_id'];
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
    
    $file_name = basename($_FILES["fileToUpload"]["name"]);
    $target_file = $target_dir . time() . "_" . $file_name;
    
    if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
        $conn->query("INSERT INTO documents (lecturer_id, file_name, file_path) VALUES ('$my_id', '$file_name', '$target_file')");
        echo "<script>alert('Document uploaded successfully!');</script>";
    } else { echo "<script>alert('Upload failed.');</script>"; }
}

// --- DELETION CONTROLLER MANAGER ---
if (isset($_GET['delete_room']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $id = $_GET['delete_room']; $conn->query("DELETE FROM classrooms WHERE id=$id");
    header("Location: " . $current_page . "?panel=classrooms"); exit();
}
if (isset($_GET['delete_duty']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $id = $_GET['delete_duty']; $conn->query("DELETE FROM duties WHERE id=$id");
    header("Location: " . $current_page . "?panel=duties"); exit();
}
if (isset($_GET['delete_doc']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $id = $_GET['delete_doc'];
    $doc = $conn->query("SELECT file_path FROM documents WHERE id=$id")->fetch_assoc();
    if(file_exists($doc['file_path'])) { unlink($doc['file_path']); }
    $conn->query("DELETE FROM documents WHERE id=$id");
    header("Location: " . $current_page . "?panel=documents"); exit();
}
if (isset($_GET['delete_msg']) && isset($_SESSION['role'])) {
    $id = $_GET['delete_msg'];
    if ($_SESSION['role'] == 'admin') {
        $conn->query("DELETE FROM messages WHERE id=$id");
        header("Location: " . $current_page . "?panel=messages");
    } else {
        $my_id = $_SESSION['user_id'];
        $conn->query("DELETE FROM messages WHERE id=$id AND receiver_id=$my_id");
        header("Location: " . $current_page);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mzumbe Invigilator Management System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 100vh; }
        .navbar { background: #800000; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .navbar a { color: white; text-decoration: none; font-weight: bold; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 4px; transition: 0.3s; }
        .navbar a:hover { background: rgba(0,0,0,0.4); }
        .wrapper { display: flex; flex: 1; }
        .sidebar { width: 260px; background: #1a252f; color: white; padding: 20px 10px; box-sizing: border-box; }
        .sidebar a { color: #dbdbdb; text-decoration: none; display: block; padding: 12px; margin: 6px 0; border-radius: 4px; transition: 0.2s; }
        .sidebar a:hover, .sidebar a.active { background: #800000; color: white; font-weight: bold; padding-left: 18px; }
        .main-content { flex: 1; padding: 30px; box-sizing: border-box; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px; }
        h2, h3 { color: #800000; margin-top: 0; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; }
        input, select, textarea { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 14px; }
        button { background: #800000; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 14px; transition: 0.3s; }
        button:hover { background: #a00000; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; border-radius: 6px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e8ed; }
        th { background-color: #800000; color: white; font-weight: 600; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        tr:hover { background-color: #f1f2f6; }
        .btn-del { color: #d9534f; text-decoration: none; font-weight: bold; }
        .btn-edit { color: #f0ad4e; text-decoration: none; font-weight: bold; margin-right: 12px; }
        
        /* Premium Layout for Landing Menu links */
        .landing-container { max-width: 800px; margin: 80px auto; text-align: center; padding: 0 20px; }
        .portal-links-grid { display: flex; gap: 25px; margin-top: 40px; justify-content: center; }
        .portal-link-card { flex: 1; background: white; padding: 40px 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); cursor: pointer; border: 1px solid #e1e8ed; transition: transform 0.3s, box-shadow 0.3s; text-decoration: none; display: block; color: #333; }
        .portal-link-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(128,0,0,0.15); border-color: #800000; }
        .portal-link-card h3 { color: #2c3e50; border: none; margin-bottom: 10px; padding: 0; }
        .portal-link-card:hover h3 { color: #800000; }
        .portal-icon { font-size: 40px; margin-bottom: 15px; display: inline-block; }
        
        /* Modal Style Login Container back button */
        .auth-wrapper { max-width: 480px; margin: 60px auto; padding: 0 20px; }
        .back-btn { display: inline-block; margin-bottom: 15px; color: #800000; text-decoration: none; font-weight: bold; font-size: 14px; }
        .back-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['user_id'])): 
    // Sub-view router for landing dashboard
    $view = isset($_GET['view']) ? $_GET['view'] : 'landing';
?>
<!-- 
    INTERFACE A: MULTI-AUTH PORTAL HUB (LANDING LINK DASHBOARD)
 -->
    <div class="navbar">
        <span style="font-size:20px; font-weight:bold; letter-spacing:1px; margin: 0 auto;">MZUMBE UNIVERSITY EXAM MANAGEMENT PORTAL</span>
    </div>

    <?php if ($view == 'landing'): ?>
        <!-- MAIN PORTAL DASHBOARD MENU LINKS -->
        <div class="landing-container">
            <h1 style="color: #2c3e50; font-size: 32px; margin-bottom: 10px;">Welcome to the Examination Hub</h1>
            <p style="color: #666; font-size: 16px;">Please select your targeted administrative or academic gateway below to clear workspace logs.</p>
            
            <div class="portal-links-grid">
                <!-- LINK FOR ADMINISTRATOR PORTAL -->
                <a href="<?php echo $current_page; ?>?view=admin_login" class="portal-link-card">
                    <span class="portal-icon">🔒</span>
                    <h3>Administrator Portal</h3>
                    <p style="color: #777; font-size: 13px; margin: 0;">Access security desk to manage invigilator allocations, classrooms metrics, and message dispatch pools.</p>
                </a>

                <!-- LINK FOR LECTURER SPACE -->
                <a href="<?php echo $current_page; ?>?view=lecturer_login" class="portal-link-card">
                    <span class="portal-icon">👨‍🏫</span>
                    <h3>Lecturer Space</h3>
                    <p style="color: #777; font-size: 13px; margin: 0;">Sign in to review assigned exam calendars, upload reports, and exchange directives logs.</p>
                </a>
            </div>
        </div>

    <?php elseif ($view == 'admin_login'): ?>
        <!-- ADMIN LOGIN SCREEN FORCED VIA LINK BUTTON -->
        <div class="auth-wrapper">
            <a href="<?php echo $current_page; ?>?view=landing" class="back-btn">← Return to Portal Hub Menu</a>
            <div class="card" style="border-top: 5px solid #800000;">
                <h2>Administrator Portal Login</h2>
                <p style="color:#666; font-size:13px; margin-bottom:20px;">Secure server checkpoint. Authorized backend specialists only.</p>
                <form method="POST" action="<?php echo $current_page; ?>">
                    <input type="text" name="username" placeholder="Admin Username" required>
                    <input type="password" name="password" placeholder="Admin Password" required>
                    <button type="submit" name="login">Access Admin Workspace</button>
                </form>
                <p style="font-size:11px; color:#aaa; margin-top:20px; text-align:center;">Default Registry Key: admin | admin123</p>
            </div>
        </div>

    <?php elseif ($view == 'lecturer_login'): ?>
        <!-- LECTURER LOGIN & DISCOVERY SCREEN FORCED VIA LINK BUTTON -->
        <div class="auth-wrapper">
            <a href="<?php echo $current_page; ?>?view=landing" class="back-btn">← Return to Portal Hub Menu</a>
            <div class="card" style="border-top: 5px solid #004d40;">
                <div id="lec_login_view">
                    <h2>Lecturer Space Login</h2>
                    <p style="color:#666; font-size:13px; margin-bottom:20px;">Provide assigned institutional key vectors to track exam allocation maps.</p>
                    <form method="POST" action="<?php echo $current_page; ?>">
                        <input type="text" name="username" placeholder="Lecturer Username" required>
                        <input type="password" name="password" placeholder="Password" required>
                        <button type="submit" name="login" style="background: #004d40;">Sign In As Lecturer</button>
                    </form>
                    <p style="text-align:center; margin-top:20px; font-size:13px;">
                        New Academic Staff? <a href="#" onclick="toggleLecView(true); return false;" style="color:#004d40; font-weight:bold;">Register Account Here</a>
                    </p>
                </div>

                <div id="lec_reg_view" style="display:none;">
                    <h2>Create Lecturer Account</h2>
                    <p style="color:#666; font-size:13px; margin-bottom:20px;">Register your parameters into database records for invigilation inclusion.</p>
                    <form method="POST" action="<?php echo $current_page; ?>">
                        <input type="text" name="fullname" placeholder="Full Professional Name" required>
                        <input type="text" name="username" placeholder="Choose Unique Username" required>
                        <input type="password" name="password" placeholder="Secure Password" required>
                        <button type="submit" name="register" style="background: #004d40;">Register System Credentials</button>
                    </form>
                    <p style="text-align:center; margin-top:20px; font-size:13px;">
                        Already possess account? <a href="#" onclick="toggleLecView(false); return false;" style="color:#004d40; font-weight:bold;">Return to Login</a>
                    </p>
                </div>
            </div>
        </div>
        <script>
            function toggleLecView(showReg) {
                document.getElementById('lec_login_view').style.display = showReg ? 'none' : 'block';
                document.getElementById('lec_reg_view').style.display = showReg ? 'block' : 'none';
            }
        </script>
    <?php endif; ?>

<?php elseif ($_SESSION['role'] == 'admin'): 
    $panel = isset($_GET['panel']) ? $_GET['panel'] : 'dashboard';
?>
<!-- 
    INTERFACE B: SYSTEM ADMINISTRATOR CONTROL SYSTEM
 -->
    <div class="navbar">
        <span><strong>MZUMBE SYSTEM COMMAND CENTER (Administrator Protected Mode)</strong></span>
        <span>Connected Account: <?php echo $_SESSION['name']; ?> | <a href="<?php echo $current_page; ?>?action=logout">Secure Logout</a></span>
    </div>

    <div class="wrapper">
        <div class="sidebar">
            <a href="<?php echo $current_page; ?>?panel=dashboard" class="<?php echo $panel == 'dashboard' ? 'active':''; ?>">Dashboard Overview</a>
            <a href="<?php echo $current_page; ?>?panel=classrooms" class="<?php echo $panel == 'classrooms' ? 'active':''; ?>">Manage Classrooms</a>
            <a href="<?php echo $current_page; ?>?panel=duties" class="<?php echo $panel == 'duties' ? 'active':''; ?>">Assign Duties Workspace</a>
            <a href="<?php echo $current_page; ?>?panel=messages" class="<?php echo $panel == 'messages' ? 'active':''; ?>">Lecturer Messages Log</a>
            <a href="<?php echo $current_page; ?>?panel=documents" class="<?php echo $panel == 'documents' ? 'active':''; ?>">Submitted Files Monitor</a>
        </div>

        <div class="main-content">
            <?php if ($panel == 'dashboard'): ?>
                <div class="card">
                    <h2>Admin Controller Dashboard Overview</h2>
                    <p>Welcome back, Administrator. Use the system layout panel on the left navigation matrix to allocate staff to duties, view messages, setup rooms, and analyze records.</p>
                </div>

            <?php elseif ($panel == 'classrooms'): ?>
                <div class="card">
                    <h2>Manage Mzumbe Classrooms / Examination Venues</h2>
                    <form method="POST" action="<?php echo $current_page; ?>">
                        <label style="font-weight:bold;">Register New Examination Classroom Infrastructure Venue:</label>
                        <input type="text" name="room_name" placeholder="E.g., SLR 1, New Assembly Hall, Main Theatre B" required>
                        <button type="submit" name="add_room">Register Classroom Location</button>
                    </form>
                    
                    <h3 style="margin-top:30px;">Currently Database Logged Classrooms</h3>
                    <table>
                        <tr><th>Unique Database Index ID</th><th>Configured Room / Venue Name</th><th>Action</th></tr>
                        <?php 
                        $rooms = $conn->query("SELECT * FROM classrooms ORDER BY id DESC");
                        while($r = $rooms->fetch_assoc()) {
                            echo "<tr>
                                    <td>#CR-00{$r['id']}</td>
                                    <td><strong>{$r['room_name']}</strong></td>
                                    <td><a class='btn-del' href='{$current_page}?delete_room={$r['id']}' onclick=\"return confirm('Confirm removal of this venue from system registries?');\">Delete Location</a></td>
                                  </tr>";
                        }
                        if($rooms->num_rows == 0) echo "<tr><td colspan='3'>No custom classrooms available in database registry. Please insert above.</td></tr>";
                        ?>
                    </table>
                </div>

            <?php elseif ($panel == 'duties'): ?>
                <?php if (isset($_GET['edit_duty_id'])): 
                    $edit_id = (int)$_GET['edit_duty_id'];
                    $duty = $conn->query("SELECT * FROM duties WHERE id=$edit_id")->fetch_assoc();
                ?>
                    <div class="card" style="border: 2px dashed #f0ad4e; background-color: #fffdf9;">
                        <h3>Modify Invigilation Assignment Allocation Block</h3>
                        <form method="POST" action="<?php echo $current_page; ?>">
                            <input type="hidden" name="duty_id" value="<?php echo $duty['id']; ?>">
                            <label>Course Designator Code / Title Name:</label>
                            <input type="text" name="course" value="<?php echo $duty['course_name']; ?>" required>
                            <label>Examination Scheduling Calendar Date:</label>
                            <input type="date" name="date" value="<?php echo $duty['exam_date']; ?>" required>
                            <label>Target Execution Clock Time:</label>
                            <input type="time" name="time" value="<?php echo $duty['exam_time']; ?>" required>
                            <label>Assigned Classroom:</label>
                            <select name="classroom_id" required>
                                <?php 
                                $rooms = $conn->query("SELECT * FROM classrooms");
                                while($r = $rooms->fetch_assoc()) {
                                    $sel = ($r['id'] == $duty['classroom_id']) ? 'selected' : '';
                                    echo "<option value='{$r['id']}' {$sel}>{$r['room_name']}</option>";
                                }
                                ?>
                            </select>
                            <button type="submit" name="update_duty" style="background:#f0ad4e;">Apply Data Changes Now</button>
                            <a href="<?php echo $current_page; ?>?panel=duties" style="margin-left:20px; color:#333; font-weight:bold;">Cancel Operation</a>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <h2>Assign Duties (With Real-time Venue Linking Selector Options)</h2>
                    <form method="POST" action="<?php echo $current_page; ?>">
                        <label>Select Staff Member (Lecturer):</label>
                        <select name="lecturer_id" required>
                            <option value="">-- Choose Target Lecturer Profile --</option>
                            <?php 
                            $lecs = $conn->query("SELECT * FROM users WHERE role='lecturer'");
                            while($l = $lecs->fetch_assoc()) echo "<option value='{$l['id']}'>{$l['full_name']}</option>";
                            ?>
                        </select>
                        <label>Course Title/Code Nomenclature:</label>
                        <input type="text" name="course" placeholder="E.g., ICT 211, BAF 100" required>
                        <label>Calendar Execution Date:</label>
                        <input type="date" name="date" required>
                        <label>Clock Time:</label>
                        <input type="time" name="time" required>
                        <label>Classroom/Venue:</label>
                        <select name="classroom_id" required>
                            <option value="">-- Choose Added Classroom Selection Option --</option>
                            <?php 
                            $rooms = $conn->query("SELECT * FROM classrooms");
                            while($r = $rooms->fetch_assoc()) echo "<option value='{$r['id']}'>{$r['room_name']}</option>";
                            ?>
                        </select>
                        <button type="submit" name="assign_duty">Deploy Allocation Schedule Matrix</button>
                    </form>
                </div>

                <div class="card">
                    <h3>Active Comprehensive Invigilation Matrix Registry Table</h3>
                    <table>
                        <tr><th>Invigilator (Lecturer Name)</th><th>Course Parameter</th><th>Calendar Date</th><th>Clock Time Slot</th><th>Allocated Venue Classroom</th><th>Actions Management</th></tr>
                        <?php 
                        $duties = $conn->query("SELECT duties.*, users.full_name, classrooms.room_name FROM duties 
                                                JOIN users ON duties.lecturer_id=users.id 
                                                JOIN classrooms ON duties.classroom_id=classrooms.id 
                                                ORDER BY duties.id DESC");
                        while($row = $duties->fetch_assoc()) {
                            echo "<tr>
                                <td><strong>{$row['full_name']}</strong></td>
                                <td>{$row['course_name']}</td>
                                <td>{$row['exam_date']}</td>
                                <td>{$row['exam_time']}</td>
                                <td><span style='background:#f1f2f6; padding:4px 8px; border-radius:4px; font-weight:bold;'>{$row['room_name']}</span></td>
                                <td>
                                    <a class='btn-edit' href='{$current_page}?panel=duties&edit_duty_id={$row['id']}'>Edit</a>
                                    <a class='btn-del' href='{$current_page}?delete_duty={$row['id']}' onclick=\"return confirm('Purge selected duty from database listings?');\">Delete Duties</a>
                                </td>
                            </tr>";
                        }
                        if($duties->num_rows == 0) echo "<tr><td colspan='6'>No active scheduling entries mapped out inside server blocks yet.</td></tr>";
                        ?>
                    </table>
                </div>

            <?php elseif ($panel == 'messages'): 
                $filter_lec = isset($_GET['filter_lecturer']) ? (int)$_GET['filter_lecturer'] : 0;
            ?>
                <div class="card">
                    <h2>Send Broadcast Direct Directives to Academic Staff Member</h2>
                    <form method="POST" action="<?php echo $current_page; ?>">
                        <select name="lecturer_id" required>
                            <option value="">-- Choose Target Lecturer Profile Destination --</option>
                            <?php 
                            $lecs = $conn->query("SELECT * FROM users WHERE role='lecturer'");
                            while($l = $lecs->fetch_assoc()) echo "<option value='{$l['id']}'>{$l['full_name']}</option>";
                            ?>
                        </select>
                        <textarea name="message" rows="3" placeholder="Input administrative notes instructions details here..." required></textarea>
                        <button type="submit" name="send_msg_admin">Dispatch Official Communication</button>
                    </form>
                </div>

                <div class="card" style="border: 2px solid #800000;">
                    <h2>View Incoming Stream Messages from Specific Lecturer</h2>
                    <form method="GET" action="<?php echo $current_page; ?>">
                        <input type="hidden" name="panel" value="messages">
                        <select name="filter_lecturer" onchange="this.form.submit()" style="border: 2px solid #800000; font-weight:bold; background:#fff;">
                            <option value="">-- CLICK HERE TO SELECT AND FILTER BY SPECIFIC LECTURER --</option>
                            <?php 
                            $lecs = $conn->query("SELECT * FROM users WHERE role='lecturer'");
                            while($l = $lecs->fetch_assoc()) {
                                $selected = ($l['id'] == $filter_lec) ? 'selected' : '';
                                echo "<option value='{$l['id']}' {$selected}>Incoming Messages From: {$l['full_name']}</option>";
                            }
                            ?>
                        </select>
                    </form>

                    <?php if ($filter_lec > 0): ?>
                        <h4 style="color:#2c3e50; margin-top:20px; text-transform:uppercase;">Isolating Communications Logs for Selected Lecturer Profile</h4>
                        <table>
                            <tr style="background:#2c3e50; color:white;"><th>Transmission Timestamp</th><th>Message String Content Text</th><th>Action</th></tr>
                            <?php 
                            $msgs = $conn->query("SELECT * FROM messages WHERE sender_id=$filter_lec ORDER BY created_at DESC");
                            while($m = $msgs->fetch_assoc()) {
                                echo "<tr>
                                    <td><small>{$m['created_at']}</small></td>
                                    <td><div style='background:#f9f9f9; padding:10px; border-left:3px solid #800000;'>{$m['message']}</div></td>
                                    <td><a class='btn-del' href='{$current_page}?delete_msg={$m['id']}' onclick=\"return confirm('Permanently remove this message?');\">Delete</a></td>
                                </tr>";
                            }
                            if($msgs->num_rows == 0) echo "<tr><td colspan='3' style='text-align:center; color:orange;'>No incoming messages registered from this isolated lecturer.</td></tr>";
                            ?>
                        </table>
                    <?php else: ?>
                        <div style="background:#e8effa; padding:15px; border-radius:5px; color:#2a4b7c; margin-top:15px; font-weight:bold; text-align:center;">
                            ← Please make a selection choice from the dropdown selector block above to load specific lecturer messages.
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($panel == 'documents'): ?>
                <div class="card">
                    <h2>Uploaded Academic Documentation Storage Repository</h2>
                    <table>
                        <tr><th>Sender Staff Name</th><th>Artifact Filename Registered</th><th>Uploaded At Timestamp</th><th>File Controls</th></tr>
                        <?php 
                        $docs = $conn->query("SELECT documents.*, users.full_name FROM documents JOIN users ON documents.lecturer_id=users.id ORDER BY documents.id DESC");
                        while($d = $docs->fetch_assoc()) {
                            echo "<tr>
                                <td><strong>{$d['full_name']}</strong></td>
                                <td>{$d['file_name']}</td>
                                <td>{$d['uploaded_at']}</td>
                                <td>
                                    <a href='{$d['file_path']}' download style='color:white; background:green; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:10px;'>Download</a>
                                    <a class='btn-del' href='{$current_page}?delete_doc={$d['id']}' onclick=\"return confirm('Evict document file permanently?');\">Delete File</a>
                                </td>
                            </tr>";
                        }
                        if($docs->num_rows == 0) echo "<tr><td colspan='4'>No document data elements uploaded onto file processing lines yet.</td></tr>";
                        ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: 
    $my_id = $_SESSION['user_id'];
?>
<!-- 
    INTERFACE C: LECTURER COMPONENT WORKSPACE
-->
    <div class="navbar" style="background: #004d40;">
        <span><strong>MZUMBE SYSTEM FACULTY SPACE (Lecturer Allocation Terminal Dashboard)</strong></span>
        <span>Welcome, Academic Staff: <?php echo $_SESSION['name']; ?> | <a href="<?php echo $current_page; ?>?action=logout">Secure Logout</a></span>
    </div>

    <div class="main-content">
        <div class="card" style="border-left:5px solid #004d40;">
            <h3>Your Assigned Active Examination Invigilation Allocation Schedules</h3>
            <table>
                <tr style="background:#004d40;"><th>Course Parameters Code/Title</th><th>Exam Calendar Date</th><th>Assigned Clock Start Time</th><th>Target Designated Classroom Venue</th></tr>
                <?php 
                $my_duties = $conn->query("SELECT duties.*, classrooms.room_name FROM duties 
                                            JOIN classrooms ON duties.classroom_id=classrooms.id 
                                            WHERE duties.lecturer_id=$my_id ORDER BY duties.id DESC");
                while($row = $my_duties->fetch_assoc()) {
                    echo "<tr>
                            <td><strong>{$row['course_name']}</strong></td>
                            <td>{$row['exam_date']}</td>
                            <td>{$row['exam_time']}</td>
                            <td><span style='background:#e0f2f1; padding:5px 10px; color:#004d40; border-radius:4px; font-weight:bold;'>{$row['room_name']}</span></td>
                          </tr>";
                }
                if($my_duties->num_rows == 0) echo "<tr><td colspan='4'>No active duty parameters mapped onto your schedule framework.</td></tr>";
                ?>
            </table>
        </div>

        <div class="card">
            <h3>Directives Inbox (Official Administrative Structural Guidance Notes Log)</h3>
            <table>
                <tr style="background:#004d40;"><th>System Timestamp Date</th><th>Official Administration Message Content Body</th><th>Row Action</th></tr>
                <?php 
                $my_msgs = $conn->query("SELECT * FROM messages WHERE receiver_id=$my_id ORDER BY created_at DESC");
                while($row = $my_msgs->fetch_assoc()) {
                    echo "<tr>
                        <td><small>{$row['created_at']}</small></td>
                        <td><div style='background:#fafafa; padding:8px; border-left:3px solid #004d40;'>{$row['message']}</div></td>
                        <td><a class='btn-del' href='{$current_page}?delete_msg={$row['id']}'>Clear Notice</a></td>
                    </tr>";
                }
                if($my_msgs->num_rows == 0) echo "<tr><td colspan='3'>Your message log history stack is currently clear.</td></tr>";
                ?>
            </table>
        </div>

        <div class="card">
            <h3>Send Immediate Response/Feedback Stream back to Admin Panel</h3>
            <form method="POST" action="<?php echo $current_page; ?>">
                <textarea name="message" rows="3" placeholder="Compose message details text block to pass back directly onto the administrative dashboard tables..." required></textarea>
                <button type="submit" name="send_msg_lec" style="background:#004d40;">Transmit Feedback Text</button>
            </form>
        </div>

        <div class="card">
            <h3>Submit Electronic Report Artifact Documentation (File Upload System)</h3>
            <form method="POST" enctype="multipart/form-data" action="<?php echo $current_page; ?>">
                <input type="file" name="fileToUpload" required><br><br>
                <button type="submit" name="upload_doc" style="background:#004d40;">Upload and Process Document File Structure</button>
            </form>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
