CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','staff') DEFAULT 'staff',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT,
  name VARCHAR(150) NOT NULL,
  sku VARCHAR(50) UNIQUE,
  unit VARCHAR(20) DEFAULT 'pcs',
  purchase_price DECIMAL(10,2) DEFAULT 0,
  selling_price DECIMAL(10,2) DEFAULT 0,
  opening_stock INT DEFAULT 0,
  current_stock INT DEFAULT 0,
  reorder_level INT DEFAULT 10,
  image VARCHAR(255),
  description TEXT,
  product_type ENUM('standard', 'regulator', 'ftl_regulator') DEFAULT 'standard',
  defective_stock INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20),
  address VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20),
  address VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT,
  invoice_no VARCHAR(50),
  purchase_date DATE NOT NULL,
  total_amount DECIMAL(10,2) DEFAULT 0,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT,
  product_id INT,
  quantity INT NOT NULL,
  rate DECIMAL(10,2) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT,
  invoice_no VARCHAR(50),
  sale_date DATE NOT NULL,
  payment_type ENUM('cash','credit') DEFAULT 'cash',
  discount DECIMAL(10,2) DEFAULT 0,
  cash_paid DECIMAL(10,2) DEFAULT 0,
  online_paid DECIMAL(10,2) DEFAULT 0,
  total_amount DECIMAL(10,2) DEFAULT 0,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT,
  product_id INT,
  quantity INT NOT NULL,
  rate DECIMAL(10,2) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  quantity INT NOT NULL,       -- +ve = add, -ve = deduct
  reason VARCHAR(255),
  adjusted_by INT,
  adjusted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (adjusted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(255) NOT NULL,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_profile (
  id INT PRIMARY KEY DEFAULT 1,
  company_name VARCHAR(255) NOT NULL,
  phone VARCHAR(50),
  email VARCHAR(100),
  address TEXT,
  gstin VARCHAR(50),
  logo VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_profile (id, company_name, phone, email, address, gstin, logo)
VALUES (1, 'StockFlow Agency', '+91 9999888877', 'info@stockflow.com', 'Sector-4, Noida', '07AAAAA1111A1Z1', NULL)
ON DUPLICATE KEY UPDATE company_name=company_name;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('admin', 'manager', 'staff') NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  is_allowed TINYINT(1) DEFAULT 0,
  UNIQUE KEY role_perm (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('admin', 'products_view', 1), ('manager', 'products_view', 1), ('staff', 'products_view', 1),
('admin', 'products_manage', 1), ('manager', 'products_manage', 1), ('staff', 'products_manage', 0),
('admin', 'products_delete', 1), ('manager', 'products_delete', 0), ('staff', 'products_delete', 0),
('admin', 'categories_view', 1), ('manager', 'categories_view', 1), ('staff', 'categories_view', 1),
('admin', 'categories_manage', 1), ('manager', 'categories_manage', 1), ('staff', 'categories_manage', 0),
('admin', 'suppliers_view', 1), ('manager', 'suppliers_view', 1), ('staff', 'suppliers_view', 1),
('admin', 'suppliers_manage', 1), ('manager', 'suppliers_manage', 1), ('staff', 'suppliers_manage', 0),
('admin', 'customers_view', 1), ('manager', 'customers_view', 1), ('staff', 'customers_view', 1),
('admin', 'customers_manage', 1), ('manager', 'customers_manage', 1), ('staff', 'customers_manage', 0),
('admin', 'purchases_view', 1), ('manager', 'purchases_view', 1), ('staff', 'purchases_view', 1),
('admin', 'purchases_manage', 1), ('manager', 'purchases_manage', 1), ('staff', 'purchases_manage', 1),
('admin', 'sales_view', 1), ('manager', 'sales_view', 1), ('staff', 'sales_view', 1),
('admin', 'sales_manage', 1), ('manager', 'sales_manage', 1), ('staff', 'sales_manage', 1),
('admin', 'sales_delete', 1), ('manager', 'sales_delete', 0), ('staff', 'sales_delete', 0),
('admin', 'adjustments_manage', 1), ('manager', 'adjustments_manage', 1), ('staff', 'adjustments_manage', 0),
('admin', 'reports_view', 1), ('manager', 'reports_view', 1), ('staff', 'reports_view', 1),
('admin', 'logs_view', 1), ('manager', 'logs_view', 0), ('staff', 'logs_view', 0),
('admin', 'settings_manage', 1), ('manager', 'settings_manage', 0), ('staff', 'settings_manage', 0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);


CREATE TABLE IF NOT EXISTS customer_replacements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    customer_name VARCHAR(150),
    product_id INT,
    quantity INT NOT NULL,
    swap_type ENUM('replacement', 'new_connection', 'tv_in', 'tv_out') DEFAULT 'replacement',
    consumer_number VARCHAR(50),
    mobile_number VARCHAR(20),
    old_regulator_no VARCHAR(100),
    new_regulator_no VARCHAR(100),
    replacement_date DATE NOT NULL,
    notes VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plant_replacements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT,
    product_id INT,
    quantity INT NOT NULL,
    return_date DATE NOT NULL,
    notes VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


