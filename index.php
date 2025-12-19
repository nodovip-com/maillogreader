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
        <header>
            <h2>
                <div class="indicator"></div>
                Live Mail Logs
            </h2>
            <div class="user-info items-center flex gap-4">
                <span id="current-user"
                    style="color: var(--text-secondary);"><?php echo $_SESSION['user'] ?? ''; ?></span>
                <button id="logout-btn" class="logout-btn btn">Cerrar Sesión</button>
            </div>
        </header>

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
                <thead>
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

    <script src="app.js"></script>
</body>

</html>