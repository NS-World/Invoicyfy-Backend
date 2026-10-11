<style>
body { font-family: Arial, sans-serif; font-size: 12px; }
h2 { margin-bottom: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #ddd; padding: 8px; }
th { background-color: #f2f2f2; }
.footer { margin-top: 30px; font-size: 10px; text-align: center; color: #777; }
</style>

<h2><?= htmlspecialchars($invoice['store_name']) ?></h2>
<p><?= nl2br(htmlspecialchars($invoice['store_address'])) ?></p>

<hr>

<h3>Invoice #: <?= htmlspecialchars($invoice['invoice_number']) ?></h3>
<p>Date: <?= $invoice['invoice_date'] ?><br>
Customer: <?= htmlspecialchars($invoice['customer_name']) ?><br>
Phone: <?= htmlspecialchars($invoice['customer_phone']) ?><br>
Email: <?= htmlspecialchars($invoice['customer_email']) ?></p>

<table>
<thead>
<tr>
    <th>#</th>
    <th>Product</th>
    <th>Qty</th>
    <th>Unit Price</th>
    <th>Total</th>
</tr>
</thead>
<tbody>
<?php foreach ($items as $i => $item): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($item['product_name']) ?></td>
    <td><?= $item['quantity'] ?></td>
    <td><?= number_format($item['unit_price'], 2) ?></td>
    <td><?= number_format($item['total_price'], 2) ?></td>
</tr>
<?php endforeach; ?>
<tr>
    <td colspan="4" align="right"><strong>Total:</strong></td>
    <td><strong><?= number_format($invoice['total_amount'], 2) ?></strong></td>
</tr>
</tbody>
</table>

<?php if ($payments): ?>
<h4>Payments</h4>
<table>
<thead>
<tr><th>Date</th><th>Method</th><th>Amount</th><th>Ref No.</th></tr>
</thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
    <td><?= $p['payment_date'] ?></td>
    <td><?= $p['method'] ?></td>
    <td><?= number_format($p['amount'], 2) ?></td>
    <td><?= $p['reference_no'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<div class="footer">Thank you for your business!</div>
