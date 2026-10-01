<?php
session_start();

// Security check - redirect if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get user data from session with defaults
$username = htmlspecialchars($_SESSION['username']);
$role = isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'User';
$login_time = isset($_SESSION['login_time']) ? $_SESSION['login_time'] : date('Y-m-d H:i:s');
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'N/A';
$last_activity = isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : time();

// Calculate session duration
$session_duration = time() - strtotime($login_time);
$hours = floor($session_duration / 3600);
$minutes = floor(($session_duration % 3600) / 60);

// Role-based styling and permissions
$role_colors = [
    'Admin' => ['bg' => 'linear-gradient(135deg, #ff6b6b, #ee5a24)', 'icon' => '👑'],
    'Manager' => ['bg' => 'linear-gradient(135deg, #4834d4, #686de0)', 'icon' => '⚡'],
    'Kasir' => ['bg' => 'linear-gradient(135deg, #00d2d3, #54a0ff)', 'icon' => '💰'],
    'User' => ['bg' => 'linear-gradient(135deg, #5f27cd, #a55eea)', 'icon' => '👤']
];

$current_role_style = $role_colors[$role] ?? $role_colors['User'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Pengguna - Dragon Cashier</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background particles */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            animation: float 15s ease-in-out infinite;
        }

        .particle:nth-child(1) { left: 10%; animation-delay: 0s; }
        .particle:nth-child(2) { left: 20%; animation-delay: 2s; }
        .particle:nth-child(3) { left: 30%; animation-delay: 4s; }
        .particle:nth-child(4) { left: 40%; animation-delay: 6s; }
        .particle:nth-child(5) { left: 50%; animation-delay: 8s; }
        .particle:nth-child(6) { left: 60%; animation-delay: 10s; }
        .particle:nth-child(7) { left: 70%; animation-delay: 12s; }
        .particle:nth-child(8) { left: 80%; animation-delay: 14s; }
        .particle:nth-child(9) { left: 90%; animation-delay: 16s; }

        @keyframes float {
            0%, 100% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-100px) rotate(360deg); opacity: 0; }
        }

        .main-container {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .profile-card {
            max-width: 900px;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 25px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .profile-header {
            background: <?php echo $current_role_style['bg']; ?>;
            color: white;
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255,255,255,0.05) 10px,
                rgba(255,255,255,0.05) 20px
            );
            animation: slide 20s linear infinite;
        }

        @keyframes slide {
            0% { transform: translateX(-50px) translateY(-50px); }
            100% { transform: translateX(50px) translateY(50px); }
        }

        .avatar-container {
            position: relative;
            z-index: 2;
            margin-bottom: 20px;
        }

        .avatar {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 20px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .username {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .role-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            position: relative;
            z-index: 2;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .profile-body {
            padding: 40px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .info-card-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .info-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            margin-right: 15px;
        }

        .info-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .info-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #34495e;
            margin-bottom: 5px;
        }

        .info-desc {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .stats-container {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            text-align: center;
        }

        .stats-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .actions-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-custom {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-secondary-custom {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
            color: white;
        }

        .btn-danger-custom {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-danger-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
            color: white;
        }

        .activity-indicator {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            background: rgba(40, 167, 69, 0.9);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .activity-dot {
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            margin-right: 8px;
            animation: blink 1.5s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        @media (max-width: 768px) {
            .profile-header {
                padding: 30px 20px;
            }
            
            .profile-body {
                padding: 30px 20px;
            }
            
            .username {
                font-size: 2rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-container {
                flex-direction: column;
            }
            
            .btn-custom {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="main-container">
        <div class="profile-card">
            <div class="activity-indicator">
                <div class="activity-dot"></div>
                Online
            </div>

            <div class="profile-header">
                <div class="avatar-container">
                    <div class="avatar">
                        <?php echo $current_role_style['icon']; ?>
                    </div>
                </div>
                <h1 class="username"><?php echo $username; ?></h1>
                <div class="role-badge">
                    <i class="fas fa-user-tag me-2"></i><?php echo $role; ?>
                </div>
            </div>

            <div class="profile-body">
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon">
                                <i class="fas fa-id-badge"></i>
                            </div>
                            <div class="info-title">ID Pengguna</div>
                        </div>
                        <div class="info-value"><?php echo $user_id; ?></div>
                        <div class="info-desc">Identitas unik dalam sistem</div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="info-title">Waktu Login</div>
                        </div>
                        <div class="info-value"><?php echo date('H:i', strtotime($login_time)); ?></div>
                        <div class="info-desc"><?php echo date('d M Y', strtotime($login_time)); ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="info-title">Durasi Sesi</div>
                        </div>
                        <div class="info-value"><?php echo $hours; ?>j <?php echo $minutes; ?>m</div>
                        <div class="info-desc">Waktu aktif dalam sistem</div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="info-title">Status Keamanan</div>
                        </div>
                        <div class="info-value text-success">Aman</div>
                        <div class="info-desc">Sesi terverifikasi</div>
                    </div>
                </div>

                <div class="stats-container">
                    <h3 class="stats-title">
                        <i class="fas fa-chart-line me-2"></i>
                        Statistik Hari Ini
                    </h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number">1</div>
                            <div class="stat-label">Sesi Login</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $hours; ?>:<?php echo str_pad($minutes, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="stat-label">Waktu Aktif</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">100%</div>
                            <div class="stat-label">Keamanan</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">Active</div>
                            <div class="stat-label">Status</div>
                        </div>
                    </div>
                </div>

                <div class="actions-container">
                    <a href="index.php" class="btn-custom btn-primary-custom">
                        <i class="fas fa-home me-2"></i>
                        Dashboard
                    </a>
                    <a href="edit_profile.php" class="btn-custom btn-secondary-custom">
                        <i class="fas fa-edit me-2"></i>
                        Edit Profil
                    </a>
                    <a href="settings.php" class="btn-custom btn-secondary-custom">
                        <i class="fas fa-cog me-2"></i>
                        Pengaturan
                    </a>
                    <a href="logout.php" class="btn-custom btn-danger-custom" onclick="return confirm('Yakin ingin logout?')">
                        <i class="fas fa-sign-out-alt me-2"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Update session duration in real-time
            function updateSessionDuration() {
                const loginTime = new Date('<?php echo $login_time; ?>');
                const now = new Date();
                const diff = Math.floor((now - loginTime) / 1000);
                
                const hours = Math.floor(diff / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                
                const durationElement = document.querySelector('.info-card:nth-child(3) .info-value');
                if (durationElement) {
                    durationElement.textContent = `${hours}j ${minutes}m`;
                }
                
                const statElement = document.querySelector('.stats-grid .stat-item:nth-child(2) .stat-number');
                if (statElement) {
                    statElement.textContent = `${hours}:${minutes.toString().padStart(2, '0')}`;
                }
            }

            // Update every minute
            setInterval(updateSessionDuration, 60000);

            // Add hover effects to info cards
            const infoCards = document.querySelectorAll('.info-card');
            infoCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Add click effect to buttons
            const buttons = document.querySelectorAll('.btn-custom');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    // Create ripple effect
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
                    ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
                    ripple.style.position = 'absolute';
                    ripple.style.borderRadius = '50%';
                    ripple.style.background = 'rgba(255,255,255,0.6)';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.pointerEvents = 'none';
                    
                    this.style.position = 'relative';
                    this.style.overflow = 'hidden';
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });

            // Show welcome animation
            setTimeout(() => {
                const profileCard = document.querySelector('.profile-card');
                profileCard.style.animation = 'none';
                profileCard.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    profileCard.style.transform = 'scale(1)';
                    profileCard.style.transition = 'transform 0.3s ease';
                }, 200);
            }, 800);
        });

        // Add CSS for ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(2);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>