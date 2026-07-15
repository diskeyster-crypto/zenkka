<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;display:flex;min-height:100vh}

/* ── Sidebar ── */
.sidebar{width:220px;min-height:100vh;background:#1e293b;color:#cbd5e1;display:flex;flex-direction:column;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100}
.sidebar-logo{padding:1.5rem 1.25rem;border-bottom:1px solid #334155}
.sidebar-logo h2{color:#f1f5f9;font-size:1.1rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-logo p{color:#64748b;font-size:.75rem;margin-top:.2rem}
.sidebar-nav{flex:1;padding:1rem 0}
.sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.25rem;color:#94a3b8;text-decoration:none;font-size:.875rem;font-weight:500;transition:background .15s,color .15s;border-left:3px solid transparent}
.sidebar-nav a:hover{background:#334155;color:#f1f5f9}
.sidebar-nav a.active{background:#334155;color:#e2e8f0;border-left-color:#667eea}
.sidebar-footer{padding:1rem 1.25rem;border-top:1px solid #334155;font-size:.75rem;color:#475569}

/* ── Main content ── */
.main-content{margin-left:220px;flex:1;padding:2rem;max-width:calc(100% - 220px)}
.page-header{margin-bottom:1.75rem}
.page-header h1{font-size:1.5rem;color:#1e293b;font-weight:700}
.text-muted{color:#64748b;font-size:.875rem;margin-top:.25rem}

/* ── Cards ── */
.card{background:#fff;border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:1.75rem;margin-bottom:1.5rem}
.card h2{font-size:1rem;color:#4a5568;margin-bottom:1.25rem;padding-bottom:.75rem;border-bottom:1px solid #e2e8f0}

/* ── Forms ── */
.form-row{display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:1.25rem}
.form-group{flex:1;min-width:180px;margin-bottom:1.25rem}
.form-group:last-child{margin-bottom:0}
label{display:block;font-size:.8rem;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:.6rem .85rem;border:1.5px solid #d1d5db;border-radius:7px;font-size:.925rem;transition:border-color .2s,box-shadow .2s;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.15)}
input[type=range]{width:100%;margin-top:.3rem}
.hint{font-size:.76rem;color:#6b7280;margin-top:.3rem}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.25rem;border:none;border-radius:7px;font-size:.875rem;font-weight:600;cursor:pointer;text-decoration:none;transition:opacity .2s,background .2s}
.btn:hover{opacity:.88}
.btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
.btn-outline{background:#fff;color:#374151;border:1.5px solid #d1d5db}
.btn-outline:hover{background:#f9fafb}
.btn-danger{background:#ef4444;color:#fff}
.btn-sm{padding:.4rem .85rem;font-size:.8rem}

/* ── Alerts ── */
.alert{padding:.85rem 1.1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.875rem}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}

/* ── Table ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th{background:#f8fafc;color:#374151;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;padding:.65rem 1rem;text-align:left;border-bottom:2px solid #e2e8f0}
td{padding:.65rem 1rem;border-bottom:1px solid #f1f5f9;color:#374151;vertical-align:top}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafbfc}
.badge{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600}
.badge-blue{background:#dbeafe;color:#1e40af}
.badge-green{background:#dcfce7;color:#166534}
.badge-gray{background:#f1f5f9;color:#475569}

/* ── Stats ── */
.stats-row{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem}
.stat-card{background:#fff;border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:1.25rem 1.5rem;flex:1;min-width:140px}
.stat-card .label{font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.stat-card .value{font-size:2rem;font-weight:700;color:#1e293b;margin-top:.25rem}
.stat-card .sub{font-size:.75rem;color:#94a3b8}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%)}
    .main-content{margin-left:0;max-width:100%}
}
</style>
