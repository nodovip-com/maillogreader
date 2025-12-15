document.addEventListener('DOMContentLoaded', () => {
    // Elements
    const loginScreen = document.getElementById('login-screen');
    const dashboard = document.getElementById('dashboard');
    const loginForm = document.getElementById('login-form');
    const loginError = document.getElementById('login-error');
    const logoutBtn = document.getElementById('logout-btn');
    const logsBody = document.getElementById('logs-body');
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const refreshBtn = document.getElementById('refresh-btn');
    const autoRefreshSelect = document.getElementById('auto-refresh');
    const loader = document.getElementById('loader');
    const currentUserSpan = document.getElementById('current-user');

    // State
    let refreshInterval = null;

    // --- Authentication ---

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;

        loginError.style.display = 'none';

        try {
            const res = await fetch('api.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();

            if (data.success) {
                // Switch view
                loginScreen.classList.add('hidden');
                dashboard.classList.remove('hidden');
                currentUserSpan.textContent = data.user;
                fetchLogs();
                startAutoRefresh();
            } else {
                loginError.textContent = data.error || 'Login failed';
                loginError.style.display = 'block';
            }
        } catch (error) {
            console.error('Login error:', error);
            loginError.textContent = 'Network or server error';
            loginError.style.display = 'block';
        }
    });

    logoutBtn.addEventListener('click', async () => {
        await fetch('api.php?action=logout');
        location.reload();
    });

    // --- Data Fetching ---

    async function fetchLogs() {
        // Show loader only if table is empty (initial load)
        if (logsBody.children.length === 0) {
            loader.classList.remove('hidden');
        }

        const search = searchInput.value;
        const status = statusFilter.value;

        const params = new URLSearchParams({
            action: 'get_logs',
            search: search,
            status: status,
            limit: 200 // Default limit
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            if (res.status === 403) {
                // Session expired
                location.reload();
                return;
            }
            const data = await res.json();

            if (data.error) {
                console.error('Server error:', data.error);
                return;
            }

            renderLogs(data.logs || []);
        } catch (error) {
            console.error('Fetch logs error:', error);
        } finally {
            loader.classList.add('hidden');
        }
    }

    function renderLogs(logs) {
        logsBody.innerHTML = '';

        if (logs.length === 0) {
            logsBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-secondary);">No logs found matching criteria</td></tr>';
            return;
        }

        logs.forEach(log => {
            const tr = document.createElement('tr');

            // Format Timestamp
            const timestamp = log.timestamp;

            // Format Status Badge
            const statusClass = `status-${log.status}`;
            const statusBadge = `<span class="badge ${statusClass}">${log.status}</span>`;

            // Sender / Recipient Logic
            let senderRecipient = '';
            if (log.sender) senderRecipient += `<div><strong style="color:var(--text-secondary)">From:</strong> ${log.sender}</div>`;
            if (log.recipient) senderRecipient += `<div><strong style="color:var(--text-secondary)">To:</strong> ${log.recipient}</div>`;
            if (!log.sender && !log.recipient) senderRecipient = '<span style="color:var(--text-secondary)">-</span>';

            tr.innerHTML = `
                <td style="color: var(--accent-color); font-weight: 500;">${timestamp}</td>
                <td>${statusBadge}</td>
                <td>${log.component}</td>
                <td title="${log.message.replace(/"/g, '&quot;')}">${formatMessage(log.message)}</td>
                <td>${senderRecipient}</td>
            `;
            logsBody.appendChild(tr);
        });
    }

    function formatMessage(msg) {
        // Highlight critical parts?
        // Basic escaping
        const div = document.createElement('div');
        div.textContent = msg;
        return div.innerHTML;
    }

    // --- Controls ---

    refreshBtn.addEventListener('click', fetchLogs);

    // Debounced search
    let debounceTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchLogs, 500);
    });

    statusFilter.addEventListener('change', fetchLogs);

    autoRefreshSelect.addEventListener('change', () => {
        startAutoRefresh();
    });

    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);

        const delay = parseInt(autoRefreshSelect.value);
        if (delay > 0) {
            refreshInterval = setInterval(fetchLogs, delay);
        }
    }

    // Initial check (if already logged in via session but refreshed page)
    // We can do a quick check_auth call or just try to fetch logs.
    // Index.php handles the initial visibility class based on PHP session, so we just need to start polling if dashboard is visible.
    if (!dashboard.classList.contains('hidden')) {
        fetchLogs();
        startAutoRefresh();
    }
});
