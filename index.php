<?php
require_once __DIR__ . '/db.php';

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'Patient') {
        header("Location: patient_dashboard.php");
    } elseif (in_array($_SESSION['user_role'], ['Staff', 'Clinical Staff'])) {
        header("Location: staff_dashboard.php");
    } elseif ($_SESSION['user_role'] === 'Admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: doctor_dashboard.php");
    }
    exit();
}

$error_msg = "";
$success_msg = "";
$active_panel = ""; // to persist right-panel-active if register fails

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // --- REGISTER USER ---
        if ($_POST['action'] === 'register') {
            $active_panel = "right-panel-active"; // keep registration panel active on error
            
            $surname = trim($_POST['surname'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $name = trim(trim($first_name . ' ' . $middle_name) . ' ' . $surname);
            
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $role = trim($_POST['role'] ?? '');

            // Validations
            if (empty($surname) || empty($first_name) || !$email || empty($password) || empty($role)) {
                $error_msg = "Please fill in all fields correctly.";
            } elseif (strlen($password) < 6) {
                $error_msg = "Password must be at least 6 characters long.";
            } elseif ($password !== $confirm_password) {
                $error_msg = "Passwords do not match.";
            } elseif (!in_array($role, ['Patient', 'Doctor', 'Clinical Staff', 'Staff', 'Admin'])) {
                $error_msg = "Invalid role selected.";
            } else {
                // Check if email already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $result = $stmt->execute();
                
                if ($result->fetchArray(SQLITE3_ASSOC)) {
                    $error_msg = "This email is already registered.";
                } else {
                    // Hash password and insert
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $insert_stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)");
                    $insert_stmt->bindValue(':name', $name, SQLITE3_TEXT);
                    $insert_stmt->bindValue(':email', $email, SQLITE3_TEXT);
                    $insert_stmt->bindValue(':password', $hashed_password, SQLITE3_TEXT);
                    $insert_stmt->bindValue(':role', $role, SQLITE3_TEXT);
                    
                    if ($insert_stmt->execute()) {
                        $success_msg = "Registration successful! You can now log in.";
                        $active_panel = ""; // switch back to login panel
                    } else {
                        $error_msg = "An error occurred during registration. Please try again.";
                    }
                }
            }
        }
        
        // --- LOGIN USER ---
        elseif ($_POST['action'] === 'login') {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';

            if (!$email || empty($password)) {
                $error_msg = "Please enter a valid email and password.";
            } else {
                $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $result = $stmt->execute();
                $user = $result->fetchArray(SQLITE3_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    // Check if archived/deactivated
                    if (isset($user['status']) && in_array($user['status'], ['Archived', 'Inactive'])) {
                        log_audit_action($user['id'], $user['name'], "Deactivated login attempt", "Email: " . $email);
                        $error_msg = "Your account has been deactivated/archived. Please contact support.";
                    } else {
                        // Set Session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        
                        log_audit_action($user['id'], $user['name'], "Successful login", "Role: " . $user['role']);
                        
                        if ($user['role'] === 'Patient') {
                            header("Location: patient_dashboard.php");
                        } elseif (in_array($user['role'], ['Staff', 'Clinical Staff'])) {
                            header("Location: staff_dashboard.php");
                        } elseif ($user['role'] === 'Admin') {
                            header("Location: admin_dashboard.php");
                        } else {
                            header("Location: doctor_dashboard.php");
                        }
                        exit();
                    }
                } else {
                    log_audit_action(null, $email, "Failed login attempt", "Attempted email: " . $email);
                    $error_msg = "Incorrect email or password.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CLINICK - Login & Registration</title>
    <meta name="description" content="Secure portal to access the CLINICK Doctor and Patient platform.">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <script src="js/theme-controller.js?v=<?php echo filemtime('js/theme-controller.js'); ?>"></script>
</head>
<body>

    <!-- No decorative elements — flat professional background -->

    <!-- Login / Registration Form Card Container -->
        <div class="container <?php echo $active_panel; ?>" id="container">
            
            <!-- Sign Up Form Panel -->
            <div class="form-container sign-up-container">
                <form action="index.php" method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="register">
                    <h1>Create Account</h1>
                    <p>Enter your details to register a clinical account</p>
                    
                    <?php if (!empty($error_msg) && $active_panel === "right-panel-active"): ?>
                        <div class="alert alert-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span><?php echo htmlspecialchars($error_msg); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="form-row-3">
                        <input type="text" name="surname" class="form-control-simple" placeholder="Surname" required value="<?php echo isset($_POST['surname']) && $active_panel ? htmlspecialchars($_POST['surname']) : ''; ?>">
                        <input type="text" name="first_name" class="form-control-simple" placeholder="First Name" required value="<?php echo isset($_POST['first_name']) && $active_panel ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                        <input type="text" name="middle_name" class="form-control-simple" placeholder="Middle Name" value="<?php echo isset($_POST['middle_name']) && $active_panel ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <input type="email" name="email" class="form-control" placeholder="Email Address" required value="<?php echo isset($_POST['email']) && $active_panel ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <i class="fa-solid fa-envelope"></i>
                    </div>

                    <div class="form-group">
                        <select name="role" class="form-control" required>
                            <option value="" disabled <?php echo !isset($_POST['role']) ? 'selected' : ''; ?>>Select Clinical Role</option>
                            <option value="Patient" <?php echo isset($_POST['role']) && $_POST['role'] === 'Patient' ? 'selected' : ''; ?>>Patient</option>
                            <option value="Doctor" <?php echo isset($_POST['role']) && $_POST['role'] === 'Doctor' ? 'selected' : ''; ?>>Doctor</option>
                            <option value="Clinical Staff" <?php echo isset($_POST['role']) && $_POST['role'] === 'Clinical Staff' ? 'selected' : ''; ?>>Clinical Staff</option>
                            <option value="Admin" <?php echo isset($_POST['role']) && $_POST['role'] === 'Admin' ? 'selected' : ''; ?>>Administrator</option>
                        </select>
                        <i class="fa-solid fa-user-tag"></i>
                    </div>
                    
                    <div class="form-group">
                        <input type="password" id="reg-password" name="password" class="form-control" placeholder="Password (Min. 6 characters)" required minlength="6">
                        <i class="fa-solid fa-lock"></i>
                    </div>

                    <div class="form-group">
                        <input type="password" id="reg-confirm-password" name="confirm_password" class="form-control" placeholder="Confirm Password" required minlength="6">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fa-solid fa-user-plus"></i> Sign Up
                    </button>

                    <div class="mobile-toggle">
                        Already have an account? <a href="#" id="toSignInMobile" class="form-link">Sign In</a>
                    </div>
                </form>
            </div>

            <!-- Sign In Form Panel -->
            <div class="form-container sign-in-container">
                <form action="index.php" method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="brand">
                        <span class="brand-mark">CL</span>
                        CLINICK
                    </div>

                    <h2>Welcome Back</h2>
                    <p>Access your secure clinical workspace</p>
                    
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?php echo htmlspecialchars($success_msg); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg) && $active_panel === ""): ?>
                        <div class="alert alert-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span><?php echo htmlspecialchars($error_msg); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <input type="email" name="email" class="form-control" placeholder="Email Address" required value="<?php echo isset($_POST['email']) && !$active_panel ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    
                    <div class="form-group">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fa-solid fa-right-to-bracket"></i> Sign In
                    </button>

                    <div class="mobile-toggle">
                        New to CLINICK? <a href="#" id="toSignUpMobile" class="form-link">Register Now</a>
                    </div>
                </form>
            </div>

            <!-- Decorative Overlay Panel (Sliding transition background) -->
            <div class="overlay-container">
                <div class="overlay">
                    <div class="overlay-panel overlay-left">
                        <h2>Already Registered?</h2>
                        <p>Access your files, schedules, and clinical messages immediately.</p>
                        <button class="btn btn-outline" id="signIn">
                            <i class="fa-solid fa-chevron-left"></i> Sign In
                        </button>
                    </div>
                    <div class="overlay-panel overlay-right">
                        <h2>First Time Here?</h2>
                        <p>Register a secure account to schedule appointments and view clinical reports.</p>
                        <button class="btn btn-outline" id="signUp">
                            Sign Up <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
        </div>

    <!-- Client-side Logic Script -->
    <script src="app.js"></script>
</body>
</html>
