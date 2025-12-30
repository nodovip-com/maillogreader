# 🦅 Mail Log Reader Pro

> **A modern, secure, and real-time interface for analyzing mail logs (Postfix/Syslog & Rspamd).**

Mail Log Reader Pro transforms raw, hard-to-read log files into a powerful, interactive visual dashboard. Designed for system administrators who need to monitor mail flow with style, security, and efficiency.

---

## ✨ Key Features

*   **🎨 Premium "Liquid Glass" Design**: Modern dark interface with blur effects and transparencies.
*   **🌍 Interactive Threat Map**: 
    *   **Cyber Visualization**: Real-time 2D world map with animated "flight paths" (IP-to-Server).
    *   **Map Interactions**: Click dots or lines to see log details in a refined "Mini Liquid Glass" popup.
    *   **Live Statistics**: Automatic "Top Countries" leaderboard and real-time traffic feed.
*   **🔒 Enterprise Security**:
    *   **MFA (2FA)**: Secure login with Time-based One-Time Password (TOTP) compatible with Google Authenticator/Authy.
    *   **Secure Storage**: Bcrypt password hashing to protect user credentials.
*   **📅 Smart Date Filtering**:
    *   **Interactive Calendar**: Easily filter logs by specific dates, automatically highlighting days with available data (Flatpickr integration).
*   **📂 Multi-Log Engine**:
    *   **Universal Syslog**: Compatible with standard Postfix/Sendmail logs.
    *   **⚡ Rspamd Integration**: Native support for `rspamd_history_json`. Visualizes **Scores**, **Actions**, and **Symbols** with color-coded toxicity indicators.
*   **⚡ High-Performance Architecture**:
    *   **Parallel Geolocation**: Releases PHP session locks to allow simultaneous IP resolution.
    *   **Incremental Rendering**: Visual feedback as data arrives; no waiting for full-batch completion.
*   **⏱️ Real-Time Monitoring**: Automatic log updates without page reloads (Silent Polling).
*   **⚙️ Dynamic Configuration**: Switch log types and paths directly from the UI.
*   **🛡️ User Management**:
    *   **Auto Setup Mode**: Guided creation of the first admin account.
    *   **Admin Panel**: Add and remove users directly from the interface.

---

## 🚀 Installation & Setup

1.  **Clone the Repository**
    ```bash
    git clone https://your-repo/maillogreader.git
    cd maillogreader
    ```

2.  **Configure Base Files**
    ```bash
    cp config.sample.php config.php
    ```
    *Note: `users.json` is automatically created during the "Initial Setup" screen in the browser.*

3.  **Permissions (Critical!)**
    The web server (www-data/apache/nginx) needs permissions to:
    *   **Read** the log files.
    *   **Write** to the directory (to manage `users.json` and `settings.json`).

    ```bash
    chown www-data:www-data .
    chmod 770 .
    ```

---

## 📖 How It Works

### 1. Dashboard
*   **Syslog Mode**: Displays Timestamp, Status (Sent/Deferred/Error), Component, and Message.
*   **Rspamd Mode**: Displays Score, Action (Reject/No Action), Subject, and Spam Symbols.

### 2. Threat Map
Toggle the **Map View** to visualize geographic data:
*   **Real-time Attack Map**: See where mail is originating from across the globe.
*   **Animated Paths**: Lines connect source countries to the server, highlighting traffic patterns.
*   **Interactive Details**: Click any node on the map to trigger a detailed "Liquid Glass" information card showing Subject, Sender, and Score.

### 3. Configuration
Access **Settings** from the user menu:
*   Select Log Type (`Standard Mail Log` or `Rspamd History`).
*   Set absolute path (e.g., `/var/log/rspamd/history.json`).

### 4. Security & MFA
*   On first launch, you will be prompted to create an Admin account.
*   Scan the **QR Code** with your Authenticator App to enable MFA.
*   Subsequent logins require username, password, and the 6-digit code.

### 5. Advanced Analysis
*   **Geo Flags**: Visual country indicators for IPs (shown in List & Map views).
*   **Queue ID Filter**: Click any Queue ID to filter logs and trace an entire message trajectory.
*   **Symbol Explorer (Rspamd)**: Detailed descriptions of spam scores.

---

## 🛠️ Requirements

*   **PHP**: 7.4+
*   **Web Server**: Apache/Nginx/IIS
*   **Browser**: Modern (Chrome, Edge, Firefox)
*   **No Database**: Zero-dependency (Flatfile JSON storage).

---

<p align="center">
  <sub>Developed by NodoVIP</sub>
</p>
