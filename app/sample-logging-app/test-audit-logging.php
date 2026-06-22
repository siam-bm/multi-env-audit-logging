#!/usr/bin/php
<?php
// Test script for centralized audit logging

// Test 1: Create a new product
echo "Test 1: Creating a new product...\n";
$createData = [
    'name' => 'Laptop Pro 2024',
    'description' => 'High-performance laptop with 16GB RAM',
    'price' => '1299.99',
    'quantity' => '15',
    'status' => 'active'
];

$ch = curl_init('http://sample-app.daybud.com/products/add');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($createData));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
$response = curl_exec($ch);
curl_close($ch);
echo "Product created.\n\n";

// Test 2: Update the product (assuming ID 1)
echo "Test 2: Updating product...\n";
$updateData = [
    'name' => 'Laptop Pro 2024 - Updated',
    'description' => 'High-performance laptop with 32GB RAM - UPGRADED',
    'price' => '1499.99',
    'quantity' => '10',
    'status' => 'active'
];

$ch = curl_init('http://sample-app.daybud.com/products/edit/1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateData));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
$response = curl_exec($ch);
curl_close($ch);
echo "Product updated.\n\n";

// Test 3: View the product
echo "Test 3: Viewing product...\n";
$ch = curl_init('http://sample-app.daybud.com/products/view/1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
$response = curl_exec($ch);
curl_close($ch);
echo "Product viewed.\n\n";

// Test 4: Create a new user
echo "Test 4: Creating a new user...\n";
$userData = [
    'name' => 'John Doe',
    'email' => 'john.doe@example.com',
    'password' => 'SecurePassword123'
];

$ch = curl_init('http://sample-app.daybud.com/users/add');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($userData));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
$response = curl_exec($ch);
curl_close($ch);
echo "User created.\n\n";

echo "All tests completed. Check the logs at:\n";
echo "- /opt/codes/sample-logging-app/logs/app.json\n";
echo "- OpenSearch Dashboard: http://localhost:5601\n";