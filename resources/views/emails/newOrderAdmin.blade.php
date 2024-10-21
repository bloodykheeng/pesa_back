<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Order Submitted</title>
</head>

<body>
    <div>
        <h1>New Order Submitted</h1>
        <p>Hello Admin,</p>
        <p>A new order has been submitted with the following details:</p>
        <ul>
            <li>Order Number: {{ $order->order_number }}</li>
            <li>Amount: {{ $order->amount }}</li>
            <li>Payment Mode: {{ $order->payment_mode }}</li>
        </ul>
        <p>Please review the order in the system.</p>
        <p>Best regards,<br>Order Management System</p>
    </div>
</body>

</html>
