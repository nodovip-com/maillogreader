# 🦅 Mail Log Reader Pro

> **A modern, secure, and real-time interface for analyzing mail logs (Postfix/Syslog & Rspamd).**

Mail Log Reader Pro transforms raw, hard-to-read log files into a powerful, interactive visual dashboard. Now powered by a **Database-First Architecture** for lightning-fast performance and historical analysis.

---

## ✨ Key Features

*   **⚡ High-Performance Database Engine**: 
    *   **MySQL/MariaDB Integration**: Stores logs in a relational database for instant searching and filtering of millions of records.
    *   **Smart Sync**: Background process intelligently imports new logs without blocking the UI.
    *   **Lazy Loading**: New "Smart Sync" ensures the interface stays responsive even during heavy log rotation.
*   **🎨 Premium "Liquid Glass" Design**: Modern dark interface with blur effects and transparencies.
*   **🌍 Interactive Threat Map**: 
    *   **Real-time Attack Map**: See where mail is originating from across the globe.
    *   **Animated Paths**: Lines connect source countries to the server.
*   **🔒 Enterprise Security**:
    *   **MFA (2FA)**: Secure login with Time-based One-Time Password (TOTP).
    *   **Secure Storage**: Bcrypt password hashing.
*   **📅 Smart Date Filtering**:
    *   **Instant Calendar**: Caches available dates for immediate loading, even with years of history.
*   **📂 Multi-Log Engine**:
    *   **Universal Syslog**: Compatible with standard Postfix/Sendmail logs.
    *   **⚡ Rspamd Integration**: Native support for `rspamd_history_json`.

---

## 🚀 Installation & Setup

### 1. Prerequisites
*   **PHP**: 7.4 or higher
*   **MySQL / MariaDB**: Verified with MySQL 5.7+ and MariaDB 10.3+
*   **Web Server**: Apache Nginx / IIS
*   **Extensions**: `pdo_mysql`, `json`, `mbstring`

### 2. Database Setup
Create a new database and import the schema:

1.  Create a database (e.g., `maillogreader`).
2.  Import `schema.sql`:
    ```bash
    mysql -u root -p maillogreader < schema.sql
    ```

### 3. Application Setup
1.  **Clone the Repository**
    ```bash
    git clone https://your-repo/maillogreader.git
    cd maillogreader
    ```

2.  **Configure Base Files**
    ```bash
    cp config.sample.php config.php
    ```

3.  **Permissions**
    The web server needs **Write** access to the directory to manage `users.json`, `settings.json`, and cache files.
    ```bash
    chown -R www-data:www-data .
    chmod -R 770 .
    ```

### 4. Cron Job (Crucial for 24/7 Sync) ⏰
To ensure logs are synced even when no one is watching the dashboard, you **MUST** set up a Cron Job.

1.  Open Crontab:
    ```bash
    crontab -e
    ```
2.  Add the following line (run every minute):
    ```bash
    * * * * * /usr/bin/php /var/www/html/maillogreader/cron_sync.php >> /var/log/maillog_sync.log 2>&1
    ```
    *Adjust paths (`/var/www/html...`) to match your installation.*

---

## ⚙️ Configuration

1.  Access the web interface.
2.  Log in (Default setup will guide you to create an Admin user).
3.  Go to **Settings** (User Icon -> Settings).
4.  **Database Connection**:
    *   Enable **"Use Database"**.
    *   Enter Host, Database Name, User, and Password.
5.  **Log File Path**:
    *   Enter the absolute path to your log file (e.g., `/var/log/rspamd/history.json` or `/var/log/mail.log`).

---

## 🛠️ Troubleshooting

### "504 Gateway Timeout"
*   **Solution**: Ensure the Cron Job is running. The web interface uses a "Smart Sync" that relies on the background process keeping the DB relatively up-to-date.
*   **Verify**: Run `php cron_sync.php` manually in the terminal to see if it imports logs correctly.

### "No Logs Found" / "Waiting for Sync"
*   If using Rspamd, ensure `history.json` is being updated by the service.
*   Check file permissions: The Web User (`www-data`) and the Cron User must both be able to read the log file.

### "Slow Initial Load"
*   The system generates a `cache_dates.json` file via the Cron Job to speed up loading. Ensure `cron_sync.php` has write permissions to the application directory.

---

<p align="center">
  <sub>Developed by NodoVIP</sub>
</p>
