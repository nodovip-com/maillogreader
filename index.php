<?php
require_once 'config.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="style.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400&display=swap"
        rel="stylesheet">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
</head>

<body>

    <!-- Setup Screen (Initial Admin Creation) -->
    <div id="setup-screen" class="hidden">
        <div class="login-card">
            <h1>Initial <span style="color:white">Setup</span></h1>
            <p style="color:var(--text-secondary); margin-bottom:1.5rem; font-size:0.9rem;">
                Welcome. No users detected. Please create an administrator account.
            </p>
            <form id="setup-form">
                <div class="input-group">
                    <label>Username (Admin)</label>
                    <input type="text" id="setup-username" required placeholder="admin">
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" id="setup-password" required placeholder="••••••">
                </div>
                <button type="submit" class="btn">Create Administrator</button>
                <div id="setup-qr-container" style="display:none; text-align:center; margin-top:1rem;">
                    <p style="font-size:0.8rem; color:white; margin-bottom:0.5rem;">Scan this QR with Authenticator App:
                    </p>
                    <img id="setup-qr-img" style="border: 4px solid white; border-radius: 4px; max-width: 150px;">
                    <p style="font-size:0.8rem; color:var(--text-secondary); margin-top:0.5rem;">Then reload to login.
                    </p>
                </div>
                <p id="setup-error"
                    style="color: var(--error-color); margin-top: 1rem; font-size: 0.9rem; display: none;"></p>
            </form>
        </div>
    </div>

    <!-- Login Screen -->
    <div id="login-screen" class="<?php echo isset($_SESSION['logged_in']) ? 'hidden' : ''; ?>">
        <div class="login-card">
            <h1>Log Reader <span style="color:white">Pro</span></h1>
            <form id="login-form">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" id="username" required placeholder="admin">
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" id="password" required placeholder="••••••">
                </div>
                <div class="input-group">
                    <label>MFA Code</label>
                    <input type="text" id="mfa-code" required placeholder="123456" pattern="\d{6}" maxlength="6"
                        style="letter-spacing: 2px;">
                </div>
                <button type="submit" class="btn">Sign In</button>
                <p id="login-error"
                    style="color: var(--error-color); margin-top: 1rem; font-size: 0.9rem; display: none;"></p>
            </form>
        </div>
    </div>

    <!-- Dashboard -->
    <div id="dashboard" class="<?php echo isset($_SESSION['logged_in']) ? '' : 'hidden'; ?>">
        <nav class="navbar">
            <div class="nav-brand" onclick="window.location.href='index.php'" style="cursor: pointer;">
                <img src="logo.png" alt="Logo" class="nav-logo">
                <span class="status-indicator"></span>
                <?php echo APP_NAME; ?>
            </div>
            <div class="nav-controls items-center flex gap-4">
                <div class="user-menu" id="user-menu-trigger">
                    <div class="user-display">
                        <span id="current-user"
                            style="color: var(--text-secondary);"><?php echo $_SESSION['user'] ?? ''; ?></span>
                        <span style="font-size: 0.8rem;">▼</span>
                    </div>
                    <!-- Dropdown -->
                    <div class="user-dropdown" id="user-dropdown">
                        <button class="dropdown-item" id="users-btn">Users</button>
                        <button class="dropdown-item" id="settings-btn">Settings</button>
                        <button class="dropdown-item" id="change-password-btn">Change Password</button>
                        <div class="dropdown-divider"></div>
                        <button class="dropdown-item" id="logout-btn">Logout</button>
                    </div>
                </div>
            </div>
        </nav>

        <div class="toolbar">
            <input type="text" id="search-input" class="search-input" placeholder="Search by email, ID, message...">

            <!-- Calendar Filter -->
            <div class="flatpickr-wrapper" style="position:relative;">
                <input type="text" id="date-filter" class="filter-select" placeholder="Select Date"
                    style="width: 140px; cursor:pointer;" readonly>
                <button type="button" id="clear-date"
                    style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-secondary); cursor:pointer; font-size:1.2rem; display:none;">&times;</button>
            </div>

            <select id="status-filter" class="filter-select">
                <option value="">Relevant (Default)</option>
                <option value="sent">Sent</option>
                <option value="deferred">Deferred</option>
                <option value="bounced">Bounced</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="error">Error</option>
                <option value="warning">Warning</option>
                <option value="info">Info (Show All)</option>
                <option value="unknown">Unknown</option>
            </select>

            <select id="auto-refresh" class="filter-select">
                <option value="0">Off (Manual)</option>
                <option value="60000" selected>1 min</option>
                <option value="120000">2 min</option>
                <option value="300000">5 min</option>
                <option value="600000">10 min</option>
                <option value="900000">15 min</option>
            </select>

            <button id="refresh-btn" class="btn" style="width: auto; padding: 0.5rem 1.5rem;">Refresh</button>
        </div>

        <div class="logs-container">
            <table class="logs-table">
                <thead id="logs-header"> <!-- Added ID for dynamic columns -->
                    <tr>
                        <th style="width: 150px;">Timestamp</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 120px;">Component</th>
                        <th>Message / Details</th>
                        <th>Sender / Recipient</th>
                    </tr>
                </thead>
                <tbody id="logs-body">
                    <!-- Logs injection -->
                </tbody>
            </table>
            <div id="loader" class="loader-container hidden">
                <div class="spinner"></div>
            </div>
        </div>
    </div>

    <!-- Password Change Modal -->
    <div id="password-modal-overlay" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Change Password</h2>
                <button id="modal-close" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="change-password-form">
                    <div class="input-group">
                        <label>Current Password</label>
                        <input type="password" id="old-password" required>
                    </div>
                    <div class="input-group">
                        <label>New Password</label>
                        <input type="password" id="new-password" required>
                    </div>
                    <p id="password-msg" style="margin-bottom:1rem; font-size:0.9rem;"></p>
                    <div style="display:flex; justify-content:flex-end; gap:1rem;">
                        <button type="button" id="modal-cancel" class="btn"
                            style="background:transparent; border:1px solid var(--border-color);">Cancel</button>
                        <button type="submit" class="btn">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal-overlay" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Settings</h2>
                <button id="settings-close" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="settings-form">
                    <div class="input-group">
                        <label>Log Type</label>
                        <select id="setting-log-type"
                            style="width:100%; padding:0.8rem; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-primary); border-radius:6px;">
                            <option value="syslog">Standard Mail Log (Syslog)</option>
                            <option value="rspamd">Rspamd History (JSON)</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Log File Path</label>
                        <input type="text" id="setting-log-path" required placeholder="/var/log/mail.log">
                        <small style="color:var(--text-secondary)">Absolute or relative path.</small>
                    </div>
                    <p id="settings-msg" style="margin-bottom:1rem; font-size:0.9rem;"></p>
                    <div style="display:flex; justify-content:flex-end; gap:1rem;">
                        <button type="button" id="settings-cancel" class="btn"
                            style="background:transparent; border:1px solid var(--border-color);">Cancel</button>
                        <button type="submit" class="btn">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Users Modal -->
    <div id="users-modal-overlay" class="modal-overlay">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2>User Management</h2>
                <button id="users-close" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:0.5rem;">Add User</h3>
                    <form id="add-user-form" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-start;">
                        <input type="text" id="new-username" placeholder="Username" required style="flex:1;">
                        <input type="password" id="new-user-pass" placeholder="Password" required style="flex:1;">
                        <button type="submit" class="btn" style="width:auto;">+</button>
                    </form>
                    <p id="users-msg" style="font-size:0.8rem; margin-top:0.5rem;"></p>
                    <!-- New User QR Area -->
                    <div id="new-user-qr-container"
                        style="display:none; text-align:center; margin-top:1rem; background:rgba(0,0,0,0.2); padding:1rem; border-radius:6px;">
                        <p style="font-size:0.85rem; color:white; margin-bottom:0.5rem;">Scan MFA Code for <b
                                id="new-user-name-display"></b>:</p>
                        <img id="new-user-qr-img"
                            style="border: 4px solid white; border-radius: 4px; max-width: 120px;">
                    </div>
                </div>

                <h3 style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:0.5rem;">Existing Users</h3>
                <div class="users-list-container"
                    style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:6px; max-height:200px; overflow-y:auto;">
                    <ul id="users-list" style="list-style:none; padding:0; margin:0;">
                        <!-- Injected via JS -->
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="app.js"></script>
</body>

</html>