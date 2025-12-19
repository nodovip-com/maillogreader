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
    const logsContainer = document.querySelector('.logs-container');

    // State
    let refreshInterval = null;
    let currentLimit = 100; // Limit per fetch for pagination
    let currentOffset = 0;
    let isFetching = false;
    let allLogsLoaded = false;

    // IP Cache to avoid spamming API
    const ipCache = {};

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
                loginScreen.classList.add('hidden');
                dashboard.classList.remove('hidden');
                currentUserSpan.textContent = data.user;
                fetchLogs(true); // Initial load
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
    async function fetchLogs(reset = false, isBackground = false) {
        if (isFetching) return;
        isFetching = true;

        if (reset && !isBackground) {
            logsBody.innerHTML = '';
            currentOffset = 0;
            allLogsLoaded = false;
            loader.classList.remove('hidden');
        } else if (reset && isBackground) {
            // For background refresh, we just reset offset effectively for the fetch, 
            // but we don't clear UI yet.
            currentOffset = 0;
            // We don't change allLogsLoaded yet, trusting the new fetch will determine it.
        }

        const search = searchInput.value;
        const status = statusFilter.value;

        const params = new URLSearchParams({
            action: 'get_logs',
            search: search,
            status: status,
            limit: currentLimit,
            offset: currentOffset
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            if (res.status === 403) {
                location.reload();
                return;
            }
            const data = await res.json();

            if (data.error) {
                console.error('Server error:', data.error);
                return;
            }

            const newLogs = data.logs || [];

            if (newLogs.length < currentLimit) {
                allLogsLoaded = true; // No more logs to fetch
            }

            // If it was a background refresh, NOW we swap the content
            if (reset && isBackground) {
                logsBody.innerHTML = '';
            }

            renderLogs(newLogs, reset);

            // Increment offset for next page
            currentOffset += newLogs.length;

        } catch (error) {
            console.error('Fetch logs error:', error);
        } finally {
            isFetching = false;
            // Only hide loader if it was shown (not background)
            if (!isBackground) {
                loader.classList.add('hidden');
            }
        }
    }

    function renderLogs(logs, isReset) {
        if (logs.length === 0 && isReset) {
            logsBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-secondary);">No logs found matching criteria</td></tr>';
            return;
        }

        logs.forEach(log => {
            const tr = document.createElement('tr');
            tr.className = 'log-row';

            const timestamp = log.timestamp;
            const statusClass = `status-${log.status}`;
            const statusBadge = `<span class="badge ${statusClass}">${log.status}</span>`;

            let senderRecipient = '';
            if (log.sender) senderRecipient += `<div><strong style="color:var(--text-secondary)">From:</strong> ${log.sender}</div>`;
            if (log.recipient) senderRecipient += `<div><strong style="color:var(--text-secondary)">To:</strong> ${log.recipient}</div>`;
            if (!log.sender && !log.recipient) senderRecipient = '<span style="color:var(--text-secondary)">-</span>';

            tr.innerHTML = `
                <td style="color: var(--accent-color); font-weight: 500;">${timestamp}</td>
                <td>${statusBadge}</td>
                <td>${log.component}</td>
                <td title="${log.message.replace(/"/g, '&quot;')}">${escapeHtml(log.message)}</td>
                <td>${senderRecipient}</td>
            `;

            // Click to toggle details
            tr.addEventListener('click', () => toggleDetails(tr, log));

            logsBody.appendChild(tr);
        });

        // Ensure "Load More" trigger exists or append observation logic
        addInfiniteScrollTrigger();

        // Trajectory Summary Logic
        document.querySelectorAll('.trajectory-summary').forEach(e => e.remove());

        // Only show summary if we are searching (likely by ID) and have logs
        const searchVal = document.getElementById('search-input').value.trim();
        if (searchVal && logs.length > 0) {
            let from = 'Unknown';
            let to = [];

            // Scan logs for From and To
            // Note: logs array is just the current batch if paginating, 
            // but usually when searching ID we get all relevant lines in one go if limit format allows
            // We should scan the Rendered HTML or the incoming data. 
            // Incoming data 'logs' is safer.

            logs.forEach(l => {
                if (l.sender && l.sender !== 'Unknown') from = l.sender;
                if (l.recipient && !to.includes(l.recipient)) to.push(l.recipient);
            });

            if (from !== 'Unknown' || to.length > 0) {
                const summaryDiv = document.createElement('div');
                summaryDiv.className = 'trajectory-summary';

                let toHtml = to.length > 0 ? to.join(', ') : 'Unknown';

                summaryDiv.innerHTML = `
                    <div class="trajectory-item">
                        <strong style="color:var(--accent-color)">FROM:</strong> ${escapeHtml(from)}
                    </div>
                    <div class="trajectory-arrow">➜</div>
                    <div class="trajectory-item">
                        <strong style="color:var(--success-color)">TO:</strong> ${escapeHtml(toHtml)}
                    </div>
                    <div style="margin-left:auto; font-size:0.8rem; color:var(--text-secondary)">
                        Message Trajectory for ID: <span class="highlight-id">${escapeHtml(searchVal)}</span>
                    </div>
                `;

                // Insert before table container or inside dashboard
                const container = document.querySelector('.logs-container');
                container.insertBefore(summaryDiv, container.firstChild);
            }
        }
    }

    function toggleDetails(row, log) {
        // If next sibling is a detail row, toggle it
        const next = row.nextElementSibling;
        if (next && next.classList.contains('log-detail-row')) {
            next.remove(); // Close
            row.style.backgroundColor = '';
            return;
        }

        // Close other open details (optional, but cleaner)
        // document.querySelectorAll('.log-detail-row').forEach(el => {
        //     el.previousElementSibling.style.backgroundColor = '';
        //     el.remove();
        // });

        // Open
        row.style.backgroundColor = 'rgba(88, 166, 255, 0.1)';

        const detailRow = document.createElement('tr');
        detailRow.className = 'log-detail-row';

        // Highlight logic
        const highlightedMessage = highlightSyntax(log.message);
        const highlightedRaw = highlightSyntax(log.raw);

        detailRow.innerHTML = `
            <td colspan="5" class="detail-content">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div>
                        <div style="margin-bottom:0.5rem; font-size:1rem; color:var(--text-primary); border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Parsed Data</div>
                        <div><strong>Timestamp:</strong> ${log.timestamp}</div>
                        <div><strong>Host:</strong> ${log.host}</div>
                        <div><strong>Component:</strong> ${log.component}</div>
                         <div><strong>Queue ID:</strong> <span class="highlight-id">${log.queue_id || 'N/A'}</span></div>
                        <div><strong>Status:</strong> ${log.status}</div>
                    </div>
                    <div>
                        <div style="margin-bottom:0.5rem; font-size:1rem; color:var(--text-primary); border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Raw Message</div>
                        <div>${highlightedMessage}</div>
                    </div>
                </div>
            </td>
        `;

        // Insert after clicked row
        row.parentNode.insertBefore(detailRow, row.nextSibling);

        // Enhance IPs with flags
        enhanceIPs(detailRow);
    }

    function highlightSyntax(text) {
        if (!text) return '';
        let html = escapeHtml(text);

        // Highlight IPs (IPv4)
        // Regex: \b(?:\d{1,3}\.){3}\d{1,3}\b
        html = html.replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, (match) => {
            return `<span class="highlight-ip" data-ip="${match}">${match}</span>`;
        });

        // Highlight Emails
        // Regex: [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}
        html = html.replace(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g, (match) => {
            return `<span class="highlight-email">${match}</span>`;
        });

        // Highlight Queue IDs (Approx: 10-12 hex chars followed by :)
        // Add onclick event for filtering
        html = html.replace(/\b([A-F0-9]{10,12}):/g, (match, id) => {
            return `<span class="highlight-id" onclick="event.stopPropagation(); filterByID('${id}')">${match}</span>`;
        });

        return html;
    }

    // Prepare global function for inline onclick
    window.filterByID = function (id) {
        const searchInput = document.getElementById('search-input');
        const statusFilter = document.getElementById('status-filter');

        searchInput.value = id;
        statusFilter.value = ''; // Reset status to see all steps

        // Trigger search
        // Need to access current state variables or just trigger a refresh
        // We can dispatch an event or call fetchLogs if accessible. 
        // fetchLogs is inside DOMContentLoaded scope.
        // We need a way to trigger it.
        // Easiest: Dispatch input event on search box
        searchInput.dispatchEvent(new Event('input'));
    };

    function enhanceIPs(container) {
        const ips = container.querySelectorAll('.highlight-ip');
        ips.forEach(async (span) => {
            const ip = span.dataset.ip;
            // Filter out internal IPs roughly
            if (ip.startsWith('192.168.') || ip.startsWith('127.') || ip.startsWith('10.')) return;

            const country = await getCountry(ip);
            if (country) {
                const flagSpan = document.createElement('span');
                flagSpan.className = 'country-flag';
                flagSpan.textContent = country.flag; // Emoji flag
                flagSpan.title = country.name;
                span.appendChild(flagSpan);
            }
        });
    }

    async function getCountry(ip) {
        if (ipCache[ip]) return ipCache[ip];

        try {
            // Using a free IP API (e.g., ipapi.co)
            // Note: In production, might hit rate limits.
            const res = await fetch(`https://ipapi.co/${ip}/json/`);
            const data = await res.json();
            // ipapi.co returns 'error' property if failed
            if (data.error) return null;

            // Generate Flag Emoji from Country Code
            const flag = getFlagEmoji(data.country_code);
            const result = { name: data.country_name, flag: flag };

            ipCache[ip] = result;
            return result;
        } catch (e) {
            console.warn('IP lookup failed', e);
            return null;
        }
    }

    function getFlagEmoji(countryCode) {
        if (!countryCode) return '';
        const codePoints = countryCode
            .toUpperCase()
            .split('')
            .map(char => 127397 + char.charCodeAt());
        return String.fromCodePoint(...codePoints);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // --- Controls ---
    refreshBtn.addEventListener('click', () => {
        currentOffset = 0; // Reset on manual refresh? Or just prepend?
        // Simpler for log reader: Reset to show newest
        fetchLogs(true);
    });

    let debounceTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            currentOffset = 0;
            fetchLogs(true);
        }, 500);
    });

    statusFilter.addEventListener('change', () => {
        currentOffset = 0;
        fetchLogs(true);
    });

    autoRefreshSelect.addEventListener('change', () => {
        startAutoRefresh();
    });

    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);

        const delay = parseInt(autoRefreshSelect.value);
        if (delay > 0) {
            refreshInterval = setInterval(() => {
                // Stop refresh if user has details open to avoid closing them
                if (document.querySelector('.log-detail-row')) {
                    return;
                }

                // If user is scrolled down or has search, maybe don't auto refresh aggressively?
                // For now, just simplistic auto-fetch (resetting view to top)
                // actually, resetting to top disturbs reading.
                // Ideal: Fetch *newer* logs than top. 
                // For this MVP: just refresh first page if user is at top.
                if (logsContainer.scrollTop < 50) {
                    currentOffset = 0;
                    fetchLogs(true, true);
                }
            }, delay);
        }
    }

    function addInfiniteScrollTrigger() {
        // Remove old trigger if exists
        const old = document.getElementById('load-more-trigger');
        if (old) old.remove();

        if (allLogsLoaded) return;

        const trigger = document.createElement('div');
        trigger.id = 'load-more-trigger';
        trigger.className = 'load-more-container';
        trigger.innerHTML = '<button class="btn" style="width:auto; background:var(--card-bg); border:1px solid var(--border-color);">Load More Logs...</button>';

        trigger.querySelector('button').addEventListener('click', () => {
            fetchLogs(false);
        });

        logsBody.parentNode.parentNode.appendChild(trigger);
    }

    // Infinite scroll listener on container (optional, button is safer for logs)
    /*
    logsContainer.addEventListener('scroll', () => {
        if (logsContainer.scrollTop + logsContainer.clientHeight >= logsContainer.scrollHeight - 50) {
            fetchLogs(false);
        }
    });
    */

    // Initial load
    if (!dashboard.classList.contains('hidden')) {
        fetchLogs(true);
        startAutoRefresh();
    }
});
