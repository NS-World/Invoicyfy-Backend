<?php
/**
 * COMPLETE DATABASE SETUP - FINAL SOLUTION
 */

header('Content-Type: text/plain; charset=utf-8');

try {
    // Connection WITHOUT database selection
    $host = 'localhost';
    $db_name = 'invoice_system';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "====== DATABASE SETUP STARTED ======\n\n";
    
    // STEP 1: Drop and create database
    echo "[1/14] Dropping old database...\n";
    $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
    echo "✓ Done\n\n";
    
    echo "[2/14] Creating fresh database...\n";
    $pdo->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    echo "✓ Done\n\n";
    
    // STEP 3: Roles
    echo "[3/14] Creating roles table...\n";
    $pdo->exec("CREATE TABLE roles (
        role_id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 4: Stores
    echo "[4/14] Creating stores table...\n";
    $pdo->exec("CREATE TABLE stores (
        store_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        city VARCHAR(100),
        address TEXT,
        phone VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 5: Users
    echo "[5/14] Creating users table...\n";
    $pdo->exec("CREATE TABLE users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 6: Customers
    echo "[6/14] Creating customers table...\n";
    $pdo->exec("CREATE TABLE customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        email VARCHAR(255),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 7: Products
    echo "[7/14] Creating products table...\n";
    $pdo->exec("CREATE TABLE products (
        product_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        sku VARCHAR(100) UNIQUE,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        unit VARCHAR(50),
        status VARCHAR(50) DEFAULT 'active',
        store_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (store_id) REFERENCES stores(store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 8: Invoices
    echo "[8/14] Creating invoices table...\n";
    $pdo->exec("CREATE TABLE invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 9: Invoice Items
    echo "[9/14] Creating invoice_items table...\n";
    $pdo->exec("CREATE TABLE invoice_items (
        invoice_item_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 10: Orders
    echo "[10/14] Creating orders table...\n";
    $pdo->exec("CREATE TABLE orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        user_id INT NOT NULL,
        store_id INT NOT NULL,
        total_amount DECIMAL(10, 2) NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (store_id) REFERENCES stores(store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 11: Order Items
    echo "[11/14] Creating order_items table...\n";
    $pdo->exec("CREATE TABLE order_items (
        order_item_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 12: Payments
    echo "[12/14] Creating payments table...\n";
    $pdo->exec("CREATE TABLE payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        payment_method VARCHAR(50),
        payment_date DATE NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Done\n\n";
    
    // STEP 13: Seed Data
    echo "[13/14] Inserting seed data...\n";
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('Admin'),('Manager'),('User')");
    $pdo->exec("INSERT INTO stores (name, city, address, phone) VALUES ('Main Store', 'New York', '123 Main St', '555-0001')");
    
    $hashedPassword = password_hash('rkinvoicyfy123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, city, role_id, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['RK User', 'rk@invoicyfy.com', $hashedPassword, '555-0002', 'New York', 1, 1]);
    
    $pdo->exec("INSERT INTO customers (name, phone, email, address) VALUES ('John Doe', '555-1234', 'john@example.com', '456 Oak Ave')");
    $pdo->exec("INSERT INTO products (name, sku, description, price, unit, status, store_id) VALUES ('Sample Product', 'SKU001', 'A sample product', 99.99, 'piece', 'active', 1)");
    echo "✓ Done\n\n";
    
    echo "[14/14] Verification...\n";
    $result = $pdo->query("SELECT COUNT(*) as user_count FROM users")->fetch(PDO::FETCH_ASSOC);
    echo "✓ Done\n\n";
    
    echo "====== ✅ DATABASE SETUP COMPLETE ✅ ======\n\n";
    echo "📊 Summary:\n";
    echo "  ✓ All 10 tables created successfully\n";
    echo "  ✓ Default roles added (Admin, Manager, User)\n";
    echo "  ✓ Test user created\n";
    echo "  ✓ Sample data loaded\n\n";
    echo "🔑 LOGIN CREDENTIALS:\n";
    echo "  Email: rk@invoicyfy.com\n";
    echo "  Password: rkinvoicyfy123\n\n";
    echo "🚀 Ready to use! Start making API requests.\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    die();
}
?>
