<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-chart-line me-2 text-success"></i>Reports & Audits Center</h3>
        <p class="text-muted" style="font-size: 0.95rem;">Export print-friendly sheets, tracking metrics, and registers.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Daily Stock Register Ledger -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-success-light text-success p-3 rounded-3 mb-3 d-inline-block">
                        <i class="fa-solid fa-book fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Daily Stock Register</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Chronological daily ledger calculating opening stock, purchase in, sales out, corrections, and ending balances.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="stock_register.php" class="btn btn-accent bg-success">Open Register</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Product-wise Inventory valuation -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-primary-light text-primary p-3 rounded-3 mb-3 d-inline-block">
                        <i class="fa-solid fa-boxes-stacked fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Product-Wise Report</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Complete inventory overview sheet detailing opening, total in, total out, adjustments, current stock, and asset valuation.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="product_wise.php" class="btn btn-accent bg-primary">Open Product Report</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Capital Stock Valuation Report -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-info-light text-info p-3 rounded-3 mb-3 d-inline-block" style="background-color: #ecfdf5; color: #10b981;">
                        <i class="fa-solid fa-calculator fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Stock Valuation</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Calculate total capital asset value, potential retail returns, and projected margins based on current inventory costing.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="valuation.php" class="btn btn-accent" style="background-color: #10b981;">Open Valuation Report</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Regulator Replacement Register Report -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-warning-light text-warning p-3 rounded-3 mb-3 d-inline-block" style="background-color: #fffbeb; color: #d97706;">
                        <i class="fa-solid fa-arrows-spin fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Regulator Swaps Register</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Audit connections list and replacement logs. Tracks NC issued, TV In issued, and TV Out defective returns.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="regulator_report.php" class="btn btn-accent" style="background-color: #d97706;">Open Swaps Register</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Low Stock warning Alerts -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-danger-light text-danger p-3 rounded-3 mb-3 d-inline-block">
                        <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Low Stock Warnings</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Filter and list all catalog items that have dipped below set threshold values requiring replenishment.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="low_stock.php" class="btn btn-accent bg-danger">View Alerts</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales invoices reports -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-warning-light text-warning p-3 rounded-3 mb-3 d-inline-block">
                        <i class="fa-solid fa-file-invoice-dollar fa-xl" style="color: #d97706;"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Sales Billings Report</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Filterable transactional list of generated sales invoices, cash/credit statuses, client targets, and gross earnings.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="sales_report.php" class="btn btn-accent" style="background-color: #d97706;">Open Sales Report</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase receipts report -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="bg-info-light text-info p-3 rounded-3 mb-3 d-inline-block" style="background-color: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-truck-ramp-box fa-xl"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Purchase Ledger Report</h5>
                    <p class="text-muted mt-2" style="font-size: 0.85rem;">Log sheets of purchase deliveries received from suppliers, vendor references, and expenditure records.</p>
                </div>
                <div class="d-grid mt-4">
                    <a href="purchase_report.php" class="btn btn-accent" style="background-color: #0284c7;">Open Purchase Report</a>
                </div>
            </div>
        </div>
    </div>

    <!-- System Audits Logs -->
    <?php if (is_admin()): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card card-premium shadow-sm h-100">
                <div class="card-premium-body d-flex flex-column justify-content-between h-100">
                    <div>
                        <div class="bg-secondary-light text-secondary p-3 rounded-3 mb-3 d-inline-block" style="background-color: #f1f5f9; color: #475569;">
                            <i class="fa-solid fa-clock-rotate-left fa-xl"></i>
                        </div>
                        <h5 class="fw-bold text-dark">System Audits Log</h5>
                        <p class="text-muted mt-2" style="font-size: 0.85rem;">Track logins, manual adjustments, edits, and deletions performed across the system.</p>
                    </div>
                    <div class="d-grid mt-4">
                        <a href="activity_log.php" class="btn btn-accent" style="background-color: #475569;">Open Audit Logs</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
