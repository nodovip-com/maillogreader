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
</head>

<body>

    <!-- Login Screen -->
    <div id="login-screen" class="<?php echo isset($_SESSION['logged_in']) ? 'hidden' : ''; ?>">
        <div class="login-card">
            <h1>Log Reader <span style="color:white">Pro</span></h1>
            <form id="login-form">
                <div class="input-group">
                    <label>Usuario</label>
                    <input type="text" id="username" required placeholder="admin">
                </div>
                <div class="input-group">
                    <label>Contraseña</label>
                    <input type="password" id="password" required placeholder="••••••">
                </div>
                <button type="submit" class="btn">Acceder al Sistema</button>
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
                        <button class="dropdown-item" id="settings-btn">Configuración</button> <!-- NEW -->
                        <button class="dropdown-item" id="change-password-btn">Cambiar Contraseña</button>
                        <div class="dropdown-divider"></div>
                        <button class="dropdown-item" id="logout-btn">Cerrar Sesión</button>
                    </div>
                </div>
            </div>
        </nav>

        <div class="toolbar">
            <input type="text" id="search-input" class="search-input" placeholder="Buscar por correo, ID, mensaje...">

            <select id="status-filter" class="filter-select">
                <option value="">Relevantes (Default)</option>
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
                <option value="2000">2s</option>
                <option value="5000" selected>5s</option>
                <option value="10000">10s</option>
            </select>

            <button id="refresh-btn" class="btn" style="width: auto; padding: 0.5rem 1.5rem;">Actualizar</button>
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
                <h2>Cambiar Contraseña</h2>
                <button id="modal-close" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="change-password-form">
                    <div class="input-group">
                        <label>Contraseña Actual</label>
                        <input type="password" id="old-password" required>
                    </div>
                    <div class="input-group">
                        <label>Nueva Contraseña</label>
                        <input type="password" id="new-password" required>
                    </div>
                    <p id="password-msg" style="margin-bottom:1rem; font-size:0.9rem;"></p>
                    <div style="display:flex; justify-content:flex-end; gap:1rem;">
                        <button type="button" id="modal-cancel" class="btn"
                            style="background:transparent; border:1px solid var(--border-color);">Cancelar</button>
                        <button type="submit" class="btn">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal-overlay" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Configuración</h2>
                <button id="settings-close" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="settings-form">
                    <div class="input-group">
                        <label>Tipo de Log</label>
                        <select id="setting-log-type"
                            style="width:100%; padding:0.8rem; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-primary); border-radius:6px;">
                            <option value="syslog">Standard Mail Log (Syslog)</option>
                            <option value="rspamd">Rspamd History (JSON)</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Ruta del Archivo</label>
                        <input type="text" id="setting-log-path" required placeholder="/var/log/mail.log">
                        <small style="color:var(--text-secondary)">Ruta absoluta o relativa al sistema.</small>
                    </div>
                    <p id="settings-msg" style="margin-bottom:1rem; font-size:0.9rem;"></p>
                    <div style="display:flex; justify-content:flex-end; gap:1rem;">
                        <button type="button" id="settings-cancel" class="btn"
                            style="background:transparent; border:1px solid var(--border-color);">Cancelar</button>
                        <button type="submit" class="btn">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="app.js"></script>
</body>

</html>