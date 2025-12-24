document.addEventListener('DOMContentLoaded', () => {
    // Elements
    const loginScreen = document.getElementById('login-screen');
    const dashboard = document.getElementById('dashboard');
    const loginForm = document.getElementById('login-form');
    const loginError = document.getElementById('login-error');
    const logoutBtn = document.getElementById('logout-btn');
    const logsBody = document.getElementById('logs-body');
    const logsHeader = document.getElementById('logs-header'); // New ID
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const refreshBtn = document.getElementById('refresh-btn');
    const autoRefreshSelect = document.getElementById('auto-refresh');
    const loader = document.getElementById('loader');
    const currentUserSpan = document.getElementById('current-user');
    const logsContainer = document.querySelector('.logs-container');

    // State
    let refreshInterval = null;
    let currentLimit = 100;
    let currentOffset = 0;
    let isFetching = false;
    let allLogsLoaded = false;
    let currentLogType = 'syslog'; // Default (will be updated from backend)

    // IP Cache
    const ipCache = {};

    // --- UI Controls ---
    const userMenuTrigger = document.getElementById('user-menu-trigger');
    const userDropdown = document.getElementById('user-dropdown');

    // --- Modals ---
    // Password
    const changePasswordBtn = document.getElementById('change-password-btn');
    const passModal = document.getElementById('password-modal-overlay');
    const passClose = document.getElementById('modal-close');
    const passCancel = document.getElementById('modal-cancel');
    const passForm = document.getElementById('change-password-form');

    // Settings
    const settingsBtn = document.getElementById('settings-btn');
    const settingsModal = document.getElementById('settings-modal-overlay');
    const settingsClose = document.getElementById('settings-close');
    const settingsCancel = document.getElementById('settings-cancel');
    const settingsForm = document.getElementById('settings-form');

    // --- Toggle Menu ---
    if (userMenuTrigger) {
        userMenuTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
        });
    }
    window.addEventListener('click', () => {
        if (userDropdown && userDropdown.classList.contains('show')) userDropdown.classList.remove('show');
    });

    // --- Password Modal Logic ---
    function openPassModal() {
        if (passModal) {
            passModal.classList.add('show');
            userDropdown.classList.remove('show');
            document.getElementById('old-password').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('password-msg').textContent = '';
        }
    }
    function closePassModal() { if (passModal) passModal.classList.remove('show'); }

    if (changePasswordBtn) changePasswordBtn.addEventListener('click', (e) => { e.stopPropagation(); openPassModal(); });
    if (passClose) passClose.addEventListener('click', closePassModal);
    if (passCancel) passCancel.addEventListener('click', closePassModal);

    if (passForm) {
        passForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('password-msg');
            msg.textContent = 'Updating...'; msg.style.color = 'var(--text-secondary)';

            try {
                const res = await fetch('api.php?action=change_password', {
                    method: 'POST', body: JSON.stringify({
                        old_password: document.getElementById('old-password').value,
                        new_password: document.getElementById('new-password').value
                    })
                });
                const data = await res.json();
                if (data.success) {
                    msg.textContent = 'Success!'; msg.style.color = 'var(--success-color)';
                    setTimeout(closePassModal, 1500);
                } else {
                    msg.textContent = data.error || 'Failed'; msg.style.color = 'var(--error-color)';
                }
            } catch (err) { msg.textContent = 'Error'; }
        });
    }

    // --- Settings Modal Logic ---
    function openSettingsModal() {
        if (!settingsModal) return;
        userDropdown.classList.remove('show');
        settingsModal.classList.add('show');
        loadSettings();
    }
    function closeSettingsModal() { if (settingsModal) settingsModal.classList.remove('show'); }

    if (settingsBtn) settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); openSettingsModal(); });
    if (settingsClose) settingsClose.addEventListener('click', closeSettingsModal);
    if (settingsCancel) settingsCancel.addEventListener('click', closeSettingsModal);

    async function loadSettings() {
        try {
            const res = await fetch('api.php?action=get_settings');
            const data = await res.json();
            document.getElementById('setting-log-type').value = data.log_type || 'syslog';
            document.getElementById('setting-log-path').value = data.log_path || '';
        } catch (e) { console.error(e); }
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('settings-msg');
            msg.textContent = 'Saving...';

            const payload = {
                log_type: document.getElementById('setting-log-type').value,
                log_path: document.getElementById('setting-log-path').value
            };

            try {
                const res = await fetch('api.php?action=save_settings', {
                    method: 'POST', body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    msg.textContent = 'Saved! Reloading...'; msg.style.color = 'var(--success-color)';
                    setTimeout(() => {
                        closeSettingsModal();
                        currentOffset = 0;
                        fetchLogs(true); // Refetch with new settings
                    }, 1000);
                } else {
                    msg.textContent = data.error || 'Failed'; msg.style.color = 'var(--error-color)';
                }
            } catch (e) { msg.textContent = 'Error'; }
        });
    }

    // --- Auth & Init ---
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await fetch('api.php?action=login', {
                method: 'POST', body: JSON.stringify({
                    username: document.getElementById('username').value,
                    password: document.getElementById('password').value
                })
            });
            const data = await res.json();
            if (data.success) {
                loginScreen.classList.add('hidden');
                dashboard.classList.remove('hidden');
                currentUserSpan.textContent = data.user;
                initApp();
            } else {
                loginError.style.display = 'block'; loginError.textContent = data.error;
            }
        } catch (e) { loginError.style.display = 'block'; loginError.textContent = 'Network Error'; }
    });

    if (logoutBtn) logoutBtn.addEventListener('click', async () => { await fetch('api.php?action=logout'); location.reload(); });

    // --- Core Logic ---
    async function initApp() {
        // Load initial settings to know log type
        await loadSettingsToState();
        fetchLogs(true);
        startAutoRefresh();
    }

    async function loadSettingsToState() {
        try {
            const res = await fetch('api.php?action=get_settings');
            const data = await res.json();
            currentLogType = data.log_type || 'syslog';
            updateTableHeader();
        } catch (e) { }
    }

    function updateTableHeader() {
        if (!logsHeader) return;
        if (currentLogType === 'rspamd') {
            logsHeader.innerHTML = `
                <tr>
                    <th style="width: 140px;">Time</th>
                    <th style="width: 100px;">Score</th>
                    <th style="width: 140px;">Action</th>
                    <th>Subject / Details</th>
                    <th>IP / Sender / Recipient</th>
                </tr>
            `;
        } else {
            logsHeader.innerHTML = `
                <tr>
                    <th style="width: 150px;">Timestamp</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 120px;">Component</th>
                    <th>Message / Details</th>
                    <th>Sender / Recipient</th>
                </tr>
            `;
        }
    }

    async function fetchLogs(reset = false, isBackground = false) {
        if (isFetching) return;
        isFetching = true;

        if (reset && !isBackground) {
            logsBody.innerHTML = '';
            currentOffset = 0;
            allLogsLoaded = false;
            loader.classList.remove('hidden');
            // If settings changed, update header potentially
            await loadSettingsToState();
        }

        const params = new URLSearchParams({
            action: 'get_logs',
            search: searchInput.value,
            status: statusFilter.value,
            limit: currentLimit,
            offset: currentOffset
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            if (res.status === 403) { location.reload(); return; }
            const data = await res.json();

            // Check if log type changed on backend unexpectedly
            if (data.type && data.type !== currentLogType) {
                currentLogType = data.type;
                updateTableHeader();
            }

            const newLogs = data.logs || [];
            if (newLogs.length < currentLimit) allLogsLoaded = true;

            if (reset && isBackground) logsBody.innerHTML = ''; // Silent refresh swap

            renderLogs(newLogs, reset);
            currentOffset += newLogs.length;

        } catch (e) { console.error(e); } finally {
            isFetching = false;
            if (!isBackground) loader.classList.add('hidden');
        }
    }

    function renderLogs(logs, isReset) {
        if (logs.length === 0 && isReset) {
            logsBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-secondary);">No logs found</td></tr>';
            return;
        }

        logs.forEach(log => {
            const tr = document.createElement('tr');
            tr.className = 'log-row';

            if (currentLogType === 'rspamd') {
                // RSPAMD RENDER
                const scoreClass = getScoreClass(log.score, log.action);
                const actionClass = getActionClass(log.action);

                tr.innerHTML = `
                    <td style="font-size:0.9rem; color:var(--text-secondary)">${log.timestamp.split(' ')[1]}<br><span style="font-size:0.7rem">${log.timestamp.split(' ')[0]}</span></td>
                    <td><span class="badge" style="${scoreClass}">${log.score.toFixed(2)}</span></td>
                    <td><span class="badge ${actionClass}">${log.action}</span></td>
                    <td title="${escapeHtml(log.message)}">
                        <div style="font-weight:500; color:var(--text-primary)">${escapeHtml(log.message)}</div>
                        <div style="font-size:0.8rem; color:var(--text-secondary)">Scan time: ${log.scan_time.toFixed(3)}s</div>
                    </td>
                    <td style="font-size:0.85rem">
                         <div style="font-weight:bold; color:var(--accent-color)">${log.host}</div>
                         <div style="color:var(--text-secondary)">F: ${escapeHtml(log.sender)}</div>
                         <div style="color:var(--text-secondary)">T: ${escapeHtml(log.recipient)}</div>
                    </td>
                `;
            } else {
                // SYSLOG RENDER
                const statusClass = `status-${log.status}`;
                const senderRecipient = (log.sender || log.recipient) ?
                    `<div><strong style="color:var(--text-secondary)">From:</strong> ${log.sender}</div><div><strong style="color:var(--text-secondary)">To:</strong> ${log.recipient}</div>` :
                    '<span style="color:var(--text-secondary)">-</span>';

                tr.innerHTML = `
                    <td style="color: var(--accent-color); font-weight: 500;">${log.timestamp}</td>
                    <td><span class="badge ${statusClass}">${log.status}</span></td>
                    <td>${log.component}</td>
                    <td title="${log.message.replace(/"/g, '&quot;')}">${escapeHtml(log.message)}</td>
                    <td>${senderRecipient}</td>
                `;
            }

            tr.addEventListener('click', () => toggleDetails(tr, log));
            logsBody.appendChild(tr);
        });

        addInfiniteScrollTrigger();
    }

    function toggleDetails(row, log) {
        // If next sibling is a detail row, toggle it
        const next = row.nextElementSibling;
        if (next && next.classList.contains('log-detail-row')) {
            next.remove(); // Close
            row.style.backgroundColor = '';
            return;
        }

        // Open
        row.style.backgroundColor = 'rgba(88, 166, 255, 0.1)';

        const detailRow = document.createElement('tr');
        detailRow.className = 'log-detail-row';

        let detailContent = '';

        if (currentLogType === 'rspamd') {
            // Symbols rendering
            const symbolsContainer = document.createElement('div');
            symbolsContainer.className = 'symbols-container';

            if (log.symbols) {
                // Sort symbols by score impact (absolute value desc) to show most relevant first
                const sortedSymbols = Object.entries(log.symbols).sort((a, b) => Math.abs(b[1].score) - Math.abs(a[1].score));

                sortedSymbols.forEach(([key, val]) => {
                    const score = val.score || 0;
                    const toxicityClass = score > 0 ? 'positive' : (score < 0 ? 'negative' : 'neutral');
                    const description = `${val.description || 'No description available for this symbol.'}\nOptions: ${val.options ? val.options.join(', ') : 'None'}`;

                    const pill = document.createElement('div');
                    pill.className = `symbol-pill ${toxicityClass}`;
                    pill.setAttribute('data-tooltip', description);
                    pill.innerHTML = `
                        <span class="symbol-name">${key}</span>
                        <span class="symbol-score">${score > 0 ? '+' : ''}${score.toFixed(1)}</span>
                    `;
                    symbolsContainer.appendChild(pill);
                });
            }

            detailContent = `
                <div style="margin-bottom:0.5rem; color:var(--text-secondary); font-size:0.85rem;">Scored Symbols (Hover for description):</div>
                ${symbolsContainer.outerHTML}
            `;
        } else {
            // Syslog details (Standard)
            const highlightedMessage = highlightSyntax(log.message);
            detailContent = `
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div>
                         <div style="margin-bottom:0.5rem; font-size:1rem; color:var(--text-primary); border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Parsed Data</div>
                         <div><strong>Component:</strong> ${log.component}</div>
                         <div><strong>Status:</strong> ${log.status}</div>
                         <div><strong>Queue ID:</strong> <span class="highlight-id">${log.queue_id || 'N/A'}</span></div>
                    </div>
                    <div>
                        <div style="margin-bottom:0.5rem; font-size:1rem; color:var(--text-primary); border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Raw Message</div>
                        <div>${highlightedMessage}</div>
                    </div>
                </div>
            `;
        }

        detailRow.innerHTML = `<td colspan="5" class="detail-content">${detailContent}</td>`;
        row.parentNode.insertBefore(detailRow, row.nextSibling);

        // Enhance IPs common logic (for Rspamd host is IP)
        if (currentLogType === 'rspamd') {
            // Rspamd standard view already shows IP, maybe enhance detail row if we added raw ip view?
            // Since we removed raw JSON, we rely on the main table for core info.
            // We can assume user sees flags in main table if we implemented them there?
            // Actually app.js renderLogs didn't explicitly run enhanceIPs on the main table cells, only on detail row usually.
            // Let's run it on the sender/host cells in main row? 
            // Currently renderLogs doesn't call enhanceIPs on main row.
        } else {
            enhanceIPs(detailRow);
        }
    }

    // Helpers
    function getScoreClass(score, action) {
        if (action === 'reject' || score > 10) return 'color:var(--error-color); border:1px solid var(--error-color);';
        if (score > 5) return 'color:var(--warning-color); border:1px solid var(--warning-color);';
        return 'color:var(--success-color); border:1px solid var(--success-color);';
    }

    function getActionClass(action) {
        if (action === 'reject') return 'status-error';
        if (action === 'no action') return 'status-success';
        return 'status-warning';
    }

    function highlightSyntax(text) {
        if (!text) return '';
        let html = escapeHtml(text);
        html = html.replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, m => `<span class="highlight-ip" data-ip="${m}">${m}</span>`);
        html = html.replace(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g, m => `<span class="highlight-email">${m}</span>`);
        return html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // Common Controls
    refreshBtn.addEventListener('click', () => { currentOffset = 0; fetchLogs(true); });
    searchInput.addEventListener('input', (e) => { // debounce logic
        clearTimeout(refreshInterval); // pause auto refresh while typing? No, just debounce fetch
        setTimeout(() => { currentOffset = 0; fetchLogs(true); }, 500);
    });
    statusFilter.addEventListener('change', () => { currentOffset = 0; fetchLogs(true); });

    autoRefreshSelect.addEventListener('change', startAutoRefresh);
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        const delay = parseInt(autoRefreshSelect.value);
        if (delay > 0) {
            refreshInterval = setInterval(() => {
                if (!document.querySelector('.log-detail-row')) {
                    if (logsContainer.scrollTop < 50) {
                        currentOffset = 0; fetchLogs(true, true);
                    }
                }
            }, delay);
        }
    }

    function addInfiniteScrollTrigger() {
        const old = document.getElementById('load-more-trigger'); if (old) old.remove();
        if (allLogsLoaded) return;
        const trigger = document.createElement('div');
        trigger.id = 'load-more-trigger'; trigger.className = 'load-more-container';
        trigger.innerHTML = '<button class="btn" style="width:auto; background:var(--card-bg); border:1px solid var(--border-color);">Load More Logs...</button>';
        trigger.querySelector('button').addEventListener('click', () => fetchLogs(false));
        logsBody.parentNode.parentNode.appendChild(trigger);
    }

    // Init if already loaded
    if (!dashboard.classList.contains('hidden')) initApp();
});
