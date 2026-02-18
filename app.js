document.addEventListener('DOMContentLoaded', () => {
    console.log('Mail Log Reader Pro - UI Version 1.0.2 Loaded');
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
    const backendError = document.getElementById('backend-error');

    // State
    let refreshInterval = null;
    let currentLimit = 100;
    let currentOffset = 0;
    let isFetching = false;
    let allLogsLoaded = false;
    let currentLogType = 'syslog'; // Default (will be updated from backend)

    // IP Cache
    const ipCache = {};

    // --- Helpers ---
    async function safeFetch(url, options = {}) {
        try {
            const res = await fetch(url, options);
            if (res.status === 403) { location.reload(); return null; }

            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if (data.error) {
                    showError(data.error);
                    return null;
                }
                return data;
            } catch (err) {
                console.error('Invalid JSON from ' + url, text);
                showError(`<strong>Server Error:</strong> The system returned an invalid response from <code>${url}</code>.<br><pre style="max-height:150px; overflow:auto; margin-top:0.5rem; font-size:0.75rem; background:rgba(0,0,0,0.3); padding:0.5rem; border-radius:4px;">${escapeHtml(text)}</pre>`);
                return null;
            }
        } catch (e) {
            console.error('Fetch error:', e);
            showError('Network error or server unreachable.');
            return null;
        }
    }

    function showError(msg) {
        if (backendError) {
            backendError.innerHTML = msg;
            backendError.classList.remove('hidden');
        }
    }

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

    // Map State
    let isMapView = false;
    let mapChart = null;

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
        if (userDropdown) userDropdown.classList.remove('show');
        if (settingsModal) settingsModal.classList.add('show');
        loadSettings();
    }
    function closeSettingsModal() {
        if (settingsModal) settingsModal.classList.remove('show');
    }

    // Toggle DB fields based on checkbox
    const useDbCheckbox = document.getElementById('setting-use-db');
    const dbFieldsContainer = document.getElementById('db-settings-fields');
    if (useDbCheckbox && dbFieldsContainer) {
        useDbCheckbox.addEventListener('change', () => {
            dbFieldsContainer.style.display = useDbCheckbox.checked ? 'block' : 'none';
        });
    }

    if (settingsBtn) settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); openSettingsModal(); });
    if (settingsClose) settingsClose.addEventListener('click', closeSettingsModal);
    if (settingsCancel) settingsCancel.addEventListener('click', closeSettingsModal);

    const testDbBtn = document.getElementById('test-db-btn');
    const syncBtn = document.getElementById('sync-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', handleSync);
    }

    async function loadSettings() {
        const res = await fetch('api.php?action=get_settings');
        const data = await res.json();
        if (data) {
            document.getElementById('setting-log-type').value = data.log_type;
            document.getElementById('setting-log-path').value = data.log_path;

            const dbHost = document.getElementById('setting-db-host');
            if (dbHost) {
                dbHost.value = data.db_host || '';
                document.getElementById('setting-db-name').value = data.db_name || '';
                document.getElementById('setting-db-user').value = data.db_user || '';
                document.getElementById('setting-db-pass').value = data.db_pass || '';
                const useDb = document.getElementById('setting-use-db');
                useDb.checked = data.use_db || false;
                if (dbFieldsContainer) {
                    dbFieldsContainer.style.display = useDb.checked ? 'block' : 'none';
                }
            }
        }
    }

    async function handleSync() {
        const syncBtn = document.getElementById('sync-btn');
        if (!syncBtn) return;
        syncBtn.disabled = true;
        const originalContent = syncBtn.innerHTML;
        syncBtn.textContent = 'Syncing...';
        try {
            const res = await fetch('api.php?action=sync_logs');
            const data = await res.json();
            if (data.success) {
                alert(`Sync complete! Imported ${data.imported} new logs.`);
                fetchLogs(true);
            } else {
                alert('Sync failed: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Sync error: ' + e.message);
        } finally {
            syncBtn.disabled = false;
            syncBtn.innerHTML = originalContent;
        }
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('settings-msg');
            msg.textContent = 'Saving...';
            msg.style.color = 'var(--text-secondary)';

            const payload = {
                log_type: document.getElementById('setting-log-type').value,
                log_path: document.getElementById('setting-log-path').value,
                db_host: document.getElementById('setting-db-host')?.value || '',
                db_name: document.getElementById('setting-db-name')?.value || '',
                db_user: document.getElementById('setting-db-user')?.value || '',
                db_pass: document.getElementById('setting-db-pass')?.value || '',
                use_db: document.getElementById('setting-use-db')?.checked || false
            };

            try {
                const res = await fetch('api.php?action=save_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    msg.style.color = 'var(--success-color)';
                    msg.textContent = data.warning ? data.warning : 'Settings saved successfully';
                    if (!data.warning) {
                        setTimeout(() => {
                            if (settingsModal) settingsModal.classList.remove('show');
                            currentOffset = 0;
                            fetchLogs(true);
                        }, 1500);
                    }
                } else {
                    msg.style.color = 'var(--error-color)';
                    msg.textContent = data.error;
                }
            } catch (e) {
                msg.textContent = 'Error: ' + e.message;
                msg.style.color = 'var(--error-color)';
            }
        });
    }

    if (testDbBtn) {
        testDbBtn.addEventListener('click', async () => {
            const msg = document.getElementById('settings-msg');
            testDbBtn.disabled = true;
            testDbBtn.textContent = 'Testing...';

            const payload = {
                log_type: document.getElementById('setting-log-type').value,
                log_path: document.getElementById('setting-log-path').value,
                db_host: document.getElementById('setting-db-host')?.value || '',
                db_name: document.getElementById('setting-db-name')?.value || '',
                db_user: document.getElementById('setting-db-user')?.value || '',
                db_pass: document.getElementById('setting-db-pass')?.value || '',
                use_db: true
            };

            try {
                await fetch('api.php?action=save_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const res = await fetch('api.php?action=test_db');
                const data = await res.json();
                if (data.success) {
                    msg.style.color = 'var(--success-color)';
                    msg.textContent = data.msg;
                } else {
                    msg.style.color = 'var(--error-color)';
                    msg.textContent = data.error;
                }
            } catch (e) {
                msg.style.color = 'var(--error-color)';
                msg.textContent = 'Connection test failed: ' + e.message;
            } finally {
                testDbBtn.disabled = false;
                testDbBtn.textContent = 'Test Connection';
            }
        });
    }

    // --- Users Modal Logic ---
    const usersBtn = document.getElementById('users-btn');
    const usersModal = document.getElementById('users-modal-overlay');
    const settingsModalOverlay = document.getElementById('settings-modal-overlay'); // This was already declared as settingsModal, keeping for consistency with instruction


    const addUserForm = document.getElementById('add-user-form');
    const usersList = document.getElementById('users-list');

    // MFA Elements for New User
    const newUserQrContainer = document.getElementById('new-user-qr-container');
    const newUserQrImg = document.getElementById('new-user-qr-img');
    const newUserNameDisplay = document.getElementById('new-user-name-display');

    if (usersBtn) usersBtn.addEventListener('click', (e) => { e.stopPropagation(); openUsersModal(); });
    if (usersClose) usersClose.addEventListener('click', () => usersModal.classList.remove('show'));

    function openUsersModal() {
        if (!usersModal) return;
        userDropdown.classList.remove('show');
        usersModal.classList.add('show');
        if (newUserQrContainer) newUserQrContainer.style.display = 'none'; // Reset QR view
        fetchUsers();
    }

    async function fetchUsers() {
        const data = await safeFetch('api.php?action=get_users');
        if (Array.isArray(data)) {
            renderUsersList(data);
        }
    }

    function renderUsersList(users) {
        usersList.innerHTML = '';
        users.forEach(u => {
            const li = document.createElement('li');
            li.style.padding = '0.5rem 1rem';
            li.style.borderBottom = '1px solid var(--border-color)';
            li.style.display = 'flex';
            li.style.justifyContent = 'space-between';
            li.style.alignItems = 'center';
            li.style.fontSize = '0.9rem';

            li.innerHTML = `
                <span style="color:var(--text-primary)">${escapeHtml(u)}</span>
                <button class="btn-delete-user" data-user="${escapeHtml(u)}" style="background:transparent; border:none; color:var(--error-color); cursor:pointer; font-size:1.2rem; line-height:1;">&times;</button>
            `;

            // Delete Listener
            li.querySelector('.btn-delete-user').addEventListener('click', () => deleteUser(u));
            usersList.appendChild(li);
        });
    }

    async function deleteUser(username) {
        if (!confirm(`Delete user ${username}?`)) return;
        try {
            const res = await fetch('api.php?action=delete_user', {
                method: 'POST', body: JSON.stringify({ username })
            });
            const data = await res.json();
            if (data.success) {
                fetchUsers();
            } else {
                alert(data.error);
            }
        } catch (e) { }
    }

    if (addUserForm) {
        addUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('users-msg');
            msg.textContent = '';
            newUserQrContainer.style.display = 'none'; // Hide previous

            const u = document.getElementById('new-username').value.trim();
            const p = document.getElementById('new-user-pass').value;

            try {
                const res = await fetch('api.php?action=add_user', {
                    method: 'POST', body: JSON.stringify({ username: u, password: p })
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('new-username').value = '';
                    document.getElementById('new-user-pass').value = '';
                    fetchUsers();
                    msg.style.color = 'var(--success-color)';
                    msg.textContent = 'User created successfully.';

                    // Show QR Code
                    if (data.secret) {
                        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=otpauth://totp/MailLogReader:${encodeURIComponent(u)}?secret=${data.secret}&issuer=MailLogReader`;
                        newUserQrImg.src = qrUrl;
                        newUserNameDisplay.textContent = u;
                        newUserQrContainer.style.display = 'block';
                    }
                } else {
                    msg.style.color = 'var(--error-color)';
                    msg.textContent = data.error;
                }
            } catch (e) { }
        });
    }

    // --- Auth & Init ---

    (async function checkAuth() {
        const data = await safeFetch('api.php?action=check_auth');
        if (!data) return;

        if (data.setup_required) {
            // Show Setup Screen
            if (document.getElementById('setup-screen')) document.getElementById('setup-screen').classList.remove('hidden');
            if (loginScreen) loginScreen.classList.add('hidden');
            if (dashboard) dashboard.classList.add('hidden');
        } else if (data.logged_in) {
            // Show Dashboard
            if (document.getElementById('setup-screen')) document.getElementById('setup-screen').classList.add('hidden');
            if (loginScreen) loginScreen.classList.add('hidden');
            if (dashboard) dashboard.classList.remove('hidden');
            if (currentUserSpan) currentUserSpan.textContent = data.user;
            initApp();
        } else {
            // Show Login
            if (document.getElementById('setup-screen')) document.getElementById('setup-screen').classList.add('hidden');
            if (loginScreen) loginScreen.classList.remove('hidden');
            if (dashboard) dashboard.classList.add('hidden');
        }
    })();

    // Setup Form
    const setupForm = document.getElementById('setup-form');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const u = document.getElementById('setup-username').value.trim();
            const p = document.getElementById('setup-password').value;
            const err = document.getElementById('setup-error');
            const qrContainer = document.getElementById('setup-qr-container');
            const qrImg = document.getElementById('setup-qr-img');

            // Hide button to prevent double submit? Or just clear error
            err.style.display = 'none';

            try {
                const res = await fetch('api.php?action=setup_admin', {
                    method: 'POST', body: JSON.stringify({ username: u, password: p })
                });
                const data = await res.json();
                if (data.success) {
                    // Show QR and wait for user to reload
                    if (data.secret) {
                        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=otpauth://totp/MailLogReader:${encodeURIComponent(u)}?secret=${data.secret}&issuer=MailLogReader`;
                        qrImg.src = qrUrl;
                        qrContainer.style.display = 'block';
                        setupForm.querySelector('button').style.display = 'none'; // Hide create button
                        // User must reload manually or we provide a button "I Scanned It"
                        const btn = document.createElement('button');
                        btn.className = 'btn';
                        btn.textContent = 'I have scanned the QR Code (Proceed to Login)';
                        btn.style.marginTop = '1rem';
                        btn.onclick = (ev) => { ev.preventDefault(); location.reload(); };
                        qrContainer.appendChild(btn);
                    } else {
                        location.reload();
                    }
                } else {
                    err.style.display = 'block'; err.textContent = data.error;
                }
            } catch (e) { err.style.display = 'block'; err.textContent = 'Error'; }
        });
    }

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        const code = document.getElementById('mfa-code').value.trim();

        loginError.style.display = 'none';

        try {
            const res = await fetch('api.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, code })
            });
            const data = await res.json();

            if (data.success) {
                loginScreen.classList.add('hidden');
                dashboard.classList.remove('hidden');
                if (currentUserSpan) currentUserSpan.textContent = data.user;
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

    if (logoutBtn) logoutBtn.addEventListener('click', async () => { await fetch('api.php?action=logout'); location.reload(); });

    // --- Core Logic ---
    async function initApp() {
        // Load initial settings to know log type
        await loadSettingsToState();
        await initCalendar(); // Init calendar
        fetchLogs(true);
        startAutoRefresh();
    }

    async function loadSettingsToState() {
        const data = await safeFetch('api.php?action=get_settings');
        if (data) {
            currentLogType = data.log_type || 'syslog';
            updateTableHeader();
        }
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

    const dateFilter = document.getElementById('date-filter');
    const clearDateBtn = document.getElementById('clear-date');
    const toggleMapBtn = document.getElementById('toggle-map-btn');
    const mapView = document.getElementById('map-view');

    toggleMapBtn.addEventListener('click', () => {
        isMapView = !isMapView;
        if (isMapView) {
            logsContainer.classList.add('hidden');
            mapView.classList.remove('hidden');
            toggleMapBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M4 12h16M4 18h16"></path>
                </svg> List View`;

            if (!mapChart) initMap();
            setTimeout(() => { mapChart && mapChart.resize(); }, 100);
            updateMap(currentLogsData); // Use global store

            // Close Popup on background click?
            mapChart.getZr().on('click', (params) => {
                if (!params.target) document.getElementById('map-popup').classList.add('hidden');
            });
        } else {
            logsContainer.classList.remove('hidden');
            mapView.classList.add('hidden');
            toggleMapBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 6v13l7-4 7 4 7-4V2l-7 4-7-4-7 4z"></path>
                    <path d="M8 2v13"></path>
                    <path d="M16 6v13"></path>
                </svg> Map View`;
        }
    });

    let currentLogsData = []; // Store for map visualization

    // --- Map Logic ---
    function initMap() {
        const dom = document.getElementById('main-map');
        mapChart = echarts.init(dom, 'dark', { renderer: 'canvas' });

        const option = {
            backgroundColor: 'transparent',
            globe: {
                baseTexture: 'https://echarts.apache.org/examples/data-gl/asset/world.topo.bathy.200401.jpg',
                heightTexture: 'https://echarts.apache.org/examples/data-gl/asset/world.topo.bathy.200401.jpg',
                displacementScale: 0.04,
                shading: 'color',
                environment: '#0d1117',
                atmosphere: { show: true },
                viewControl: { autoRotate: true, autoRotateSpeed: 5 }
            },
            series: []
        };
        // Use 2D map for better performance and "Cyber" look instead of heavy Globe?
        // User asked for "Cyber Attack Map", usually 2D flat with flight paths is clearer.
        // Let's switch to Geo (2D) map which is standard ECharts.

        const geoOption = {
            backgroundColor: 'transparent',
            geo: {
                map: 'world',
                roam: true,
                label: { show: false },
                itemStyle: {
                    areaColor: '#161b22',
                    borderColor: '#30363d'
                },
                emphasis: {
                    itemStyle: { areaColor: '#2b3137' },
                    label: { show: false }
                }
            },
            series: []
        };

        // Load World Map JSON if not included? ECharts usually needs map registration.
        // Since we are using CDN, we might need the map json.
        // ECharts doesn't include map geojson by default.
        // We can fetch it.
        fetch('https://raw.githubusercontent.com/apache/echarts/master/test/data/map/json/world.json')
            .then(r => r.json())
            .then(geoJson => {
                echarts.registerMap('world', geoJson);
                mapChart.setOption(geoOption);

                // Event Listener for Lines/Points
                mapChart.on('click', function (params) {
                    if (params.componentType === 'series' && params.data && params.data.log) {
                        showMapPopup(params.data.log);
                    }
                });
            });
    }

    // Popup Logic
    const mapPopup = document.getElementById('map-popup');
    const mapPopupContent = document.getElementById('map-popup-content');
    const mapPopupClose = document.getElementById('map-popup-close');

    if (mapPopupClose) mapPopupClose.addEventListener('click', () => mapPopup.classList.add('hidden'));

    function showMapPopup(log) {
        if (!mapPopup) return;

        const ip = log.host || 'Unknown';
        const country = ipCache[ip] ? ipCache[ip].country : 'Unknown';
        const scoreColor = log.score > 5 ? 'var(--warning-color)' : (log.score > 10 ? 'var(--error-color)' : 'var(--success-color)');

        mapPopupContent.innerHTML = `
            <div class="detail-header">
                ${escapeHtml(log.message || 'No Subject')}
            </div>
            <div class="detail-item">
                <span class="detail-label">From</span>
                <span class="detail-value" title="${escapeHtml(log.sender)}">${escapeHtml(log.sender)}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">To</span>
                <span class="detail-value" title="${escapeHtml(log.recipient)}">${escapeHtml(log.recipient)}</span>
            </div>
             <div class="detail-item">
                <span class="detail-label">Origin</span>
                <span class="detail-value">
                    ${ipCache[ip] && ipCache[ip].code ? `<img src="https://flagcdn.com/w40/${ipCache[ip].code.toLowerCase()}.png" style="width:16px; margin-right:4px; vertical-align:middle;">` : ''}
                    ${country} (${ip})
                </span>
            </div>
             <div class="detail-item">
                <span class="detail-label">Status</span>
                <span class="detail-value" style="color:${log.status === 'error' || log.action === 'reject' ? '#ff4d4f' : '#3fb950'}">${log.action || log.status}</span>
            </div>
             <div class="detail-item">
                <span class="detail-label">Score</span>
                <span class="detail-value" style="color:${scoreColor}">${log.score ? log.score.toFixed(2) : 'N/A'}</span>
            </div>
        `;
        mapPopup.classList.remove('hidden');
    }

    // ...

    // --- Calendar Logic ---
    let calendarInstance = null;

    async function initCalendar() {
        if (!dateFilter) return;

        // Fetch available dates first
        let availableDates = [];
        const data = await safeFetch('api.php?action=get_available_dates');
        if (data) availableDates = data.dates || [];

        calendarInstance = flatpickr(dateFilter, {
            dateFormat: "Y-m-d",
            theme: "dark",
            enable: availableDates.length > 0 ? availableDates : [],
            onChange: (selectedDates, dateStr, instance) => {
                if (dateStr) {
                    clearDateBtn.style.display = 'block';
                    currentOffset = 0;
                    fetchLogs(true);
                }
            }
        });

        if (availableDates.length === 0 && dateFilter) {
            console.warn('No available dates found for the calendar.');
        }

        clearDateBtn.addEventListener('click', () => {
            calendarInstance.clear();
            clearDateBtn.style.display = 'none';
            currentOffset = 0;
            fetchLogs(true);
        });
    }

    function formatTime(unixTime) {
        if (!unixTime) return '';
        const date = new Date(unixTime * 1000);
        // Format: 9:14 pm (GMT-5)
        // We use Intl.DateTimeFormat for flexibility
        const timePart = new Intl.DateTimeFormat('default', {
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: true
        }).format(date);

        const datePart = new Intl.DateTimeFormat('default', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }).format(date);

        // Get Timezone offset string (e.g. GMT-5)
        const offset = -date.getTimezoneOffset() / 60;
        const offsetStr = `GMT${offset >= 0 ? '+' : ''}${offset}`;

        return `<div style="font-weight:600">${timePart}</div>
                <div style="font-size:0.75rem; color:var(--text-secondary)">${datePart} (${offsetStr})</div>`;
    }

    async function fetchLogs(reset = false, isBackground = false) {
        if (isFetching) return;
        isFetching = true;

        if (reset && !isBackground) {
            logsBody.innerHTML = '';
            currentOffset = 0;
            allLogsLoaded = false;
            loader.classList.remove('hidden');
            if (backendError) backendError.classList.add('hidden'); // Reset error
            await loadSettingsToState();
        }

        const dateVal = dateFilter && dateFilter.value ? dateFilter.value : '';

        const params = new URLSearchParams({
            action: 'get_logs',
            search: searchInput.value,
            status: statusFilter.value,
            date: dateVal,
            limit: currentLimit,
            offset: currentOffset
        });

        const data = await safeFetch(`api.php?${params.toString()}`);
        if (!data) {
            isFetching = false;
            if (!isBackground) loader.classList.add('hidden');
            return;
        }

        if (data.type && data.type !== currentLogType) {
            currentLogType = data.type;
            updateTableHeader();
        }

        const newLogs = data.logs || [];
        if (newLogs.length < currentLimit) allLogsLoaded = true;

        if (reset && isBackground) logsBody.innerHTML = '';
        renderLogs(newLogs, reset);
        enhanceLogsWithGeo(newLogs);
        currentOffset += newLogs.length;

        isFetching = false;
        if (!isBackground) loader.classList.add('hidden');
    }

    // --- Geolocation Logic ---
    async function enhanceLogsWithGeo(logs) {
        const ipsToFetch = new Set();
        const addIfValid = (ip) => {
            if (!ip) return;
            if (ip === '127.0.0.1' || ip === '::1' || ip.startsWith('192.168.') || ip.startsWith('10.')) return;
            if (!ipCache[ip]) ipsToFetch.add(ip);
        };

        logs.forEach(log => {
            if (log.host && !log.host.includes('unknown')) addIfValid(log.host);
            const ipRegex = /\b(?:\d{1,3}\.){3}\d{1,3}\b/g;
            if (log.message) {
                const matches = log.message.match(ipRegex);
                if (matches) matches.forEach(ip => addIfValid(ip));
            }
        });

        if (ipsToFetch.size === 0) {
            updateGeoUI();
            if (isMapView) updateMap(currentLogsData); // Ensure map updates even if cached
            return;
        }

        const ipArray = Array.from(ipsToFetch);
        const chunkSize = 50; // Smaller chunks for faster first-paint
        const chunks = [];

        for (let i = 0; i < ipArray.length; i += chunkSize) {
            chunks.push(ipArray.slice(i, i + chunkSize));
        }

        // Parallel execution with incremental updates
        const promises = chunks.map(async (chunk) => {
            try {
                // Proxy through backend
                const res = await fetch('api.php?action=get_ip_geo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(chunk.map(ip => ({ query: ip, fields: "query,country,countryCode,lat,lon" })))
                });
                const data = await res.json();

                let hasNewData = false;
                if (Array.isArray(data)) {
                    data.forEach(item => {
                        if (item.query && !ipCache[item.query]) {
                            ipCache[item.query] = {
                                country: item.country || 'Unknown',
                                code: item.countryCode || '',
                                lat: item.lat || 0,
                                lon: item.lon || 0
                            };
                            hasNewData = true;
                        } else if (item.query && ipCache[item.query]) {
                            // Update existing if missing lat/lon (e.g. from previous run without it)
                            if (!ipCache[item.query].lat && item.lat) {
                                ipCache[item.query].lat = item.lat;
                                ipCache[item.query].lon = item.lon;
                                hasNewData = true;
                            }
                        }
                    });
                }

                if (hasNewData) {
                    updateGeoUI();
                    if (isMapView) updateMap(currentLogsData); // Incremental paint
                }
            } catch (e) { console.error('Geo Fetch Error', e); }
        });

        await Promise.all(promises);
    }

    // --- Map Update Logic ---
    // Server Location (Placeholder: US East). 
    // Ideally we'd fetch this from server or config.
    const SERVER_LAT = 40.7128;
    const SERVER_LON = -74.0060;

    // Helper to get lat/lon from country code (Approximation)
    // Since we only get Country Code from FlagCDN/IP-API (we requested fields "query,country,countryCode"), 
    // we don't strictly have lat/lon for every IP unless we ask for it.
    // OPTIMIZATION: Update fetch body to request 'lat,lon' from ip-api.

    // Changing enhanceLogsWithGeo request to include lat,lon

    // ... (Wait, I need to update enhanceLogsWithGeo first to fetch lat/lon)

    function updateMap(logs) {
        if (!mapChart || !logs) return;

        const linesData = [];
        const effectScatterData = [];
        const countryCount = {};

        logs.forEach(log => {
            const ip = log.host || (log.message.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/) || [])[0];
            if (!ip || !ipCache[ip] || !ipCache[ip].lat) return;

            const info = ipCache[ip];
            const country = info.country;

            // Stats
            countryCount[country] = (countryCount[country] || 0) + 1;

            // Line: Source -> Server
            linesData.push({
                coords: [
                    [info.lon, info.lat], // From
                    [SERVER_LON, SERVER_LAT] // To
                ],
                lineStyle: {
                    color: log.status === 'error' || log.action === 'reject' ? '#ff4d4f' : '#3fb950'
                },
                log: log // Attach Log Data
            });

            // Point
            effectScatterData.push({
                name: ip,
                value: [info.lon, info.lat],
                itemStyle: { color: log.status === 'error' || log.action === 'reject' ? '#ff4d4f' : '#3fb950' },
                log: log // Add log to scatter point for click event
            });
        });

        // Add Server Node
        effectScatterData.push({
            name: "Server",
            value: [SERVER_LON, SERVER_LAT],
            itemStyle: { color: '#58a6ff' },
            symbolSize: 15
        });

        mapChart.setOption({
            series: [
                {
                    type: 'effectScatter',
                    coordinateSystem: 'geo',
                    zlevel: 2,
                    rippleEffect: { brushType: 'stroke' },
                    symbolSize: 10,
                    itemStyle: { color: '#3fb950' },
                    data: effectScatterData
                },
                {
                    type: 'lines',
                    zlevel: 1,
                    effect: {
                        show: true,
                        period: 4,
                        trailLength: 0.5, // Long trails for "Liquid" feel
                        color: '#fff',
                        symbolSize: 3
                    },
                    lineStyle: {
                        curveness: 0.2,
                        opacity: 0.1,
                        width: 1
                    },
                    data: linesData
                }
            ]
        });

        updateMapStats(countryCount);
    }

    function updateMapStats(counts) {
        const container = document.getElementById('map-stats-content');
        if (!container) return;

        const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 10);
        if (sorted.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:1rem; color:var(--text-secondary)">No localized data yet</div>';
            return;
        }

        const max = sorted[0][1];
        container.innerHTML = sorted.map(([country, count]) => `
            <div class="country-stat">
                <div class="country-stat-row">
                    <span>${country}</span>
                    <span style="color:var(--text-secondary)">${count}</span>
                </div>
                <div class="country-stat-bar">
                    <div class="country-stat-fill" style="width: ${(count / max) * 100}%"></div>
                </div>
            </div>
        `).join('');
    }

    function updateGeoUI() {
        if (currentLogType === 'rspamd') {
            const hostDivs = document.querySelectorAll('.log-row td:nth-child(5) div:first-child');
            hostDivs.forEach(div => {
                const ip = div.textContent.trim();
                const info = ipCache[ip];
                if (info && info.code && !div.querySelector('.flag-icon')) {
                    div.innerHTML = `<img src="https://flagcdn.com/w40/${info.code.toLowerCase()}.png" class="flag-icon" style="margin-right:6px; vertical-align:middle; width:21px;"> ${ip}`;
                    div.title = info.country;
                }
            });
        }
        const ipSpans = document.querySelectorAll('.highlight-ip');
        ipSpans.forEach(span => {
            const ip = span.getAttribute('data-ip');
            const info = ipCache[ip];
            if (info && info.code && !span.querySelector('.flag-icon')) {
                const img = document.createElement('img');
                img.src = `https://flagcdn.com/w40/${info.code.toLowerCase()}.png`;
                img.className = 'flag-icon';
                img.style.marginRight = '6px';
                img.style.verticalAlign = 'middle';
                img.style.width = '21px';
                img.title = info.country;
                span.insertBefore(img, span.firstChild);
            }
        });
    }


    function renderLogs(logs, isReset) {
        currentLogsData = logs; // Store for map
        if (isMapView) updateMap(logs);

        if (logs.length === 0 && isReset) {
            logsBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-secondary);">No logs found</td></tr>';
            return;
        }

        logs.forEach(log => {
            const tr = document.createElement('tr');
            tr.className = 'log-row';

            if (currentLogType === 'rspamd') {
                // RSPAMD RENDER (New JSON structure)

                // Format timestamp locally
                const timeHtml = log.unix_time ? formatTime(log.unix_time) : (log.timestamp || '-');

                const scoreClass = getScoreClass(log.score);
                const actionClass = getActionClass(log.action);

                tr.innerHTML = `
                    <td style="white-space:nowrap">${timeHtml}</td>
                    <td><span class="badge" style="${scoreClass}">${log.score.toFixed(2)}</span></td>
                    <td><span class="badge ${actionClass}">${log.action}</span></td>
                    <td title="${log.message.replace(/"/g, '&quot;')}">
                        <div style="font-weight:600; margin-bottom:2px;">${escapeHtml(log.message)}</div>
                        <div style="font-size:0.75rem; color:var(--text-secondary)">Scan time: ${log.scan_time.toFixed(3)}s</div>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; margin-bottom:2px;">${escapeHtml(log.host)}</div>
                        <div style="font-size:0.75rem; color:var(--text-secondary)">
                            F: ${escapeHtml(log.sender)}<br>
                            T: ${escapeHtml(log.recipient)}
                        </div>
                    </td>
                `;
            } else {
                // SYSLOG RENDER
                // Format timestamp locally
                const timeHtml = log.unix_time ? formatTime(log.unix_time) : (log.timestamp || '-');

                // Determine row class based on status...
                // (Existing logic)
                const statusClass = getStatusClass(log.status);

                const senderRecipient = (log.sender || log.recipient)
                    ? `<div style="font-weight:600">${escapeHtml(log.sender)}</div><div style="color:var(--text-secondary)">${escapeHtml(log.recipient)}</div>`
                    : '<span style="color:var(--text-secondary)">-</span>';

                tr.innerHTML = `
                    <td style="white-space:nowrap">${timeHtml}</td>
                    <td><span class="badge ${statusClass}">${log.status}</span></td>
                    <td>${escapeHtml(log.component || '-')}</td>
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

    function getStatusClass(status) {
        if (status === 'error' || status === 'failed') return 'status-error';
        if (status === 'success') return 'status-success';
        if (status === 'warning') return 'status-warning';
        return 'status-info';
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
