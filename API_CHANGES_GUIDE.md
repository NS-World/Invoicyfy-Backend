# API Functionality Updates - Complete Guide

## Overview
Fixed the synchronization between order creation and order items. Orders can now be created together with items in a single transaction.

---

## Changes Made

### 1. ✅ CREATE ORDER API (`master/order/create-order.php`)

**What Changed:**
- Now accepts optional `items` array in the request payload
- Creates order items in the same transaction as the order
- Removed hardcoded `order_number` and `order_date` requirements
- Uses database transaction for atomicity

**How to Use:**

```json
POST http://localhost/invoice-api/master/order/create-order.php

Headers:
{
  "Content-Type": "application/json",
  "Authorization": "Bearer {JWT_TOKEN}"
}

Body:
{
  "customer_id": 1,
  "user_id": 1,
  "store_id": 1,
  "total_amount": 250.00,
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 50.00
    },
    {
      "product_id": 2,
      "quantity": 3,
      "unit_price": 50.00
    }
  ]
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Order created successfully",
  "order_id": 15,
  "items_created": 2,
  "items_skipped": 0,
  "inserted_product_ids": [1, 2],
  "skipped_items": []
}
```

**Key Features:**
- ✓ Creates order and items together (transaction)
- ✓ Validates all items have product_id, quantity, unit_price
- ✓ Checks if products exist in products table
- ✓ Returns feedback on which items were created/skipped
- ✓ Automatic order_id assignment
- ✓ HTTP 201 (Created) response code

---

### 2. ✅ GET ORDER ITEMS API (`master/order_items/get-order-items.php`)

**What Changed:**
- Fixed SQL query - now calculates total_price instead of selecting non-existent column
- Returns complete order details with all related customer, product, and user information
- Includes order summary with item count and grand total

**How to Use:**

```
GET http://localhost/invoice-api/master/order_items/get-order-items.php?order_id=15

Headers:
{
  "Authorization": "Bearer {JWT_TOKEN}"
}
```

**Response:**
```json
{
  "status": "success",
  "summary": {
    "order_id": 15,
    "item_count": 2,
    "grand_total": 250.00
  },
  "items": [
    {
      "order_item_id": 23,
      "order_id": 15,
      "product_id": 1,
      "quantity": 2,
      "unit_price": 50.00,
      "total_price": 100.00,
      "product_name": "Product Name",
      "sku": "SKU123",
      "product_price": 50.00,
      "description": "Product description",
      "order_number": "ORD-15",
      "order_date": "2026-03-15",
      "total_amount": 250.00,
      "order_status": "pending",
      "customer_name": "Customer Name",
      "customer_id": 1,
      "email": "customer@example.com",
      "phone": "1234567890",
      "user_name": "Admin User",
      "user_id": 1
    }
  ]
}
```

---

### 3. ✅ UPDATE ORDER STATUS API (`master/order/update-order-status.php`)

**What Changed:**
- Added order existence validation
- Made status case-insensitive (accepts "pending", "Pending", "PENDING", etc.)
- Returns better response with new status
- Only updates if status actually changes

**How to Use:**

```json
POST http://localhost/invoice-api/master/order/update-order-status.php

Headers:
{
  "Content-Type": "application/json",
  "Authorization": "Bearer {JWT_TOKEN}"
}

Body:
{
  "order_id": 15,
  "status": "Approved"
}
```

**Valid Status Values:**
- `pending` / `Pending`
- `approved` / `Approved`
- `cancelled` / `Cancelled`
- `invoiced` / `Invoiced`

**Response:**
```json
{
  "status": "success",
  "message": "Order status updated successfully",
  "order_id": 15,
  "new_status": "Approved"
}
```

---

## Complete Workflow Example

### Step 1: Create Order with Items
```bash
curl --request POST \
  --url http://localhost/invoice-api/master/order/create-order.php \
  --header 'Authorization: Bearer YOUR_JWT_TOKEN' \
  --header 'Content-Type: application/json' \
  --data '{
    "customer_id": 1,
    "user_id": 1,
    "store_id": 1,
    "total_amount": 350,
    "items": [
      {"product_id": 1, "quantity": 2, "unit_price": 50},
      {"product_id": 2, "quantity": 3, "unit_price": 50},
      {"product_id": 3, "quantity": 1, "unit_price": 100}
    ]
  }'
```
Response: `order_id: 20`

### Step 2: Retrieve Order Items
```bash
curl --request GET \
  --url 'http://localhost/invoice-api/master/order_items/get-order-items.php?order_id=20' \
  --header 'Authorization: Bearer YOUR_JWT_TOKEN'
```
Response: All items with product details and pricing

### Step 3: Update Order Status
```bash
curl --request POST \
  --url http://localhost/invoice-api/master/order/update-order-status.php \
  --header 'Authorization: Bearer YOUR_JWT_TOKEN' \
  --header 'Content-Type: application/json' \
  --data '{
    "order_id": 20,
    "status": "Approved"
  }'
```
Response: Confirmation of status update

---

## Database Schema (Unchanged)

### Orders Table
```sql
CREATE TABLE orders (
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
)
```

### Order Items Table
```sql
CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
)
```

---

## Error Handling

### Create Order Errors
| Error | Cause |
|-------|-------|
| 400 - Missing required fields | customer_id, user_id, store_id, or total_amount not provided |
| 409 - Product not found | One of the product_ids in items doesn't exist |
| 500 - Database error | Connection or query failure |

### Get Order Items Errors
| Error | Cause |
|-------|-------|
| 400 - No valid parameter | order_id or order_item_id not provided |
| 404 - Order not found | Specified order_id doesn't exist |

### Update Order Status Errors
| Error | Cause |
|-------|-------|
| 400 - Missing fields | order_id or status not provided |
| 400 - Invalid status | Status not in ['pending', 'approved', 'cancelled', 'invoiced'] |
| 404 - Order not found | Specified order_id doesn't exist |

---

## Important Notes

✅ **What Works Now:**
- Orders created with items in a single transaction
- Items immediately available via get-order-items endpoint
- Order status updates work correctly
- All database relationships maintained
- No database schema changes required
- No API endpoint changes needed

✅ **API Design Maintained:**
- Same endpoint URLs
- Same authentication method (JWT)
- Same request/response format
- Backward compatible (items parameter is optional)

## JWT Secret Configuration

Configure `JWT_ACCESS_SECRET` and `JWT_REFRESH_SECRET` in the PHP runtime environment before using the authentication endpoints. Use separate, randomly generated secrets of at least 32 bytes and keep them out of source control. The application fails explicitly if either variable is missing.

If the previously hardcoded signing keys have been used in any environment, replace them with newly generated secrets and invalidate existing tokens.

Install PHP dependencies from the project root with `composer install`. The generated `vendor/` directory is excluded from source control.

---

## Testing the APIs

To test without needing a valid JWT token during development, you can temporarily comment out the authentication check in the auth/validate.php file.

For production, make sure:
1. JWT tokens are properly generated from signin endpoint
2. `JWT_ACCESS_SECRET` and `JWT_REFRESH_SECRET` are configured in the PHP runtime environment
3. All required database tables are properly created
4. Test with valid JWT tokens from the signin endpoint
