<?php
/**
 * PQM Login Page
 * - Viewer: click "Continue as Viewer" — no password
 * - Admin: enter password to unlock full access
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → go home
if (!empty($_SESSION['pqm_role'])) {
    header('Location: index.php');
    exit;
}

// ── Credentials (change ADMIN_PASSWORD to your preferred password) ───────────
define('ADMIN_PASSWORD', 'pqm@admin2024');   // ← change this

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'viewer') {
        $_SESSION['pqm_role'] = 'viewer';
        $_SESSION['pqm_user'] = 'Viewer';
        header('Location: index.php');
        exit;
    }

    if ($action === 'admin') {
        $pw = $_POST['password'] ?? '';
        if ($pw === ADMIN_PASSWORD) {
            $_SESSION['pqm_role'] = 'admin';
            $_SESSION['pqm_user'] = 'Administrator';
            header('Location: index.php');
            exit;
        } else {
            $error = 'Incorrect password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PQM Dashboard — Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-base:    #0f1117;
            --bg-card:    #1a1d27;
            --bg-input:   #12141c;
            --border:     rgba(255,255,255,.08);
            --accent:     #3b82f6;
            --accent-dim: rgba(59,130,246,.15);
            --text:       #e2e8f0;
            --muted:      #64748b;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-base);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        /* Subtle grid background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(59,130,246,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59,130,246,.04) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        /* Glow orb */
        body::after {
            content: '';
            position: fixed;
            top: -200px; left: 50%;
            transform: translateX(-50%);
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59,130,246,.12) 0%, transparent 70%);
            pointer-events: none;
        }

        .login-wrap {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        /* Brand header */
        .brand-block {
            text-align: center;
            margin-bottom: 2rem;
        }
        .brand-logo {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: .85rem;
            box-shadow: 0 0 30px rgba(59,130,246,.35);
        }
        .brand-name  { font-size: 1.55rem; font-weight: 700; letter-spacing: -.5px; }
        .brand-sub   { font-size: .8rem; color: var(--muted); margin-top: .2rem; letter-spacing: .06em; text-transform: uppercase; }

        /* Card */
        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.5);
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: .06em;
            text-transform: uppercase;
            margin-bottom: 1.4rem;
        }

        /* Role buttons */
        .role-btn {
            display: flex;
            align-items: center;
            gap: 1rem;
            width: 100%;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: .85rem 1.1rem;
            color: var(--text);
            text-align: left;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
        }
        .role-btn:hover { background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.18); color: var(--text); }
        .role-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .role-icon.admin  { background: rgba(59,130,246,.18);  color: #60a5fa; }
        .role-icon.viewer { background: rgba(16,185,129,.18); color: #34d399; }
        .role-label { font-weight: 600; font-size: .95rem; }
        .role-desc  { font-size: .77rem; color: var(--muted); margin-top: .15rem; }

        /* Admin expand panel */
        .admin-panel {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(0,0,0,.25);
            border: 1px solid rgba(59,130,246,.25);
            border-radius: 10px;
        }
        .admin-panel.show { display: block; }
        .admin-panel label { font-size: .82rem; color: var(--muted); margin-bottom: .4rem; display: block; }

        .pw-wrap { position: relative; }
        .pw-input {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .6rem 2.6rem .6rem .85rem;
            color: var(--text);
            font-size: .9rem;
            outline: none;
            transition: border-color .2s;
        }
        .pw-input:focus { border-color: var(--accent); }
        .pw-toggle {
            position: absolute;
            right: .7rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--muted); cursor: pointer; padding: 0; font-size: 1rem;
        }
        .pw-toggle:hover { color: var(--text); }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: .6rem;
            font-weight: 600;
            font-size: .9rem;
            margin-top: .75rem;
            cursor: pointer;
            transition: opacity .2s, transform .15s;
        }
        .btn-login:hover { opacity: .9; transform: translateY(-1px); }
        .btn-login:active { transform: none; }

        .error-msg {
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
            border-radius: 8px;
            padding: .5rem .85rem;
            font-size: .83rem;
            margin-top: .65rem;
            display: flex; align-items: center; gap: .5rem;
        }

        .divider {
            display: flex; align-items: center; gap: .8rem;
            color: var(--muted); font-size: .78rem;
            margin: 1.1rem 0;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1;
            height: 1px; background: var(--border);
        }

        .footer-note {
            text-align: center;
            font-size: .75rem;
            color: var(--muted);
            margin-top: 1.4rem;
        }
    </style>
</head>
<body>
<div class="login-wrap">

    <!-- Brand -->
    <div class="brand-block">
        <div class="brand-logo"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="brand-name">PQM Dashboard</div>
        <div class="brand-sub">Manufacturing Analytics</div>
    </div>

    <!-- Card -->
    <div class="login-card">
        <div class="card-title">Select access level</div>

        <!-- Admin Role -->
        <button class="role-btn" id="adminToggle" type="button">
            <div class="role-icon admin"><i class="bi bi-shield-lock-fill"></i></div>
            <div>
                <div class="role-label">Administrator</div>
                <div class="role-desc">Full access — add, edit, upload data</div>
            </div>
            <i class="bi bi-chevron-down ms-auto" id="chevron" style="color:var(--muted);transition:transform .2s;"></i>
        </button>

        <!-- Admin Password Panel -->
        <div class="admin-panel" id="adminPanel">
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="admin">
                <label for="pw">Admin Password</label>
                <div class="pw-wrap">
                    <input type="password" class="pw-input" id="pw" name="password"
                           placeholder="Enter password" autocomplete="current-password" autofocus>
                    <button type="button" class="pw-toggle" id="pwToggle">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <?php if ($error): ?>
                <div class="error-msg"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <button class="btn-login" type="submit">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In as Admin
                </button>
            </form>
        </div>

        <div class="divider">or</div>

        <!-- Viewer Role (no password) -->
        <form method="POST" action="login.php">
            <input type="hidden" name="action" value="viewer">
            <button class="role-btn" type="submit">
                <div class="role-icon viewer"><i class="bi bi-eye-fill"></i></div>
                <div>
                    <div class="role-label">Viewer</div>
                    <div class="role-desc">Read-only access — no password required</div>
                </div>
                <i class="bi bi-arrow-right ms-auto" style="color:var(--muted);"></i>
            </button>
        </form>
    </div>

    <div class="footer-note">
        <i class="bi bi-lock me-1"></i> Secure local session &nbsp;·&nbsp; PQM System
    </div>
</div>

<script>
const toggle     = document.getElementById('adminToggle');
const panel      = document.getElementById('adminPanel');
const chevron    = document.getElementById('chevron');
const pwInput    = document.getElementById('pw');
const pwBtn      = document.getElementById('pwToggle');
const eyeIcon    = document.getElementById('eyeIcon');

toggle.addEventListener('click', () => {
    const open = panel.classList.toggle('show');
    chevron.style.transform = open ? 'rotate(180deg)' : '';
    if (open) setTimeout(() => pwInput?.focus(), 80);
});

pwBtn.addEventListener('click', () => {
    const show = pwInput.type === 'password';
    pwInput.type = show ? 'text' : 'password';
    eyeIcon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});

// Auto-expand if there was an error
<?php if ($error): ?>
panel.classList.add('show');
chevron.style.transform = 'rotate(180deg)';
<?php endif; ?>
</script>
</body>
</html>
