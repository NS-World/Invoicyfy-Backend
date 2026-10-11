<?php
/**
 * Database Setup Script
 * Run this once to create all required tables and seed initial data
 */

header('Content-Type: text/plain; charset=utf-8');

try {
    // ============ DATABASE CONNECTION WITHOUT SELECTING DB ============
    $host = 'localhost';
    $db_name = 'invoice_system';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "[Step 1] Dropping old database...\n";
    $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
    echo "✓ Old database dropped\n\n";
    
    echo "[Step 2] Creating fresh database...\n";
    $pdo->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    echo "✓ New database created\n\n";
    
    // ============ CREATE ROLES TABLE ============
    echo "[Step 3] Creating roles table...\n";
    $pdo->exec("
        CREATE TABLE roles (
            role_id INT PRIMARY KEY AUTO_INCREMENT,
            role_name VARCHAR(100) UNIQUE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Roles table created\n\n";

    // ============ CREATE STORES TABLE ============
    echo "[Step 4] Creating stores table...\n";
    $pdo->exec("
        CREATE TABLE stores (
            store_id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            city VARCHAR(100),
            address TEXT,
            phone VARCHAR(20),
            logo_url VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Stores table created\n\n";

    // ============ CREATE USERS TABLE ============
    $pdo->exec("
        CREATE TABLE users (
            user_id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            phone VARCHAR(20),
            city VARCHAR(100),
            role_id INT,
            store_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(role_id),
            FOREIGN KEY (store_id) REFERENCES stores(store_id)
        )
    ");
    echo "✓ Created 'users' table\n";

    // ============ CREATE CUSTOMERS TABLE ============
    $pdo->exec("
        CREATE TABLE customers (
            customer_id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(20),
            email VARCHAR(255),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Created 'customers' table\n";

    // ============ CREATE PRODUCTS TABLE ============
    $pdo->exec("
        CREATE TABLE products (
            product_id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            sku VARCHAR(100) UNIQUE,
            description TEXT,
            price DECIMAL(10, 2) NOT NULL,
            unit VARCHAR(50),
            status VARCHAR(50) DEFAULT 'active',
            store_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (store_id) REFERENCES stores(store_id)
        )
    ");
    echo "✓ Created 'products' table\n";

    // ============ CREATE INVOICES TABLE ============
    $pdo->exec("
        CREATE TABLE invoices (
            invoice_id INT PRIMARY KEY AUTO_INCREMENT,
            invoice_number VARCHAR(50) UNIQUE NOT NULL,
            customer_id INT NOT NULL,
            user_id INT NOT NULL,
            store_id INT NOT NULL,
            invoice_date DATE NOT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (store_id) REFERENCES stores(store_id)
        )
    ");
    echo "✓ Created 'invoices' table\n";

    // ============ CREATE INVOICE_ITEMS TABLE ============
    $pdo->exec("
        CREATE TABLE invoice_items (
            invoice_item_id INT PRIMARY KEY AUTO_INCREMENT,
            invoice_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(product_id)
        )
    ");
    echo "✓ Created 'invoice_items' table\n";

    // ============ CREATE ORDERS TABLE ============
    $pdo->exec("
        CREATE TABLE orders (
            order_id INT PRIMARY KEY AUTO_INCREMENT,
            order_number VARCHAR(50) UNIQUE NOT NULL,
            customer_id INT NOT NULL,
            user_id INT NOT NULL,
            store_id INT NOT NULL,
            order_date DATE NOT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (store_id) REFERENCES stores(store_id)
        )
    ");
    echo "✓ Created 'orders' table\n";

    // ============ CREATE ORDER_ITEMS TABLE ============
    $pdo->exec("
        CREATE TABLE order_items (
            order_item_id INT PRIMARY KEY AUTO_INCREMENT,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(product_id)
        )
    ");
    echo "✓ Created 'order_items' table\n";

    // ============ CREATE PAYMENTS TABLE ============
    $pdo->exec("
        CREATE TABLE payments (
            payment_id INT PRIMARY KEY AUTO_INCREMENT,
            order_id INT NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            payment_method VARCHAR(50),
            payment_date DATE NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(order_id)
        )
    ");
    echo "✓ Created 'payments' table\n";

    // ============ SEED DEFAULT DATA ============
    
    // Insert default roles
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('Admin')");
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('Manager')");
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('User')");
    echo "✓ Inserted default roles\n";

    // Insert default store
    $pdo->exec("INSERT INTO stores (name, city, address, phone) VALUES ('Main Store', 'New York', '123 Main St', '555-0001')");
    echo "✓ Inserted default store\n";

    // Insert test user (matching your curl request)
    // Password hash for 'rkinvoicyfy123'
    $hashedPassword = password_hash('rkinvoicyfy123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, city, role_id, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['RK User', 'rk@invoicyfy.com', $hashedPassword, '555-0002', 'New York', 1, 1]);
    echo "✓ Inserted test user (rk@invoicyfy.com)\n";

    // Insert sample customer
    $pdo->exec("INSERT INTO customers (name, phone, email, address) VALUES ('John Doe', '555-1234', 'john@example.com', '456 Oak Ave')");
    echo "✓ Inserted sample customer\n";

    // Insert sample product
    $pdo->exec("INSERT INTO products (name, sku, description, price, unit, status, store_id) VALUES ('Sample Product', 'SKU001', 'A sample product for testing', 99.99, 'piece', 'active', 1)");
    echo "✓ Inserted sample product\n";

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✓ DATABASE SETUP COMPLETE!\n";
    echo str_repeat("=", 50) . "\n";
    echo "\nYou can now login with:\n";
    echo "Email: rk@invoicyfy.com\n";
    echo "Password: rkinvoicyfy123\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "✗ ERROR: " . $e->getMessage();
    die();
}
?>
