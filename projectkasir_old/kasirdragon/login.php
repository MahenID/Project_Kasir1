<?php 
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error_message = '';
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DRAGONMART System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Animated background elements */
        .bg-decoration {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .floating-shape {
            position: absolute;
            opacity: 0.1;
            animation: float 15s ease-in-out infinite;
        }

        .shape-1 {
            top: 10%;
            left: 10%;
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            animation-delay: 0s;
        }

        .shape-2 {
            top: 70%;
            right: 10%;
            width: 120px;
            height: 120px;
            background: white;
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            animation-delay: 3s;
        }

        .shape-3 {
            bottom: 20%;
            left: 20%;
            width: 100px;
            height: 100px;
            background: white;
            clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%);
            animation-delay: 6s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-30px) rotate(10deg); }
            66% { transform: translateY(20px) rotate(-10deg); }
        }

        .login-container {
            position: relative;
            z-index: 1;
            max-width: 420px;
            width: 100%;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.05"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .dragon-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .login-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .login-subtitle {
            opacity: 0.9;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        .login-body {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 15px 20px 15px 50px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: translateY(-2px);
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1.1rem;
            z-index: 2;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px;
            z-index: 2;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px;
            margin-bottom: 20px;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .loading {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .login-footer {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 0.85rem;
        }

        @media (max-width: 576px) {
            .login-container {
                padding: 10px;
            }
            
            .login-body {
                padding: 30px 25px;
            }
            
            .dragon-icon {
                font-size: 2.5rem;
            }
            
            .login-title {
                font-size: 1.5rem;
            }
        }

        /* Form validation styles */
        .form-control.is-invalid {
            border-color: #dc3545;
            animation: shake 0.3s ease-in-out;
        }

        .form-control.is-valid {
            border-color: #28a745;
        }

        .invalid-feedback {
            display: block;
            width: 100%;
            margin-top: 5px;
            font-size: 0.875rem;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="bg-decoration">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        <div class="floating-shape shape-3"></div>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="dragon-icon">🐉</div>
                <h1 class="login-title">DRAGONMART</h1>
                <p class="login-subtitle">Sistem Kasir Profesional</p>
            </div>

            <div class="login-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="cek_login.php" id="loginForm" novalidate>
                    <div class="form-group">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" 
                               name="username" 
                               id="username"
                               class="form-control" 
                               placeholder="Username" 
                               required
                               autocomplete="username">
                        <div class="invalid-feedback"></div>
                    </div>

                    <div class="form-group">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" 
                               name="password" 
                               id="password"
                               class="form-control" 
                               placeholder="Password" 
                               required
                               autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                        <div class="invalid-feedback"></div>
                    </div>

                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <span class="btn-text">Masuk Sistem</span>
                        <div class="loading">
                            <div class="spinner"></div>
                        </div>
                    </button>
                </form>

                <div class="forgot-password">
                    <a href="#" id="forgotPassword">Lupa Password?</a>
                </div>
            </div>

            <div class="login-footer">
                <i class="fas fa-shield-alt me-1"></i>
                Sistem Keamanan Aktif - © 2025 DRAGONMART
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const usernameInput = document.getElementById('username');

            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });


            function validateForm() {
                let isValid = true;
                
                if (usernameInput.value.trim().length < 3) {
                    showError(usernameInput, 'Username minimal 3 karakter');
                    isValid = false;
                } else {
                    showSuccess(usernameInput);
                }

                if (passwordInput.value.length < 3) {
                    showError(passwordInput, 'Password minimal 3 karakter');
                    isValid = false;
                } else {
                    showSuccess(passwordInput);
                }

                return isValid;
            }

            function showError(input, message) {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                const feedback = input.nextElementSibling;
                if (feedback && feedback.classList.contains('invalid-feedback')) {
                    feedback.textContent = message;
                }
            }

            function showSuccess(input) {
                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
            }


            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (validateForm()) {

                    const btnText = loginBtn.querySelector('.btn-text');
                    const loading = loginBtn.querySelector('.loading');
                    
                    btnText.style.opacity = '0';
                    loading.style.display = 'block';
                    loginBtn.disabled = true;
                    
                    setTimeout(() => {
                        this.submit();
                    }, 1000);
                }
            });


            usernameInput.addEventListener('input', function() {
                if (this.value.trim().length >= 3) {
                    showSuccess(this);
                } else if (this.value.trim().length > 0) {
                    showError(this, 'Username minimal 3 karakter');
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            });

            passwordInput.addEventListener('input', function() {
                if (this.value.length >= 6) {
                    showSuccess(this);
                } else if (this.value.length > 0) {
                    showError(this, 'Password minimal 6 karakter');
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            });


            document.getElementById('forgotPassword').addEventListener('click', function(e) {
                e.preventDefault();
                alert('Fitur reset password belum tersedia. Silakan hubungi administrator.');
            });

            usernameInput.focus();
        });
    </script>
</body>
</html>