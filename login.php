<?php
session_start();

$role = isset($_GET['role']) ? $_GET['role'] : 'student';
$allowed_roles = ['student', 'faculty', 'hod', 'admin'];
if (!in_array($role, $allowed_roles)) {
    $role = 'student';
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    require_once 'config.php';

    $user = null;
    $table = '';
    
    if ($role === 'student') {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE BINARY zprn = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    } elseif ($role === 'faculty') {
        $stmt = $pdo->prepare("SELECT * FROM faculty WHERE BINARY username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    } elseif ($role === 'hod') {
        $stmt = $pdo->prepare("SELECT * FROM hod WHERE BINARY username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    } elseif ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE BINARY username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    }

    if ($user && password_verify($password, $user['password'])) {
        // Strict case-sensitive check against stored username (Bugs 4 & 20)
        $stored_username = ($role === 'student') ? $user['zprn'] : $user['username'];
        if ($stored_username !== $username) {
            $error_message = 'Invalid username or password for ' . ucfirst($role) . ' portal.';
        } else {
            // Students do not need to be forced to change password after immediate login
            /*
            if ($role === 'student' && $user['must_change_password'] == 1) {
                $_SESSION['temp_zprn'] = $user['zprn'];
                header("Location: change_password.php");
                exit;
            }
            */

        $_SESSION['user'] = [
            'id' => ($role === 'student') ? $user['zprn'] : $user['username'],
            'username' => ($role === 'student') ? $user['zprn'] : $user['username'],
            'name' => ($role === 'student') ? $user['student_name'] : $user['name'],
            'dept' => ($role === 'student') ? ($user['department'] . ' - Div ' . $user['division']) : (($role === 'hod') ? $user['department'] : 'IT Department'),
            'avatar' => $user['avatar']
        ];
        $_SESSION['role'] = $role;
        
        switch ($role) {
            case 'student': header("Location: student_dashboard.php"); exit;
            case 'faculty': header("Location: faculty_dashboard.php"); exit;
            case 'hod': header("Location: hod_dashboard.php"); exit;
            case 'admin': header("Location: admin_dashboard.php"); exit;
        }
        }
    } else {
        if (empty($error_message)) {
            $error_message = 'Invalid username or password for ' . ucfirst($role) . ' portal.';
        }
    }
}

// Display page titles based on roles
$role_title = ucfirst($role);
$page_theme_class = "theme-" . $role;
$role_description = "";
$role_subtitle = "";

switch ($role) {
    case 'student':
        $role_description = "Sign in to access your academic dashboard, assignments, attendance, grievances, notices and other student services.";
        $role_subtitle = "Please login to your student account";
        break;
    case 'faculty':
        $role_description = "Sign in to manage classes, publish assignments, review submissions, and approve leave requests.";
        $role_subtitle = "Please login to your faculty account";
        break;
    case 'hod':
        $role_description = "Access HOD controls to oversee department activities, monitor faculty progress, and view reports.";
        $role_subtitle = "Please login to your HOD account";
        break;
    case 'admin':
        $role_description = "Access system-wide administration, configurations, and global logs.";
        $role_subtitle = "Please login to your administrator account";
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College ERP Portal - <?php echo $role_title; ?> Login</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="<?php echo $page_theme_class; ?>">
    <div class="login-container">
        <!-- Left Side Panel (Illustration and Branding) -->
        <div class="login-left">
            <div class="login-logo" onclick="window.location.href='index.php'" style="cursor:pointer">
                <i class="fa-solid fa-graduation-cap"></i>
                <span>College ERP Portal</span>
            </div>
            
            <div class="login-illustration-box">
                <!-- Using the generated premium 3D student illustration -->
                <img src="assets/images/login_illustration.png?v=<?php echo time(); ?>" alt="Portal Login Illustration">
            </div>
        </div>

        <!-- Right Side Panel (Login Form) -->
        <div class="login-right">
            <div class="login-card glass-container">
                <div class="login-card-header">
                    <div class="avatar-box">
                        <?php if ($role === 'student'): ?>
                            <i class="fa-solid fa-user-graduate"></i>
                        <?php elseif ($role === 'faculty'): ?>
                            <i class="fa-solid fa-chalkboard-user"></i>
                        <?php elseif ($role === 'hod'): ?>
                            <i class="fa-solid fa-users-viewfinder"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-user-shield"></i>
                        <?php endif; ?>
                    </div>
                    <h3>Welcome Back!</h3>
                    <p><?php echo $role_subtitle; ?></p>
                </div>

                <!-- Error Messages Box -->
                <div class="error-message" id="errorMessage" <?php echo !empty($error_message) ? 'style="display:flex;"' : ''; ?>>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span id="errorText"><?php echo $error_message; ?></span>
                </div>

                <form id="loginForm" method="POST" action="login.php?role=<?php echo $role; ?>" onsubmit="return validateForm(event)">
                    <input type="hidden" name="role" value="<?php echo $role; ?>">
                    
                    <!-- Username Field -->
                    <div class="form-group">
                        <label for="username">
                            <i class="fa-solid fa-user"></i> 
                            <span><?php echo $role_title; ?> Username</span>
                        </label>
                        <div class="input-wrapper">
                             <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus
                                    value="<?php echo ($role === 'student') ? '125UIT1195' : (($role === 'faculty') ? 'em2.faculty' : (($role === 'hod') ? 'hod1' : (($role === 'admin') ? 'admin1' : ''))); ?>">
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group">
                        <label for="password">
                            <i class="fa-solid fa-lock"></i> 
                            <span>Password</span>
                        </label>
                        <div class="input-wrapper">
                             <input type="password" id="password" name="password" placeholder="Enter your password" required
                                    value="<?php echo ($role === 'admin') ? '12345' : (($role === 'student') ? '125UIT1195' : '12345678'); ?>">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                                <i class="fa-regular fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Captcha Verification Field -->
                    <div class="form-group">
                        <label for="captchaInput">
                            <i class="fa-solid fa-shield-halved"></i> 
                            <span>Captcha</span>
                        </label>
                        <div class="captcha-container">
                            <div class="captcha-box-wrapper">
                                <canvas id="captchaCanvas" class="captcha-canvas" width="140" height="38"></canvas>
                            </div>
                            <button type="button" class="btn-refresh-captcha" onclick="generateCaptcha()" title="Refresh Captcha">
                                <i class="fa-solid fa-rotate-right"></i>
                            </button>
                        </div>
                        <div class="input-wrapper" style="margin-top: 0.75rem;">
                            <input type="text" id="captchaInput" placeholder="Enter captcha" required autocomplete="off">
                        </div>
                    </div>

                    <!-- Additional Options -->
                    <div class="login-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            <span>Remember Me</span>
                        </label>
                        <a href="#" class="forgot-password" onclick="forgotPasswordFlow(); return false;">Forgot Password?</a>
                    </div>

                    <!-- Login Submit Button -->
                    <button type="submit" class="btn-login">
                        <span>Sign In</span>
                        <i class="fa-solid fa-right-to-bracket"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Client-side Captcha & Forms Handling JavaScript -->
    <script>
        let currentCaptchaText = '';

        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Renders captcha text onto HTML canvas
        function generateCaptcha() {
            const canvas = document.getElementById('captchaCanvas');
            const ctx = canvas.getContext('2d');
            
            // Clear canvas
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Generate random 6 characters code
            const chars = 'A7K9PQ3M8ZX2Y4W5V6R';
            currentCaptchaText = '';
            for (let i = 0; i < 6; i++) {
                currentCaptchaText += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            // Background decorations (lines)
            ctx.fillStyle = '#f3f4f6';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            // Draw grid noise
            ctx.strokeStyle = 'rgba(79, 70, 229, 0.08)';
            ctx.lineWidth = 1;
            for(let i = 0; i < canvas.width; i += 10) {
                ctx.beginPath();
                ctx.moveTo(i, 0);
                ctx.lineTo(i, canvas.height);
                ctx.stroke();
            }
            for(let i = 0; i < canvas.height; i += 10) {
                ctx.beginPath();
                ctx.moveTo(0, i);
                ctx.lineTo(canvas.width, i);
                ctx.stroke();
            }

            // Draw noise lines
            for (let i = 0; i < 4; i++) {
                ctx.strokeStyle = `rgba(79, 70, 229, ${Math.random() * 0.2 + 0.1})`;
                ctx.beginPath();
                ctx.moveTo(Math.random() * canvas.width, Math.random() * canvas.height);
                ctx.lineTo(Math.random() * canvas.width, Math.random() * canvas.height);
                ctx.stroke();
            }
            
            // Draw text
            ctx.font = 'bold 20px "Outfit", sans-serif';
            ctx.textBaseline = 'middle';
            
            for (let i = 0; i < currentCaptchaText.length; i++) {
                const char = currentCaptchaText[i];
                ctx.fillStyle = `hsl(${Math.random() * 360}, 50%, 40%)`;
                
                // Add rotation and scaling distortion
                ctx.save();
                const x = 15 + i * 20;
                const y = canvas.height / 2;
                ctx.translate(x, y);
                ctx.rotate((Math.random() - 0.5) * 0.3);
                ctx.fillText(char, 0, 0);
                ctx.restore();
            }
        }

        function validateForm(event) {
            const captchaInput = document.getElementById('captchaInput').value.trim().toUpperCase();
            const errorMessage = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');

            if (captchaInput !== currentCaptchaText) {
                event.preventDefault(); // Stop submission
                errorMessage.style.display = 'flex';
                errorText.textContent = 'Captcha validation failed. Please try again.';
                generateCaptcha();
                document.getElementById('captchaInput').value = '';
                return false;
            }
            return true;
        }

        // Initialize captcha on load
        window.onload = function() {
            generateCaptcha();
        };

        function forgotPasswordFlow() {
            const usernameInput = document.getElementById('username').value.trim();
            if (!usernameInput) {
                Swal.fire({
                    title: 'Error',
                    text: 'Please enter your username first before resetting your password.',
                    icon: 'error',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            const roleInput = document.querySelector('input[name="role"]').value;
            
            // Show loading state
            Swal.fire({
                title: 'Sending OTP...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('username', usernameInput);
            formData.append('role', roleInput);
            
            fetch('send_otp.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire('Error', data.message, 'error');
                    return;
                }
                
                Swal.fire({
                    title: 'OTP Sent',
                    text: 'An OTP has been sent securely to your registered email address.',
                    icon: 'info',
                    confirmButtonText: 'Enter OTP',
                    confirmButtonColor: '#6366f1'
                }).then(() => {
                    Swal.fire({
                        title: 'Reset Password',
                        html:
                            '<input id="swal-input-otp" class="swal2-input" placeholder="Enter the 4-digit OTP" type="text">' +
                            '<input id="swal-input-pass" class="swal2-input" type="password" placeholder="Enter new password">',
                        focusConfirm: false,
                        showCancelButton: true,
                        confirmButtonText: 'Reset Password',
                        confirmButtonColor: '#10b981',
                        preConfirm: () => {
                            const otp = document.getElementById('swal-input-otp').value;
                            const pass = document.getElementById('swal-input-pass').value;
                            if (!otp || !pass) {
                                Swal.showValidationMessage('Please enter both OTP and new password');
                            }
                            return { otp: otp, new_password: pass };
                        }
                    }).then((formResult) => {
                        if (formResult.isConfirmed) {
                            const resetData = new FormData();
                            resetData.append('username', usernameInput);
                            resetData.append('new_password', formResult.value.new_password);
                            resetData.append('otp', formResult.value.otp);
                            
                            fetch('reset_password.php', {
                                method: 'POST',
                                body: resetData
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    Swal.fire('Updated!', data.message, 'success');
                                } else {
                                    Swal.fire('Error', data.message, 'error');
                                }
                            })
                            .catch(() => {
                                Swal.fire('Error', 'An unexpected error occurred.', 'error');
                            });
                        }
                    });
                });
            })
            .catch(() => {
                Swal.fire('Error', 'Failed to send OTP.', 'error');
            });
        }
    </script>
</body>
</html>
