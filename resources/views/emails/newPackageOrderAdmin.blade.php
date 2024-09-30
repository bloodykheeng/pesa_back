<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Package Order Submitted</title>
</head>

<body>
    <div>
        <h1>New Package Order Submitted</h1>
        <p>Hello Admin,</p>
        <p>A new package order has been submitted with the following details:</p>
        <ul>
            <li>Package Name: {{ $package->name }}</li>
            <li>Package Number: {{ $package->package_number }}</li>
            <li>Extra Info: {{ $package->extraInfo }}</li>
        </ul>
        <p>Please review the package order in the system.</p>
        <p>Best regards,<br>Order Management System</p>
    </div>
</body>

</html>
