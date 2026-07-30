        <div id="view-student-marks" class="app-view">
            <style>
                .overview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
                .overview-filters { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; background: white; padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 2rem; position: sticky; top: 0; z-index: 10; }
                .overview-filters select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-color); color: var(--text-color); font-size: 0.9rem; outline: none; }
                .search-box { flex-grow: 1; min-width: 200px; display: flex; align-items: center; border: 1px solid var(--border-color); border-radius: 6px; padding: 0.4rem 0.8rem; background: #f8fafc; }
                .search-box input { border: none; background: transparent; outline: none; margin-left: 0.5rem; width: 100%; font-size: 0.9rem; }
                .export-btn { background: white; border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; color: var(--text-color); font-weight: 500; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
                .export-btn:hover { background: #f1f5f9; }
                
                .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
                .stat-card { background: white; border-radius: 12px; padding: 1.2rem; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; cursor: default; }
                .stat-card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }
                .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
                .stat-icon.blue { background: #eff6ff; color: #3b82f6; }
                .stat-icon.green { background: #f0fdf4; color: #22c55e; }
                .stat-icon.purple { background: #faf5ff; color: #a855f7; }
                .stat-icon.orange { background: #fffbeb; color: #f59e0b; }
                .stat-icon.red { background: #fef2f2; color: #ef4444; }
                .stat-info h3 { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; margin: 0 0 0.25rem 0; letter-spacing: 0.5px; }
                .stat-info .value { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: baseline; gap: 0.5rem; }
                .stat-info .sub-value { font-size: 0.85rem; font-weight: 500; color: #94a3b8; }
                
                .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem; }
                @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
                
                .panel { background: white; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; display: flex; flex-direction: column; }
                .panel-header { padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
                .panel-title { font-weight: 700; color: #1e293b; font-size: 1.1rem; margin: 0 0 0.25rem 0; }
                .panel-subtitle { font-size: 0.85rem; color: #64748b; margin: 0; }
                .panel-body { padding: 0; flex-grow: 1; overflow-y: auto; }
                .panel-inner { padding: 1.5rem; }
                
                /* Subject Table */
                .subject-row { border-bottom: 1px solid var(--border-color); transition: background 0.2s; cursor: pointer; display: flex; align-items: center; padding: 1rem 1.5rem; gap: 1rem; }
                .subject-row:hover { background: #f8fafc; }
                .subject-name-col { flex: 2; min-width: 200px; }
                .subject-name { font-weight: 600; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.2rem; }
                .subject-faculty { font-size: 0.8rem; color: #64748b; }
                .subject-stats-col { flex: 1; text-align: center; }
                .subject-stat-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.2rem; }
                .subject-stat-val { font-weight: 600; color: #334155; font-size: 0.95rem; }
                
                /* Progress circle */
                .circular-chart { display: block; margin: 0 auto; max-width: 80%; max-height: 250px; }
                .circle-bg { fill: none; stroke: #f1f5f9; stroke-width: 3.8; }
                .circle { fill: none; stroke-width: 2.8; stroke-linecap: round; animation: progress 1s ease-out forwards; }
                @keyframes progress { 0% { stroke-dasharray: 0 100; } }
                .percentage { fill: #1e293b; font-family: sans-serif; font-size: 0.5em; text-anchor: middle; font-weight: bold; }
                
                .badge { padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
                .badge.green { background: #dcfce7; color: #166534; }
                .badge.orange { background: #fef3c7; color: #92400e; }
                .badge.red { background: #fee2e2; color: #991b1b; }
                .badge.gray { background: #f1f5f9; color: #475569; }
                
                /* Accordion */
                .accordion-content { background: #f8fafc; border-bottom: 1px solid var(--border-color); display: none; padding: 1rem 1.5rem; }
                .accordion-content.open { display: block; animation: slideDown 0.3s ease-out; }
                @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
                .assignment-item { display: flex; align-items: center; justify-content: space-between; padding: 0.8rem; background: white; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 0.5rem; }
                .assignment-item:last-child { margin-bottom: 0; }
                .assign-title { font-weight: 600; color: #334155; font-size: 0.9rem; }
                .assign-stats { display: flex; gap: 1.5rem; font-size: 0.85rem; color: #64748b; }
                .assign-stats span strong { color: #1e293b; }
                .btn-view { background: white; border: 1px solid var(--border-color); padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--primary-color); cursor: pointer; transition: all 0.2s; }
                .btn-view:hover { background: #eff6ff; border-color: #bfdbfe; }
                
                /* Drawer */
                .drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); z-index: 100; display: none; opacity: 0; transition: opacity 0.3s; }
                .drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 100%; max-width: 600px; background: white; z-index: 101; box-shadow: -4px 0 15px rgba(0,0,0,0.1); transform: translateX(100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; }
                .drawer-overlay.active { display: block; opacity: 1; }
                .drawer.active { transform: translateX(0); }
                .drawer-header { padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
                .drawer-body { flex-grow: 1; overflow-y: auto; padding: 0; background: #f1f5f9; }
                .drawer-close { background: none; border: none; font-size: 1.2rem; color: #64748b; cursor: pointer; }
                .drawer-filters { padding: 1rem 1.5rem; background: white; border-bottom: 1px solid var(--border-color); display: flex; gap: 0.5rem; flex-wrap: wrap; }
                .student-list { padding: 1rem; }
                .student-card { background: white; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
                .student-card .info { display: flex; align-items: center; gap: 1rem; flex-grow: 1; }
                
                /* Layout utilities */
                .mb-1 { margin-bottom: 0.25rem; } .mb-2 { margin-bottom: 0.5rem; } .mb-4 { margin-bottom: 1rem; }
                .flex { display: flex; } .items-center { align-items: center; } .gap-2 { gap: 0.5rem; } .gap-4 { gap: 1rem; }
                .text-sm { font-size: 0.875rem; } .text-xs { font-size: 0.75rem; } .text-muted { color: #64748b; }

                /* Student Report Modal */
                .sr-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; opacity: 0; transition: opacity 0.3s; }
                .sr-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -45%) scale(0.95); width: 95%; height: 90vh; max-width: 1400px; background: white; z-index: 201; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); display: none; opacity: 0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); flex-direction: column; overflow: hidden; }
                .sr-modal-overlay.active { display: block; opacity: 1; }
                .sr-modal.active { display: flex; opacity: 1; transform: translate(-50%, -50%) scale(1); }
                .sr-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
                .sr-body { flex-grow: 1; overflow-y: auto; padding: 0; }
                .sr-table { width: 100%; border-collapse: collapse; }
                .sr-table th { background: #f1f5f9; position: sticky; top: 0; padding: 1rem; text-align: left; font-size: 0.85rem; color: #475569; font-weight: 600; z-index: 10; border-bottom: 2px solid #e2e8f0; }
                .sr-table td { padding: 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; color: #334155; }
                .sr-table tbody tr { transition: background 0.15s; }
                .sr-table tbody tr.main-row:hover { background: #f8fafc; }
                .expand-btn { background: none; border: none; cursor: pointer; color: #94a3b8; font-size: 0.8rem; padding: 0.5rem; transition: transform 0.2s; }
                .expand-btn.expanded { transform: rotate(90deg); color: var(--primary-color); }
                .nested-row { display: none; background: #fafafa; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
                .nested-row.active { display: table-row; }
                .nested-content { padding: 1rem 1rem 1rem 3rem; }
                .subject-card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.5rem; overflow: hidden; }
                .subject-card-header { padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: background 0.2s; }
                .subject-card-header:hover { background: #f8fafc; }
                .subject-assignments { display: none; padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }
                .subject-assignments.active { display: block; }
                .assignment-pill { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: white; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 0.5rem; }
                .assignment-pill:last-child { margin-bottom: 0; }
                .pagination { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; border-top: 1px solid var(--border-color); background: white; }
                .page-btn { padding: 0.5rem 1rem; border: 1px solid var(--border-color); border-radius: 6px; background: white; cursor: pointer; }
                .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
                .sr-toolbar { display: flex; gap: 1rem; align-items: center; }
            </style>
            
            <div class="overview-header">
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;">Assignment Overview</h2>
                    <p style="color: #64748b; font-size: 0.95rem; margin: 0;">A clean, simple and smart UI/UX for Admin to view complete assignment status.</p>
                </div>
            </div>
            
            <div class="overview-filters">
                <select id="ao-dept" onchange="fetchAOData()">
                    <option value="ALL">All Departments</option>
                    <?php foreach($db['departments'] ?? [] as $d): ?>
                        <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="ao-year" onchange="fetchAOData()">
                    <option value="ALL">All Years</option>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
                <select id="ao-sem" onchange="fetchAOData()">
                    <option value="ALL">All Semesters</option>
                    <option value="1">Semester 1</option>
                    <option value="2">Semester 2</option>
                </select>
                <select id="ao-div" onchange="fetchAOData()">
                    <option value="ALL">All Divisions</option>
                    <option value="A">Div A</option>
                    <option value="B">Div B</option>
                    <option value="C">Div C</option>
                    <option value="D">Div D</option>
                </select>
                <div class="search-box">
                    <i class="fa-solid fa-search" style="color: #94a3b8;"></i>
                    <input type="text" id="ao-search" placeholder="Search Student..." onkeyup="debounceFetchAO()">
                </div>
            </div>
            
            <!-- Stat Cards -->
            <div class="stat-cards" id="ao-stat-cards">
                <!-- Injected via JS -->
                <div class="stat-card"><div class="stat-icon gray"><i class="fa-solid fa-spinner fa-spin"></i></div><div class="stat-info"><h3>Loading</h3><p class="value">...</p></div></div>
            </div>
            
            <div class="dashboard-grid">
                <!-- Left: Subject Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Subject Wise Assignment Summary</h3>
                        <p class="panel-subtitle">Overview of all subjects and assignments</p>
                    </div>
                    <div class="panel-body" id="ao-subjects-container">
                        <div style="padding: 2rem; text-align: center; color: #94a3b8;"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i></div>
                    </div>
                    <div style="padding: 0.75rem 1.5rem; background: #eff6ff; border-top: 1px solid #bfdbfe; font-size: 0.8rem; color: #1d4ed8; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-info-circle"></i> Click on any subject row to view assignment wise details
                    </div>
                </div>
                
                <!-- Right: Analytics -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Overall Assignment Completion</h3>
                        <p class="panel-subtitle">Visual overview of class performance</p>
                    </div>
                    <div class="panel-inner" style="display: flex; flex-direction: column; align-items: center; border-bottom: 1px solid var(--border-color);">
                        <svg viewBox="0 0 36 36" class="circular-chart" id="ao-doughnut">
                            <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="circle" stroke="#22c55e" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" id="ao-doughnut-path" />
                            <text x="18" y="19.5" class="percentage" id="ao-doughnut-text" style="font-size: 8px;">0%</text>
                            <text x="18" y="24" class="percentage" style="font-size: 3px; font-weight: normal; fill: #64748b;">Overall Completion</text>
                        </svg>
                    </div>
                    <div class="panel-inner" id="ao-analytics-stats" style="flex-grow: 1;">
                        <!-- Injected via JS -->
                    </div>
                </div>
            </div>
            
            <div class="panel" style="margin-bottom: 2rem;">
                <div class="panel-header">
                    <h3 class="panel-title">Recent Assignments Summary</h3>
                </div>
                <div class="panel-body">
                    <table class="data-table" style="width: 100%; border-collapse: collapse;">
                        <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; color: #475569;">
                            <tr>
                                <th style="padding: 1rem; text-align: left;">Assignment Title</th>
                                <th style="padding: 1rem; text-align: left;">Subject</th>
                                <th style="padding: 1rem; text-align: left;">Total Students</th>
                                <th style="padding: 1rem; text-align: left;">Submitted</th>
                                <th style="padding: 1rem; text-align: left;">Pending</th>
                                <th style="padding: 1rem; text-align: center;">Completion</th>
                            </tr>
                        </thead>
                        <tbody id="ao-recent-table" style="font-size: 0.9rem;">
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div style="text-align: center; color: #166534; background: #dcfce7; padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; margin-bottom: 2rem;">
                <i class="fa-solid fa-check-circle"></i> All data is real-time and updated as per student submissions
            </div>
            
            <!-- Drawer -->
            <div class="drawer-overlay" id="ao-drawer-overlay" onclick="closeAODrawer()"></div>
            <div class="drawer" id="ao-drawer">
                <div class="drawer-header">
                    <div>
                        <h3 class="panel-title" id="drawer-assign-title">Assignment Title</h3>
                        <p class="panel-subtitle" id="drawer-assign-subtitle">Subject Name</p>
                    </div>
                    <button class="drawer-close" onclick="closeAODrawer()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="drawer-filters">
                    <div class="search-box" style="padding: 0.3rem 0.6rem;">
                        <i class="fa-solid fa-search" style="font-size: 0.8rem;"></i>
                        <input type="text" id="drawer-search" placeholder="Search students..." onkeyup="renderAODrawerStudents()">
                    </div>
                    <select id="drawer-status-filter" style="border: 1px solid var(--border-color); border-radius: 6px; outline: none; padding: 0.3rem 0.5rem; font-size: 0.85rem;" onchange="renderAODrawerStudents()">
                        <option value="ALL">All Status</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Pending">Pending Evaluation</option>
                        <option value="Not Uploaded">Not Uploaded</option>
                    </select>
                </div>
                <div class="drawer-body">
                    <div class="student-list" id="drawer-student-list">
                        <!-- Injected via JS -->
                    </div>
                </div>
            </div>
            
            <!-- Student Report Modal -->
            <div class="sr-modal-overlay" id="sr-modal-overlay" onclick="closeStudentReportModal()"></div>
            <div class="sr-modal" id="sr-modal">
                <div class="sr-header">
                    <div>
                        <h3 class="panel-title">Student Assignment Report</h3>
                        <p class="panel-subtitle" id="sr-subtitle">Filter by Dept, Year, Sem, Div</p>
                    </div>
                    <div class="sr-toolbar">
                        <div class="search-box">
                            <i class="fa-solid fa-search" style="color: #94a3b8;"></i>
                            <input type="text" id="sr-search" placeholder="Search Report..." onkeyup="debounceFetchSR()">
                        </div>
                        <button class="export-btn" onclick="exportSRCSV()" style="background: #10b981; color: white; border: none;">
                            <i class="fa-solid fa-file-excel"></i> Export to Excel
                        </button>
                        <button class="drawer-close" onclick="closeStudentReportModal()" style="margin-left: 1rem;"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div class="sr-body" id="sr-body-container">
                    <table class="sr-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Roll No / PRN</th>
                                <th>Student Name</th>
                                <th>Div</th>
                                <th>Overall %</th>
                                <th>Submitted</th>
                                <th>Pending</th>
                                <th>Avg Marks</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="sr-table-body">
                            <!-- Injected -->
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="sr-pagination">
                    <div class="text-sm text-muted" id="sr-page-info">Showing 0 to 0 of 0 entries</div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="page-btn" id="sr-prev-btn" onclick="changeSRPage(-1)">Previous</button>
                        <button class="page-btn" id="sr-next-btn" onclick="changeSRPage(1)">Next</button>
                    </div>
                </div>
            </div>
            
            <script>
                let aoData = null;
                let aoDrawerData = [];
                let aoDebounceTimer;
                
                function debounceFetchAO() {
                    clearTimeout(aoDebounceTimer);
                    aoDebounceTimer = setTimeout(fetchAOData, 500);
                }
                
                async function fetchAOData() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    const search = document.getElementById('ao-search').value;
                    
                    document.getElementById('ao-subjects-container').innerHTML = '<div style="padding: 2rem; text-align: center; color: #94a3b8;"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i></div>';
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_dashboard_summary&dept=${dept}&year=${year}&sem=${sem}&div=${div}&search=${search}`);
                        const data = await res.json();
                        if (data.success) {
                            aoData = data;
                            renderAODashboard();
                        }
                    } catch (e) {
                        console.error('Error fetching data', e);
                    }
                }
                
                function renderAODashboard() {
                    if (!aoData) return;
                    
                    // Stat Cards
                    const stats = aoData.stats;
                    const subPercent = stats.expected > 0 ? Math.round((stats.submitted / stats.expected)*100) : 0;
                    const penPercent = stats.expected > 0 ? Math.round((stats.pending / stats.expected)*100) : 0;
                    
                    document.getElementById('ao-stat-cards').innerHTML = `
                        <div class="stat-card" style="cursor: pointer;" onclick="openStudentReportModal()">
                            <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
                            <div class="stat-info"><h3>Total Students</h3><p class="value">${stats.total_students}</p></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-book-open"></i></div>
                            <div class="stat-info"><h3>Total Subjects</h3><p class="value">${stats.total_subjects}</p></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon purple"><i class="fa-solid fa-file-invoice"></i></div>
                            <div class="stat-info"><h3>Total Assignments</h3><p class="value">${stats.total_assignments}</p></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-regular fa-circle-check"></i></div>
                            <div class="stat-info"><h3>Submitted</h3><p class="value">${stats.submitted} <span class="sub-value">(${subPercent}%)</span></p></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="fa-regular fa-clock"></i></div>
                            <div class="stat-info"><h3>Pending</h3><p class="value">${stats.pending} <span class="sub-value">(${penPercent}%)</span></p></div>
                        </div>
                    `;
                    
                    // Subjects Table
                    let subjHtml = '';
                    if (aoData.subject_summary.length === 0) {
                        subjHtml = '<div style="padding: 2rem; text-align: center; color: #94a3b8;">No data available for these filters.</div>';
                    } else {
                        aoData.subject_summary.forEach((subj, idx) => {
                            const badgeColor = subj.status === 'Excellent' ? 'green' : (subj.status === 'Good' ? 'green' : (subj.status === 'Average' ? 'orange' : 'red'));
                            
                            subjHtml += `
                                <div class="subject-row" onclick="toggleAccordion('ao-acc-${idx}')">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;">
                                        ${subj.subject_name.substring(0,2).toUpperCase()}
                                    </div>
                                    <div class="subject-name-col">
                                        <div class="subject-name">${subj.subject_name}</div>
                                        <div class="subject-faculty">${subj.faculty_name}</div>
                                    </div>
                                    <div class="subject-stats-col">
                                        <div class="subject-stat-label">Total Assignments</div>
                                        <div class="subject-stat-val">${subj.total_assignments}</div>
                                    </div>
                                    <div class="subject-stats-col">
                                        <div class="subject-stat-label">Submitted</div>
                                        <div class="subject-stat-val">${subj.submitted}</div>
                                    </div>
                                    <div class="subject-stats-col">
                                        <div class="subject-stat-label">Pending</div>
                                        <div class="subject-stat-val">${subj.pending}</div>
                                    </div>
                                    <div class="subject-stats-col">
                                        <div class="subject-stat-label">Avg Score</div>
                                        <div class="subject-stat-val"><span style="color: ${subj.avg_score >= 70 ? '#166534' : '#92400e'}">${subj.avg_score}%</span></div>
                                    </div>
                                    <div class="subject-stats-col" style="flex: 1.5; display: flex; align-items: center; justify-content: space-between;">
                                        <span class="badge ${badgeColor}">${subj.status}</span>
                                        <i class="fa-solid fa-chevron-down" style="color: #cbd5e1; font-size: 0.8rem; margin-left: 1rem;"></i>
                                    </div>
                                </div>
                                <div class="accordion-content" id="ao-acc-${idx}">
                            `;
                            
                            if (subj.assignments.length === 0) {
                                subjHtml += `<div style="font-size: 0.85rem; color: #64748b;">No assignments published.</div>`;
                            } else {
                                subj.assignments.forEach(ass => {
                                    subjHtml += `
                                        <div class="assignment-item">
                                            <div class="assign-title"><i class="fa-solid fa-file-lines text-muted" style="margin-right: 0.5rem;"></i> ${ass.title}</div>
                                            <div class="assign-stats">
                                                <span>Submitted <strong>${ass.submitted}</strong></span>
                                                <span>Pending <strong>${ass.pending}</strong></span>
                                                <span>Completion <strong>${ass.completion}%</strong></span>
                                                <button class="btn-view" onclick="openAODrawer(${ass.id}, '${ass.title.replace(/'/g, "\\'")}', '${subj.subject_name.replace(/'/g, "\\'")}')">View Students</button>
                                            </div>
                                        </div>
                                    `;
                                });
                            }
                            subjHtml += `</div>`;
                        });
                    }
                    document.getElementById('ao-subjects-container').innerHTML = subjHtml;
                    
                    // Doughnut Chart & Analytics
                    document.getElementById('ao-doughnut-path').style.strokeDasharray = `${subPercent}, 100`;
                    document.getElementById('ao-doughnut-text').textContent = `${subPercent}%`;
                    
                    document.getElementById('ao-analytics-stats').innerHTML = `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.85rem;">
                            <span style="color: #64748b; display: flex; align-items: center; gap: 0.5rem;"><div style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></div> Submitted</span>
                            <span style="font-weight: 600; color: #1e293b;">${subPercent}% <span style="color: #94a3b8; font-weight: normal; margin-left: 0.5rem;">${stats.submitted} Assignments</span></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1.5rem; font-size: 0.85rem;">
                            <span style="color: #64748b; display: flex; align-items: center; gap: 0.5rem;"><div style="width: 8px; height: 8px; border-radius: 50%; background: #f1f5f9; border: 1px solid #cbd5e1;"></div> Pending</span>
                            <span style="font-weight: 600; color: #1e293b;">${penPercent}% <span style="color: #94a3b8; font-weight: normal; margin-left: 0.5rem;">${stats.pending} Assignments</span></span>
                        </div>
                        
                        <div style="padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span style="font-size: 0.85rem; color: #475569;"><i class="fa-solid fa-ranking-star text-muted" style="margin-right: 0.25rem;"></i> Class Average Score</span>
                                <span style="font-weight: 700; color: #0f172a;">${aoData.analytics.class_average}%</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span style="font-size: 0.85rem; color: #475569;"><i class="fa-solid fa-arrow-trend-up text-muted" style="margin-right: 0.25rem;"></i> Highest Subject</span>
                                <span style="font-weight: 600; color: #166534; font-size: 0.8rem; text-align: right; max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${aoData.analytics.highest_subject}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="font-size: 0.85rem; color: #475569;"><i class="fa-solid fa-arrow-trend-down text-muted" style="margin-right: 0.25rem;"></i> Lowest Subject</span>
                                <span style="font-weight: 600; color: #991b1b; font-size: 0.8rem; text-align: right; max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${aoData.analytics.lowest_subject}</span>
                            </div>
                        </div>
                        <div style="padding: 0.75rem 1rem; background: #fffbeb; border-radius: 8px; border: 1px solid #fde68a; font-size: 0.85rem;">
                            <div style="color: #92400e; font-weight: 600; margin-bottom: 0.25rem;"><i class="fa-solid fa-award"></i> Top Faculty</div>
                            <div style="color: #b45309;">${aoData.analytics.top_faculty}</div>
                        </div>
                    `;
                    
                    // Recent Table
                    let rtHtml = '';
                    if (aoData.recent_assignments.length === 0) {
                        rtHtml = `<tr><td colspan="6" style="padding: 1.5rem; text-align: center; color: #94a3b8;">No recent assignments.</td></tr>`;
                    } else {
                        aoData.recent_assignments.forEach(ra => {
                            rtHtml += `
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 1rem; font-weight: 500; color: #1e293b;">${ra.title}</td>
                                    <td style="padding: 1rem; color: #475569;">${ra.subject}<br><span style="font-size: 0.75rem; color: #94a3b8;">${ra.faculty}</span></td>
                                    <td style="padding: 1rem; color: #475569;">${ra.total_students}</td>
                                    <td style="padding: 1rem; color: #166534; font-weight: 600;">${ra.submitted}</td>
                                    <td style="padding: 1rem; color: #991b1b; font-weight: 600;">${ra.pending}</td>
                                    <td style="padding: 1rem; text-align: center;">
                                        <div style="font-weight: 600; color: #334155; margin-bottom: 0.2rem;">${ra.completion}%</div>
                                        <div style="width: 100%; height: 4px; background: #f1f5f9; border-radius: 2px; overflow: hidden;">
                                            <div style="width: ${ra.completion}%; height: 100%; background: #3b82f6;"></div>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    document.getElementById('ao-recent-table').innerHTML = rtHtml;
                }
                
                function toggleAccordion(id) {
                    const el = document.getElementById(id);
                    if (el.classList.contains('open')) {
                        el.classList.remove('open');
                    } else {
                        document.querySelectorAll('.accordion-content').forEach(e => e.classList.remove('open'));
                        el.classList.add('open');
                    }
                }
                
                async function openAODrawer(saId, title, subj) {
                    event.stopPropagation();
                    document.getElementById('drawer-assign-title').textContent = title;
                    document.getElementById('drawer-assign-subtitle').textContent = subj;
                    
                    document.getElementById('ao-drawer-overlay').classList.add('active');
                    document.getElementById('ao-drawer').classList.add('active');
                    
                    document.getElementById('drawer-student-list').innerHTML = '<div style="padding: 2rem; text-align: center; color: #94a3b8;"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i></div>';
                    
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_assignment_students&sa_id=${saId}&dept=${dept}&year=${year}&sem=${sem}&div=${div}`);
                        const data = await res.json();
                        if (data.success) {
                            aoDrawerData = data.students;
                            renderAODrawerStudents();
                        }
                    } catch (e) {
                        console.error('Error fetching students', e);
                        document.getElementById('drawer-student-list').innerHTML = '<div style="color: red;">Error loading students.</div>';
                    }
                }
                
                function closeAODrawer() {
                    document.getElementById('ao-drawer-overlay').classList.remove('active');
                    document.getElementById('ao-drawer').classList.remove('active');
                }
                
                function renderAODrawerStudents() {
                    const search = document.getElementById('drawer-search').value.toLowerCase();
                    const statusFilter = document.getElementById('drawer-status-filter').value;
                    
                    const filtered = aoDrawerData.filter(s => {
                        let ms = true;
                        if (search !== '') {
                            ms = s.name.toLowerCase().includes(search) || s.roll.toLowerCase().includes(search);
                        }
                        let mStat = true;
                        if (statusFilter !== 'ALL') {
                            if (statusFilter === 'Pending') mStat = (s.status === 'Pending Evaluation' || s.status === 'Pending');
                            else mStat = (s.status === statusFilter);
                        }
                        return ms && mStat;
                    });
                    
                    let html = '';
                    if (filtered.length === 0) {
                        html = '<div style="padding: 2rem; text-align: center; color: #94a3b8;">No students found.</div>';
                    } else {
                        filtered.forEach(s => {
                            let badgeClass = 'gray';
                            if (s.status === 'Submitted' || s.status === 'Evaluated') badgeClass = 'green';
                            if (s.status === 'Pending' || s.status === 'Pending Evaluation') badgeClass = 'orange';
                            
                            html += `
                                <div class="student-card">
                                    <div class="info">
                                        <img src="${s.photo}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                        <div>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 0.95rem; margin-bottom: 0.2rem;">${s.name}</div>
                                            <div style="font-size: 0.8rem; color: #64748b;">${s.roll} • <span class="badge ${badgeClass}" style="font-size: 0.65rem;">${s.status}</span></div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 700; color: ${badgeClass === 'green' ? '#166534' : '#64748b'}; font-size: 1.1rem;">${s.marks}</div>
                                        <div style="font-size: 0.75rem; color: #94a3b8;">${s.submitted_at}</div>
                                    </div>
                                </div>
                            `;
                        });
                    }
                    document.getElementById('drawer-student-list').innerHTML = html;
                }
                
                function exportAOCSV() {
                    if (!aoData || aoData.subject_summary.length === 0) {
                        alert("No data to export");
                        return;
                    }
                    
                    let csv = [];
                    aoData.subject_summary.forEach(subj => {
                        subj.assignments.forEach(ass => {
                            csv.push({
                                Subject: subj.subject_name,
                                Faculty: subj.faculty_name,
                                Assignment: ass.title,
                                Total_Students: subj.expected_submissions / subj.total_assignments, // approx class size
                                Submitted: ass.submitted,
                                Pending: ass.pending,
                                Completion: ass.completion + '%'
                            });
                        });
                    });
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'api_admin_assignments.php?action=export_csv';
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'data';
                    input.value = JSON.stringify(csv);
                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }

                // Initial fetch
                setTimeout(fetchAOData, 100);

                // --- Student Report Logic ---
                let srCurrentPage = 1;
                let srDebounceTimer;
                
                function openStudentReportModal() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    document.getElementById('sr-subtitle').textContent = `${dept !== 'ALL' ? dept : 'All Depts'} | Yr ${year} | Sem ${sem} | Div ${div}`;
                    
                    document.getElementById('sr-modal-overlay').classList.add('active');
                    document.getElementById('sr-modal').classList.add('active');
                    
                    srCurrentPage = 1;
                    fetchStudentReport();
                }
                
                function closeStudentReportModal() {
                    document.getElementById('sr-modal-overlay').classList.remove('active');
                    document.getElementById('sr-modal').classList.remove('active');
                }
                
                function debounceFetchSR() {
                    clearTimeout(srDebounceTimer);
                    srDebounceTimer = setTimeout(() => { srCurrentPage = 1; fetchStudentReport(); }, 500);
                }
                
                async function fetchStudentReport() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    const search = document.getElementById('sr-search').value;
                    
                    document.getElementById('sr-table-body').innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 2rem;"><i class="fa-solid fa-circle-notch fa-spin fa-2x text-muted"></i></td></tr>';
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_student_assignment_report&dept=${dept}&year=${year}&sem=${sem}&div=${div}&search=${search}&page=${srCurrentPage}&limit=15`);
                        const result = await res.json();
                        
                        if (result.success) {
                            renderStudentReport(result.data, result.pagination);
                        }
                    } catch (e) {
                        console.error('Error fetching student report', e);
                    }
                }
                
                function renderStudentReport(data, pagination) {
                    let html = '';
                    if (data.length === 0) {
                        html = '<tr><td colspan="9" style="text-align: center; padding: 2rem; color: #64748b;">No students found matching your criteria.</td></tr>';
                    } else {
                        data.forEach(s => {
                            let badgeClass = s.status === 'Excellent' ? 'green' : (s.status === 'Good' ? 'blue' : (s.status === 'Needs Attention' ? 'red' : 'orange'));
                            html += `
                                <tr class="main-row">
                                    <td style="width: 40px; text-align: center;">
                                        <button class="expand-btn" id="btn-exp-${s.id}" onclick="toggleStudentRow('${s.id}')"><i class="fa-solid fa-chevron-right"></i></button>
                                    </td>
                                    <td style="font-weight: 500;">${s.roll}</td>
                                    <td style="font-weight: 600; color: #0f172a;">${s.name}</td>
                                    <td>${s.division}</td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span style="font-weight: 600;">${s.completion}%</span>
                                            <div style="flex-grow: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; min-width: 60px;">
                                                <div style="width: ${s.completion}%; height: 100%; background: ${s.completion >= 80 ? '#10b981' : (s.completion >= 50 ? '#3b82f6' : '#ef4444')};"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color: #166534; font-weight: 600;">${s.submitted}</td>
                                    <td style="color: #991b1b; font-weight: 600;">${s.pending}</td>
                                    <td style="font-weight: 600;">${s.avg_marks > 0 ? s.avg_marks : '-'}</td>
                                    <td><span class="badge ${badgeClass}">${s.status}</span></td>
                                </tr>
                                <tr class="nested-row" id="nested-row-${s.id}">
                                    <td colspan="9" class="nested-content" id="nested-content-${s.id}">
                                        <div style="text-align: center; padding: 1rem;"><i class="fa-solid fa-spinner fa-spin text-muted"></i> Loading subjects...</div>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    
                    document.getElementById('sr-table-body').innerHTML = html;
                    
                    // Update pagination
                    document.getElementById('sr-page-info').textContent = `Showing Page ${pagination.page} of ${pagination.pages} (${pagination.total} entries)`;
                    document.getElementById('sr-prev-btn').disabled = pagination.page <= 1;
                    document.getElementById('sr-next-btn').disabled = pagination.page >= pagination.pages;
                }
                
                function changeSRPage(dir) {
                    srCurrentPage += dir;
                    fetchStudentReport();
                }
                
                async function toggleStudentRow(studentId) {
                    const btn = document.getElementById(`btn-exp-${studentId}`);
                    const row = document.getElementById(`nested-row-${studentId}`);
                    const content = document.getElementById(`nested-content-${studentId}`);
                    
                    if (btn.classList.contains('expanded')) {
                        btn.classList.remove('expanded');
                        row.classList.remove('active');
                        return;
                    }
                    
                    // Close others
                    document.querySelectorAll('.expand-btn.expanded').forEach(b => {
                        if (b.id !== `btn-exp-${studentId}`) {
                            b.classList.remove('expanded');
                            document.getElementById(b.id.replace('btn-exp-', 'nested-row-')).classList.remove('active');
                        }
                    });
                    
                    btn.classList.add('expanded');
                    row.classList.add('active');
                    
                    // Fetch subjects
                    const dept = document.getElementById('ao-dept').value;
                    const div = document.getElementById('ao-div').value;
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_student_subjects&student_id=${studentId}&dept=${dept}&div=${div}`);
                        const result = await res.json();
                        
                        if (result.success) {
                            let sHtml = '<div style="margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Subject Breakdown</div>';
                            if (result.subjects.length === 0) {
                                sHtml += '<div style="color: #64748b; font-size: 0.9rem;">No subjects found.</div>';
                            } else {
                                result.subjects.forEach((subj, idx) => {
                                    sHtml += `
                                        <div class="subject-card">
                                            <div class="subject-card-header" onclick="toggleSubjectRow('${studentId}', '${subj.name.replace(/'/g, "\\'")}', this)">
                                                <div style="display: flex; align-items: center; gap: 1rem; width: 40%;">
                                                    <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.75rem; transition: transform 0.2s;"></i>
                                                    <div>
                                                        <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;">${subj.name}</div>
                                                        <div style="font-size: 0.75rem; color: #64748b;">${subj.faculty}</div>
                                                    </div>
                                                </div>
                                                <div style="display: flex; align-items: center; justify-content: space-between; width: 60%; padding-right: 1rem;">
                                                    <div style="font-size: 0.85rem; color: #475569;"><span style="color:#166534;font-weight:600;">${subj.submitted}</span> Sub / <span style="color:#991b1b;font-weight:600;">${subj.pending}</span> Pend</div>
                                                    <div style="font-weight: 600; font-size: 0.85rem;">Avg: ${subj.avg_marks > 0 ? subj.avg_marks : '-'}</div>
                                                    <div><span class="badge ${subj.completion >= 80 ? 'green' : (subj.completion >= 50 ? 'blue' : 'red')}">${subj.completion}%</span></div>
                                                </div>
                                            </div>
                                            <div class="subject-assignments" id="assign-${studentId}-${idx}">
                                                <div style="text-align: center; padding: 1rem;"><i class="fa-solid fa-spinner fa-spin text-muted"></i> Loading assignments...</div>
                                            </div>
                                        </div>
                                    `;
                                });
                            }
                            content.innerHTML = sHtml;
                        }
                    } catch (e) {
                        content.innerHTML = '<div style="color: red;">Error loading subjects.</div>';
                    }
                }
                
                async function toggleSubjectRow(studentId, subjectName, headerEl) {
                    const icon = headerEl.querySelector('.fa-chevron-right');
                    const body = headerEl.nextElementSibling;
                    
                    if (body.classList.contains('active')) {
                        body.classList.remove('active');
                        icon.style.transform = 'rotate(0deg)';
                        return;
                    }
                    
                    body.classList.add('active');
                    icon.style.transform = 'rotate(90deg)';
                    
                    const dept = document.getElementById('ao-dept').value;
                    const div = document.getElementById('ao-div').value;
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_student_subject_assignments&student_id=${studentId}&subject_name=${encodeURIComponent(subjectName)}&dept=${dept}&div=${div}`);
                        const result = await res.json();
                        
                        if (result.success) {
                            let aHtml = '';
                            if (result.assignments.length === 0) {
                                aHtml = '<div style="color: #64748b; font-size: 0.85rem;">No assignments published.</div>';
                            } else {
                                result.assignments.forEach(ass => {
                                    const bClass = ass.status === 'Submitted' ? 'green' : (ass.status === 'Pending Evaluation' ? 'orange' : 'red');
                                    aHtml += `
                                        <div class="assignment-pill">
                                            <div style="width: 30%;">
                                                <div style="font-weight: 600; color: #334155; font-size: 0.85rem;">${ass.title}</div>
                                                <div style="font-size: 0.75rem; color: #94a3b8;"><i class="fa-regular fa-clock"></i> ${ass.submission_date}</div>
                                            </div>
                                            <div style="width: 20%; text-align: center;">
                                                <span class="badge ${bClass}">${ass.status}</span>
                                            </div>
                                            <div style="width: 20%; text-align: center; font-size: 0.85rem; font-weight: 600; color: #0f172a;">
                                                ${ass.marks !== '-' ? \`\${ass.marks}/\${ass.total_marks}\` : '-'}
                                                <span style="color: #64748b; font-size: 0.75rem; margin-left: 0.5rem;">(\${ass.percentage})</span>
                                            </div>
                                            <div style="width: 30%; font-size: 0.8rem; color: #64748b; padding-left: 1rem; border-left: 1px solid #e2e8f0;">
                                                <div style="font-weight: 600; margin-bottom: 0.2rem;">Remarks:</div>
                                                <div style="font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${ass.remarks}</div>
                                            </div>
                                        </div>
                                    `;
                                });
                            }
                            body.innerHTML = aHtml;
                        }
                    } catch (e) {
                        body.innerHTML = '<div style="color: red;">Error loading assignments.</div>';
                    }
                }
                
                function exportSRCSV() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    const search = document.getElementById('sr-search').value;
                    
                    window.location.href = \`api_admin_assignments.php?action=export_student_assignment_report&dept=\${dept}&year=\${year}&sem=\${sem}&div=\${div}&search=\${search}\`;
                }
            </script>
        </div>
