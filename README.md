# 📊 StockFlow — Premium LPG Inventory & Regulator Swaps Register

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg?style=for-the-badge&logo=php)](https://www.php.net/)
[![MySQL Version](https://img.shields.io/badge/MySQL-8.0%2B-orange.svg?style=for-the-badge&logo=mysql)](https://www.mysql.com/)
[![Bootstrap Version](https://img.shields.io/badge/Bootstrap-5.3-purple.svg?style=for-the-badge&logo=bootstrap)](https://getbootstrap.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)

StockFlow is an offline-capable, web-based inventory ledger system specifically optimized for LPG distributors, gas agencies, and regulator suppliers. It provides a premium dashboard to trace standard items, FTL (Free Transfer Limit) assets, and dual-balance tracking of Regulator stocks (Fresh Good Stock vs. Defective Stock).

---

## 🌟 Key Features

### 🔄 Dual-Balance Regulator Swaps
Tracks regulator inventory across separate Fresh Good Stock and Defective Stock pools:
* **Replacement Swap:** Defective regulator exchange (Good Stock `-1`, Defective Stock `+1`).
* **New Connection:** Issue fresh regulator (Good Stock `-1`, Defective Stock: `No Change`).
* **TV In (Transfer In):** Issue fresh regulator (Good Stock `-1`, Defective Stock: `No Change`).
* **TV Out (Transfer Out):** Receive returned regulator (Good Stock: `No Change`, Defective Stock `+1`).
* **Plant Swap-Back:** Factory returns of defective units to receive new good stock (Defective `-Qty`, Good `+Qty`).

### 🔍 Consumer History Verification Search
Allows immediate tracking and verification of any consumer by their **Connection/Consumer Number**:
* Instant retrieval of total regulator replacement frequencies.
* Logs serial numbers of both returned (Old SN) and issued (New SN) regulators.
* Displays transactional dates and staff remark logs.

### 🖨️ A4 Print-Friendly Audits
All reports—including the **Daily Stock Ledger** and **Valuation Sheets**—are optimized for printing:
* Automatically hides sidebar navigations, toggle controls, and action buttons during printing.
* Displays unified profile header metadata (Logo, Address, Phone, Email, GSTIN) dynamically.

### 📶 Offline Wi-Fi Local Sharing
Run the application completely offline on local counter terminals and share it across staff devices (mobiles/tablets) on the same Wi-Fi network without requiring internet.

---

## 📁 Project Directory Structure

```
├── assets/
│   ├── css/
│   │   └── custom.css          # Premium glassmorphism UI & print overrides
│   └── uploads/                # Dynamic company logo upload storage
├── config/
│   └── db.php                  # Database connection profile (PDO MySQL)
├── includes/
│   ├── functions.php           # Core business logic, stock updates & flash helper
│   ├── header.php              # Global navigation, alerts, and unified print header
│   └── sidebar.php             # Responsive collateral navigation sidebar
├── modules/
│   ├── auth/                   # Authentication controller (Login/Logout)
│   ├── products/               # Product catalog, type settings, and reorder levels
│   ├── purchase/               # Standard supply invoices logging
│   ├── sales/                  # Billing generation and cash/credit reports
│   ├── replacements/           # Customer swaps form and factory swap-back register
│   └── reports/                # Dynamic dual-balance ledgers and valuation reports
├── schema.sql                  # MySQL database initialization script
└── README.md                   # System documentation
```

---

## 🛢️ Database Installation

1. Create a MySQL database named `stock_register`:
   ```sql
   CREATE DATABASE stock_register;
   ```
2. Import the `schema.sql` file:
   ```bash
   mysql -u root -p stock_register < schema.sql
   ```

---

## 📶 How to Run Offline (Local Wi-Fi Network Sharing)

To run StockFlow offline on multiple computers and mobile devices inside your store using a local Wi-Fi router:

1. **Host Computer Setup:**
   - Install **XAMPP** on your main PC and run Apache and MySQL.
   - Copy this project folder into `C:\xampp\htdocs\stock-register`.
2. **Find Host IP Address:**
   - Open Command Prompt (`cmd`) and run `ipconfig`.
   - Look for the IPv4 Address (e.g., `192.168.1.15`).
3. **Share on Same Wi-Fi:**
   - Connect the host PC and other devices (mobiles/tablets/laptops) to the same Wi-Fi network.
   - On other devices, open the browser and enter:
     `http://192.168.1.15/stock-register/`
     *(Replace `192.168.1.15` with your host PC's actual IP address)*.
